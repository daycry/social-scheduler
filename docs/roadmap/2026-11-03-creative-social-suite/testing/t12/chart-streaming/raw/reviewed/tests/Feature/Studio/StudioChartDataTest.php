<?php

use App\Services\Studio\StudioChartData;

function chartTable(array $values = [' 001 ', '=SUM(A1)', null, 2.5]): array
{
    return ['columns' => array_map(fn ($i) => ['id' => 'c_'.$i, 'label' => '🎵 '.$i], range(1, count($values))),
        'rows' => [['id' => 'r_1', 'values' => $values]]];
}

test('normalization preserves typed Unicode data and does not mutate input', function () {
    $table = chartTable();
    $before = serialize($table);
    expect((new StudioChartData)->normalize($table))->toBe($table)
        ->and(serialize($table))->toBe($before);
});

test('normalization rejects malformed schema and bounded hostile values', function (string $case) {
    $table = chartTable(['ok']);
    switch ($case) {
        case 'unknown table': $table['extra'] = true;
            break;
        case 'unknown column': $table['columns'][0]['extra'] = true;
            break;
        case 'unknown row': $table['rows'][0]['extra'] = true;
            break;
        case 'missing rows': unset($table['rows']);
            break;
        case 'not list': $table['columns'] = ['x' => $table['columns'][0]];
            break;
        case 'empty columns': $table['columns'] = [];
            break;
        case 'too many columns': $table['columns'] = array_fill(0, 101, $table['columns'][0]);
            break;
        case 'too many rows': $table['rows'] = array_fill(0, 1001, $table['rows'][0]);
            break;
        case 'duplicate column': $table['columns'][] = $table['columns'][0];
            break;
        case 'duplicate row': $table['rows'][] = $table['rows'][0];
            break;
        case 'bad id': $table['columns'][0]['id'] = 'é';
            break;
        case 'long id': $table['rows'][0]['id'] = str_repeat('a', 65);
            break;
        case 'long label': $table['columns'][0]['label'] = str_repeat('🎵', 501);
            break;
        case 'bad label type': $table['columns'][0]['label'] = 1;
            break;
        case 'long cell': $table['rows'][0]['values'][0] = str_repeat('🎵', 10001);
            break;
        case 'invalid utf8': $table['rows'][0]['values'][0] = "\xFF";
            break;
        case 'control': $table['rows'][0]['values'][0] = "bad\rvalue";
            break;
        case 'unicode control': $table['rows'][0]['values'][0] = "bad\u{0085}value";
            break;
        case 'bool': $table['rows'][0]['values'][0] = true;
            break;
        case 'array': $table['rows'][0]['values'][0] = [];
            break;
        case 'infinity': $table['rows'][0]['values'][0] = INF;
            break;
        case 'nan': $table['rows'][0]['values'][0] = NAN;
            break;
        case 'width': $table['rows'][0]['values'] = [];
            break;
        case 'values not list': $table['rows'][0]['values'] = ['x' => 'ok'];
            break;
        case 'json bytes': $table['rows'] = array_map(fn ($i) => ['id' => 'r_'.$i, 'values' => [str_repeat('🎵', 10000)]], range(1, 53));
            break;
    }
    expect(fn () => (new StudioChartData)->normalize($table))->toThrow(InvalidArgumentException::class);
})->with(['unknown table', 'unknown column', 'unknown row', 'missing rows', 'not list', 'empty columns', 'too many columns', 'too many rows', 'duplicate column', 'duplicate row', 'bad id', 'long id', 'long label', 'bad label type', 'long cell', 'invalid utf8', 'control', 'unicode control', 'bool', 'array', 'infinity', 'nan', 'width', 'values not list', 'json bytes']);

test('normalization accepts exact Unicode and collection boundaries', function () {
    $table = ['columns' => array_map(fn ($i) => ['id' => 'c_'.$i, 'label' => str_repeat('🎵', 500)], range(1, 100)), 'rows' => []];
    expect((new StudioChartData)->normalize($table))->toBe($table);
    $table = chartTable([str_repeat('🎵', 10000), "\n\t", -1, 0, null]);
    expect((new StudioChartData)->normalize($table))->toBe($table);
    $table = chartTable(['']);
    $table['rows'] = array_map(fn ($i) => ['id' => 'r_'.$i, 'values' => ['']], range(1, 1000));
    expect((new StudioChartData)->normalize($table))->toBe($table);
});

