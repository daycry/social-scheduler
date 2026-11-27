<?php

namespace App\Services;

use App\Models\User;
use App\Notifications\AsyncJobDone;
use App\Notifications\AsyncJobFailed;
use App\Notifications\AsyncJobFailureSummary;
use App\Notifications\ConnectionExpiring;
use App\Notifications\FirstCommentFailed;
use App\Notifications\GoalAlert;
use App\Notifications\ManualPublishReminder;
use App\Notifications\PostMediaMissing;
use App\Notifications\PostWorkflowActivity;
use App\Notifications\PublishFailed;
use App\Notifications\ReconnectRequired;
use App\Notifications\SeriesGenerationFailed;
use App\Notifications\SiteMessagesFull;
use App\Notifications\SmartLinkSubscribersFull;
use App\Notifications\StudioDesignActivity;
use App\Notifications\WeeklyDigestReady;
use Illuminate\Notifications\Notification;

final class NotificationPreferences
{
    private const APP_PUSH = ['database', 'push'];

    private const ALL = ['database', 'push', 'mail'];

    public const TYPES = [
        'weekly_digest' => ['Resumen semanal', self::ALL, WeeklyDigestReady::class],
        'manual_reminder' => ['Recordatorio de publicación manual', self::ALL, ManualPublishReminder::class],
        'manual_due' => ['Hora de publicar manualmente', self::APP_PUSH, null],
        'approval_activity' => ['Aprobaciones de contenido', self::APP_PUSH, PostWorkflowActivity::class],
        'collaboration' => ['Colaboración en marcas', self::APP_PUSH, null],
        'publish_failed' => ['Fallos de publicación', self::ALL, PublishFailed::class],
        'first_comment_failed' => ['Fallos del primer comentario', self::APP_PUSH, FirstCommentFailed::class],
        'media_missing' => ['Archivos de publicación que faltan', self::APP_PUSH, PostMediaMissing::class],
        'series_failed' => ['Fallos al generar series', self::APP_PUSH, SeriesGenerationFailed::class],
        'connection_expiring' => ['Conexiones a punto de caducar', self::ALL, ConnectionExpiring::class],
        'reconnect_required' => ['Cuentas que requieren reconexión', self::ALL, ReconnectRequired::class],
        'job_done' => ['Trabajos en segundo plano completados', self::APP_PUSH, AsyncJobDone::class],
        'job_failed' => ['Fallos de trabajos en segundo plano', self::ALL, AsyncJobFailed::class],
        'goal_alert' => ['Alertas de objetivos', self::ALL, GoalAlert::class],
        'scheduled_report' => ['Informes programados', ['mail'], null],
        'studio_activity' => ['Actividad y colaboración en el Estudio', self::APP_PUSH, StudioDesignActivity::class],
        'site_messages_full' => ['Límite de mensajes del sitio', self::APP_PUSH, SiteMessagesFull::class],
        'smartlink_subscribers_full' => ['Límite de suscriptores de SmartLinks', self::APP_PUSH, SmartLinkSubscribersFull::class],
    ];

    public static function typeFor(Notification|string $notice, string $kind = ''): ?string
    {
        if ($notice instanceof PostWorkflowActivity) {
            $kind = $notice->kind;
        }
        $class = is_string($notice) ? $notice : $notice::class;

        return match ($class) {
            PostWorkflowActivity::class => match ($kind) {
                'manual_due' => 'manual_due',
                'collaborator_invited', 'collaborator_revoked' => 'collaboration',
                default => 'approval_activity',
            },
            AsyncJobFailureSummary::class => 'job_failed',
            default => self::typeFromClass($class),
        };
    }

    private static function typeFromClass(string $class): ?string
    {
        foreach (self::TYPES as $type => [, , $notification]) {
            if ($notification === $class) {
                return $type;
            }
        }

        return null;
    }

    /** null identifies a security notification or a delivery outside the user's preferences. */
    public static function allows(User $user, ?string $type, string $channel): bool
    {
        if ($type === null) {
            return true;
        }
        if (! in_array($channel, self::TYPES[$type][1], true)) {
            return false;
        }
        if (($type === 'weekly_digest' && ! $user->notify_weekly_digest)
            || ($type === 'manual_reminder' && ! $user->notify_manual_reminder)) {
            return false;
        }

        return $user->notification_channels[$type][$channel] ?? self::defaultFor($user, $type, $channel);
    }

    public static function anyChannel(User $user, string $type): bool
    {
        foreach (self::TYPES[$type][1] as $channel) {
            if (self::allows($user, $type, $channel)) {
                return true;
            }
        }

        return false;
    }

    public static function defaultFor(User $user, string $type, string $channel): bool
    {
        return $channel !== 'mail' || in_array($type, ['publish_failed', 'reconnect_required', 'scheduled_report'], true)
            || $user->notify_by_mail;
    }

    public static function forProfile(User $user): array
    {
        $preferences = [];
        foreach (self::TYPES as $type => [, $channels]) {
            foreach ($channels as $channel) {
                $preferences[$type][$channel] = $user->notification_channels[$type][$channel] ?? self::defaultFor($user, $type, $channel);
            }
        }

        return $preferences;
    }
}
