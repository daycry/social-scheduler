@php $t = $theme; @endphp
<style nonce="{{ $nonce }}">
    :root { --accent: {{ $t['accent'] }}; --on-accent: {{ $t['on_accent'] }}; --bg: {{ $t['bg'] }}; --fg: {{ $t['fg'] }}; --muted: {{ $t['muted'] }}; --card: {{ $t['card'] }}; color-scheme: {{ $t['mode'] }}; }
    * { box-sizing: border-box; }
    body { margin: 0; min-height: 100vh; background: var(--bg); color: var(--fg); font-family: {!! $t['font'] ? '"'.e($t['font']).'", ' : '' !!}system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; line-height: 1.6; overflow-wrap: anywhere; }
    a { color: var(--fg); }
    .skip { position: absolute; left: .5rem; top: -4rem; background: var(--fg); color: var(--bg); padding: .5rem .75rem; z-index: 10; }
    .skip:focus { top: .5rem; }
    header.site { border-bottom: 4px solid var(--accent); background: var(--card); }
    header.site .in, main, footer .in { max-width: 48rem; margin: 0 auto; padding: 1rem 1.25rem; }
    header.site p.brand { margin: 0; font-size: 1.25rem; font-weight: 700; }
    nav ul { list-style: none; margin: .5rem 0 0; padding: 0; display: flex; flex-wrap: wrap; gap: .5rem; }
    nav a { display: inline-block; padding: .35rem .75rem; border-radius: .5rem; text-decoration: none; font-weight: 600; border: 2px solid transparent; }
    nav a[aria-current="page"] { border-color: var(--accent); }
    nav a:hover, a:focus-visible, button:focus-visible, input:focus-visible, textarea:focus-visible, summary:focus-visible { outline: 3px solid var(--accent); outline-offset: 2px; }
    main { padding-bottom: 2rem; }
    main > * + * { margin-top: 1.5rem; }
    h1 { font-size: 1.75rem; margin: 0 0 .5rem; }
    h2 { font-size: 1.4rem; margin: 0; }
    h3 { font-size: 1.15rem; margin: 0; }
    p, ul, ol { margin: 0 0 .75rem; }
    figure { margin: 0; }
    img { display: block; max-width: 100%; height: auto; border-radius: .5rem; }
    figcaption { color: var(--muted); font-size: .9rem; margin-top: .35rem; }
    .gallery { display: grid; grid-template-columns: repeat(auto-fill, minmax(9rem, 1fr)); gap: .5rem; }
    .gallery img { width: 100%; aspect-ratio: 1; object-fit: cover; }
    .btn { display: inline-block; padding: .75rem 1.25rem; border-radius: .6rem; background: var(--accent); color: var(--on-accent); text-decoration: none; border: 0; font: inherit; font-weight: 700; cursor: pointer; }
    .btn[disabled] { opacity: .6; cursor: not-allowed; }
    .video iframe { width: 100%; aspect-ratio: 16 / 9; border: 0; border-radius: .5rem; background: #000; }
    .card { background: var(--card); border-radius: .75rem; padding: 1rem 1.25rem; border: 1px solid var(--muted); }
    details { background: var(--card); border-radius: .6rem; padding: .6rem 1rem; margin-top: .5rem; border: 1px solid var(--muted); }
    summary { cursor: pointer; font-weight: 700; }
    label { display: block; font-weight: 600; margin: .5rem 0 .2rem; }
    label.opt { display: flex; gap: .5rem; align-items: center; margin: .25rem 0; font-weight: 400; }
    label.flat { margin: 0; }
    input[type=text], input[type=email], input[type=password], textarea { width: 100%; padding: .6rem .75rem; border-radius: .5rem; border: 2px solid var(--muted); background: var(--bg); color: var(--fg); font: inherit; }
    fieldset { border: 0; padding: 0; margin: 0; min-width: 0; }
    legend { font-weight: 700; padding: 0; margin-bottom: .5rem; }
    progress { width: 100%; height: 1rem; accent-color: var(--accent); }
    .res { margin: .35rem 0; }
    .trap { position: absolute; left: -9999px; width: 1px; height: 1px; overflow: hidden; }
    .note { color: var(--muted); font-size: .9rem; }
    .ok { font-weight: 700; }
    footer { border-top: 1px solid var(--muted); margin-top: 2rem; color: var(--muted); font-size: .85rem; }
    .banner { background: var(--fg); color: var(--bg); text-align: center; padding: .4rem; font-size: .85rem; }
</style>