test('import preserves quoted Unicode text and deterministic independent identities', function () {
    $codec = new StudioChartData;
    $table = $codec->import("\xEF\xBB\xBF,name,name\r\n\"001\",\"a,b\",\"say \"\"hi\"\"\n🎵\"\r\n 2 ,=SUM(A1),<b>x</b>\r\n");
    expect($table)->toBe([
        'columns' => [['id' => 'column_1', 'label' => ''], ['id' => 'column_2', 'label' => 'name'], ['id' => 'column_3', 'label' => 'name']],
        'rows' => [['id' => 'row_1', 'values' => ['001', 'a,b', "say \"hi\"\n🎵"]], ['id' => 'row_2', 'values' => [' 2 ', '=SUM(A1)', '<b>x</b>']]],
    ]);
    expect($codec->import("\"a\tb\"\t\"\"\n", 'tsv', false))->toBe([
        'columns' => [['id' => 'column_1', 'label' => ''], ['id' => 'column_2', 'label' => '']],
        'rows' => [['id' => 'row_1', 'values' => ["a\tb", '']]],
    ]);
    expect($codec->import('header'))->toBe(['columns' => [['id' => 'column_1', 'label' => 'header']], 'rows' => []]);
    expect($codec->import("\n"))->toBe(['columns' => [['id' => 'column_1', 'label' => '']], 'rows' => []]);
});

test('import canonicalizes Windows multiline CRLF while preserving surrounding text', function () {
    expect((new StudioChartData)->import("h\r\n\" a\r\nb \"\r\n"))->toBe([
        'columns' => [['id' => 'column_1', 'label' => 'h']],
        'rows' => [['id' => 'row_1', 'values' => [" a\nb "]]],
    ]);
});

test('import rejects malformed delimiters with exact record and field location', function (string $text, string $message) {
    expect(fn () => (new StudioChartData)->import($text))->toThrow(InvalidArgumentException::class, $message);
})->with([
    ["h\n\"unclosed", 'row 2, column 1'],
    ["h\n\"a\"x", 'row 2, column 1'],
    ["h\na\"b", 'row 2, column 1'],
    ["a,b\nx", 'row 2'],
    ["a\rvalue", 'row 1, column 1'],
]);

test('import rejects empty unsupported invalid and oversized input', function (string $case) {
    $codec = new StudioChartData;
    expect(fn () => match ($case) {
        'empty' => $codec->import(''),
        'bom only' => $codec->import("\xEF\xBB\xBF"),
        'format' => $codec->import('a', 'xlsx'),
        'utf8' => $codec->import("\xFF"),
        'bytes' => $codec->import(str_repeat('a', 2097153)),
        'columns' => $codec->import(implode(',', array_fill(0, 101, 'a'))),
        'rows' => $codec->import("h\n".str_repeat("x\n", 1001)),
        'cell' => $codec->import("h\n".str_repeat('a', 10001)),
        'label' => $codec->import(str_repeat('a', 501)),
        'control' => $codec->import("h\n\"x\ry\""),
    })->toThrow(InvalidArgumentException::class);
})->with(['empty', 'bom only', 'format', 'utf8', 'bytes', 'columns', 'rows', 'cell', 'label', 'control']);

test('export writes faithful escaped CRLF text without evaluating spreadsheet expressions', function () {
    $table = chartTable(['001', '=SUM(A1)', '+cmd', '-1', '@x', "a,b\"\n🎵", null, 1.5, 1.0]);
    $table['columns'] = array_map(fn ($i) => ['id' => 'c_'.$i, 'label' => ''], range(1, 9));
    $before = serialize($table);
    expect((new StudioChartData)->export($table))->toBe(",,,,,,,,\r\n001,=SUM(A1),+cmd,-1,@x,\"a,b\"\"\n🎵\",,1.5,1.0\r\n")
        ->and(serialize($table))->toBe($before);
    expect((new StudioChartData)->export(chartTable(["a\tb", ' x ']), 'tsv', false))->toBe("\"a\tb\"\t x \r\n");
});

