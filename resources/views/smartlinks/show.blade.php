@php
    $dark = $link->theme_mode === 'dark';
    $accent = preg_match('/^#[0-9a-fA-F]{6}$/', $link->theme_color) ? $link->theme_color : '#4f46e5';
    $description = $link->bio ?: $link->title;
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $link->title }}</title>
    <meta name="description" content="{{ $description }}">
    <link rel="canonical" href="{{ $link->url() }}">
    <meta property="og:type" content="website">
    <meta property="og:title" content="{{ $link->title }}">
    <meta property="og:description" content="{{ $description }}">
    <meta property="og:url" content="{{ $link->url() }}">
    @if ($avatar)
        <meta property="og:image" content="{{ $avatar }}">
    @endif
    <meta name="twitter:card" content="summary">
    <style>
        :root { --accent: {{ $accent }}; --bg: {{ $dark ? '#111827' : '#f9fafb' }}; --fg: {{ $dark ? '#f9fafb' : '#111827' }}; --muted: {{ $dark ? '#9ca3af' : '#6b7280' }}; --card: {{ $dark ? '#1f2937' : '#ffffff' }}; }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; background: var(--bg); color: var(--fg); font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; }
        main { max-width: 32rem; margin: 0 auto; padding: 2.5rem 1.25rem 3rem; text-align: center; }
        .avatar { width: 6rem; height: 6rem; border-radius: 9999px; object-fit: cover; border: 3px solid var(--accent); }
        .avatar-fallback { width: 6rem; height: 6rem; border-radius: 9999px; background: var(--accent); color: #fff; font-size: 2.25rem; font-weight: 700; display: inline-flex; align-items: center; justify-content: center; }
        h1 { font-size: 1.5rem; margin: 1rem 0 .25rem; }
        .bio { color: var(--muted); margin: 0 0 1.75rem; white-space: pre-line; }
        ul { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: .75rem; }
        .btn { display: block; padding: .9rem 1rem; border-radius: .75rem; background: var(--accent); color: #fff; text-decoration: none; font-weight: 600; }
        .btn:hover, .btn:focus-visible { filter: brightness(1.1); outline: 3px solid var(--fg); outline-offset: 2px; }
        .header { margin-top: 1rem; font-size: .8rem; text-transform: uppercase; letter-spacing: .08em; color: var(--muted); font-weight: 700; }
        footer { margin-top: 2.5rem; font-size: .75rem; color: var(--muted); }
    </style>
</head>
<body>
<main>
    @if ($avatar)
        <img class="avatar" src="{{ $avatar }}" alt="" width="96" height="96">
    @else
        <span class="avatar-fallback" aria-hidden="true">{{ mb_strtoupper(mb_substr($link->title, 0, 1)) }}</span>
    @endif
    <h1>{{ $link->title }}</h1>
    @if ($link->bio)
        <p class="bio">{{ $link->bio }}</p>
    @endif
    <ul>
        @foreach ($blocks as $block)
            @if ($block->type === 'header')
                <li class="header" role="heading" aria-level="2">{{ $block->label }}</li>
            @else
                <li><a class="btn" rel="noopener nofollow" href="{{ url('/l/'.$link->slug.'/go/'.$block->id) }}">{{ $block->label }}</a></li>
            @endif
        @endforeach
    </ul>
    <footer>Hecho con Social Scheduler</footer>
</main>
</body>
</html>
