<?php

$python = base_path('storage/framework/studio-python/'.(PHP_OS_FAMILY === 'Windows' ? 'Scripts/python.exe' : 'bin/python'));

return [
    'node' => env('STUDIO_NODE_BINARY', 'node'),
    'python' => env('STUDIO_PYTHON_BINARY', is_file($python) ? $python : (PHP_OS_FAMILY === 'Windows' ? 'python' : 'python3')),
    // Ruta de Chromium para el renderizado (la leen los scripts Node por STUDIO_CHROMIUM_PATH); vacia = el de Playwright.
    'chromium_path' => env('STUDIO_CHROMIUM_PATH'),
    'av' => [
        'queue' => 'studio',
        'lease_seconds' => 180,
        'disk' => ['snapshot' => 8 * 1024 ** 3, 'pcm' => 8 * 1024 ** 3, 'segment' => 8 * 1024 ** 3, 'output' => 8 * 1024 ** 3],
    ],
    // Autoguardado (A-07): un autoguardado del mismo autor dentro de este intervalo (s) reescribe la revision reciente en vez de crear otra.
    // Dias durante los que un export listo bloquea el borrado de su media (0 = nunca); los exports mas antiguos no la protegen.
    'export_media_guard_days' => (int) env('STUDIO_EXPORT_MEDIA_GUARD_DAYS', 30),
    // Miniaturas del listado (T-35): se renderizan bajo demanda por la cola `media` con el mismo Chromium de las exportaciones. `false` = sin miniaturas.
    'thumbnails' => ['enabled' => (bool) env('STUDIO_THUMBNAILS', true)],
    'revisions' => ['min_interval_seconds' => (int) env('STUDIO_REVISION_MIN_INTERVAL', 300)],
    'fonts' => [
        'disk' => env('STUDIO_FONT_DISK', 'local'),
        'max_bytes' => 8 * 1024 * 1024,
        'max_decoded_bytes' => 32 * 1024 * 1024,
        'probe_timeout' => 20,
    ],
    'images' => [
        'model_path' => env('STUDIO_BACKGROUND_MODEL_PATH', storage_path('app/private/studio-models/isnet-general-use.onnx')),
        'model_sha256' => env('STUDIO_BACKGROUND_MODEL_SHA256', '60920e99c45464f2ba57bee2ad08c919a52bbf852739e96947fbb4358c0d964a'),
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
        'max_variants' => 3,
        'max_active_jobs' => 5,
        'max_input_bytes' => 20 * 1024 * 1024,
        'max_output_bytes' => 30 * 1024 * 1024,
        'max_output_pixels' => 36000000,
        'max_edit_side' => 1536,
        /* Plazos encadenados (docs/arquitectura.md): job 110 s < worker --timeout 120 s < retry_after 180 s. `budget` es el tiempo total de
           llamadas al proveedor dentro del job; `timeout` el maximo de UNA llamada. Un reintento solo se programa si cabe en el presupuesto. */
        'budget' => (int) env('STUDIO_AI_BUDGET', 100),
        'timeout' => (int) env('STUDIO_AI_TIMEOUT', 90),
        /* Intentos totales ante 429/5xx/cortes y espera (s) antes de cada reintento. */
        'attempts' => 3,
        'backoff' => [2, 6],
        'max_retry_after' => 30,
        'user_limits' => json_decode((string) env('STUDIO_AI_USER_LIMITS', '{}'), true) ?: [],
        'providers' => [
            'openai' => [
                'label' => 'OpenAI Images',
                'key' => env('STUDIO_AI_OPENAI_KEY', env('OPENAI_API_KEY')),
                /* gpt-image-1 esta retirado (ficha del modelo, 2026-11-10); ID vigente de la guia de imagenes de OpenAI. */
                'model' => env('STUDIO_AI_OPENAI_MODEL', 'gpt-image-2.5-sunburst'),
                'quality' => env('STUDIO_AI_OPENAI_QUALITY', 'medium'),
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
