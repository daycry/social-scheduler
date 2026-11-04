<?php

use App\Models\Brand;
use App\Models\User;
use App\Services\Studio\StudioAutomation;
use App\Services\Studio\StudioDocument;

require __DIR__.'/../../vendor/autoload.php';
$user = new User;
$user->id = 1;
$brand = new Brand;
$brand->user_id = 1;
$document = StudioDocument::blank(400, 400);
$document['pages'] = [];
for ($pageIndex = 0; $pageIndex < 6; $pageIndex++) {
    $page = ['id' => 'page_'.$pageIndex, 'background' => '#ffffff', 'elements' => []];
    for ($elementIndex = 0; $elementIndex < 500; $elementIndex++) {
        $stops = [];
        for ($stopIndex = 0; $stopIndex < 12; $stopIndex++) {
            $number = ($pageIndex * 500 + $elementIndex) * 12 + $stopIndex;
            $stops[] = ['offset' => $stopIndex / 11, 'color' => '#'.str_pad(dechex($number), 6, '0', STR_PAD_LEFT)];
        }
        $page['elements'][] = ['id' => 'rect_'.$pageIndex.'_'.$elementIndex, 'type' => 'rect', 'x' => 0, 'y' => 0,
            'width' => 100, 'height' => 100, 'fill' => ['type' => 'linear', 'angle' => 0, 'stops' => $stops]];
    }
    $document['pages'][] = $page;
}
(new StudioDocument)->validate($user, $brand, $document);
$service = (new ReflectionClass(StudioAutomation::class))->newInstanceWithoutConstructor();
$started = hrtime(true);
$result = (new ReflectionMethod($service, 'applyKit'))->invoke($service, $user, $brand, $document, [], ['palette' => ['#111111', '#222222'], 'fonts' => [], 'logos' => []]);
echo json_encode(['elements' => 3000, 'colors' => 36000, 'source_bytes' => strlen(json_encode($document)),
    'milliseconds' => (hrtime(true) - $started) / 1000000, 'first_color' => $result['pages'][0]['elements'][0]['fill']['stops'][0]['color']]);
