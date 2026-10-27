<?php

use App\Support\CsvWriter;

it('CsvWriter: neutraliza =, +, -, @, TAB y CR iniciales y los tramos tras separador (CWE-1236)', function () {
    expect(CsvWriter::cell('=cmd'))->toBe("'=cmd")
        ->and(CsvWriter::cell('+1'))->toBe("'+1")
        ->and(CsvWriter::cell('-1'))->toBe("'-1")
        ->and(CsvWriter::cell('@x'))->toBe("'@x")
        ->and(CsvWriter::cell("\t=x"))->toBe("'\t'=x")
        ->and(CsvWriter::cell("\r=x"))->toBe("'\r'=x")
        ->and(CsvWriter::cell('a;=b'))->toBe("a;'=b")
        ->and(CsvWriter::cell('normal = 5'))->toBe('normal = 5')
        ->and(CsvWriter::cell(-5))->toBe(-5)->and(CsvWriter::cell(1.5))->toBe(1.5);
});

it('CsvWriter: texto siempre entre comillas, numeros sin ellas, null vacio y saltos de linea aplanados', function () {
    $w = new CsvWriter(';', ',');

    expect($w->line(['a "b"', 12, 1.5, null, "x\ny", '=1']))->toBe("\"a \"\"b\"\"\";12;1,5;;\"x y\";\"'=1\"\n")
        ->and((new CsvWriter(',', '.'))->line([1.5, 'a']))->toBe("1.5,\"a\"\n")
        ->and($w->field(0.0))->toBe('0')->and($w->field(-0.00001))->toBe('0');
});

it('CsvWriter: el BOM es el de UTF-8 para Excel', function () {
    expect(CsvWriter::BOM)->toBe("\xEF\xBB\xBF");
});
