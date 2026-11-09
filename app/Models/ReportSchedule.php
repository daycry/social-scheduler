<?php

namespace App\Models;

use App\Analytics\Period;
use App\Models\Concerns\BelongsToUser;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Envio periodico (semanal o mensual) del PDF de una plantilla por email a destinatarios validados. */
class ReportSchedule extends Model
{
    use BelongsToUser;

    public const WEEKLY = 'weekly';

    public const MONTHLY = 'monthly';

    public const FREQUENCIES = [self::WEEKLY, self::MONTHLY];

    /** Hora local de la marca a la que se envia. */
    public const SEND_HOUR = 8;

    protected $fillable = ['user_id', 'brand_id', 'report_template_id', 'frequency', 'recipients', 'active', 'next_run_at', 'last_run_at', 'last_error'];

    protected function casts(): array
    {
        return ['recipients' => 'array', 'active' => 'boolean', 'next_run_at' => 'datetime', 'last_run_at' => 'datetime'];
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(ReportTemplate::class, 'report_template_id');
    }

    /** Proxima ejecucion (UTC) a partir de `$from`: lunes (semanal) o dia 1 (mensual) a las 08:00 de la zona de la marca. */
    public static function nextRun(string $frequency, ?string $timezone, ?CarbonImmutable $from = null): CarbonImmutable
    {
        $zone = in_array($timezone, timezone_identifiers_list(), true) ? $timezone : 'UTC';
        $now = ($from ?? CarbonImmutable::now('UTC'))->setTimezone($zone);
        $candidate = $frequency === self::MONTHLY ? $now->startOfMonth()->setTime(self::SEND_HOUR, 0) : $now->startOfWeek()->setTime(self::SEND_HOUR, 0);

        while ($candidate->lte($now)) {
            $candidate = $frequency === self::MONTHLY ? $candidate->addMonthNoOverflow()->startOfMonth()->setTime(self::SEND_HOUR, 0) : $candidate->addWeek();
        }

        return $candidate->utc();
    }

    /** Periodo cerrado que cubre el envio: semana anterior (lunes a domingo) o mes natural anterior, en la zona de la marca. */
    public static function periodFor(string $frequency, ?string $timezone, ?CarbonImmutable $now = null): Period
    {
        $zone = in_array($timezone, timezone_identifiers_list(), true) ? $timezone : 'UTC';
        $local = ($now ?? CarbonImmutable::now('UTC'))->setTimezone($zone);

        if ($frequency === self::MONTHLY) {
            $start = $local->startOfMonth()->subMonthNoOverflow()->startOfMonth();
            $end = $start->endOfMonth();
        } else {
            $start = $local->startOfWeek()->subWeek();
            $end = $start->addDays(6);
        }

        return Period::between(CarbonImmutable::createFromFormat('!Y-m-d', $start->format('Y-m-d'), 'UTC'), CarbonImmutable::createFromFormat('!Y-m-d', $end->format('Y-m-d'), 'UTC'));
    }
}
