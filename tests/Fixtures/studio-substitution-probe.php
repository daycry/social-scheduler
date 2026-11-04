<?php

use App\Services\Studio\StudioAutomation;
use App\Services\Studio\StudioDocument;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Validation\ValidationException;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$document = StudioDocument::blank(400, 400);
for ($i = 0; $i < 10; $i++) {
    $document['pages'][0]['elements'][] = ['id' => 'text_'.$i, 'type' => 'text', 'x' => 0, 'y' => 0,
        'width' => 100, 'height' => 100, 'text' => str_repeat('{{a}}', 2000)];
}
$service = (new ReflectionClass(StudioAutomation::class))->newInstanceWithoutConstructor();
try {
    (new ReflectionMethod($service, 'substitute'))->invoke($service, $document, ['a' => str_repeat('x', 10000)]);
    echo json_encode(['rejected' => false]);
} catch (ValidationException) {
    echo json_encode(['rejected' => true]);
}
