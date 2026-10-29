<!DOCTYPE html>
<html lang="id">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <title inertia>{{ config('app.name', 'StreamNet') }}</title>
        <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
        <script>
            try {
                if (localStorage.getItem('theme') === 'light') {
                    document.documentElement.dataset.theme = 'light';
                }

                var boardLive = localStorage.getItem('board.live') === '1';
                document.cookie = 'board_live=' + (boardLive ? '1' : '0') + ';path=/;max-age=31536000;SameSite=Lax';
                var boardPaths = { '/': 1, '/bandwidth': 1, '/dhcp': 1, '/dns': 1, '/logs': 1, '/security': 1, '/pengaturan': 1 };
                if (boardPaths[location.pathname]) {
                    var params = new URLSearchParams(location.search);
                    if (boardLive && params.get('live') !== '1') {
                        params.delete('minutes');
                        params.delete('from');
                        params.delete('to');
                        params.set('live', '1');
                        location.replace(location.pathname + '?' + params.toString());
                    }
                }
            } catch (e) {}
        </script>
        @fonts
        <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@24,500,0,0" rel="stylesheet">
        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @inertiaHead
    </head>
    <body class="bg-canvas text-paper antialiased">
        @inertia
    </body>
</html>
