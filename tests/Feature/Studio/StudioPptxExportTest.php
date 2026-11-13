<?php

use App\Models\Brand;
use App\Models\MediaAsset;
use App\Models\User;
use App\Services\Studio\StudioExports;
use App\Services\Studio\StudioPptxExporter;
use App\Services\Studio\StudioRenderer;
use App\Services\Studio\StudioService;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

// T-35: exportacion PPTX (una diapositiva por pagina con la imagen renderizada). El fichero se abre con un lector ZIP/OOXML propio del test.

beforeEach(function () {
    Queue::fake();
    Storage::fake('local');
    $this->user = User::factory()->create();
    $this->brand = Brand::factory()->for($this->user)->create();
    $this->document = ['schema_version' => 1, 'width' => 120, 'height' => 80, 'pages' => [
        ['id' => 'page_one', 'name' => 'Uno', 'background' => '#ffffff', 'duration' => 5, 'elements' => []],
        ['id' => 'page_two', 'name' => 'Dos', 'background' => '#000000', 'duration' => 5, 'elements' => []],
    ]];
    $this->design = app(StudioService::class)->create($this->user, $this->brand, ['name' => 'Presentacion & Co', 'document' => $this->document]);
});

/** Lector ZIP independiente de ext-zip: valida firmas, CRC y tamaños y devuelve nombre => contenido. */
function pptxEntries(string $bytes): array
{
    $end = strrpos($bytes, "PK\x05\x06");
    expect($end)->not->toBeFalse();
    $eocd = unpack('Vsig/vdisk/vcd_disk/ventries_disk/ventries/Vsize/Voffset/vcomment', substr($bytes, $end, 22));
    $entries = [];
    $position = $eocd['offset'];
    for ($i = 0; $i < $eocd['entries']; $i++) {
        $central = unpack('Vsig/vmade/vneeded/vflags/vmethod/vtime/vdate/Vcrc/Vcsize/Vsize/vnlen/vxlen/vclen/vdisk/vintattr/Vextattr/Voffset', substr($bytes, $position, 46));
        expect($central['sig'])->toBe(0x02014B50);
        $name = substr($bytes, $position + 46, $central['nlen']);
        $local = unpack('Vsig/vneeded/vflags/vmethod/vtime/vdate/Vcrc/Vcsize/Vsize/vnlen/vxlen', substr($bytes, $central['offset'], 30));
        expect($local['sig'])->toBe(0x04034B50);
        $data = substr($bytes, $central['offset'] + 30 + $local['nlen'] + $local['xlen'], $central['csize']);
        $content = $central['method'] === 8 ? gzinflate($data) : $data;
        expect(strlen($content))->toBe($central['size'])->and(crc32($content))->toBe($central['crc']);
        $entries[$name] = $content;
        $position += 46 + $central['nlen'] + $central['xlen'] + $central['clen'];
    }

    return $entries;
}

function pptxPng(int $w, int $h, int $gray): string
{
    $image = imagecreatetruecolor($w, $h);
    imagefill($image, 0, 0, imagecolorallocate($image, $gray, $gray, $gray));
    ob_start();
    imagepng($image);
    imagedestroy($image);

    return (string) ob_get_clean();
}

