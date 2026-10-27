<?php

/**
 * Тесты поддержки тем (без PHPUnit): php tests/themes.php
 *
 * Страницы без og_*, сверка кеша страниц/дерева/меню с файлами, папка media/,
 * нейтральный MenuRenderer и меню темы-примера.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$corePath = dirname(__DIR__);
require_once $corePath . '/vendor/autoload.php';
spl_autoload_register(static function (string $class) use ($corePath): void {
    $file = $corePath . '/includes/' . $class . '.php';
    if (is_file($file)) {
        require $file;
    }
});

ob_start();
Env::set('APP_SECRET', 'themes-test-secret');
$failures = 0;
$passed = 0;

function out(string $text): void
{
    fwrite(STDOUT, $text);
}

function check(bool $ok, string $name, string $details = ''): void
{
    global $failures, $passed;
    if ($ok) {
        $passed++;
        out("  ok   {$name}\n");
        return;
    }
    $failures++;
    out("  FAIL {$name}" . ($details !== '' ? " — {$details}" : '') . "\n");
}

function ti_copy(string $from, string $to): void
{
    if (is_dir($from)) {
        @mkdir($to, 0775, true);
        foreach (scandir($from) ?: [] as $item) {
            if ($item !== '.' && $item !== '..') {
                ti_copy($from . '/' . $item, $to . '/' . $item);
            }
        }
        return;
    }
    copy($from, $to);
}

function ti_rm(string $path): void
{
    if (is_link($path) || is_file($path)) {
        unlink($path);
        return;
    }
    if (!is_dir($path)) {
        return;
    }
    foreach (scandir($path) ?: [] as $item) {
        if ($item !== '.' && $item !== '..') {
            ti_rm($path . '/' . $item);
        }
    }
    rmdir($path);
}

function ti_page(string $title, string $body, array $extra = []): string
{
    $fm = $extra + ['title' => $title, 'published' => true, 'template' => 'default', 'og_title' => "''", 'og_description' => "''"];
    $yaml = '';
    foreach ($fm as $k => $v) {
        $yaml .= $k . ': ' . (is_bool($v) ? ($v ? 'true' : 'false') : $v) . "\n";
    }

    return "---\n{$yaml}---\n\n## block: content\n\n{$body}\n";
}

function ti_plugin(string $site, string $id, array $manifest, string $entry, array $files = []): void
{
    $dir = $site . '/plugins/' . $id;
    @mkdir($dir, 0775, true);
    file_put_contents($dir . '/plugin.json', json_encode($manifest + ['id' => $id, 'name' => $id, 'version' => '1.0.0', 'core' => '^0.5'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    file_put_contents($dir . '/plugin.php', $entry);
    foreach ($files as $name => $content) {
        @mkdir(dirname($dir . '/' . $name), 0775, true);
        file_put_contents($dir . '/' . $name, $content);
    }
}

function ti_capture(callable $fn): array
{
    http_response_code(200);
    ob_start();
    try {
        $fn();
    } finally {
        $body = (string) ob_get_clean();
    }

    return [http_response_code(), $body];
}

$site = sys_get_temp_dir() . '/motorchk-themes-test-' . getmypid();
ti_rm($site);
mkdir($site, 0775, true);
foreach (['content', 'themes', 'config'] as $dir) {
    ti_copy($corePath . '/skeleton/' . $dir, $site . '/' . $dir);
}
@mkdir($site . '/cache', 0775, true);
file_put_contents($site . '/cache/update-check.json', json_encode(['checked_at' => time() + 3600, 'current' => Version::current(), 'update' => null]));
file_put_contents($site . '/content/about.md', ti_page('О нас', 'Русский текст about.'));
file_put_contents($site . '/content/team.md', ti_page('Команда', 'Русский текст team.'));
file_put_contents($site . '/content/bare.md', "---\ntitle: Без og\ndescription: Описание без og\n---\n\n## block: content\n\nТекст.\n");
file_put_contents($site . '/content/_menus.yaml', "menus:\n  main:\n    label: Основное\n    items:\n      - slug: about\n      - slug: team\n      - label: Внешняя\n        url: https://example.com/\n        external: true\n");
$config = [
    'site_path' => $site, 'core_path' => $corePath, 'base_path' => $site,
    'content_path' => $site . '/content', 'cache_path' => $site . '/cache', 'themes_path' => $site . '/themes',
    'forms_path' => $site . '/content/forms', 'submissions_path' => $site . '/submissions',
    'admin_templates_path' => $corePath . '/admin-panel/templates', 'menu_file' => $site . '/content/_menus.yaml',
    'skeleton_path' => $corePath . '/skeleton', 'plugins_path' => $site . '/plugins',
    'plugin_data_path' => $site . '/plugin-data', 'installed' => true,
];
$_SESSION = [];
$_GET = $_POST = $_FILES = $_COOKIE = [];
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTP_HOST'] = 'example.test';
$public = static fn(string $uri): array => ti_capture(fn() => (new Router($config))->dispatchPublic($uri));


out("motorchk themes tests (core v" . Version::current() . ")\n");

$warnings = [];
set_error_handler(static function (int $no, string $msg, string $file, int $line) use (&$warnings): bool {
    $warnings[] = "{$msg} ({$file}:{$line})";
    return true;
});

// ---------------------------------------------------------------- og_*
out("\n[front matter без og_title/og_description]\n");
[$st, $body] = $public('/bare');
check($st === 200 && str_contains($body, 'Текст.'), '/bare без og_* — 200');
check($warnings === [], 'без предупреждений PHP', implode('; ', $warnings));
check(str_contains($body, 'Без og'), 'og_title по умолчанию — title');

// ---------------------------------------------------------------- кеш
out("\n[кеш страниц сверяется с файлами]\n");
$aboutFile = $site . '/content/about.md';
[, $body] = $public('/about');
check(str_contains($body, 'Русский текст about.'), 'страница в кеше');
file_put_contents($aboutFile, ti_page('О нас', 'Новый текст после правки файлом.'));
touch($aboutFile, time() + 5);
[, $body] = $public('/about');
check(str_contains($body, 'Новый текст после правки файлом.'), 'изменённый mtime — страница перечитана');
$mtime = filemtime($aboutFile);
file_put_contents($aboutFile, ti_page('О нас', 'Тот же mtime, другой размер текста!'));
touch($aboutFile, $mtime);
[, $body] = $public('/about');
check(str_contains($body, 'Тот же mtime, другой размер текста!'), 'тот же mtime, другой размер — страница перечитана');
[, $body] = $public('/about');
check(str_contains($body, 'Тот же mtime, другой размер текста!'), 'повторный запрос — тот же текст');

file_put_contents($site . '/content/team.md', ti_page('Наша команда', 'Русский текст team.'));
touch($site . '/content/team.md', time() + 7);
[, $body] = $public('/about');
check(str_contains($body, 'Наша команда'), 'заголовок страницы изменён файлом — подпись меню обновилась');
file_put_contents($site . '/content/_menus.yaml', "menus:\n  main:\n    label: Основное\n    items:\n      - slug: about\n      - label: Добавлено файлом\n        url: /team\n");
touch($site . '/content/_menus.yaml', time() + 9);
[, $body] = $public('/team');
check(str_contains($body, 'Добавлено файлом'), '_menus.yaml изменён файлом — меню обновилось');
file_put_contents($site . '/content/news.md', ti_page('Новости', 'Текст новостей.'));
$cache = new Cache($site . '/cache');
$loader = new PageLoader($config, $cache, new MarkdownRenderer());
$tree = new PageTree($config, $cache, $loader, new MarkdownRenderer());
check(in_array('news', $tree->getAllSlugs(), true) && in_array('news', array_column($tree->getTree(), 'slug'), true), 'новый файл — в дереве страниц без очистки кеша');
$before = $loader->contentStamp();
check($before === $loader->contentStamp(), 'contentStamp() стабилен без изменений');
unlink($site . '/content/news.md');
check($before !== $loader->contentStamp() && !in_array('news', array_column($tree->getTree(), 'slug'), true), 'удалённый файл — отпечаток и дерево меняются');
check(PageLoader::fileStamp($site . '/content/nope.md') === null, 'fileStamp(): нет файла — null');

// ---------------------------------------------------------------- меню
out("\n[MenuRenderer и данные меню]\n");
$items = [
    ['label' => 'Каталог <b>', 'slug' => 'equipment', 'url' => '/equipment', 'external' => false, 'active' => true, 'current' => false, 'children' => [
        ['label' => 'Щитки', 'slug' => 'equipment/pads', 'url' => '/equipment/pads', 'external' => false, 'active' => true, 'current' => true, 'children' => [
            ['label' => 'P5', 'slug' => 'equipment/pads/p5', 'url' => '/equipment/pads/p5', 'external' => false, 'active' => false, 'current' => false, 'children' => []],
        ]],
    ]],
    ['label' => 'VK', 'slug' => null, 'url' => 'https://vk.com/x?a=1&b=2', 'external' => true, 'active' => false, 'current' => false, 'children' => []],
];
$html = MenuRenderer::html($items, ['class' => 'nav']);
check(str_starts_with($html, '<ul class="nav"><li class="nav__item is-active has-children"><a class="nav__link" href="/equipment">Каталог &lt;b&gt;</a><ul class="nav__sub">'), 'корень, классы, экранирование подписи', $html);
check(str_contains($html, '<a class="nav__link" href="/equipment/pads" aria-current="page">Щитки</a>'), 'aria-current только у текущей страницы');
check(str_contains($html, 'href="https://vk.com/x?a=1&amp;b=2" target="_blank" rel="noopener">VK</a>'), 'внешняя ссылка: target, rel, экранирование адреса');
check(!preg_match('/x-data|@click|x-show|text-slate|rounded|flex/', $html), 'без классов Tailwind и атрибутов Alpine');
check(!str_contains(MenuRenderer::html($items, ['class' => 'nav', 'depth' => 1]), 'nav__sub') && !str_contains(MenuRenderer::html($items, ['depth' => 1]), 'has-children'), 'depth 1 — без подменю и has-children');
check(str_contains(MenuRenderer::html($items, ['class' => '"><x']), '<ul class="menu">') && MenuRenderer::html([]) === '', 'недопустимый класс — menu; пустое меню — пустая строка');
ob_start();
MenuRenderer::render($items);
check(str_starts_with((string) ob_get_clean(), '<ul class="menu">'), 'render() выводит то же, что html()');

$menuWriter = new MenuWriter($config, $cache);
$builder = new MenuBuilder($config, $cache, $loader, $tree, $menuWriter);
file_put_contents($site . '/content/_menus.yaml', "menus:\n  main:\n    label: Основное\n    items:\n      - slug: index\n        label: Главная\n      - slug: about\n      - label: Раздел плагина\n        url: /shop/\n      - label: Якорь\n        url: '#top'\n");
$byLabel = static fn(array $list): array => array_column($list, null, 'label');
$m = $byLabel($builder->build('shop/item'));
check($m['Раздел плагина']['active'] && !$m['Раздел плагина']['current'], 'url-пункт /shop/ активен на shop/item, но не текущий');
check(!$m['Главная']['active'] && !$m['Якорь']['active'], 'главная и якорь неактивны внутри раздела');
$m = $byLabel($builder->build('shop'));
check($m['Раздел плагина']['current'], 'url-пункт /shop/ — текущий на shop');
$m = $byLabel($builder->build('index'));
check($m['Главная']['current'] && $m['Главная']['active'] && !$m['О нас']['active'], 'главная текущая на index');

[, $body] = $public('/about');
check(str_contains($body, '<ul class="flex items-center gap-1">') && str_contains($body, 'aria-current="page"'), 'тема-пример рисует меню сама (Tailwind), aria-current у текущей страницы');

// ---------------------------------------------------------------- media
out("\n[media/]\n");
check(Media::url('products/p5-1.webp') === '/media/products/p5-1.webp' && Media::url('/a/b.png') === '/media/a/b.png', 'Media::url()');
$bad = 0;
foreach (['../x.png', 'a/../b.png', '.htaccess', 'a/.hidden.png', 'p5 1.webp'] as $p) {
    try {
        Media::url($p);
    } catch (InvalidArgumentException) {
        $bad++;
    }
}
check($bad === 5 && Media::path($config, '../x') === null, 'небезопасные пути отклонены');
check(Media::path($config + ['media_path' => $site . '/media'], 'a/b.png') === $site . '/media/a/b.png', 'Media::path() — путь в media_path');
check(!is_dir($site . '/media'), 'до миграции media/ нет');
require_once $corePath . '/install/migrate.php';
motorchk_migrate($config, Version::current());
$ht = (string) @file_get_contents($site . '/media/.htaccess');
check(is_dir($site . '/media') && $ht === file_get_contents($corePath . '/skeleton/media/.htaccess'), 'migrate создаёт media/ и .htaccess из skeleton');
check(str_contains($ht, 'php_flag engine off') && str_contains($ht, 'Require all denied') && str_contains($ht, 'Options -Indexes'), '.htaccess: без PHP, по умолчанию запрет, без листинга');
file_put_contents($site . '/media/.htaccess', "# своё\n");
Media::ensure($config);
check(file_get_contents($site . '/media/.htaccess') === "# своё\n", 'существующий .htaccess не перезаписывается');
check(in_array('media', PluginManager::RESERVED_PREFIXES, true), 'префикс media зарезервирован');

out("\n[core/.htaccess: /core/]\n");
$coreHt = (string) @file_get_contents($corePath . '/.htaccess');
$rootHt = (string) file_get_contents($corePath . '/skeleton/.htaccess');
$hasCoreBlock = preg_match('~<If "-z %\{ENV:REDIRECT_STATUS\}">\s*RedirectMatch 404 (\S+)\s*</If>~', $coreHt, $htm) === 1;
check($hasCoreBlock && $htm[1] === '^/core/(?!assets/)', 'core/.htaccess: /core/ кроме assets/ — 404, только для запроса клиента (REDIRECT_STATUS пуст)');
check(!str_contains($rootHt, '/core/(') && preg_match('~^RedirectMatch \d+ \^/core/~m', $rootHt) === 0, 'skeleton/.htaccess: правил для /core/ нет — они в core/.htaccess и обновляются с ядром');
check(str_contains($rootHt, 'RewriteRule ^admin-panel(/.*)?$ core/admin-panel/index.php [L,QSA]'), 'админка — внутренний переход /admin-panel → core/admin-panel/index.php');
$coreRe = '~' . ($htm[1] ?? '^$') . '~';
$closed = ['/core/VERSION', '/core/README.md', '/core/CHANGELOG.md', '/core/composer.json', '/core/composer.lock', '/core/', '/core/admin-panel/index.php', '/core/includes/bootstrap.php', '/core/vendor/autoload.php'];
$open = ['/core/assets/css/admin.css', '/core/assets/js/htmx.min.js', '/core/assets/favicon.svg', '/admin-panel/login', '/customizer/', '/plugin-assets/x/a.js', '/themes/example/assets/css/app.css'];
check(array_filter($closed, static fn(string $u): bool => preg_match($coreRe, $u) !== 1) === [] && array_filter($open, static fn(string $u): bool => preg_match($coreRe, $u) === 1) === [], 'правило: служебные файлы ядра закрыты, /core/assets/ и маршруты сайта открыты');
$coreUrls = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($corePath, FilesystemIterator::SKIP_DOTS));
foreach ($it as $file) {
    $rel = substr($file->getPathname(), strlen($corePath) + 1);
    if (!preg_match('~^(admin-panel|includes|skeleton|assets/js)/.*\.(php|js|html)$~', $rel) || str_ends_with($rel, '.min.js')) {
        continue;
    }
    preg_match_all('~(?:href|src|action|url|fetch)\s*[=(]\s*["\'](/core/[^"\'?#]*)~', (string) file_get_contents($file->getPathname()), $mm);
    foreach ($mm[1] as $u) {
        $coreUrls[$u] = $rel;
    }
}
$blocked = array_filter(array_keys($coreUrls), static fn(string $u): bool => preg_match($coreRe, $u) === 1);
check($coreUrls !== [] && $blocked === [], 'ссылки ядра (админка, тема-пример, представления) ведут только в /core/assets/ (' . count($coreUrls) . ')', implode(', ', $blocked));

out("\n[релиз: установочный zip и zip ядра]\n");
$wf = (string) @file_get_contents($corePath . '/.github/workflows/release.yml');
$build = (string) @file_get_contents($corePath . '/.github/build-release.sh');
check(str_contains($wf, 'bash .github/build-release.sh') && str_contains($wf, '/motorchk-core-${{ env.VERSION }}.zip') && str_contains($wf, '/motorchk-install-${{ env.VERSION }}.zip'), 'release.yml: оба zip собирает build-release.sh и публикует в релизе');
check(str_contains($build, 'CORE_STAGE="motorchk-core-${VERSION}"') && str_contains($build, 'zip -qr "$OUT/${CORE_STAGE}.zip" "$CORE_STAGE"'), 'motorchk-core-X.zip: прежний формат (папка motorchk-core-X/) — для self-update');
check(str_contains($build, 'cp "$INST/core/skeleton/.htaccess" "$INST/.htaccess"') && str_contains($build, 'cp "$INST/core/skeleton/index.php" "$INST/index.php"') && str_contains($build, 'zip -qr "$OUT/motorchk-install-${VERSION}.zip" .htaccess index.php core'), 'motorchk-install-X.zip: core/ и корневые .htaccess, index.php из skeleton');
$findZip = Closure::bind(fn(array $r, string $v): ?string => $this->findZipAssetUrl($r, $v), new Updater([]), Updater::class);
$assets = ['assets' => [['name' => 'motorchk-install-9.9.9.zip', 'browser_download_url' => 'https://x/install.zip'], ['name' => 'motorchk-core-9.9.9.zip', 'browser_download_url' => 'https://x/core.zip']]];
check($findZip($assets, '9.9.9') === 'https://x/core.zip', 'обновление берёт motorchk-core-X.zip, установочный zip не трогает');
$inst = sys_get_temp_dir() . '/mk-inst-' . bin2hex(random_bytes(4));
mkdir($inst, 0755, true);
copy($corePath . '/skeleton/.htaccess', $inst . '/.htaccess');
file_put_contents($inst . '/index.php', "<?php // свой\n");
$copySkeleton = Closure::bind(fn(string $from, string $to) => $this->copySkeleton($from, $to), new Installer(['site_path' => $inst]), Installer::class);
$copySkeleton($corePath . '/skeleton', $inst);
check(file_get_contents($inst . '/index.php') === "<?php // свой\n" && file_get_contents($inst . '/.htaccess') === file_get_contents($corePath . '/skeleton/.htaccess'), 'установщик: существующие файлы (.htaccess, index.php) не перезаписывает');
check(is_file($inst . '/.env.example') && is_file($inst . '/media/.htaccess') && is_dir($inst . '/themes/example'), 'установщик: копирует недостающее, включая скрытые файлы skeleton');
ti_rm($inst);

out("\n[службы плагинов в теме]\n");
ti_plugin($site, 'demo', ['provides' => ['demo']], "<?php\nreturn static function (PluginContext \$plugin): void { \$plugin->provide('demo', new class { public function hello(): string { return 'привет из службы'; } }); };\n");
file_put_contents($site . '/themes/example/probe.php', "<?php echo 'svc=', \$plugins?->service('demo')?->hello() ?? 'нет', ';none=', \$plugins?->service('nope') === null ? 'null' : 'obj';\n");
file_put_contents($site . '/content/probe.md', "---\ntitle: Проба\ntemplate: probe\n---\n\n## block: content\n\nx\n");
[$st, $body] = $public('/probe');
check($st === 200 && $body === 'svc=привет из службы;none=null', 'шаблон темы: $plugins->service() — объект службы, неизвестная — null', $body);
restore_error_handler();
ti_rm($site);
ob_end_clean();
out("\n{$passed} passed, {$failures} failed\n");
exit($failures > 0 ? 1 : 0);
