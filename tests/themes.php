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
    file_put_contents($dir . '/plugin.json', json_encode($manifest + ['id' => $id, 'name' => $id, 'version' => '1.0.0', 'core' => '^0.4'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
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
