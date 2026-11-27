<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $title }}</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; background: #f9fafb; color: #111827; font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; }
        main { max-width: 28rem; padding: 2rem 1.25rem; text-align: center; }
        h1 { font-size: 1.5rem; margin: 0 0 .5rem; }
        p { color: #4b5563; margin: 0 0 .75rem; overflow-wrap: anywhere; }
        .go { display: inline-block; padding: .5rem 1rem; border-radius: .5rem; background: #4f46e5; color: #fff; text-decoration: none; }
    </style>
</head>
<body>
    <main>
        <h1>{{ $title }}</h1>
        <p>{{ $message }}</p>
        @isset($continue)
            <p><strong>{{ $host }}</strong></p>
            <p><a class="go" href="{{ $continue }}" rel="noopener noreferrer nofollow">Continuar</a></p>
        @endisset
    </main>
</body>
</html>
