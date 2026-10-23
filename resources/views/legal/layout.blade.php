<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title') · {{ config('app.name') }}</title>
    <style>
        body { font-family: system-ui, sans-serif; max-width: 46rem; margin: 2rem auto; padding: 0 1rem; line-height: 1.6; color: #1f2937; }
        h1 { font-size: 1.6rem; } h2 { font-size: 1.15rem; margin-top: 1.8rem; }
        footer { margin-top: 3rem; font-size: .85rem; color: #6b7280; }
    </style>
</head>
<body>
    <main>@yield('content')</main>
    <footer>{{ config('app.name') }} · <a href="{{ route('legal.privacy') }}">Privacidad</a> · <a href="{{ route('legal.terms') }}">Condiciones</a></footer>
</body>
</html>
