<?php

use App\Models\Brand;
use App\Models\Studio\StudioFont;
use App\Models\User;
use App\Services\Studio\StudioDocument;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

function studioCatalogDocuments(?array $kit = null): array
{
    static $catalog = null;
    if ($kit === null && $catalog !== null) {
        return $catalog;
    }

    $node = (new ExecutableFinder)->find('node');
    expect($node)->not->toBeNull('Node es necesario para leer el catálogo real del Estudio.');
    $process = new Process([$node, '--input-type=module', '-e', <<<'JS'
        import { STUDIO_TEMPLATES, createFromTemplate } from './resources/js/Studio/templates.js';
        const kit = JSON.parse(process.argv[1]);
        const templates = kit ? STUDIO_TEMPLATES.filter(template => template.layout) : STUDIO_TEMPLATES;
        console.log(JSON.stringify(templates.map(({ key, name, pages, layout }) => ({ key, name, pages, layout, document: createFromTemplate(key, kit ?? {}) }))));
        JS,
        json_encode($kit, JSON_THROW_ON_ERROR),
    ], base_path());
    $process->mustRun();

    $documents = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    if ($kit === null) {
        $catalog = $documents;
    }

    return $documents;
}

test('el catálogo añade al menos diez plantillas por formato principal y conserva las originales', function () {
    $originals = [
        'aurora-launch', 'bold-statement', 'minimal-quote', 'organic-note', 'studio-offer',
        'social-announcement', 'story-editorial', 'story-launch', 'story-event', 'portrait-spotlight',
        'carousel-story', 'deck-cover', 'deck-agenda', 'video-title',
    ];
    $catalog = studioCatalogDocuments();
    expect(array_diff($originals, array_column($catalog, 'key')))->toBeEmpty();
    $counts = [];
    foreach ($catalog as $template) {
        if (in_array($template['key'], $originals, true)) {
            continue;
        }
        $document = $template['document'];
        $format = $document['width'].'×'.$document['height'];
        $counts[$format] = ($counts[$format] ?? 0) + 1;
        expect($document['schema_version'])->toBe(2, $template['key']);
    }
    foreach (['1080×1080', '1080×1350', '1080×1440', '1080×1920', '1920×1080'] as $format) {
        expect($counts[$format] ?? 0)->toBeGreaterThanOrEqual(10, $format.' carece de diez plantillas nuevas');
    }
});

test('cada plantilla se valida en el servidor con identificadores únicos y sin recursos externos', function () {
    $user = new User;
    $user->id = 1;
    $brand = new Brand;
    $brand->user_id = $user->id;
    $validator = app(StudioDocument::class);
    $catalog = studioCatalogDocuments();
    foreach (['key', 'name'] as $field) {
        expect(array_unique(array_column($catalog, $field)))->toHaveCount(count($catalog));
    }

    $ids = [];
    foreach ($catalog as $template) {
        $source = $template['document'];
        expect($source['pages'])->toHaveCount($template['pages']);
        $document = $source['schema_version'] === 2 ? $source : array_replace($source, [
            'schema_version' => 2, 'timeline' => ['fps' => 30, 'audio_tracks' => []],
        ]);
        expect($validator->validate($user, $brand, $document))->toBe($document, $template['key'])
            ->and($document['timeline']['audio_tracks'])->toBe([]);
        foreach ($document['pages'] as $page) {
            $ids[] = $page['id'];
            expect($page['elements'])->not->toBeEmpty();
            foreach ($page['elements'] as $element) {
                $ids[] = $element['id'];
                expect($element['type'])->toBeIn(['rect', 'ellipse', 'text'])
                    ->and(array_intersect(['asset_id', 'font_id', 'url'], array_keys($element)))->toBeEmpty();
                if ($element['type'] === 'text') {
                    expect($element['fontFamily'])->toBe('Arial');
                }
            }
        }
    }
    expect(array_unique($ids))->toHaveCount(count($ids));
});

test('las 50 plantillas nuevas validan la paleta y la fuente propia de la marca', function () {
    $user = User::factory()->create();
    $brand = Brand::factory()->for($user)->create();
    $bytes = file_get_contents(base_path('tests/Fixtures/fonts/SpaceGrotesk-Variable.woff2'));
    $font = StudioFont::create([
        'user_id' => $user->id,
        'brand_id' => $brand->id,
        'name' => 'Fuente de la marca',
        'family' => 'Space Grotesk',
        'style' => 'normal',
        'weight' => 400,
        'format' => 'woff2',
        'mime' => 'font/woff2',
        'size' => strlen($bytes),
        'sha256' => hash('sha256', $bytes),
        'source_sha256' => hash('sha256', $bytes),
        'disk' => 'local',
        'path' => 'studio-catalog.woff2',
        'status' => 'ready',
    ]);
    $validator = app(StudioDocument::class);

    foreach ([['#124', '#d86e', '#fff7e7'], ['#172238f0', '#e6a040', '#ffe']] as $palette) {
        [$dark, $accent, $paper] = $palette;
        $catalog = studioCatalogDocuments(['palette' => $palette, 'fonts' => [$font->family], 'font_ids' => [$font->id]]);
        expect($catalog)->toHaveCount(50);

        foreach ($catalog as ['key' => $key, 'layout' => $layout, 'pages' => $pageCount, 'document' => $document]) {
            expect($validator->validate($user, $brand, $document))->toBe($document, $key)
                ->and($document['pages'])->toHaveCount($pageCount);

            foreach ($document['pages'] as $index => $page) {
                $context = $key.'/'.$page['name'];
                $background = $layout === 'launch' || ($layout === 'carousel' && $index === 2) ? $dark : $paper;
                $text = array_filter($page['elements'], fn (array $element) => $element['type'] === 'text');
                expect($page['background'])->toBe($background, $context)
                    ->and(array_column($page['elements'], 'fill'))->toContain($accent)
                    ->and($text)->not->toBeEmpty();
                foreach ($text as $element) {
                    expect($element['fontFamily'])->toBe($font->family, $context)
                        ->and($element['font_id'])->toBe($font->id, $context);
                }
            }
        }
    }
});

test('cada formato principal ofrece animaciones de entrada y salida dentro de la vida del objeto', function () {
    $formats = [];
    foreach (studioCatalogDocuments() as ['document' => $document]) {
        foreach ($document['pages'] as $page) {
            foreach ($page['elements'] as $element) {
                if (! isset($element['animation'])) {
                    continue;
                }
                $animation = $element['animation'];
                $life = $element['timing']['duration'] ?? $page['duration'];
                $occupied = array_sum(array_map(fn (array $phase) => $phase['duration'] + ($phase['delay'] ?? 0), $animation));
                expect($occupied)->toBeLessThanOrEqual($life);
                if (isset($animation['in'], $animation['out'])) {
                    $formats[$document['width'].'×'.$document['height']] = true;
                }
            }
        }
    }
    expect($formats)->toHaveKeys(['1080×1080', '1080×1350', '1080×1440', '1080×1920', '1920×1080']);
});
