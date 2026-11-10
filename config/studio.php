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
    /*
    | IA creativa (T-14): generacion y edicion de imagenes con proveedor configurable. Cada proveedor solo se ofrece si su clave
    | esta definida (vacia por defecto); sin clave la funcion aparece como «no configurada». Las claves nunca se registran ni se
    | devuelven por la API. `daily_limit` es por usuario y proveedor (imagenes al dia); `user_limits` permite ajustarlo por
    | usuario: STUDIO_AI_USER_LIMITS='{"12":{"openai":5}}'.
    */
    'ai' => [
        'max_prompt' => 1000,
        'max_variants' => 4,
        'max_active_jobs' => 5,
        'max_input_bytes' => 20 * 1024 * 1024,
        'max_output_bytes' => 30 * 1024 * 1024,
        'max_output_pixels' => 36000000,
        'max_edit_side' => 1536,
        'timeout' => (int) env('STUDIO_AI_TIMEOUT', 120),
        /* Intentos totales ante 429/5xx/cortes y espera (s) antes de cada reintento. */
        'attempts' => 3,
        'backoff' => [2, 6],
        'max_retry_after' => 30,
        'user_limits' => json_decode((string) env('STUDIO_AI_USER_LIMITS', '{}'), true) ?: [],
        'providers' => [
            'openai' => [
                'label' => 'OpenAI Images',
                'key' => env('STUDIO_AI_OPENAI_KEY', env('OPENAI_API_KEY')),
                'model' => env('STUDIO_AI_OPENAI_MODEL', 'gpt-image-1'),
                'endpoint' => 'https://api.openai.com/v1',
                'daily_limit' => (int) env('STUDIO_AI_OPENAI_DAILY_LIMIT', 20),
            ],
            'google' => [
                'label' => 'Google (Gemini imagen)',
                'key' => env('STUDIO_AI_GOOGLE_KEY', env('GEMINI_API_KEY')),
                'model' => env('STUDIO_AI_GOOGLE_MODEL', 'gemini-nano-banana-2.1'),
                'endpoint' => 'https://generativelanguage.googleapis.com/v1beta',
                'api_revision' => '2026-05-20',
                'daily_limit' => (int) env('STUDIO_AI_GOOGLE_DAILY_LIMIT', 20),
            ],
        ],
    ],
];
