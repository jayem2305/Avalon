<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#0e1016">
    <title>Avalon</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@500;700&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @inertiaHead
</head>
<body>
    {{-- Shown until the app has started (removed in resources/js/app.js). --}}
    <div id="boot-skeleton" class="nav-skeleton" aria-busy="true" aria-label="Loading">
        @if (request()->is('r/*'))
            <div class="wrap">
                <header class="topbar"><div class="title"><h1>AVALON</h1></div></header>
                <div class="room-layout">
                    <main>
                        <div class="skel-panel"><span class="skeleton skel-title"></span><span class="skeleton skel-line" style="width: 60%"></span></div>
                        <div class="skel-panel"><div class="skel-circles">@for ($i = 0; $i < 5; $i++)<span class="skeleton"></span>@endfor</div></div>
                        <div class="skel-panel"><span class="skeleton skel-title" style="width: 30%"></span><span class="skeleton skel-line"></span><span class="skeleton skel-line" style="width: 80%"></span></div>
                        <div class="skel-panel"><span class="skeleton skel-map"></span></div>
                    </main>
                    <div class="room-side">
                        <div class="skel-panel"><span class="skeleton skel-card"></span></div>
                    </div>
                </div>
            </div>
        @else
            <div class="home">
                <div class="brand"><h1>AVALON</h1></div>
                <div class="skel-panel">
                    <span class="skeleton" style="height: 46px"></span>
                    <span class="skeleton" style="height: 46px; margin-top: 14px"></span>
                    <span class="skeleton" style="height: 56px; margin-top: 14px"></span>
                </div>
            </div>
        @endif
    </div>
    @inertia
</body>
</html>
