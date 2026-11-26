<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $title }} · {{ config('app.name') }}</title>
    <style>
        body { font-family: system-ui, sans-serif; max-width: 34rem; margin: 3rem auto; padding: 0 1rem; line-height: 1.6; color: #1f2937; }
        h1 { font-size: 1.4rem; }
        button { font: inherit; padding: .6rem 1.2rem; border: 0; border-radius: .4rem; background: #1d4ed8; color: #fff; cursor: pointer; }
        button:focus-visible { outline: 3px solid #93c5fd; outline-offset: 2px; }
    </style>
</head>
<body>
    <main>
        @if ($done)
            <h1 role="status">Listo</h1>
            <p>{{ $doneText }}</p>
        @else
            <h1>{{ $question }}</h1>
            <p>{{ $detail }}</p>
            <form method="POST" action="{{ $action }}">
                <button type="submit">Darme de baja</button>
            </form>
        @endif
    </main>
</body>
</html>
