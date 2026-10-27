<?php

use App\Support\TextLength;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

function textLengthCases(): array
{
    return json_decode((string) file_get_contents(dirname(__DIR__).'/Fixtures/text-length-cases.json'), true);
}

test('TextLength cuenta chars, grafemas y la regla de Threads segun la tabla de casos', function () {
    foreach (textLengthCases() as $case) {
        expect(TextLength::count($case['text'], 'chars'))->toBe($case['chars'], $case['name'])
            ->and(TextLength::count($case['text'], 'graphemes'))->toBe($case['graphemes'], $case['name'])
            ->and(TextLength::count($case['text'], 'threads'))->toBe($case['threads'], $case['name']);
    }
});

test('un modo desconocido o ausente cuenta como chars', function () {
    $family = "\u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F467}\u{200D}\u{1F466}";

    expect(TextLength::count($family))->toBe(7)->and(TextLength::count($family, 'otro'))->toBe(7)->and(TextLength::count(null))->toBe(0);
});

test('el espejo JavaScript (textLength.js) da lo mismo que PHP en toda la tabla', function () {
    $node = (new ExecutableFinder)->find('node');
    if ($node === null) {
        $this->markTestSkipped('node no esta disponible');
    }

    $script = "import('./resources/js/Composables/textLength.js').then(m => {"
        .'const cases = JSON.parse(require("fs").readFileSync("tests/Fixtures/text-length-cases.json", "utf8"));'
        .'console.log(JSON.stringify(cases.map(c => ({ name: c.name, chars: m.countText(c.text, "chars"), graphemes: m.countText(c.text, "graphemes"), threads: m.countText(c.text, "threads") }))));});';
    $process = new Process([$node, '-e', $script], dirname(__DIR__, 2));
    $process->mustRun();
    $js = json_decode(trim($process->getOutput()), true);

    foreach (textLengthCases() as $i => $case) {
        expect($js[$i])->toBe(['name' => $case['name'], 'chars' => $case['chars'], 'graphemes' => $case['graphemes'], 'threads' => $case['threads']]);
    }
});

test('Threads cuenta de forma conservadora los grafemas con emoji y rechaza 250 banderas', function () {
    $flag = "\u{1F1EA}\u{1F1F8}";

    expect(TextLength::count($flag, 'threads'))->toBe(8)
        ->and(TextLength::count("\u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F467}\u{200D}\u{1F466}", 'threads'))->toBe(25)
        ->and(TextLength::count("\u{1F44D}\u{1F3FD}", 'threads'))->toBe(8)
        ->and(TextLength::count("\u{2764}\u{FE0F}", 'threads'))->toBe(6)
        ->and(TextLength::count("\u{1F3F3}\u{FE0F}\u{200D}\u{1F308}", 'threads'))->toBe(14)
        ->and(TextLength::count('漢字', 'threads'))->toBe(2)
        ->and(TextLength::count(str_repeat($flag, 250), 'threads'))->toBeGreaterThan(500);
});