test('export validates table and format before encoding', function () {
    $codec = new StudioChartData;
    expect(fn () => $codec->export(['columns' => [], 'rows' => []]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $codec->export(chartTable(), 'CSV'))->toThrow(InvalidArgumentException::class);
});

test('text tables roundtrip both formats headers and empty records without inventing rows', function () {
    $codec = new StudioChartData;
    foreach (['csv', 'tsv'] as $format) {
        foreach ([true, false] as $header) {
            $table = $codec->import("\"\"\n\"\"\n", $format, $header);
            expect($codec->import($codec->export($table, $format, $header), $format, $header))->toBe($table);
        }
        $table = $codec->import("\"🎵\"\r\n\" 001\r\n=SUM(A1) \"\r\n", $format);
        expect($codec->import($codec->export($table, $format), $format))->toBe($table);
        $table = ['columns' => [['id' => 'column_1', 'label' => '']], 'rows' => []];
        expect($codec->import($codec->export($table, $format), $format))->toBe($table);
    }
});

test('export lexical interchange explicitly loses numeric null types and identities on reimport', function () {
    $codec = new StudioChartData;
    $table = chartTable([null, 1, 1.0, '001']);
    $imported = $codec->import($codec->export($table));
    expect($imported['rows'][0])->toBe(['id' => 'row_1', 'values' => ['', '1', '1.0', '001']]);
});

test('export protects a literal leading BOM from being mistaken for an input signature', function () {
    $codec = new StudioChartData;
    foreach ([true, false] as $header) {
        $table = ['columns' => [['id' => 'column_1', 'label' => "\u{FEFF}title"]],
            'rows' => [['id' => 'row_1', 'values' => ["\u{FEFF}text"]]]];
        $expected = $table;
        if (! $header) {
            $expected['columns'][0]['label'] = '';
        }
        expect($codec->import($codec->export($table, 'csv', $header), 'csv', $header))->toBe($expected);
    }
});

test('normalization checks the exact encoded byte boundary independently of codepoint limits', function () {
    $table = chartTable(['']);
    $table['rows'] = array_map(fn ($i) => ['id' => 'r_'.$i, 'values' => [$i < 210 ? str_repeat('a', 10000) : '']], range(1, 210));
    $bytes = strlen(json_encode($table, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
    $table['rows'][209]['values'][0] = str_repeat('a', 2097152 - $bytes);
    expect((new StudioChartData)->normalize($table))->toBe($table);
    $table['rows'][209]['values'][0] .= 'a';
    expect(fn () => (new StudioChartData)->normalize($table))->toThrow(InvalidArgumentException::class, '2 MiB');
});

test('import validation identifies the CSV record including headers and multiline fields', function (bool $header, string $invalid) {
    $prefix = $header ? "h\n" : '';
    $input = $prefix."\"valid\nmultiline\"\n\"".$invalid.'"';
    $record = $header ? 3 : 2;
    expect(fn () => (new StudioChartData)->import($input, 'csv', $header))
        ->toThrow(InvalidArgumentException::class, 'row '.$record.', column 1');
})->with([true, false])->with(["bad\0value", str_repeat('x', 10001)]);

test('import validation identifies invalid header cells as CSV record one', function (string $label) {
    expect(fn () => (new StudioChartData)->import('valid,"'.$label."\"\nvalue,other"))
        ->toThrow(InvalidArgumentException::class, 'row 1, column 2');
})->with(["bad\0label", str_repeat('x', 501)]);

test('normalization errors continue to identify data rows independently of CSV headers', function () {
    $table = chartTable(['valid']);
    $table['rows'][] = ['id' => 'r_2', 'values' => ["bad\0value"]];
    expect(fn () => (new StudioChartData)->normalize($table))
        ->toThrow(InvalidArgumentException::class, 'row 2, column 1');
});

test('stream boundaries preserve BOM Unicode quotes CRLF and literal BOM', function () {
    $codec = new StudioChartData;
    foreach (['csv' => ',', 'tsv' => "\t"] as $format => $d) {
        $input = "\xEF\xBB\xBFh{$d}h\r\n\" a\r\n?? \"\"x\"\"\"{$d}\"\u{FEFF}text\"\r\n";
        foreach ([1, 2, 3] as $n) {
            expect($codec->importChunks(str_split($input, $n), $format))->toBe($codec->import($input, $format));
        }
    }
});

test('server dataset profile accepts over inline budget without changing default', function () {
    $input = "h\n".str_repeat(str_repeat('x', 5000)."\n", 420);
    $table = (new StudioChartData('dataset'))->import($input);
    expect(count($table['rows']))->toBe(420)->and($table['rows'][419]['values'][0])->toBe(str_repeat('x', 5000));
    expect(fn () => (new StudioChartData)->import($input))->toThrow(InvalidArgumentException::class, '2 MiB');
});

test('unknown server profile fails closed', function () {
    expect(fn () => new StudioChartData('arbitrary'))->toThrow(InvalidArgumentException::class, 'profile');
});

test('JSON budget stops before traversing a virtual huge tail', function () {
    $table = ['columns' => array_map(fn ($i) => ['id' => 'c'.$i, 'label' => ''], range(0, 99)), 'rows' => []];
    $shared = array_fill(0, 100, str_repeat('x', 10000));
    for ($i = 0; $i < 1000; $i++) {
        $table['rows'][] = ['id' => 'r'.$i, 'values' => $shared];
    }
    $table['rows'][999]['values'][0] = false;
    expect(fn () => (new StudioChartData)->normalize($table))->toThrow(InvalidArgumentException::class, 'Canonical table exceeds 2 MiB');
});

test('chunks validate full fields and retain record diagnostics at every split', function () {
    $codec = new StudioChartData;
    foreach ([1, 2, 3] as $n) {
        foreach (["h\r\nx\r", "h\n\"x\"z", "h\n\"x", "h\n\xF0\x9F", "h\n\"x\0\""] as $input) {
            expect(fn () => $codec->importChunks(str_split($input, $n)))->toThrow(InvalidArgumentException::class, 'row 2, column 1');
        }
        $input = "h\r\n\"\u{1F3B5}\r\n \"\"x\"\"\"\r\n";
        expect($codec->importChunks(str_split($input, $n))['rows'][0]['values'][0])->toBe("\u{1F3B5}\n \"x\"");
    }
    foreach ([[], ['', ''], ["\xEF", '', "\xBB", "\xBF"]] as $chunks) {
        expect(fn () => $codec->importChunks($chunks))->toThrow(InvalidArgumentException::class, 'Input is empty');
    }
    expect(fn () => $codec->importChunks(['h', null]))->toThrow(InvalidArgumentException::class, 'chunks must be strings');
});

test('input chunk budget rejects before reading chunk contents or resuming producer', function () {
    $resumed = false;
    $chunks = (function () use (&$resumed) {
        yield str_repeat('x', 2097153);
        $resumed = true;
        throw new RuntimeException('producer resumed');
    })();
    expect(fn () => (new StudioChartData)->importChunks($chunks))->toThrow(InvalidArgumentException::class, '2 MiB');
    expect($resumed)->toBeFalse();
});

test('incremental JSON accounting equals native flags for escaped text numbers and key order', function () {
    $table = ['rows' => [], 'columns' => [['label' => "\u{1F3B5} / \\ \" \t\n\u{2028}", 'id' => 'c']]];
    $values = [null, 0, 1.0, -1.2e30, "\u{1F3B5}/\\\t\n\u{2028}"];
    for ($i = 0; $i < 212; $i++) {
        $table['rows'][] = ['values' => [$i < 209 ? str_repeat('a', 10000) : ''], 'id' => 'r'.$i];
    }
    foreach ($values as $value) {
        $table['rows'][0]['values'][0] = $value;
        $table['rows'][209]['values'][0] = '';
        $length = strlen(json_encode($table, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));
        // Fill the gap using multiple already bounded cells.
        $remaining = 2097152 - $length;
        foreach ([209, 210, 211] as $index) {
            $add = min(10000 - strlen($table['rows'][$index]['values'][0]), $remaining);
            $table['rows'][$index]['values'][0] .= str_repeat('x', $add);
            $remaining -= $add;
        }
        expect($remaining)->toBe(0)->and((new StudioChartData)->normalize($table))->toBe($table);
        $table['rows'][211]['values'][0] .= 'x';
        expect(fn () => (new StudioChartData)->normalize($table))->toThrow(InvalidArgumentException::class, '2 MiB');
        $table['rows'][211]['values'][0] = substr($table['rows'][211]['values'][0], 0, -1);
        foreach ([209, 210, 211] as $index) {
            $table['rows'][$index]['values'][0] = '';
        }
    }
});

test('export chunks yields complete lexical records matching string wrapper', function () {
    $codec = new StudioChartData;
    $table = $codec->import("h\r\n\"\u{FEFF}x\"\r\n\"a\"\"b\"\r\n");
    foreach (['csv', 'tsv'] as $format) {
        foreach ([true, false] as $header) {
            $chunks = iterator_to_array($codec->exportChunks($table, $format, $header));
            expect(count($chunks))->toBe(2 + (int) $header)->and(implode('', $chunks))->toBe($codec->export($table, $format, $header));
        }
    }
});

function dataset25MB(): Generator
{
    for ($r = 0; $r < 1000; $r++) {
        yield implode(',', array_fill(0, 100, str_repeat('\\', $r < 990 ? 249 : 248)))."\r\n";
    }
}

test('dataset exact 25 MB worst escaping roundtrips without truncation within 256M', function () {
    $inputHash = hash_init('sha256');
    $bytes = 0;
    $source = (function () use ($inputHash, &$bytes) {
        foreach (dataset25MB() as $chunk) {
            $bytes += strlen($chunk);
            hash_update($inputHash, $chunk);
            yield $chunk;
        }
    })();
    $codec = new StudioChartData('dataset');
    $table = $codec->importChunks($source, 'csv', false);
    expect($bytes)->toBe(25000000)->and(count($table['columns']))->toBe(100)->and(count($table['rows']))->toBe(1000)
        ->and($table['rows'][0]['values'][0])->toBe(str_repeat('\\', 249))
        ->and($table['rows'][999]['values'][99])->toBe(str_repeat('\\', 248));
    $outputHash = hash_init('sha256');
    $outputBytes = 0;
    foreach ($codec->exportChunks($table, 'csv', false) as $chunk) {
        $outputBytes += strlen($chunk);
        hash_update($outputHash, $chunk);
    }
    expect($outputBytes)->toBe(25000000)->and(hash_final($outputHash))->toBe(hash_final($inputHash));
    unset($table);
    $over = (function () {
        yield from dataset25MB();
        yield 'x';
    })();
    expect(fn () => $codec->importChunks($over, 'csv', false))->toThrow(InvalidArgumentException::class, '25000000 bytes');
    fwrite(STDOUT, json_encode(['peak_php_bytes' => memory_get_peak_usage(true), 'memory_limit' => ini_get('memory_limit')]).PHP_EOL);
});

test('dataset canonical JSON exact 52 million bytes accepts and plus one rejects', function () {
    $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR;
    $table = ['columns' => array_map(fn ($i) => ['id' => 'c'.$i, 'label' => ''], range(0, 99)), 'rows' => []];
    $shared = array_fill(0, 100, str_repeat('\\', 258));
    $bytes = strlen(json_encode(['columns' => $table['columns'], 'rows' => []], $flags));
    for ($r = 0; $r < 1000; $r++) {
        $row = ['id' => 'r'.$r, 'values' => $shared];
        $bytes += (int) ($r > 0) + strlen(json_encode($row, $flags));
        $table['rows'][] = $row;
    }
    $gap = 52000000 - $bytes;
    for ($r = 0; $r < 1000 && $gap > 0; $r++) {
        for ($c = 0; $c < 100 && $gap > 0; $c++) {
            $add = min(2, $gap);
            $table['rows'][$r]['values'][$c] .= $add === 2 ? '\\' : 'x';
            $gap -= $add;
        }
    }
    expect($gap)->toBe(0)->and((new StudioChartData('dataset'))->normalize($table))->toBe($table);
    $table['rows'][999]['values'][99] .= 'x';
    expect(fn () => (new StudioChartData('dataset'))->normalize($table))->toThrow(InvalidArgumentException::class, '52000000 bytes');
});
