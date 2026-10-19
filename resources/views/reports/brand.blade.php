@php
    $color = $branding['color'];
    $fmt = fn ($v) => $v === null ? 'No disponible' : (is_float($v) && floor($v) != $v ? number_format($v, 1, ',', '.') : number_format($v, 0, ',', '.'));
    $pct = fn ($v) => $v === null ? '-' : (($v > 0 ? '+' : '').number_format($v, 1, ',', '.').' %');
    $compare = $data['comparison'];
    $maxView = collect($data['timeline']['points'])->max('value') ?: 0;
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Informe {{ $data['brand']['name'] }}</title>
    <style>
        @page { margin: 60px 40px 50px 40px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #1f2937; }
        h1 { font-size: 26px; margin: 0 0 6px 0; }
        h2 { font-size: 15px; color: {{ $color }}; border-bottom: 2px solid {{ $color }}; padding-bottom: 4px; margin: 0 0 12px 0; }
        h3 { font-size: 12px; margin: 14px 0 6px 0; }
        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; background: #f3f4f6; padding: 5px 6px; font-size: 9px; text-transform: uppercase; color: #4b5563; }
        td { padding: 5px 6px; border-bottom: 1px solid #e5e7eb; vertical-align: top; }
        .num { text-align: right; white-space: nowrap; }
        .muted { color: #9ca3af; }
        .up { color: #047857; }
        .down { color: #b91c1c; }
        .cover { text-align: left; padding-top: 140px; }
        .cover .bar { height: 8px; background: {{ $color }}; width: 140px; margin: 14px 0; }
        .cover img { max-height: 70px; max-width: 220px; margin-bottom: 30px; }
        .cover .brand { font-size: 12px; color: {{ $color }}; font-weight: bold; text-transform: uppercase; margin-bottom: 30px; }
        .page { page-break-before: always; }
        .kpi { width: 25%; border: 1px solid #e5e7eb; padding: 8px; }
        .kpi .v { font-size: 18px; font-weight: bold; }
        .bar-cell { height: 6px; background: {{ $color }}; }
        .chart td { padding: 0 1px; border: 0; vertical-align: bottom; }
        .footer { position: fixed; bottom: -30px; left: 0; right: 0; font-size: 8px; color: #9ca3af; text-align: center; }
    </style>
</head>
<body>
<div class="footer">{{ $branding['footer'] ?: config('app.name') }} · {{ $data['brand']['name'] }} · {{ $data['period']['from'] }} - {{ $data['period']['to'] }}</div>

@if (in_array('cover', $sections))
    <div class="cover">
        @if ($branding['logo'])
            <img src="{{ $branding['logo'] }}" alt="Logo">
        @else
            <div class="brand">{{ config('app.name') }}</div>
        @endif
        <h1>Informe de redes sociales</h1>
        <div class="bar"></div>
        <p style="font-size:16px;margin:0 0 4px 0;">{{ $data['brand']['name'] }}</p>
        <p style="font-size:12px;margin:0;" class="muted">
            {{ $data['period']['from'] }} - {{ $data['period']['to'] }} ({{ $data['period']['days'] }} dias)
            @if ($compare) · Comparado con {{ $compare['from'] }} - {{ $compare['to'] }} @endif
        </p>
        <p style="font-size:9px;margin-top:40px;" class="muted">Generado el {{ \Illuminate\Support\Carbon::parse($data['generated_at'])->format('Y-m-d H:i') }} UTC</p>
    </div>
@endif

@if (in_array('summary', $sections))
    <div class="{{ in_array('cover', $sections) ? 'page' : '' }}">
        <h2>Resumen</h2>
        <table style="margin-bottom:12px;"><tr>
            @foreach ($data['cards'] as $c)
                <td class="kpi">
                    <div class="muted">{{ $c['label'] }}</div>
                    <div class="v {{ $c['available'] ? '' : 'muted' }}" @unless($c['available']) style="font-size:11px" @endunless>{{ $fmt($c['total']) }}</div>
                    @if ($compare && $c['available'])
                        <div class="{{ ($c['delta_pct'] ?? 0) >= 0 ? 'up' : 'down' }}">{{ $pct($c['delta_pct']) }}</div>
                    @endif
                </td>
            @endforeach
        </tr></table>

        @if (count($data['timeline']['points']) && $maxView > 0)
            <h3>{{ $data['timeline']['label'] }} por dia</h3>
            <table class="chart"><tr>
                @foreach ($data['timeline']['points'] as $p)
                    <td style="height:90px;"><div style="background:{{ $color }};height:{{ $p['value'] === null ? 1 : max(1, round(($p['value'] / $maxView) * 88)) }}px;"></div></td>
                @endforeach
            </tr></table>
            <p class="muted" style="margin:2px 0 12px 0;">{{ $data['period']['from'] }} ... {{ $data['period']['to'] }} (maximo diario: {{ $fmt($maxView) }})</p>
        @endif

        @foreach ($data['cards'] as $c)
            <h3>{{ $c['label'] }}</h3>
            <table>
                <tr><th>Red</th><th class="num">Valor</th>@if ($compare)<th class="num">Anterior</th><th class="num">Variacion</th>@endif</tr>
                @forelse ($c['by_network'] as $b)
                    <tr>
                        <td>{{ $b['label'] }}</td>
                        <td class="num {{ $b['available'] ? '' : 'muted' }}">{{ $fmt($b['value']) }}</td>
                        @if ($compare)<td class="num">{{ $fmt($b['previous']) }}</td><td class="num">{{ $pct($b['delta_pct']) }}</td>@endif
                    </tr>
                @empty
                    <tr><td colspan="4" class="muted">Sin redes conectadas.</td></tr>
                @endforelse
            </table>
        @endforeach
    </div>
@endif

@if (in_array('networks', $sections))
    @foreach ($data['networks'] as $n)
        <div class="page">
            <h2>{{ $n['label'] }}</h2>
            <table>
                <tr><th>Metrica</th><th class="num">Total</th>@if ($compare)<th class="num">Anterior</th><th class="num">Variacion</th>@endif</tr>
                @foreach ($n['metrics'] as $m)
                    <tr>
                        <td>{{ $m['label'] }}</td>
                        <td class="num {{ $m['available'] ? '' : 'muted' }}">{{ $fmt($m['total']) }}</td>
                        @if ($compare)<td class="num">{{ $fmt($m['previous']) }}</td><td class="num">{{ $pct($m['delta_pct']) }}</td>@endif
                    </tr>
                @endforeach
            </table>
            @if ($n['engagement_rate'] !== null)
                <p>Tasa de interaccion: <strong>{{ number_format($n['engagement_rate'], 2, ',', '.') }} %</strong></p>
            @endif
        </div>
    @endforeach
@endif

@if (in_array('top_posts', $sections))
    <div class="page">
        <h2>Publicaciones destacadas</h2>
        @php $any = false; @endphp
        @foreach ($data['networks'] as $n)
            @if (count($n['posts']))
                @php $any = true; @endphp
                <h3>{{ $n['label'] }}</h3>
                <table>
                    <tr><th>Publicacion</th><th>Fecha</th><th class="num">Visualiz.</th><th class="num">Alcance</th><th class="num">Interac.</th></tr>
                    @foreach ($n['posts'] as $p)
                        <tr>
                            <td>{{ $p['text'] ?? '(sin texto)' }}</td>
                            <td>{{ substr((string) $p['published_at'], 0, 10) }}</td>
                            <td class="num">{{ $fmt($p['metrics']['views'] ?? null) }}</td>
                            <td class="num">{{ $fmt($p['metrics']['reach'] ?? null) }}</td>
                            <td class="num">{{ $fmt($p['metrics']['interactions'] ?? null) }}</td>
                        </tr>
                    @endforeach
                </table>
            @endif
        @endforeach
        @unless ($any)
            <p class="muted">No hay publicaciones de la app con metricas en este periodo.</p>
        @endunless
    </div>
@endif

@if (in_array('notes', $sections))
    <div class="{{ in_array('top_posts', $sections) ? '' : 'page' }}" style="margin-top:20px;">
        <h2>Notas sobre las metricas</h2>
        <p>Las metricas marcadas como <strong>No disponible</strong> no las ofrece la API de la red (o la cuenta no ha concedido el permiso). No se muestran como 0 ni se estiman. Los totales suman solo las redes que tienen dato.</p>
        @if (count($data['notes']))
            <ul>
                @foreach ($data['notes'] as $note)
                    <li>{{ $note['network'] }}: {{ $note['metric'] }}</li>
                @endforeach
            </ul>
        @endif
    </div>
@endif
</body>
</html>
