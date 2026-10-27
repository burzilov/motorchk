<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php
    $layoutTitle = trim((string) ($pageTitle ?? ''));
    $layoutFullscreen = !empty($layoutFullscreen);
    $layoutPath = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?? '');
    $layoutNav = [
        '/admin-panel/pages' => 'Страницы',
        '/admin-panel/menu' => 'Меню',
        '/admin-panel/submissions' => 'Заявки',
        '/admin-panel/system' => 'Система',
    ];
    ?>
    <title><?= htmlspecialchars($layoutTitle !== '' ? $layoutTitle . ' — Админка motorchk' : 'Админка — motorchk') ?></title>
    <meta name="description" content="Админка сайта на motorchk">
    <link rel="icon" href="/core/assets/favicon.svg" type="image/svg+xml">
    <link href="/core/assets/css/admin.css" rel="stylesheet">
    <script src="/core/assets/js/htmx.min.js" defer></script>
    <script src="/core/assets/js/toast.js" defer></script>
    <?php foreach ($layoutScripts ?? [] as $layoutScript): ?><script src="<?= htmlspecialchars($layoutScript, ENT_QUOTES, 'UTF-8') ?>" defer></script>
    <?php endforeach; ?><script src="/core/assets/js/alpine.min.js" defer></script>
    <style>
        [x-cloak]{display:none!important}
        .mc-admin--fullscreen{display:flex;flex-direction:column;height:100vh;overflow:hidden}
        .mc-admin--fullscreen>header{flex:0 0 auto}
        .mc-admin--fullscreen>header>div{max-width:none;padding-top:.5rem;padding-bottom:.5rem}
        .mc-admin--fullscreen>main{flex:1 1 auto;min-height:0;position:relative;overflow:hidden}
    </style>
    <?php foreach ($layoutStyles ?? [] as $layoutStyle): ?>
        <link href="<?= htmlspecialchars($layoutStyle, ENT_QUOTES, 'UTF-8') ?>" rel="stylesheet">
    <?php endforeach; ?>
    <?php foreach ($layoutScriptsAfterAlpine ?? [] as $layoutScript): ?>
        <script src="<?= htmlspecialchars($layoutScript, ENT_QUOTES, 'UTF-8') ?>" defer></script>
    <?php endforeach; ?>
</head>
<body class="mc-admin<?= $layoutFullscreen ? ' mc-admin--fullscreen' : '' ?>">
    <header class="mc-bar">
        <div class="mc-bar__inner">
            <a href="/admin-panel/pages" class="mc-brand"><span class="mc-brand__square" aria-hidden="true"></span>motorchk</a>
            <nav class="mc-nav" aria-label="Разделы админки">
                <?php foreach ($layoutNav as $layoutNavUrl => $layoutNavLabel): ?>
                    <?php $layoutNavCurrent = $layoutPath === $layoutNavUrl || str_starts_with($layoutPath, $layoutNavUrl . '/'); ?>
                    <a href="<?= $layoutNavUrl ?>" class="mc-nav__link"<?= $layoutNavCurrent ? ' aria-current="page"' : '' ?>><?= $layoutNavLabel ?></a>
                <?php endforeach; ?>
                <?php foreach ($pluginMenu ?? [] as $pluginItem): ?>
                    <a href="<?= htmlspecialchars($pluginItem['url'], ENT_QUOTES, 'UTF-8') ?>" class="mc-nav__link mc-nav__link--plugin"<?= !empty($pluginItem['active']) ? ' aria-current="page"' : '' ?>><?= htmlspecialchars($pluginItem['label']) ?></a>
                <?php endforeach; ?>
            </nav>
            <div class="mc-bar__aside">
                <a href="/" target="_blank" rel="noopener noreferrer" class="mc-bar__link">
                    На сайт
                </a>
                <span class="mc-bar__version">
                    v<?= htmlspecialchars(Version::current()) ?>
                    <?php if (!empty($coreUpdate) && !empty($coreUpdate['version'])): ?>
                        <?php
                        $updateHint = 'Доступна новая версия ядра: v' . $coreUpdate['version'];
                        ?>
                        <span
                            class="mc-bar__update"
                            title="<?= htmlspecialchars($updateHint, ENT_QUOTES, 'UTF-8') ?>"
                            aria-label="<?= htmlspecialchars($updateHint, ENT_QUOTES, 'UTF-8') ?>"
                        >
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" width="14" height="14" aria-hidden="true">
                                <path fill-rule="evenodd" d="M8.485 2.495c.673-1.167 2.357-1.167 3.03 0l6.28 10.875c.673 1.167-.168 2.625-1.516 2.625H3.72c-1.347 0-2.189-1.458-1.515-2.625L8.485 2.495zM10 6a.75.75 0 01.75.75v3.5a.75.75 0 01-1.5 0v-3.5A.75.75 0 0110 6zm0 9a1 1 0 100-2 1 1 0 000 2z" clip-rule="evenodd" />
                            </svg>
                        </span>
                    <?php endif; ?>
                </span>
                <form method="post" action="/admin-panel/logout" hx-post="/admin-panel/logout" hx-target="body">
                    <?= Csrf::field() ?>
                    <button type="submit" class="mc-bar__link mc-bar__logout">Выйти</button>
                </form>
            </div>
        </div>
    </header>
    <?php if ($layoutFullscreen): ?>
        <main id="mc-admin-main">
            <?= $content ?? '' ?>
        </main>
    <?php else: ?>
        <main class="mc-main">
            <?= $content ?? '' ?>
        </main>
    <?php endif; ?>
    <?php require dirname(__DIR__, 2) . '/includes/views/toast-host.php'; ?>
    <?php require dirname(__DIR__, 2) . '/includes/views/toast-boot.php'; ?>
</body>
</html>