test('PPTX: el exportador genera un paquete OOXML valido con una diapositiva por imagen', function () {
    $dir = Storage::disk('local')->path('pptx-test');
    Storage::disk('local')->makeDirectory('pptx-test');
    file_put_contents($dir.'/a.png', pptxPng(120, 80, 255));
    file_put_contents($dir.'/b.png', pptxPng(120, 80, 0));

    app(StudioPptxExporter::class)->build($dir.'/out.pptx', [['path' => $dir.'/a.png', 'name' => 'Uno'], ['path' => $dir.'/b.png', 'name' => 'Dos & <tres>']], 1200, 800, 'Presentacion & Co');
    $entries = pptxEntries(file_get_contents($dir.'/out.pptx'));

    expect(array_key_first($entries))->toBe('[Content_Types].xml')
        ->and(array_keys($entries))->toContain('_rels/.rels', 'ppt/presentation.xml', 'ppt/_rels/presentation.xml.rels', 'ppt/slideMasters/slideMaster1.xml', 'ppt/slideLayouts/slideLayout1.xml', 'ppt/theme/theme1.xml', 'ppt/slides/slide1.xml', 'ppt/slides/slide2.xml', 'ppt/media/image1.png', 'ppt/media/image2.png', 'docProps/core.xml');
    // Todo XML esta bien formado y cada parte referenciada existe.
    foreach ($entries as $name => $content) {
        if (str_ends_with($name, '.xml') || str_ends_with($name, '.rels')) {
            $xml = new DOMDocument;
            expect($xml->loadXML($content, LIBXML_NONET))->toBeTrue($name);
        }
    }
    $types = simplexml_load_string($entries['[Content_Types].xml']);
    $overrides = array_map(fn ($o) => (string) $o['PartName'], iterator_to_array($types->Override, false));
    expect($overrides)->toContain('/ppt/presentation.xml', '/ppt/slides/slide1.xml', '/ppt/slides/slide2.xml');
    expect((string) $types->Default[2]['Extension'])->toBe('png');
    $presentation = simplexml_load_string($entries['ppt/presentation.xml']);
    $size = $presentation->children('p', true)->sldSz->attributes();
    expect((int) $size['cx'])->toBe(1200 * 9525)->and((int) $size['cy'])->toBe(800 * 9525);
    $rels = simplexml_load_string($entries['ppt/_rels/presentation.xml.rels']);
    $targets = array_map(fn ($r) => (string) $r['Target'], iterator_to_array($rels->Relationship, false));
    expect($targets)->toContain('slides/slide1.xml', 'slides/slide2.xml', 'slideMasters/slideMaster1.xml', 'theme/theme1.xml');
    expect($entries['ppt/slides/_rels/slide1.xml.rels'])->toContain('../media/image1.png')->and($entries['ppt/media/image1.png'])->toBe(file_get_contents($dir.'/a.png'));
    // El nombre de la diapositiva se escapa como XML y el titulo va en las propiedades del documento.
    expect($entries['ppt/slides/slide2.xml'])->toContain('Dos &amp; &lt;tres&gt;')->and($entries['docProps/core.xml'])->toContain('Presentacion &amp; Co');
    // Si ext-zip esta disponible, tambien lo abre.
    if (class_exists(ZipArchive::class)) {
        $zip = new ZipArchive;
        expect($zip->open($dir.'/out.pptx', ZipArchive::CHECKCONS))->toBeTrue()->and($zip->locateName('[Content_Types].xml'))->toBe(0);
        $zip->close();
    }
});

test('PPTX: una exportacion pptx se renderiza como PNG, se empaqueta, no crea media y se descarga', function () {
    $export = app(StudioRenderer::class)->export($this->user, $this->design, ['revision' => 1, 'format' => 'pptx', 'pages' => ['page_one', 'page_two']]);
    expect($export->format)->toBe('pptx')->and($export->status)->toBe('pending');
    Process::fake(function ($process) {
        expect(json_decode($process->input, true)['format'])->toBe('png');
        foreach (['001' => 255, '002' => 0] as $n => $gray) {
            file_put_contents($process->command[2].DIRECTORY_SEPARATOR."page-$n.png", pptxPng(120, 80, $gray));
        }

        return Process::result(output: '{"files":[{"name":"page-001.png","mime":"image/png"},{"name":"page-002.png","mime":"image/png"}]}');
    });

    app(StudioRenderer::class)->render($export->id);

    $export->refresh();
    expect($export->status)->toBe('ready', $export->error ?? '')->and($export->media_ids)->toBe([])->and($export->path)->toEndWith('/design.pptx');
    expect(MediaAsset::withoutUserScope()->count())->toBe(0);
    $entries = pptxEntries(Storage::disk('local')->get($export->path));
    expect($entries)->toHaveKeys(['ppt/slides/slide1.xml', 'ppt/slides/slide2.xml', 'ppt/media/image2.png']);
    // Solo queda el PPTX: las paginas PNG intermedias se borran.
    expect(Storage::disk('local')->files(dirname($export->path)))->toBe([$export->path]);
    expect(app(StudioExports::class)->present($this->user, $export)['download_url'])->not->toBeNull();
    // «Crear borrador» solo existe para imagenes y video.
    expect(fn () => app(StudioExports::class)->createPost($this->user, $export))->toThrow(ValidationException::class);
});

test('PPTX: un render que falla no deja un PPTX descargable y el limite de tamano se respeta', function () {
    $export = app(StudioRenderer::class)->export($this->user, $this->design, ['revision' => 1, 'format' => 'pptx', 'pages' => ['page_one']]);
    Process::fake(['*' => Process::result(exitCode: 1)]);
    app(StudioRenderer::class)->render($export->id);
    expect($export->refresh()->status)->toBe('failed')->and(app(StudioExports::class)->present($this->user, $export)['download_url'])->toBeNull();

    expect(fn () => app(StudioRenderer::class)->export($this->user, $this->design, ['revision' => 1, 'format' => 'pptx', 'pages' => ['page_one'], 'scale' => 4000]))->toThrow(ValidationException::class);
});
