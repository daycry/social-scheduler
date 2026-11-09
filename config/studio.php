<?php

$python = base_path('storage/framework/studio-python/'.(PHP_OS_FAMILY === 'Windows' ? 'Scripts/python.exe' : 'bin/python'));

return [
    'node' => env('STUDIO_NODE_BINARY', 'node'),
    'python' => env('STUDIO_PYTHON_BINARY', is_file($python) ? $python : (PHP_OS_FAMILY === 'Windows' ? 'python' : 'python3')),
    'av' => [
        'queue' => 'studio',
        'lease_seconds' => 180,
        'disk' => ['snapshot' => 8 * 1024 ** 3, 'pcm' => 8 * 1024 ** 3, 'segment' => 8 * 1024 ** 3, 'output' => 8 * 1024 ** 3],
    ],
    'fonts' => [
        'disk' => env('STUDIO_FONT_DISK', 'local'),
        'max_bytes' => 8 * 1024 * 1024,
        'max_decoded_bytes' => 32 * 1024 * 1024,
        'probe_timeout' => 20,
    ],
    'images' => [
        'model_path' => env('STUDIO_BACKGROUND_MODEL_PATH', storage_path('app/private/studio-models/birefnet-general-lite.onnx')),
        'model_sha256' => env('STUDIO_BACKGROUND_MODEL_SHA256', '5600024376f572a557870a5eb0afb1e5961636bef4e1e22132025467d0f03333'),
        'timeout' => 85,
        'max_pixels' => 16000000,
        'max_bytes' => 30 * 1024 * 1024,
    ],
];
