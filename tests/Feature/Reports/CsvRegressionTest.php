<?php

use App\Services\ReportService;
use App\Support\CsvWriter;

/**
 * Caracterizacion (T-37): la salida de `ReportService::csv()` byte a byte. Se escribio y paso en verde ANTES de extraer
 * `CsvWriter` y se conserva como regresion: cualquier cambio en separador, comillas, BOM, decimales o neutralizacion rompe esto.
 */
function regressionData(bool $comparison): array
{
    return [
        'brand' => ['id' => 1, 'name' => '=HYPERLINK("x";"y")'],
        'period' => ['from' => '2026-10-01', 'to' => '2026-10-02', 'days' => 2],
        'comparison' => $comparison ? ['from' => '2026-09-29', 'to' => '2026-09-30', 'days' => 2] : null,
        'cards' => [
            ['label' => 'Visualizaciones', 'total' => 1234, 'previous' => 1000, 'delta_pct' => 23.4, 'by_network' => [
                ['label' => 'Instagram', 'value' => 1234, 'previous' => 1000, 'delta_pct' => 23.4],
                ['label' => 'Bluesky', 'value' => null, 'previous' => null, 'delta_pct' => null],
            ]],
        ],
        'networks' => [[
            'label' => 'Instagram', 'engagement_rate' => 3.5,
            'metrics' => [['label' => 'Alcance', 'total' => 10.5, 'previous' => null, 'delta_pct' => -2.0, 'series' => [['date' => '2026-10-01', 'value' => 5], ['date' => '2026-10-02', 'value' => null]]]],
            'posts' => [
                ['text' => "+SUMA(1;1)\n\"cita\"; y -más", 'published_at' => '2026-10-01T10:00:00+00:00', 'metrics' => ['views' => 7, 'reach' => null, 'interactions' => 0], 'engagement_rate' => null],
                ['text' => null, 'published_at' => null, 'metrics' => [], 'engagement_rate' => 1.25],
            ],
        ]],
        'notes' => [['network' => 'Bluesky', 'metric' => 'views']],
    ];
}

it('CsvRegression: el CSV de informes con comparacion es identico byte a byte (locale es)', function () {
    config(['reports.csv_locale' => 'es']);
    $expected = "\xEF\xBB\xBF"
        .'"seccion";"red";"elemento";"metrica";"fecha";"valor";"anterior";"variacion_pct"'."\n"
        .'"informe";"";"\'=HYPERLINK(""x"";""y"")";"periodo";"2026-10-01 / 2026-10-02";"";"";""'."\n"
        .'"resumen";"todas";"";"Visualizaciones";"";1234;1000;23,4'."\n"
        .'"resumen";"Instagram";"";"Visualizaciones";"";1234;1000;23,4'."\n"
        .'"resumen";"Bluesky";"";"Visualizaciones";"";"no disponible";"no disponible";"no disponible"'."\n"
        .'"red";"Instagram";"";"Alcance";"";10,5;"no disponible";-2'."\n"
        .'"serie";"Instagram";"";"Alcance";"2026-10-01";5;"";""'."\n"
        .'"serie";"Instagram";"";"Alcance";"2026-10-02";"no disponible";"";""'."\n"
        .'"red";"Instagram";"";"Tasa de interaccion (%)";"";3,5;"";""'."\n"
        .'"publicacion";"Instagram";"\'+SUMA(1;1) ""cita""; y -más";"views";"2026-10-01";7;"";""'."\n"
        .'"publicacion";"Instagram";"\'+SUMA(1;1) ""cita""; y -más";"reach";"2026-10-01";"no disponible";"";""'."\n"
        .'"publicacion";"Instagram";"\'+SUMA(1;1) ""cita""; y -más";"interactions";"2026-10-01";0;"";""'."\n"
        .'"publicacion";"Instagram";"\'+SUMA(1;1) ""cita""; y -más";"tasa_interaccion_pct";"2026-10-01";"no disponible";"";""'."\n"
        .'"publicacion";"Instagram";"";"views";"";"no disponible";"";""'."\n"
        .'"publicacion";"Instagram";"";"reach";"";"no disponible";"";""'."\n"
        .'"publicacion";"Instagram";"";"interactions";"";"no disponible";"";""'."\n"
        .'"publicacion";"Instagram";"";"tasa_interaccion_pct";"";1,25;"";""'."\n"
        .'"nota";"Bluesky";"La API de la red no ofrece esta metrica";"views";"";"no disponible";"";""'."\n";

    expect(app(ReportService::class)->csv(regressionData(true)))->toBe($expected);
});

it('CsvRegression: sin comparacion las columnas anterior y variacion quedan vacias y con locale en usa , y .', function () {
    config(['reports.csv_locale' => 'en']);
    $csv = app(ReportService::class)->csv(regressionData(false));

    expect($csv)->toStartWith("\xEF\xBB\xBF\"seccion\",\"red\"")
        ->and($csv)->toContain('"resumen","todas","","Visualizaciones","",1234,"",""'."\n")
        ->and($csv)->toContain('"red","Instagram","","Alcance","",10.5,"",""'."\n")
        ->and($csv)->toContain('"red","Instagram","","Tasa de interaccion (%)","",3.5,"",""'."\n");
});

it('CsvWriter: el locale configurado elige separador y decimal', function () {
    config(['reports.csv_locale' => 'en']);
    expect(CsvWriter::forConfiguredLocale()->delimiter())->toBe(',');
    config(['reports.csv_locale' => 'es']);
    expect(CsvWriter::forConfiguredLocale()->delimiter())->toBe(';');
});
