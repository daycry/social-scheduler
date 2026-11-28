<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" @auth data-account-theme="{{ auth()->user()->theme }}" @endauth>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="theme-color" content="#0f172a" data-light="#0f172a" data-dark="#0a1020">
        <script nonce="{{ Vite::cspNonce() }}">
            (() => {
                const root = document.documentElement;
                let preference = root.dataset.accountTheme;
                if (!preference) {
                    try {
                        preference = window.localStorage.getItem('ss.theme');
                    } catch {
                        // El navegador puede bloquear el almacenamiento local.
                    }
                }
                const dark = preference === 'dark' || (preference !== 'light' && window.matchMedia('(prefers-color-scheme: dark)').matches);
                const scheme = dark ? 'dark' : 'light';
                root.classList.toggle('dark', dark);
                root.style.colorScheme = scheme;
                const meta = document.querySelector('meta[name="theme-color"]');
                meta.content = meta.dataset[scheme];
            })();
        </script>

        <title inertia>{{ config('app.name', 'Social Scheduler') }}</title>

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet" />
        <link rel="icon" type="image/svg+xml" href="/favicon.svg">
        <link rel="icon" type="image/png" sizes="192x192" href="/icon-192.png">
        <link rel="icon" href="/favicon.ico" sizes="48x48">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">
        <link rel="manifest" href="/manifest.webmanifest">

        <!-- Scripts -->
        @routes(nonce: Vite::cspNonce())
        @vite(['resources/js/app.js', "resources/js/Pages/{$page['component']}.vue"])
        @inertiaHead
    </head>
    <body class="font-sans antialiased">
        @inertia
    </body>
</html>
