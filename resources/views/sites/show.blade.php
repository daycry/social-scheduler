@php
    $theme = $doc['theme'];
    $pageTitle = ($page['title'] ?? '').' · '.$doc['title'];
    $desc = $doc['description'] ?: $doc['title'];
    $menu = collect($doc['pages'])->filter(fn ($p) => $p['menu'] ?? true)->values();
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $pageTitle }}</title>
    <meta name="description" content="{{ $desc }}">
    @if ($noindex)
        <meta name="robots" content="noindex, nofollow">
    @endif
    @if (! $preview)
        <link rel="canonical" href="{{ $view->pageUrl($page['key'], true) }}">
        <meta property="og:type" content="website">
        <meta property="og:title" content="{{ $pageTitle }}">
        <meta property="og:description" content="{{ $desc }}">
    @endif
    @include('sites._style')
</head>
<body>
<a class="skip" href="#contenido">Saltar al contenido</a>
@if ($preview)
    <div class="banner" role="note">Vista previa del borrador</div>
@endif
<header class="site">
    <div class="in">
        <p class="brand">{{ $doc['title'] }}</p>
        @if ($menu->count() > 1)
            <nav aria-label="Principal">
                <ul>
                    @foreach ($menu as $p)
                        <li><a href="{{ $view->pageUrl($p['key']) }}" @if ($p['key'] === $page['key']) aria-current="page" @endif>{{ $p['title'] }}</a></li>
                    @endforeach
                </ul>
            </nav>
        @endif
    </div>
</header>
<main id="contenido" tabindex="-1">
    <h1>{{ $page['title'] }}</h1>
    @foreach ($page['blocks'] as $block)
        @include('sites.blocks.'.$block['type'], ['b' => $block])
    @endforeach
</main>
<footer><div class="in"><p>{{ $doc['title'] }}</p></div></footer>
</body>
</html>
