<?php

/**
 * Тесты точек расширения 0.4.0 (без PHPUnit): php tests/i18n.php
 *
 * Службы плагинов (provides/service), хуки сайта (request, url, menu, head, output),
 * язык запроса (SiteLocale), переводы Markdown-страниц, hreflang, styles/head_scripts,
 * порядок скриптов относительно Alpine.js и поведение без службы i18n.
 * Тестовые плагины создаются во временном сайте; настоящий плагин i18n не нужен.
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
Env::set('APP_SECRET', 'i18n-test-secret');
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

$site = sys_get_temp_dir() . '/motorchk-i18n-test-' . getmypid();
ti_rm($site);
mkdir($site, 0775, true);
foreach (['content', 'themes', 'config'] as $dir) {
    ti_copy($corePath . '/skeleton/' . $dir, $site . '/' . $dir);
}
@mkdir($site . '/cache', 0775, true);
file_put_contents($site . '/cache/update-check.json', json_encode(['checked_at' => time() + 3600, 'current' => Version::current(), 'update' => null]));
file_put_contents($site . '/content/about.md', ti_page('О нас', 'Русский текст about.'));
file_put_contents($site . '/content/team.md', ti_page('Команда', 'Русский текст team.'));
file_put_contents($site . '/content/about.en.md', ti_page('About us', 'English about text.'));
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

out("motorchk i18n/hooks tests (core v" . Version::current() . ")\n");

// ---------------------------------------------------------------- без плагинов
out("\n[без плагинов — как до 0.4.0]\n");
$baseline = [];
foreach (['/about', '/team', '/about.en', '/en/about', '/'] as $uri) {
    $baseline[$uri] = $public($uri);
}
check($baseline['/about'][0] === 200 && str_contains($baseline['/about'][1], '<html lang="ru">') && !str_contains($baseline['/about'][1], 'hreflang'), '/about: lang="ru", без hreflang');
check($baseline['/about.en'][0] === 200 && str_contains($baseline['/about.en'][1], 'English about text.'), 'без службы i18n about.en.md — обычная страница /about.en (как раньше)');
check($baseline['/en/about'][0] === 404, '/en/about без i18n — 404');
check(SiteLocale::current() === 'ru' && SiteLocale::locales() === ['ru'] && !SiteLocale::isActive(), 'SiteLocale: ru, неактивен');
check(SiteLocale::localized(['en' => 'Item', 'ru' => 'Товар']) === 'Товар' && SiteLocale::localized(['ru' => '', 'en' => 'Item']) === 'Item' && SiteLocale::localized(['ru' => 'Товар', 'en' => 'Item'], 'en') === 'Item' && SiteLocale::localized(['ru' => '']) === null, 'SiteLocale::localized() без службы: язык → ru → первое непустое → null');
check(str_contains($baseline['/about'][1], "site-chrome.css\">\n    <script src=\"/core/assets/js/htmx.min.js\""), 'site-chrome.php без дополнений выводит ровно одну строку');

// ---------------------------------------------------------------- плагины без i18n
out("\n[плагины без службы i18n]\n");
ti_plugin($site, 'plain', ['public' => ['prefix' => 'plain']], <<<'PHP'
<?php
return static function (PluginContext $plugin): void {
    $plugin->publicRoute('GET', '/', static function (PluginRequest $r, PluginContext $p): void {
        $svc = $p->service('i18n');
        $p->renderPublic('page', ['svc' => $svc === null ? 'нет' : 'есть', 'url' => $p->publicUrl('/x'), 'locale' => $p->locale()], ['title' => 'Plain', 'styles' => [$p->assetUrl('p.css')], 'head_scripts' => [$p->assetUrl('early.js')], 'alternates' => true]);
    });
};
PHP, ['templates/page.php' => '<p id="plain">svc=<?= $svc ?> url=<?= $url ?> locale=<?= $locale ?></p>', 'assets/p.css' => 'p{}', 'assets/early.js' => '']);
foreach (['/about', '/team', '/about.en', '/en/about', '/'] as $uri) {
    check($public($uri) === $baseline[$uri], "{$uri}: ответ байт в байт как без плагинов");
}
[$st, $body] = $public('/plain/');
check($st === 200 && str_contains($body, 'svc=нет url=/plain/x locale=ru'), 'service(i18n) → null, publicUrl без префикса, locale ru', $body);
check(str_contains($body, '<link href="/plugin-assets/plain/p.css?v=1.0.0" rel="stylesheet">') && str_contains($body, '<script src="/plugin-assets/plain/early.js?v=1.0.0" defer></script>'), 'renderPublic: styles и head_scripts в <head>');
check(strpos($body, 'early.js') < strpos($body, 'alpine.min.js') && strpos($body, 'p.css') < strpos($body, '</head>'), 'head_scripts подключены до Alpine.js темы');
check(!str_contains($body, 'hreflang'), 'alternates без службы i18n игнорируются');

// ---------------------------------------------------------------- манифест
out("\n[plugin.json: provides и hooks]\n");
ti_plugin($site, 'bad-hooks', ['hooks' => ['request', 'nope']], "<?php return static function (PluginContext \$p): void {};\n");
ti_plugin($site, 'bad-provides', ['provides' => ['Bad Name']], "<?php return static function (PluginContext \$p): void {};\n");
ti_plugin($site, 'undeclared', ['public' => ['prefix' => 'undeclared']], "<?php return static function (PluginContext \$p): void { \$p->filter('url', fn(string \$u): string => \$u); };\n");
ti_plugin($site, 'zz-second', ['provides' => ['i18n']], "<?php return static function (PluginContext \$p): void {};\n");
$m = new PluginManager($config);
$all = $m->discover();
check($all['bad-hooks']['status'] === 'error' && str_contains(implode(' ', $all['bad-hooks']['errors']), 'неизвестный хук «nope»'), 'неизвестный хук — ошибка манифеста');
check($all['bad-provides']['status'] === 'error', 'некорректное имя службы — ошибка манифеста');
check($m->boot('undeclared') === null && str_contains(implode(' ', $m->discover()['undeclared']['errors']), 'не объявлен в plugin.json "hooks"'), 'хук без объявления в манифесте — ошибка загрузки');

// ---------------------------------------------------------------- служба i18n и хуки
out("\n[служба i18n и хуки]\n");
ti_plugin($site, 'langs', ['provides' => ['i18n'], 'hooks' => ['request', 'url', 'menu', 'head', 'output'], 'autoload' => ['Langs\\' => 'lib']], <<<'PHP'
<?php
return static function (PluginContext $plugin): void {
    $plugin->provide('i18n', new Langs\Service());
    $plugin->on('request', static function (SiteRequest $r): void {
        if ($r->path() === '/old') {
            $r->redirect('/about', 301);
            return;
        }
        if (preg_match('#^/en(/.*)?$#', $r->path(), $m)) {
            $r->setLocale('en');
            $r->setPath($m[1] ?? '/');
        }
    });
    $plugin->filter('url', static function (string $url, array $ctx): string {
        return ($ctx['locale'] ?? 'ru') === 'en' ? '/en' . ($url === '/' ? '' : $url) : $url;
    });
    $plugin->filter('menu', static function (array $menus, array $ctx): array {
        $menus['main'][] = ['label' => 'menu-filter-' . $ctx['locale'], 'slug' => null, 'url' => '#x', 'external' => false, 'active' => false, 'broken' => false, 'children' => []];
        return $menus;
    });
    $plugin->filter('head', static fn(string $head, array $ctx): string => $head . "    <meta name=\"x-locale\" content=\"{$ctx['locale']}\">\n");
    $plugin->filter('output', static fn(string $html): string => str_replace('</body>', '<!-- output-filter --></body>', $html));
    $plugin->filter('output', static fn(string $html): int => 42, 20); // неверный тип — игнорируется
    $plugin->filter('output', static function (string $html): string { throw new RuntimeException('boom'); }, 30);
};
PHP, ['lib/Service.php' => <<<'PHP'
<?php
namespace Langs;

final class Service implements \LocaleProvider
{
    public function locales(): array { return ['ru', 'en', 'Bad Code']; }
    public function defaultLocale(): string { return 'ru'; }
}
PHP]);
ti_plugin($site, 'shop', ['public' => ['prefix' => 'shop']], <<<'PHP'
<?php
return static function (PluginContext $plugin): void {
    $plugin->publicRoute('GET', '/{item}', static function (PluginRequest $r, PluginContext $p): void {
        $name = $p->localized(['ru' => 'Товар', 'en' => 'Item']);
        $p->renderPublic('item', ['name' => $name, 'link' => $p->publicUrl('/other'), 'ru' => $p->publicUrl('/other', 'ru'), 'svc' => get_class($p->service('i18n'))], ['title' => $name, 'alternates' => true]);
    });
};
PHP, ['templates/item.php' => '<p id="shop"><?= $name ?>|<?= $link ?>|<?= $ru ?>|<?= $svc ?></p>']);

$m = new PluginManager($config);
check($m->serviceOwner('i18n') === 'langs' && in_array('Служба «i18n» уже предоставляется плагином «langs» — объявление проигнорировано', $m->discover()['zz-second']['warnings'], true), 'две службы с одним именем — первая по алфавиту, вторая с предупреждением');
check($m->service('i18n') instanceof LocaleProvider && $m->service('nothing') === null, 'service(): объект владельца / null для неизвестной');

[$st, $ru] = $public('/about');
check($st === 200 && str_contains($ru, 'Русский текст about.') && str_contains($ru, '<html lang="ru">'), '/about — русский оригинал');
check(str_contains($ru, '<link rel="alternate" hreflang="ru" href="http://example.test/about">') && str_contains($ru, '<link rel="alternate" hreflang="en" href="http://example.test/en/about">') && str_contains($ru, 'hreflang="x-default" href="http://example.test/about"'), '/about: hreflang ru, en и x-default (абсолютные адреса)');
check(SiteLocale::locales() === ['ru', 'en'], 'некорректный код языка службы отброшен');
check(SiteLocale::localized(['ru' => 'Товар', 'en' => 'Item']) === 'Товар' && SiteLocale::localized(['ru' => 'Товар', 'en' => ''], 'en') === 'Товар' && SiteLocale::localized(['de' => 'Ware'], 'en') === 'Ware', 'SiteLocale::localized() со службой: язык → основной → первое непустое');
[$st, $en] = $public('/en/about');
check($st === 200 && str_contains($en, 'English about text.') && str_contains($en, '<html lang="en">') && !str_contains($en, 'Русский текст'), '/en/about — перевод about.en.md, <html lang="en"> (тема с lang="ru" исправлена ядром)');
check(str_contains($en, '<title>About us</title>') && !str_contains($en, 'rel="canonical"'), 'заголовок перевода, без canonical');
check(str_contains($en, 'href="/en/about"') && str_contains($en, '>About us</a>') && str_contains($en, 'href="/en/team"') && str_contains($en, 'href="https://example.com/"'), 'меню: фильтр url для внутренних ссылок, подпись из перевода, внешняя ссылка без изменений', preg_match('#<nav class="hidden.*?</nav>#s', $en, $mm) ? $mm[0] : 'нет nav');
check(str_contains($en, 'menu-filter-en') && str_contains($en, '<meta name="x-locale" content="en">') && str_contains($en, '<!-- output-filter --></body>'), 'фильтры menu, head и output');
[$st, $team] = $public('/en/team');
check($st === 200 && str_contains($team, 'Русский текст team.') && str_contains($team, '<html lang="ru">') && str_contains($team, '<link rel="canonical" href="http://example.test/team">') && !str_contains($team, 'hreflang'), '/en/team без перевода — оригинал, lang ru, canonical на /team, без hreflang');
check(str_contains($team, '>Команда</a>'), 'меню: без перевода — заголовок оригинала');
[$st] = $public('/about.en');
check($st === 404, 'файл перевода не открывается как /about.en, пока служба активна');
[$st, $body] = $public('/en/nope');
check($st === 404 && str_contains($body, '<html lang="en">'), '/en/nope — 404 на английском lang');
[$st, $body] = ti_capture(fn() => (new Router($config))->dispatchPublic('/old'));
check($st === 301 && $body === '', 'хук request может ответить сам (редирект)');
[$st, $body] = $public('/en/shop/a1');
check($st === 200 && str_contains($body, '<p id="shop">Item|/en/shop/other|/shop/other|Langs\\Service</p>'), 'плагин: localized(), publicUrl с языком, service() из обработчика', $body);
check(str_contains($body, 'hreflang="en" href="http://example.test/en/shop/a1"') && str_contains($body, 'hreflang="ru" href="http://example.test/shop/a1"'), 'страница плагина: alternates => true');
[$st, $body] = $public('/shop/a1');
check($st === 200 && str_contains($body, '<p id="shop">Товар|/shop/other|/shop/other|'), '/shop/a1 — основной язык');
[$st, $body] = $public('/');
check($st === 200 && !str_contains($body, 'hreflang'), 'главная без index.en.md — без hreflang');

// перевод правится вне админки — кеш сверяется с mtime
file_put_contents($site . '/content/about.en.md', ti_page('About', 'Updated English.'));
touch($site . '/content/about.en.md', time() + 5);
[, $body] = $public('/en/about');
check(str_contains($body, 'Updated English.'), 'изменённый файл перевода подхватывается без сброса кеша');
file_put_contents($site . '/content/team.en.md', ti_page('Team', 'Draft', ['published' => false]));
[, $body] = $public('/en/team');
check(str_contains($body, 'Русский текст team.') && !str_contains($body, 'hreflang'), 'неопубликованный перевод — показывается оригинал', preg_match_all('#<link rel="(?:alternate|canonical)"[^>]*>#', $body, $mm) ? implode(' ', $mm[0]) : 'нет link');

$tree = (new PageTree($config, new Cache($site . '/cache'), new PageLoader($config, new Cache($site . '/cache'), new MarkdownRenderer()), new MarkdownRenderer()));
SiteLocale::reset();
$slugsOff = $tree->getAllSlugs();
SiteLocale::init(new PluginManager($config), false);
$slugsOn = $tree->getAllSlugs();
$treeOn = array_column($tree->getTree(), 'slug');
check(in_array('about.en', $slugsOff, true) && !in_array('about.en', $slugsOn, true) && !in_array('about.en', $treeOn, true) && in_array('about', $treeOn, true), 'PageTree: переводы скрыты, только пока служба активна (кеш дерева общий)');

$_SESSION = ['admin_authenticated' => true, 'csrf_token' => 'tok'];
[$st, $body] = ti_capture(fn() => (new AdminRouter($config))->dispatchAdmin('/admin-panel/pages'));
check($st === 200 && str_contains($body, 'Команда') && !str_contains($body, 'about.en'), 'админка: список страниц без файлов переводов');
[$st, $body] = $public('/about');
check(str_contains($body, 'data-inline-edit="1"'), 'inline-edit на основном языке');
[$st, $body] = $public('/en/about');
check(!str_contains($body, 'data-inline-edit'), 'inline-edit выключен на других языках (он пишет в оригинал)');
$_SESSION = [];

// ---------------------------------------------------------------- админка и Alpine
out("\n[админка: скрипты и Alpine]\n");
ti_plugin($site, 'adm', ['admin' => ['menu' => 'Adm']], <<<'PHP'
<?php
return static function (PluginContext $plugin): void {
    $plugin->adminRoute('GET', '/', static fn(PluginRequest $r, PluginContext $p) => $p->render('a', [], ['scripts' => [$p->assetUrl('a.js')], 'scripts_after_alpine' => [$p->assetUrl('late.js')]]));
    $plugin->adminRoute('GET', '/plain', static fn(PluginRequest $r, PluginContext $p) => $p->render('a'));
};
PHP, ['templates/a.php' => '<p>adm</p>', 'assets/a.js' => '', 'assets/late.js' => '']);
$_SESSION = ['admin_authenticated' => true, 'csrf_token' => 'tok'];
$m = new PluginManager($config);
$ctx = $m->boot('adm');
$router = new AdminRouter($config);
[$st, $body] = ti_capture(fn() => $router->dispatchAdmin('/admin-panel/plugins/adm'));
$a = strpos($body, 'a.js'); $alpine = strpos($body, 'alpine.min.js'); $late = strpos($body, 'late.js');
check($st === 200 && $a !== false && $a < $alpine && $late > $alpine, 'scripts — до Alpine.js, scripts_after_alpine — после');
[$st, $plain] = ti_capture(fn() => (new AdminRouter($config))->dispatchAdmin('/admin-panel/plugins/adm/plain'));
check(str_contains($plain, "toast.js\" defer></script>\n    <script src=\"/core/assets/js/alpine.min.js\" defer></script>\n    <style>"), 'без скриптов плагина <head> админки как в 0.3');
$_SESSION = [];

ob_end_clean();
ti_rm($site);
out("\n{$passed} passed, {$failures} failed\n");
exit($failures === 0 ? 0 : 1);
