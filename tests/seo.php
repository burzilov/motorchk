<?php

/**
 * Тесты служебных файлов (без PHPUnit): php tests/seo.php
 *
 * /sitemap.xml, /robots.txt, /llms.txt из опубликованных страниц: черновики, noindex, sitemap: false,
 * служебные файлы и префиксы плагинов исключены; настройки seo в config/site.php; hreflang при i18n;
 * фильтры плагинов sitemap/robots/llms.
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
Env::set('APP_SECRET', 'seo-test-secret');
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
    file_put_contents($dir . '/plugin.json', json_encode($manifest + ['id' => $id, 'name' => $id, 'version' => '1.0.0', 'core' => '>=0.5.0'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
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

$site = sys_get_temp_dir() . '/motorchk-seo-test-' . getmypid();
ti_rm($site);
mkdir($site, 0775, true);
foreach (['content', 'themes', 'config'] as $dir) {
    ti_copy($corePath . '/skeleton/' . $dir, $site . '/' . $dir);
}
@mkdir($site . '/cache', 0775, true);
file_put_contents($site . '/cache/update-check.json', json_encode(['checked_at' => time() + 3600, 'current' => Version::current(), 'update' => null]));
file_put_contents($site . '/content/about.md', ti_page('О нас', 'Текст.', ['description' => "'Кто мы и чем занимаемся'"]));
file_put_contents($site . '/content/draft.md', ti_page('Черновик', 'Текст.', ['published' => false]));
file_put_contents($site . '/content/secret.md', ti_page('Секрет', 'Текст.', ['noindex' => true]));
file_put_contents($site . '/content/thanks.md', ti_page('Спасибо', 'Текст.', ['sitemap' => false]));
file_put_contents($site . '/content/_partial.md', ti_page('Фрагмент', 'Текст.'));
@mkdir($site . '/content/_drafts', 0775, true);
file_put_contents($site . '/content/_drafts/idea.md', ti_page('Идея', 'Текст.'));
@mkdir($site . '/content/services', 0775, true);
file_put_contents($site . '/content/services/audit.md', ti_page('Аудит & <проверка>', 'Текст.'));
file_put_contents($site . '/content/shop.md', ti_page('Магазин-страница', 'Текст.'));
touch($site . '/content/about.md', strtotime('2026-01-02 03:04:05'));
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
unset($_SERVER['HTTPS'], $_SERVER['SERVER_PORT']);
$public = static fn(string $uri, ?array $cfg = null): array => ti_capture(fn() => (new Router($cfg ?? $config))->dispatchPublic($uri));

out("motorchk seo tests (core v" . Version::current() . ")\n");

out("\n[без плагинов, настройки по умолчанию]\n");
[$st, $xml] = $public('/sitemap.xml');
$doc = @simplexml_load_string($xml);
check($st === 200 && $doc !== false, '/sitemap.xml — 200, корректный XML', $xml);
$locs = [];
foreach ($doc !== false ? $doc->url : [] as $url) {
    $locs[] = (string) $url->loc;
}
check($locs === ['http://example.test/', 'http://example.test/about', 'http://example.test/services/audit', 'http://example.test/shop'], 'в sitemap — главная и опубликованные страницы, абсолютные адреса', implode(' ', $locs));
foreach (['draft' => 'черновик (published: false)', 'secret' => 'noindex: true', 'thanks' => 'sitemap: false', '_partial' => 'файл на «_»', '_drafts' => 'папка на «_»'] as $needle => $why) {
    check(!str_contains($xml, '/' . $needle), "исключено: {$why}");
}
check(str_contains($xml, '<lastmod>' . date('c', strtotime('2026-01-02 03:04:05')) . '</lastmod>'), 'lastmod — mtime файла в формате W3C', $xml);
check(str_contains($xml, '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">') && !str_contains($xml, 'xhtml'), 'без i18n — без xhtml:link');

$_SERVER['REQUEST_METHOD'] = 'HEAD';
[$st] = $public('/sitemap.xml');
check($st === 200, 'HEAD /sitemap.xml — 200');
$_SERVER['REQUEST_METHOD'] = 'POST';
[$st] = $public('/robots.txt');
check($st === 405, 'POST /robots.txt — 405');
$_SERVER['REQUEST_METHOD'] = 'GET';

[$st, $robots] = $public('/robots.txt');
check($st === 200 && str_starts_with($robots, "User-agent: *\nAllow: /\n"), '/robots.txt — 200, User-agent и Allow: /', $robots);
check(str_contains($robots, "Disallow: /admin-panel\n") && str_contains($robots, "Disallow: /api/\n") && str_contains($robots, "Disallow: /*?preview=\n"), 'Disallow: админка, API, предпросмотр', $robots);
check(str_contains($robots, "Sitemap: http://example.test/sitemap.xml\n"), 'Sitemap: абсолютный адрес', $robots);
check(!str_contains($robots, '/core'), '/core/assets не закрыт (CSS и JS нужны поисковикам)');

[$st, $llms] = $public('/llms.txt');
check($st === 200 && str_starts_with($llms, "# Добро пожаловать\n\n> Сайт на motorchk CMS\n"), 'llms.txt: заголовок и описание — с главной', $llms);
check(str_contains($llms, "## Страницы\n") && str_contains($llms, '- [О нас](http://example.test/about): Кто мы и чем занимаемся'), 'llms.txt: список страниц со ссылками и описаниями', $llms);
check(!str_contains($llms, 'Черновик') && !str_contains($llms, 'Секрет') && !str_contains($llms, 'Спасибо'), 'llms.txt: без черновиков, noindex и sitemap: false');
file_put_contents($site . '/content/_llms.txt', "## Контакты\n\n- Email: hello@example.test");
[, $llms] = $public('/llms.txt');
check(str_ends_with($llms, "## Контакты\n\n- Email: hello@example.test\n"), 'content/_llms.txt дописывается в конец llms.txt', $llms);
[$st] = $public('/_llms');
check($st === 404, 'content/_llms.txt не открывается как страница');

[$st, $page] = $public('/secret');
check($st === 200 && str_contains($page, 'Секрет'), 'страница с noindex: true открывается (заголовок X-Robots-Tag: noindex)');
$router = (string) file_get_contents($corePath . '/includes/Router.php');
check(str_contains($router, "SeoFiles::noindex(\$page['front_matter'])"), 'Router: noindex: true → X-Robots-Tag: noindex');

out("\n[настройки seo в config/site.php]\n");
$custom = $config + ['seo' => ['site_url' => 'https://example.ru/', 'robots_disallow' => ['/private/', 'bad rule', '*?utm_'], 'site_name' => 'Пример', 'llms_description' => "Описание\nв две строки"]];
[, $xml] = $public('/sitemap.xml', $custom);
check(str_contains($xml, '<loc>https://example.ru/about</loc>') && !str_contains($xml, 'example.test'), 'site_url — адрес в sitemap', $xml);
[, $robots] = $public('/robots.txt', $custom);
check(str_contains($robots, "Disallow: /private/\n") && str_contains($robots, "Disallow: *?utm_\n") && !str_contains($robots, 'bad rule'), 'robots_disallow: свои правила, некорректные отброшены', $robots);
check(str_contains($robots, 'Sitemap: https://example.ru/sitemap.xml'), 'Sitemap: с site_url');
[, $llms] = $public('/llms.txt', $custom);
check(str_starts_with($llms, "# Пример\n\n> Описание в две строки\n") && str_contains($llms, '(https://example.ru/about)'), 'site_name и llms_description', $llms);
[, $robots] = $public('/robots.txt', $config + ['seo' => ['robots_disallow' => ['/']]]);
check(str_contains($robots, "Disallow: /\n") && !str_contains($robots, 'Allow: /'), "robots_disallow ['/'] закрывает весь сайт (без Allow: /)", $robots);
[$st] = $public('/sitemap.xml', $config + ['seo' => ['sitemap' => false]]);
[, $robots] = $public('/robots.txt', $config + ['seo' => ['sitemap' => false]]);
check($st === 404 && !str_contains($robots, 'Sitemap:'), 'sitemap: false — /sitemap.xml 404, в robots.txt нет Sitemap:');
[$st] = $public('/robots.txt', $config + ['seo' => ['robots' => false]]);
[$st2] = $public('/llms.txt', $config + ['seo' => ['llms' => false]]);
check($st === 404 && $st2 === 404, 'robots: false и llms: false — 404, как до 0.7.0');
check(SeoFiles::settings(['seo' => 'мусор'])['sitemap'] === true && SeoFiles::settings([])['robots_disallow'] === [], 'нет seo или неверный тип — значения по умолчанию');
$_SERVER['HTTPS'] = 'on';
[, $robots] = $public('/robots.txt');
check(str_contains($robots, 'Sitemap: https://example.test/sitemap.xml'), 'HTTPS=on (за прокси) — https-адреса', $robots);
unset($_SERVER['HTTPS']);

out("\n[плагины: i18n, префиксы, фильтры]\n");
ti_plugin($site, 'langs', ['provides' => ['i18n'], 'hooks' => ['request', 'url'], 'autoload' => ['Langs\\' => 'lib']], <<<'PHP'
<?php
return static function (PluginContext $plugin): void {
    $plugin->provide('i18n', new Langs\Service());
    $plugin->on('request', static function (SiteRequest $r): void {
        if (preg_match('#^/en(/.*)?$#', $r->path(), $m)) {
            $r->setLocale('en');
            $r->setPath($m[1] ?? '/');
        }
    });
    $plugin->filter('url', static function (string $url, array $ctx): string {
        return ($ctx['locale'] ?? 'ru') === 'en' ? '/en' . ($url === '/' ? '' : $url) : $url;
    });
};
PHP, ['lib/Service.php' => <<<'PHP'
<?php
namespace Langs;

final class Service implements \LocaleProvider
{
    public function locales(): array { return ['ru', 'en']; }
    public function defaultLocale(): string { return 'ru'; }
}
PHP]);
ti_plugin($site, 'shop', ['public' => ['prefix' => 'shop'], 'hooks' => ['sitemap', 'robots', 'llms']], <<<'PHP'
<?php
return static function (PluginContext $plugin): void {
    $plugin->publicRoute('GET', '/', static fn() => print('shop'));
    $plugin->filter('sitemap', static function (array $entries, array $ctx): array {
        $entries[] = ['loc' => '/shop/item-1', 'lastmod' => '2026-10-01', 'alternates' => ['ru' => '/shop/item-1', 'en' => '/en/shop/item-1'], 'priority' => 0.5];
        $entries[] = ['loc' => 'javascript:alert(1)'];
        $entries[] = 'мусор';
        return $entries;
    });
    $plugin->filter('robots', static fn(string $text): string => $text . "\nUser-agent: GPTBot\nDisallow: /shop/\n");
    $plugin->filter('llms', static fn(string $text, array $ctx): string => $text . "\n## Магазин\n\n- [Каталог](" . $ctx['base_url'] . "/shop/)\n");
};
PHP);
file_put_contents($site . '/content/about.en.md', ti_page('About us', 'English.'));
touch($site . '/content/about.en.md', strtotime('2026-02-03 04:05:06'));
file_put_contents($site . '/content/secret.en.md', ti_page('Secret', 'English.'));

$m = new PluginManager($config);
check(($m->discover()['shop']['status'] ?? '') !== 'error', 'хуки sitemap, robots, llms — допустимы в plugin.json', implode(' ', $m->discover()['shop']['errors'] ?? []));
[$st, $xml] = $public('/sitemap.xml');
$doc = @simplexml_load_string($xml);
check($st === 200 && $doc !== false, 'i18n: sitemap — корректный XML', $xml);
check(str_contains($xml, 'xmlns:xhtml="http://www.w3.org/1999/xhtml"'), 'i18n: объявлено пространство xhtml');
check(str_contains($xml, "<loc>http://example.test/about</loc>") && str_contains($xml, "<loc>http://example.test/en/about</loc>"), 'i18n: обе языковые версии about — отдельные <url>', $xml);
check(substr_count($xml, '<xhtml:link rel="alternate" hreflang="en" href="http://example.test/en/about"/>') === 2 && substr_count($xml, 'hreflang="x-default" href="http://example.test/about"') === 2, 'i18n: hreflang ru/en и x-default у обеих версий');
check(str_contains($xml, '<loc>http://example.test/en/about</loc>' . "\n" . '    <lastmod>' . date('c', strtotime('2026-02-03 04:05:06')) . '</lastmod>'), 'i18n: lastmod перевода — mtime файла перевода');
check(!str_contains($xml, '/en/services') && !str_contains($xml, 'about.en') && !str_contains($xml, '/en/secret'), 'i18n: только существующие переводы, файлы переводов — не страницы');
check(!str_contains($xml, '<loc>http://example.test/shop</loc>'), 'страница shop.md перекрыта префиксом плагина — не в sitemap');
check(str_contains($xml, '<loc>http://example.test/shop/item-1</loc>') && str_contains($xml, '<lastmod>2026-10-01</lastmod>') && str_contains($xml, '<priority>0.5</priority>'), 'фильтр sitemap: запись плагина с lastmod и priority', $xml);
check(!str_contains($xml, 'javascript'), 'фильтр sitemap: некорректные записи отброшены');
[$st] = $public('/en/sitemap.xml');
check($st === 404, '/en/sitemap.xml — 404 (без дублей на языковых префиксах)');
[$st, $robots] = $public('/robots.txt');
check($st === 200 && str_ends_with($robots, "User-agent: GPTBot\nDisallow: /shop/\n"), 'фильтр robots', $robots);
[$st, $llms] = $public('/llms.txt');
check($st === 200 && str_contains($llms, "## Магазин\n\n- [Каталог](http://example.test/shop/)"), 'фильтр llms: base_url в контексте', $llms);
check(str_contains($llms, '- [Аудит & <проверка>](http://example.test/services/audit)'), 'llms.txt — Markdown, без HTML-экранирования');

ti_rm($site);
ob_end_clean();
fwrite(STDOUT, "\n{$passed} passed, {$failures} failed\n");
exit($failures === 0 ? 0 : 1);
