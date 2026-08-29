<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}"  @class(['dark' => ($appearance ?? 'system') == 'dark'])>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        {{-- Inline script to detect system dark mode preference and apply it immediately --}}
        <script>
            (function() {
                const appearance = '{{ $appearance ?? "system" }}';

                if (appearance === 'system') {
                    const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;

                    if (prefersDark) {
                        document.documentElement.classList.add('dark');
                    }
                }
            })();
        </script>

        {{-- Inline style to set the HTML background color based on our theme in app.css --}}
        <style>
            html {
                background-color: oklch(1 0 0);
            }

            html.dark {
                background-color: oklch(0.16 0.01 250);
            }
        </style>

        <link rel="icon" href="/favicon.ico" sizes="any">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">

        {{-- The PWA (#102).

             The manifest is **served**, not built — see
             `WebManifestController`. The plugin's copy lands in `public/build`
             and enters the worker's precache list as a relative URL that
             resolves to a path nothing serves, and no build hook can reach
             that entry.

             `theme-color` is Design System §2.4's `--primary`, as the sRGB a
             browser chrome can hold: it colours the address bar on Android
             and the status bar of an installed app, neither of which can read
             a CSS custom property. The dark variant is deliberately the same
             — this is a brand colour on a system surface, not a page
             background, and the one thing worse than the wrong shade is the
             bar changing colour when the phone flips to dark at sunset. --}}
        <link rel="manifest" href="/manifest.webmanifest">
        <meta name="theme-color" content="#1A588F">

        {{-- iOS ignores the manifest's `display` and reads these instead. It
             is the platform that makes S54 mandatory rather than optional
             (Screen Inventory §J), so the tags it actually honours are worth
             writing out even though Android needs none of them. --}}
        <meta name="apple-mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-status-bar-style" content="default">
        <meta name="apple-mobile-web-app-title" content="{{ config('app.product_name') }}">

        @vite(['resources/css/app.css', 'resources/js/app.ts', "resources/js/pages/{$page['component']}.vue"])
        <x-inertia::head>
            <title>{{ config('app.product_name') }}</title>
        </x-inertia::head>
    </head>
    <body class="font-sans antialiased">
        <x-inertia::app />
    </body>
</html>
