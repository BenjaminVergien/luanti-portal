<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Meine Zugänge · Luanti-Portal</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="min-h-screen bg-stone-50 text-stone-900 antialiased">
    <header class="border-b border-stone-200 bg-white">
        <div class="mx-auto flex max-w-5xl items-center justify-between px-6 py-5">
            <a href="{{ route('access') }}" class="text-xl font-semibold">Luanti-Portal</a>
            <form method="POST" action="{{ route('logout') }}">@csrf <button class="rounded-lg border border-stone-300 px-4 py-2">Abmelden</button></form>
        </div>
    </header>
    <main class="mx-auto max-w-5xl px-6 py-10">{{ $slot }}</main>
    @livewireScripts
</body>
</html>
