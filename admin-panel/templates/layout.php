<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Админка — motorchk</title>
    <link rel="icon" href="/core/assets/favicon.svg" type="image/svg+xml">
    <link href="/core/assets/css/admin.css" rel="stylesheet">
    <script src="/core/assets/js/htmx.min.js" defer></script>
    <script src="/core/assets/js/toast.js" defer></script>
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
                    <a href="/admin-panel/submissions" class="text-slate-600 hover:text-sky-700">Заявки</a>
                    <a href="/admin-panel/system" class="text-slate-600 hover:text-sky-700">Система</a>
                </nav>
            </div>
            <div class="flex items-center gap-4">
                <a href="/" target="_blank" rel="noopener noreferrer" class="rounded border border-slate-300 bg-white px-3 py-1.5 text-sm text-slate-700 hover:bg-slate-50">
                    На сайт
                </a>
                <span class="inline-flex items-center gap-1 text-xs text-slate-400">
                    v<?= htmlspecialchars(Version::current()) ?>
                    <?php if (!empty($coreUpdate) && !empty($coreUpdate['version'])): ?>
                        <?php
                        $updateHint = 'Доступна новая версия ядра: v' . $coreUpdate['version'];
                        ?>
                        <span
                            class="inline-flex text-amber-500"
                            title="<?= htmlspecialchars($updateHint, ENT_QUOTES, 'UTF-8') ?>"
                            aria-label="<?= htmlspecialchars($updateHint, ENT_QUOTES, 'UTF-8') ?>"
                        >
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-3.5 w-3.5" aria-hidden="true">
                                <path fill-rule="evenodd" d="M8.485 2.495c.673-1.167 2.357-1.167 3.03 0l6.28 10.875c.673 1.167-.168 2.625-1.516 2.625H3.72c-1.347 0-2.189-1.458-1.515-2.625L8.485 2.495zM10 6a.75.75 0 01.75.75v3.5a.75.75 0 01-1.5 0v-3.5A.75.75 0 0110 6zm0 9a1 1 0 100-2 1 1 0 000 2z" clip-rule="evenodd" />
                            </svg>
                        </span>
                    <?php endif; ?>
                </span>
                <form method="post" action="/admin-panel/logout" hx-post="/admin-panel/logout" hx-target="body">
                    <?= Csrf::field() ?>
                    <button type="submit" class="text-sm text-slate-600 hover:text-red-600">Выйти</button>
                </form>
            </div>
        </div>
    </header>
    <main class="mx-auto max-w-6xl px-4 py-8">
        <?= $content ?? '' ?>
    </main>
    <?php require dirname(__DIR__, 2) . '/includes/views/toast-host.php'; ?>
    <?php require dirname(__DIR__, 2) . '/includes/views/toast-boot.php'; ?>
</body>
</html>
