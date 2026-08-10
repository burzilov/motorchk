<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Админка — motorchk</title>
    <link rel="icon" href="/core/assets/favicon.svg" type="image/svg+xml">
    <link href="/core/assets/css/admin.css" rel="stylesheet">
    <script src="/core/assets/js/htmx.min.js" defer></script>
    <script src="/core/assets/js/alpine.min.js" defer></script>
    <style>[x-cloak]{display:none!important}</style>
</head>
<body class="min-h-screen bg-slate-100 text-slate-900">
    <header class="border-b border-slate-200 bg-white">
        <div class="mx-auto flex max-w-6xl items-center justify-between px-4 py-4">
            <div class="flex items-center gap-6">
                <nav class="flex gap-4 text-sm">
                    <a href="/admin-panel/pages" class="text-slate-600 hover:text-sky-700">Страницы</a>
                    <a href="/admin-panel/menu" class="text-slate-600 hover:text-sky-700">Меню</a>
                    <a href="/admin-panel/system" class="text-slate-600 hover:text-sky-700">Система</a>
                </nav>
            </div>
            <div class="flex items-center gap-4">
                <a href="/" target="_blank" rel="noopener noreferrer" class="rounded border border-slate-300 bg-white px-3 py-1.5 text-sm text-slate-700 hover:bg-slate-50">
                    На сайт
                </a>
                <span class="text-xs text-slate-400">v<?= htmlspecialchars(Version::current()) ?></span>
                <form method="post" action="/admin-panel/logout" hx-post="/admin-panel/logout" hx-target="body">
                    <?= Csrf::field() ?>
                    <button type="submit" class="text-sm text-slate-600 hover:text-red-600">Выйти</button>
                </form>
            </div>
        </div>
    </header>
    <?php if (!empty($coreUpdate) && !empty($coreUpdate['version'])): ?>
        <div class="border-b border-amber-200 bg-amber-50">
            <div class="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-3 px-4 py-3 text-sm text-amber-900">
                <span>Доступна новая версия ядра: <strong>v<?= htmlspecialchars($coreUpdate['version']) ?></strong> (сейчас v<?= htmlspecialchars(Version::current()) ?>)</span>
                <a href="/admin-panel/system" class="font-medium text-amber-900 underline hover:no-underline">Обновить</a>
            </div>
        </div>
    <?php endif; ?>
    <main class="mx-auto max-w-6xl px-4 py-8">
        <?= $content ?? '' ?>
    </main>
</body>
</html>
