TU RESUMEN SEMANAL
Semana del {!! $period !!}, comparada con la anterior.
@if ($ai)

{!! $ai !!}
(Párrafo generado con IA a partir de las cifras de abajo.)
@endif
@foreach ($brands as $brand)

{!! $brand['name'] !!}
@if (! $brand['has_data'])
- sin datos esta semana.
@else
@foreach ($brand['metrics'] as $metric)
- {!! $metric['label'] !!}: {!! $metric['value'] !!} ({!! $metric['change'] !!})
@endforeach
- Publicaciones publicadas: {!! $brand['published'] !!}
@if ($brand['best'])
- Mejor publicación ({!! $brand['best']['network'] !!}): {!! $brand['best']['text'] ?? 'sin texto' !!} — {!! $brand['best']['interactions'] !!} interacciones, {!! $brand['best']['views'] !!} visualizaciones.
@endif
@endif
@endforeach

Abrir el panel: {!! $dashboardUrl !!}

Recibes esto porque activaste el resumen semanal.
Dejar de recibirlo: {!! $unsubscribeUrl !!}
Cambiar mis preferencias de avisos: {!! $preferencesUrl !!}
