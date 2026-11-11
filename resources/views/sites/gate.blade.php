@php
    $theme = ['accent' => '#4f46e5', 'on_accent' => '#ffffff', 'mode' => 'light', 'bg' => '#ffffff', 'fg' => '#111827', 'muted' => '#4b5563', 'card' => '#f3f4f6', 'font' => null];
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Contenido protegido</title>
    <meta name="robots" content="noindex, nofollow">
    @include('sites._style')
</head>
<body>
<main id="contenido">
    <h1>Contenido protegido</h1>
    @if ($mode === 'password')
        <p>Este sitio requiere una contraseña.</p>
        @if ($error)
            <p class="ok" role="alert">{{ $error }}</p>
        @endif
        <form method="post" action="{{ url('/s/'.$slug.'/_access') }}">
            <label for="pw">Contraseña</label>
            <input id="pw" type="password" name="password" required autocomplete="current-password" maxlength="128">
            <p><button class="btn" type="submit">Entrar</button></p>
        </form>
    @else
        <p>Necesitas un enlace de acceso válido para ver este sitio. Pídelo a quien lo comparte contigo.</p>
    @endif
</main>
</body>
</html>
