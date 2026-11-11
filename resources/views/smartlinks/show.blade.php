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
        .dest { display: block; margin-top: .25rem; font-size: .7rem; color: var(--muted); overflow-wrap: anywhere; }
        .header { margin-top: 1rem; font-size: .8rem; text-transform: uppercase; letter-spacing: .08em; color: var(--muted); font-weight: 700; }
        .imglink { display: block; border-radius: .75rem; overflow: hidden; background: var(--card); color: var(--fg); text-decoration: none; font-weight: 600; }
        .imglink img { display: block; width: 100%; height: auto; max-height: 14rem; object-fit: cover; }
        .imglink span { display: block; padding: .6rem .8rem; }
        .video iframe { width: 100%; aspect-ratio: 16 / 9; border: 0; border-radius: .75rem; background: #000; }
        .divider { border-top: 1px solid var(--muted); margin: .5rem 0; padding-top: .35rem; font-size: .75rem; color: var(--muted); }
        .social, .recent { display: flex; flex-wrap: wrap; justify-content: center; gap: .5rem; margin-top: .35rem; }
        .recent { flex-direction: column; }
        .chip { display: inline-block; padding: .5rem .8rem; border-radius: 9999px; background: var(--card); color: var(--fg); border: 2px solid var(--accent); text-decoration: none; font-weight: 600; font-size: .85rem; overflow-wrap: anywhere; }
        .chip.wide { border-radius: .75rem; display: block; text-align: left; font-weight: 500; }
        .mail { display: flex; flex-direction: column; gap: .5rem; text-align: left; }
        .mail input[type=email] { padding: .7rem .8rem; border-radius: .6rem; border: 2px solid var(--muted); background: var(--card); color: var(--fg); font-size: 1rem; }
        .mail .btn { border: 0; cursor: pointer; font-size: 1rem; }
        .trap { position: absolute; left: -9999px; width: 1px; height: 1px; overflow: hidden; }
        .ok { color: var(--accent); font-weight: 700; margin: 0; }
        footer { margin-top: 2.5rem; font-size: .75rem; color: var(--muted); }
        footer p { margin: .25rem 0; }
        footer a { color: var(--fg); text-decoration: underline; }
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
            @php $go = url('/l/'.$link->slug.'/go/'.$block->id); @endphp
            @switch($block->type)
                @case('header')
                    <li class="header" role="heading" aria-level="2">{{ $block->label }}</li>
                    @break
                @case('divider')
                    <li><div role="separator" class="divider">{{ $block->label }}</div></li>
                    @break
                @case('link')
                    <li>
                        <a class="btn" rel="nofollow noopener" href="{{ $go }}">{{ $block->label }}</a>
                        <span class="dest">Destino: {{ \App\Support\SafeUrl::displayHost($block->url) }}</span>
                    </li>
                    @break
                @case('image')
                    <li>
                        <a class="imglink" rel="nofollow noopener" href="{{ $go }}">
                            <img src="{{ $images[$block->id] }}" alt="{{ $block->label }}" loading="lazy">
                            <span>{{ $block->label }}</span>
                        </a>
                        <span class="dest">Destino: {{ \App\Support\SafeUrl::displayHost($block->url) }}</span>
                    </li>
                    @break
                @case('youtube')
                    <li class="video">
                        <iframe src="https://www.youtube-nocookie.com/embed/{{ $block->data['video_id'] }}" title="{{ $block->label }}" loading="lazy" allowfullscreen
                                referrerpolicy="strict-origin-when-cross-origin" sandbox="allow-scripts allow-same-origin allow-presentation"></iframe>
                        <a class="dest" rel="nofollow noopener" href="{{ $go }}">{{ $block->label }} (ver en YouTube)</a>
                    </li>
                    @break
                @case('social')
                    <li>
                        @if ($block->label !== '')
                            <p class="header">{{ $block->label }}</p>
                        @endif
                        <ul class="social" aria-label="Redes sociales">
                            @foreach ((array) ($block->data['items'] ?? []) as $i => $item)
                                <li><a class="chip" rel="nofollow noopener" href="{{ $go }}?i={{ $i }}" aria-label="{{ ucfirst($item['network']) }}">{{ ucfirst($item['network']) }}</a></li>
                            @endforeach
                        </ul>
                    </li>
                    @break
                @case('email')
                    <li id="suscripcion">
                        <form class="mail" method="post" action="{{ url('/l/'.$link->slug.'/subscribe/'.$block->id) }}">
                            <label for="sub-email-{{ $block->id }}" class="header">{{ $block->label }}</label>
                            <input id="sub-email-{{ $block->id }}" type="email" name="email" required maxlength="254" autocomplete="email" placeholder="tu@email.com">
                            <div class="trap" aria-hidden="true"><label>No rellenar <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>
                            <button class="btn" type="submit">{{ $block->data['cta'] ?? 'Suscribirme' }}</button>
                            <p class="dest">{{ $block->data['consent'] ?? '' }}</p>
                            @if ($subscribed === '1')
                                <p class="ok" role="status">¡Gracias! Si la dirección es válida, quedas suscrito.</p>
                            @elseif ($subscribed === '0')
                                <p class="ok" role="alert">Revisa la dirección de email.</p>
                            @endif
                        </form>
                    </li>
                    @break
                @case('posts')
                    @if (! empty($posts[$block->id]))
                        <li>
                            @if ($block->label !== '')
                                <p class="header">{{ $block->label }}</p>
                            @endif
                            <ul class="recent">
                                @foreach ($posts[$block->id] as $post)
                                    <li><a class="chip wide" rel="nofollow noopener" href="{{ $post['url'] }}">{{ $post['text'] }}</a></li>
                                @endforeach
                            </ul>
                        </li>
                    @endif
                    @break
            @endswitch
        @endforeach
    </ul>
    @php $report = config('social.smartlinks.report_email') ?: config('mail.from.address'); @endphp
    <footer>
        <p>Contenido publicado por su autor; los enlaces llevan a sitios externos que no controlamos.</p>
        @if ($report)
            <p><a rel="nofollow noopener" href="mailto:{{ $report }}?subject={{ rawurlencode('Denuncia de la pagina '.$link->url()) }}">Denunciar esta pagina</a></p>
        @endif
        <p>Hecho con Social Scheduler</p>
    </footer>
</main>
</body>
</html>
