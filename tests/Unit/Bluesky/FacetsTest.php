<?php

use App\Connectors\Bluesky\Facets;
use App\Services\MediaValidator;
use App\Support\Hashtags;

const FACET_DID = 'did:plc:mentionedaccount0123456';

function facetsOf(string $text, ?Closure $resolve = null): array
{
    return Facets::build($text, $resolve ?? fn (string $h) => $h === 'ana.example.com' ? FACET_DID : null);
}

/** El fragmento de `$text` que cubre un facet, leido por bytes (como lo haria el cliente de Bluesky). */
function sliceOf(string $text, array $facet): string
{
    return substr($text, $facet['index']['byteStart'], $facet['index']['byteEnd'] - $facet['index']['byteStart']);
}

test('un enlace lleva su rango en bytes y su uri', function () {
    $text = 'Mira https://example.com/pagina?x=1 ahora';
    $facets = facetsOf($text);

    expect($facets)->toHaveCount(1)
        ->and($facets[0]['features'][0])->toBe(['$type' => 'app.bsky.richtext.facet#link', 'uri' => 'https://example.com/pagina?x=1'])
        ->and(sliceOf($text, $facets[0]))->toBe('https://example.com/pagina?x=1');
});

test('la puntuacion final no entra en el enlace', function () {
    foreach (['Hola https://example.com/a.' => 'https://example.com/a', 'Ver (https://example.com/a)' => 'https://example.com/a', 'Ver https://example.com/a_(b)' => 'https://example.com/a_(b)', '¿https://example.com/a?' => 'https://example.com/a'] as $text => $expected) {
        $facets = facetsOf($text);
        expect($facets)->toHaveCount(1)->and(sliceOf($text, $facets[0]))->toBe($expected);
    }
});

test('los indices son bytes UTF-8: con emojis compuestos coinciden con strlen del prefijo', function () {
    $family = "\u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F467}\u{200D}\u{1F466}"; // 25 bytes
    $text = "Hola {$family} #verano y https://example.com/x";
    $facets = facetsOf($text);

    expect($facets)->toHaveCount(2)
        ->and($facets[0]['index']['byteStart'])->toBe(strlen("Hola {$family} "))
        ->and(sliceOf($text, $facets[0]))->toBe('#verano')
        ->and($facets[0]['features'][0])->toBe(['$type' => 'app.bsky.richtext.facet#tag', 'tag' => 'verano'])
        ->and($facets[1]['index']['byteStart'])->toBe(strlen("Hola {$family} #verano y "))
        ->and(sliceOf($text, $facets[1]))->toBe('https://example.com/x');
    // En caracteres seria otra cosa: la prueba de que no se usa mb_strlen.
    expect(mb_strlen("Hola {$family} "))->not->toBe(strlen("Hola {$family} "));
});

test('hashtags: acentos, sin # en el tag, sin numeros puros, y se descartan los de mas de 64 grafemas', function () {
    $long = str_repeat('a', 65);
    $ok = str_repeat('b', 64);
    $text = "#canción #2024 #{$long} #{$ok} #a_b";
    $tags = array_map(fn ($f) => $f['features'][0]['tag'], facetsOf($text));

    expect($tags)->toBe(['canción', $ok, 'a_b']);
});

test('un tag de 64 grafemas con emojis compuestos cuenta grafemas, no bytes', function () {
    // Los emojis no son \p{L}: el tag se corta; este caso comprueba que letras acentuadas (2 bytes) no se pasan de 64.
    $tag = str_repeat('é', 64); // 64 grafemas, 128 bytes
    $facets = facetsOf("#{$tag}");

    expect($facets)->toHaveCount(1)->and($facets[0]['features'][0]['tag'])->toBe($tag);
});

test('un # dentro de una URL o pegado a una palabra no es un hashtag', function () {
    $facets = facetsOf('https://example.com/#seccion y a#b');

    expect($facets)->toHaveCount(1)->and($facets[0]['features'][0]['$type'])->toBe('app.bsky.richtext.facet#link');
});

test('una mencion resoluble lleva el DID y una no resoluble queda como texto plano', function () {
    $text = 'Hola @ana.example.com y @nadie.example.com';
    $facets = facetsOf($text);

    expect($facets)->toHaveCount(1)
        ->and($facets[0]['features'][0])->toBe(['$type' => 'app.bsky.richtext.facet#mention', 'did' => FACET_DID])
        ->and(sliceOf($text, $facets[0]))->toBe('@ana.example.com');
});

test('los emails y las menciones dentro de una URL no son menciones', function () {
    $resolved = [];
    $facets = Facets::build('escribe a ana@example.com o mira https://medium.com/@ana.example.com', function (string $h) use (&$resolved) {
        $resolved[] = $h;

        return FACET_DID;
    });

    expect($resolved)->toBe([])->and($facets)->toHaveCount(1)->and($facets[0]['features'][0]['$type'])->toBe('app.bsky.richtext.facet#link');
});

test('cada mencion repetida se resuelve una sola vez', function () {
    $calls = 0;
    Facets::build('@ana.example.com y otra vez @ana.example.com', function () use (&$calls) {
        $calls++;

        return FACET_DID;
    });

    expect($calls)->toBe(1);
});

test('los facets salen ordenados por posicion y sin solapes', function () {
    $facets = facetsOf('#uno https://example.com/a @ana.example.com #dos');
    $starts = array_map(fn ($f) => $f['index']['byteStart'], $facets);

    expect($facets)->toHaveCount(4)->and($starts)->toBe(collect($starts)->sort()->values()->all());
});

test('un texto sin enlaces ni etiquetas no produce facets', function () {
    expect(facetsOf('Solo texto, sin nada especial.'))->toBe([])->and(facetsOf(''))->toBe([]);
});

test('B-7: los hashtags con marcas combinantes (NFD e indicos) no se cortan', function () {
    $nfd = "Hola #cafe\u{0301} y #ni\u{0303}o";
    $hindi = "Mira #\u{092D}\u{093E}\u{0930}\u{0924} ahora";

    foreach ([$nfd, $hindi] as $text) {
        $tags = array_values(array_filter(facetsOf($text), fn ($f) => $f['features'][0]['$type'] === 'app.bsky.richtext.facet#tag'));
        expect(array_map(fn ($f) => sliceOf($text, $f), $tags))->toBe($text === $nfd ? ["#cafe\u{0301}", "#ni\u{0303}o"] : ["#\u{092D}\u{093E}\u{0930}\u{0924}"]);
    }

    expect(Hashtags::find($hindi)[0]['tag'])->toBe("\u{092D}\u{093E}\u{0930}\u{0924}")
        ->and(MediaValidator::countHashtags($nfd))->toBe(2)
        ->and(MediaValidator::countHashtags($hindi))->toBe(1)
        // Pegado a una letra con marca (no es un hashtag), como con una letra sin ella.
        ->and(MediaValidator::countHashtags("cafe\u{0301}#x"))->toBe(0);
});
