<?php

namespace App\Services\Sites;

use App\Models\Site;
use App\Models\User;
use App\Notifications\SiteMessagesFull;
use App\Services\SmartLinkService;
use App\Support\DailyCounter;
use App\Support\Ownership;
use App\Support\SiteRichText;
use Illuminate\Support\Facades\DB;

/**
 * Lo que ocurre en un sitio publicado sin sesion (T-15): visitas, formulario de contacto y encuestas, mas las estadisticas
 * agregadas y el vaciado de mensajes que ve el propietario.
 */
class SitePublicInteractions
{
    public const MAX_MESSAGES = 500;

    public function __construct(private readonly DailyCounter $counter) {}

    public function recordVisit(Site $site, string $pageKey): void
    {
        $key = ['site_id' => $site->id, 'page_key' => $pageKey, 'date' => now('UTC')->toDateString()];
        $this->counter->increment('site_visits', $key);
    }

    /** @return array{total: int, by_page: array<string, int>, daily: list<array{date: string, views: int}>} */
    public function stats(Site $site, int $days = 30): array
    {
        $rows = DB::table('site_visits')->where('site_id', $site->id)->where('date', '>=', now('UTC')->subDays($days - 1)->toDateString())->get();
        $daily = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $d = now('UTC')->subDays($i)->toDateString();
            $daily[] = ['date' => $d, 'views' => (int) $rows->where('date', $d)->sum('count')];
        }

        return ['total' => (int) $rows->sum('count'), 'by_page' => $rows->groupBy('page_key')->map(fn ($r) => (int) $r->sum('count'))->all(), 'daily' => $daily];
    }

    public function addMessage(Site $site, string $pageKey, string $blockKey, string $name, string $email, string $message): bool
    {
        $name = SmartLinkService::clean($name, 100);
        $email = mb_substr(trim($email), 0, 254);
        $message = SiteRichText::clean($message, 2000);
        if ($name === '' || $message === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false || preg_match('/[\r\n]/', $email) === 1) {
            return false;
        }
        DB::table('site_messages')->insert(['site_id' => $site->id, 'page_key' => $pageKey, 'block_key' => $blockKey, 'name' => $name, 'email' => $email, 'message' => $message, 'created_at' => now()]);
        $this->trimMessages($site);

        return true;
    }

    /** Borra todos los mensajes (o solo los leidos) y rearma el aviso de tope. */
    public function clearMessages(User $user, Site $site, bool $onlyRead): int
    {
        Ownership::assert($site, $user);
        $n = DB::table('site_messages')->where('site_id', $site->id)->when($onlyRead, fn ($q) => $q->whereNotNull('read_at'))->delete();
        DB::table('sites')->where('id', $site->id)->update(['messages_full_notified_at' => null]);

        return $n;
    }

    public static function optionsHash(array $options): string
    {
        return substr(sha1((string) json_encode(array_values($options))), 0, 20);
    }

    public function vote(Site $site, array $block, int $option): bool
    {
        if ($option < 0 || $option >= count($block['options'] ?? [])) {
            return false;
        }
        $this->counter->increment('site_poll_votes', ['site_id' => $site->id, 'block_key' => $block['key'], 'options_hash' => self::optionsHash($block['options']), 'option_index' => $option]);

        return true;
    }

    /** @return list<int> votos por opcion (solo los de las opciones vigentes del bloque). */
    public function pollResults(Site $site, array $block): array
    {
        $counts = DB::table('site_poll_votes')->where('site_id', $site->id)->where('block_key', $block['key'])
            ->where('options_hash', self::optionsHash($block['options']))->pluck('count', 'option_index');

        return array_map(fn ($i) => (int) ($counts[$i] ?? 0), array_keys($block['options']));
    }

    /** Tope FIFO: el formulario nunca se bloquea; al pasar del tope se descartan los mas antiguos y se avisa al propietario UNA vez. */
    private function trimMessages(Site $site): void
    {
        $cut = DB::table('site_messages')->where('site_id', $site->id)->orderByDesc('id')->skip(self::MAX_MESSAGES)->value('id');
        if ($cut === null) {
            return;
        }
        DB::table('site_messages')->where('site_id', $site->id)->where('id', '<=', $cut)->delete();
        if (DB::table('sites')->where('id', $site->id)->whereNull('messages_full_notified_at')->update(['messages_full_notified_at' => now()]) > 0) {
            Site::withoutUserScope()->find($site->id)?->user?->notify(new SiteMessagesFull($site->id, $site->title, self::MAX_MESSAGES));
        }
    }
}
