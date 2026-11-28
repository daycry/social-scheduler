<!DOCTYPE html>
<html lang="es">
<body style="font-family: Arial, sans-serif; color: #1f2937;">
    <h1 style="font-size: 18px;">Tu resumen semanal</h1>
    <p>Semana del {{ $period }}, comparada con la anterior.</p>
    @if ($ai)
        <p style="border-left: 3px solid #1d4ed8; padding-left: 10px;">{{ $ai }}<br><span style="font-size: 12px; color: #4b5563;">Párrafo generado con IA a partir de las cifras de abajo.</span></p>
    @endif
    @foreach ($brands as $brand)
        <h2 style="font-size: 16px; margin-top: 24px;">{{ $brand['name'] }}</h2>
        @if (! $brand['has_data'])
            <p>sin datos esta semana.</p>
        @else
            <ul>
                @foreach ($brand['metrics'] as $metric)
                    <li>{{ $metric['label'] }}: <strong>{{ $metric['value'] }}</strong> ({{ $metric['change'] }})</li>
                @endforeach
                <li>Publicaciones publicadas: <strong>{{ $brand['published'] }}</strong></li>
            </ul>
            @if ($brand['best'])
                <p>Mejor publicación ({{ $brand['best']['network'] }}): {{ $brand['best']['text'] ?? 'sin texto' }} — {{ $brand['best']['interactions'] }} interacciones, {{ $brand['best']['views'] }} visualizaciones.</p>
            @endif
        @endif
    @endforeach
    <p><a href="{{ $dashboardUrl }}">Abrir el panel</a></p>
    <p style="font-size: 12px; color: #4b5563;">Recibes esto porque activaste el resumen semanal. <a href="{{ $unsubscribeUrl }}">Dejar de recibirlo</a> · <a href="{{ $preferencesUrl }}">Cambiar mis preferencias de avisos</a>.</p>
</body>
</html>
