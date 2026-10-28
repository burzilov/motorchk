<?php
// 404 публичных страниц: ядро вызывает этот шаблон само (статус 404, noindex), без него — заглушка ядра.
// $pluginVars['requestPath'] — запрошенный путь. Подробнее — core/docs/12-themes.md.
$requestPath = (string) ($pluginVars['requestPath'] ?? '');
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($contentLocale ?? 'ru', ENT_QUOTES, 'UTF-8') ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex">
    <title><?= htmlspecialchars($title) ?></title>
    <link rel="icon" href="/core/assets/favicon.svg" type="image/svg+xml">
    <link href="<?= htmlspecialchars($themeAssetsUrl) ?>/css/app.css" rel="stylesheet">
    <?php require $config['core_path'] . '/includes/views/site-chrome.php'; ?>
    <script src="/core/assets/js/alpine.min.js" defer></script>
</head>
<body class="min-h-screen bg-slate-50 text-slate-900" x-data="{ mobileOpen: false }">
    <header class="border-b border-slate-200 bg-white">
        <div class="mx-auto flex max-w-5xl items-center justify-between px-4 py-4">
            <a href="/" class="text-lg font-bold text-slate-900">motorchk</a>
            <button type="button" class="rounded border border-slate-300 px-3 py-1 text-sm md:hidden" @click="mobileOpen = !mobileOpen">Меню</button>
            <nav class="hidden md:block" aria-label="Основное меню">
                <?php $menuMode = 'desktop'; require __DIR__ . '/partials/menu.php'; ?>
            </nav>
        </div>
        <nav class="border-t border-slate-200 bg-white px-4 py-3 md:hidden" x-show="mobileOpen" x-cloak aria-label="Основное меню">
            <?php $menuMode = 'mobile'; require __DIR__ . '/partials/menu.php'; ?>
        </nav>
    </header>
    <main class="mx-auto max-w-5xl px-4 py-10">
        <section class="rounded-2xl bg-white p-8 shadow-sm prose prose-slate max-w-none">
            <h1>Страница не найдена</h1>
            <p>Такой страницы нет<?php if ($requestPath !== ''): ?>: <code><?= htmlspecialchars($requestPath, ENT_QUOTES, 'UTF-8') ?></code><?php endif; ?>. Возможно, её удалили или в адресе опечатка.</p>
            <p><a href="/">На главную</a></p>
        </section>
    </main>
    <footer class="border-t border-slate-200 bg-white">
        <div class="mx-auto flex max-w-5xl flex-col gap-4 px-4 py-6 sm:flex-row sm:items-center sm:justify-between">
            <div class="text-sm text-slate-500">motorchk CMS</div>
            <?php require __DIR__ . '/partials/footer-menu.php'; ?>
        </div>
    </footer>
</body>
</html>
