<?php

namespace App\Services\Goals;

use App\Models\Goal;
use App\Models\GoalAlertEvent;
use App\Models\User;
use App\Notifications\GoalAlert;
use App\Support\Ownership;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/** El evento y el aviso in-app se confirman juntos, bajo el lock del objetivo y una clave única. */
final class GoalEvaluator
{
    public function __construct(private readonly GoalObservations $observations) {}

    public function evaluate(User $user, Goal $source): ?GoalAlertEvent
    {
        Ownership::assert($source, $user);

        return DB::transaction(function () use ($user, $source): ?GoalAlertEvent {
            $goal = Goal::ownedBy($user->id)->whereKey($source->id)->lockForUpdate()->first();
            if ($goal === null || ! $goal->enabled) {
                return null;
            }
            Ownership::assert($goal->brand, $user);
            $value = $goal->kind === 'token_expiry'
                ? $this->observations->token($user, $goal->brand, $goal)
                : $this->observations->read($user, $goal->brand, $goal);
            $goal->forceFill(['last_value' => $value['current'], 'last_period' => $value['period'], 'last_evaluated_at' => now(), 'last_expires_at' => $value['expires_at'] ?? null])->save();
            if ($value['current'] === null) {
                return null;
            }
            if ($goal->kind === 'milestone') {
                if ($value['current'] < $goal->threshold) {
                    return null;
                }
                $key = 'milestone:'.$this->ruleHash($goal);
                $message = 'Se ha alcanzado el hito con '.$value['current'].' (umbral '.$goal->threshold.').';
            } elseif ($goal->kind === 'drop') {
                if ($value['previous'] === null || $value['previous'] <= 0) {
                    return null;
                }
                $drop = ($value['previous'] - $value['current']) / $value['previous'] * 100;
                if ($drop <= $goal->threshold) {
                    return null;
                }
                $key = 'drop:'.$value['period'].':'.$this->ruleHash($goal);
                $message = 'Caída del '.round($drop, 2).'% (umbral '.$goal->threshold.'%).';
            } elseif ($goal->kind === 'token_expiry') {
                if ($value['current'] > $goal->threshold) {
                    return null;
                }
                $key = 'token:'.hash('sha256', json_encode([$goal->social_account_id, $value['expires_at']], JSON_THROW_ON_ERROR));
                $date = CarbonImmutable::parse($value['expires_at'])->setTimezone($goal->brand->timezone ?: 'UTC')->locale('es')->isoFormat('D [de] MMMM [de] YYYY');
                $message = ($value['current'] < 0 ? 'La credencial caducó el ' : 'La credencial caduca el ').$date.'.';
            } else {
                return null;
            }
            if (GoalAlertEvent::ownedBy($user->id)->where('goal_id', $goal->id)->where('period_key', $key)->exists()) {
                return null;
            }
            $payload = [
                'goal_id' => $goal->id, 'brand_id' => $goal->brand_id, 'brand_name' => $goal->brand->name,
                'title' => $goal->name, 'message' => $message.' Periodo: '.$value['period'].'.',
                'period' => $value['period'], 'value' => $value['current'],
                'expires_at' => $value['expires_at'] ?? null,
                'url' => route('goals.index', ['brand_id' => $goal->brand_id], false),
            ];
            $event = new GoalAlertEvent;
            $event->forceFill(['user_id' => $user->id, 'goal_id' => $goal->id, 'period_key' => $key, 'payload' => $payload])->save();
            Notification::sendNow($user, new GoalAlert($user->id, $payload));
            if ($goal->mail_enabled && $user->notify_by_mail && $user->hasVerifiedEmail()) {
                $user->notify((new GoalAlert($user->id, $payload, mailOnly: true))->afterCommit());
            }

            return $event;
        }, 3);
    }

    private function ruleHash(Goal $goal): string
    {
        return hash('sha256', json_encode([$goal->metric, $goal->threshold, $goal->social_account_id], JSON_THROW_ON_ERROR));
    }
}
