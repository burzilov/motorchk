<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($title) ?></title>
    <?php if ($description !== ''): ?>
        <meta name="description" content="<?= htmlspecialchars($description) ?>">
    <?php endif; ?>
    <meta property="og:title" content="<?= htmlspecialchars($ogTitle) ?>">
    <?php if ($ogDescription !== ''): ?>
        <meta property="og:description" content="<?= htmlspecialchars($ogDescription) ?>">
    <?php endif; ?>
    <?php if ($ogImage !== ''): ?>
        <meta property="og:image" content="<?= htmlspecialchars($ogImage) ?>">
    <?php endif; ?>
    <link rel="icon" href="/core/assets/favicon.svg" type="image/svg+xml">
    <link href="<?= htmlspecialchars($themeAssetsUrl) ?>/css/app.css" rel="stylesheet">
    <script src="/core/assets/js/htmx.min.js" defer></script>
    <script src="/core/assets/js/alpine.min.js" defer></script>
</head>
<body
    class="min-h-screen bg-white text-slate-900"
    x-data="{ mobileOpen: false }"
    <?php if (!empty($canEdit)): ?>
        data-inline-edit="1"
        data-slug="<?= htmlspecialchars($slug, ENT_QUOTES, 'UTF-8') ?>"
        data-csrf="<?= htmlspecialchars($inlineEditCsrf ?? '', ENT_QUOTES, 'UTF-8') ?>"
        data-save-url="<?= htmlspecialchars($inlineEditSaveUrl ?? '', ENT_QUOTES, 'UTF-8') ?>"
    <?php endif; ?>
>
    <?php require __DIR__ . '/partials/preview-banner.php'; ?>
    <header class="border-b border-slate-200">
        <div class="mx-auto flex max-w-4xl items-center justify-between px-4 py-4">
            <a href="/" class="text-lg font-bold">motorchk</a>
            <button type="button" class="rounded border px-3 py-1 text-sm md:hidden" @click="mobileOpen = !mobileOpen">Меню</button>
            <nav class="hidden md:block" aria-label="Основное меню">
                <?php $menuMode = 'desktop'; require __DIR__ . '/partials/menu.php'; ?>
            </nav>
        </div>
        <nav class="border-t px-4 py-3 md:hidden" x-show="mobileOpen" x-cloak aria-label="Основное меню">
            <?php $menuMode = 'mobile'; require __DIR__ . '/partials/menu.php'; ?>
        </nav>
    </header>
    <main class="mx-auto max-w-3xl px-4 py-12">
        <article class="prose prose-slate max-w-none<?= !empty($canEdit) ? ' editable' : '' ?>" <?php if (!empty($canEdit)): ?>data-block="content"<?php endif; ?>>
            <?= $blocks['content'] ?? '' ?>
        </article>
    </main>
    <?php require __DIR__ . '/partials/scripts.php'; ?>
</body>
</html>
