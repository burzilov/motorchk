<?php

/**
 * Тесты режима технического обслуживания (без PHPUnit): php tests/maintenance.php
 *
 * Гейт Router::dispatchPublic → Maintenance::gate: при включённом режиме 503 на все
 * PHP-страницы (включая SEO-файлы, API форм и роуты/assets плагинов), доступ — только
 * у залогиненного администратора ($_SESSION['admin_authenticated'], общая сессия с
 * админкой); answering 503 — самодостаточная страница, без формы и внешних ресурсов
 * (docs/14-maintenance.md).
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
Env::set('APP_SECRET', 'maintenance-test-secret');
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

function mt_copy(string $from, string $to): void
{
    if (is_dir($from)) {
        @mkdir($to, 0775, true);
        foreach (scandir($from) ?: [] as $item) {
            if ($item !== '.' && $item !== '..') {
                mt_copy($from . '/' . $item, $to . '/' . $item);
            }
        }
        return;
    }
    copy($from, $to);
}

function mt_rm(string $path): void
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
            mt_rm($path . '/' . $item);
        }
    }
    rmdir($path);
}

function mt_capture(callable $fn): array
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

$site = sys_get_temp_dir() . '/motorchk-maintenance-test-' . getmypid();
mt_rm($site);
mkdir($site, 0775, true);
foreach (['content', 'themes', 'config'] as $dir) {
    mt_copy($corePath . '/skeleton/' . $dir, $site . '/' . $dir);
}
@mkdir($site . '/cache', 0775, true);
$config = [
    'site_path' => $site, 'core_path' => $corePath, 'base_path' => $site,
    'content_path' => $site . '/content', 'cache_path' => $site . '/cache', 'themes_path' => $site . '/themes',
    'forms_path' => $site . '/content/forms', 'submissions_path' => $site . '/submissions',
    'admin_templates_path' => $corePath . '/admin-panel/templates', 'menu_file' => $site . '/content/_menus.yaml',
    'skeleton_path' => $corePath . '/skeleton', 'plugins_path' => $site . '/plugins',
    'plugin_data_path' => $site . '/plugin-data', 'installed' => true,
];

$_SERVER['HTTP_HOST'] = 'example.test';
$_SERVER['REQUEST_METHOD'] = 'GET';
unset($_SERVER['HTTPS'], $_SERVER['SERVER_PORT'], $_SERVER['REMOTE_ADDR']);

$public = static function (string $uri): array {
    $_SERVER['REQUEST_URI'] = $uri;
    $_SERVER['QUERY_STRING'] = str_contains($uri, '?') ? explode('?', $uri, 2)[1] : '';
    return mt_capture(fn() => (new Router($GLOBALS['config']))->dispatchPublic($uri));
};

$loginAdmin = static fn (): bool => $_SESSION['admin_authenticated'] = true;
$logout = static function (): void {
    $_SESSION = [];
    $_GET = [];
};

out("motorchk maintenance tests (core v" . Version::current() . ")\n");

out("\n[выключенный режим]\n");
check(!Maintenance::enabled($config), 'по умолчанию режим выключен');
check(!Maintenance::allowed(), 'без входа в админку доступа нет');
[$st, $home] = $public('/');
check($st === 200 && $home !== '', 'выключенный режим: главная рендерится');

out("\n[включённый режим: 503, вход только у админа]\n");
SiteMeta::setMaintenance($config, true);
check(Maintenance::enabled($config), 'включён через SiteMeta::setMaintenance');
$metaYaml = (string) file_get_contents(SiteMeta::path($config));
check(str_contains($metaYaml, 'maintenance_enabled: true'), 'флаг в content/_meta.yaml');
Maintenance::writeFlag($config);
check(is_file(Maintenance::flagPath($config)), 'флаг-файл content/.maintenance создан (как в админ-сохранении)');
$adminRouter = (string) file_get_contents($corePath . '/includes/AdminRouter.php');
check(str_contains($adminRouter, 'Maintenance::writeFlag') && str_contains($adminRouter, 'Maintenance::removeFlag'), 'админ-сохранение пишет и снимает флаг');

[$st, $page] = $public('/');
check($st === 503, 'GET / — 503 для гостя', (string) $st);
check(str_contains($page, 'Закрыто на техобслуживание'), 'заголовок страницы обслуживания');
check(!str_contains($page, 'admin_authenticated'), 'механика сессии админа не раскрывается');
check(!str_contains($page, '<script') && !str_contains($page, '<link') && !str_contains($page, '<img'), 'страница самодостаточна: без внешних скриптов, стилей и картинок');
check(str_contains($page, 'admin-panel') && str_contains($page, 'href="/admin-panel/"'), '503-страница ведёт администратора в админку');

$maintSource = (string) file_get_contents($corePath . '/includes/Maintenance.php');
check(str_contains($maintSource, 'http_response_code(503)'), 'код ответа 503');
check(str_contains($maintSource, "'X-Robots-Tag: noindex, nofollow'"), 'X-Robots-Tag: noindex (заголовки в CLI не читаются — по исходнику)');
check(str_contains($maintSource, "'Retry-After: 3600'") && str_contains($maintSource, "'Cache-Control: no-store'"), 'Retry-After и no-store');
check(!str_contains($maintSource, 'maintenance_key') && !str_contains($maintSource, 'password_hash'), 'без секретных ссылок и паролей — ничего лишнего');

out("\n[вход администратора]\n");
check($loginAdmin() === true && Maintenance::allowed(), 'сессия администратора открывает доступ');
check(Maintenance::gate($config, '/', 'GET') === false, 'гейт пропускает залогиненного админа');
[$st, $page] = $public('/');
check($st === 200 && $page !== '' && !str_contains($page, 'Закрыто на техобслуживание'), 'GET / из админ-сессии: главная рендерится как обычно');

$logout();

out("\n[гейт в Router::dispatchPublic — раньше SEO и хуков]\n");
$routerSource = (string) file_get_contents($corePath . '/includes/Router.php');
$gatePos = strpos($routerSource, 'Maintenance::gate($this->config');
$seoPos = strpos($routerSource, 'SeoFiles::kind($path)');
$beforePos = strpos($routerSource, 'beforeRouting($path');
check($gatePos !== false && $seoPos !== false && $gatePos < $seoPos && $gatePos < $beforePos, 'гейт вызывается раньше SEO-файлов и beforeRouting');

out("\n[SEO-файлы и API под гейтом]\n");
[$st, $page] = $public('/sitemap.xml');
check($st === 503 && str_contains($page, 'Закрыто на техобслуживание'), '/sitemap.xml — 503');
$_SERVER['REQUEST_METHOD'] = 'POST';
[$st] = $public('/api/forms/submit');
check($st === 503, 'POST /api/forms/submit — 503 (гейт раньше API)', (string) $st);
$_SERVER['REQUEST_METHOD'] = 'GET';

out("\n[plugin-роут и plugin-assets под гейтом]\n");
@mkdir($site . '/plugins/shoplib/assets', 0775, true);
file_put_contents($site . '/plugins/shoplib/plugin.json', json_encode(
    ['id' => 'shoplib', 'name' => 'shoplib', 'version' => '1.0.0', 'core' => '>=0.5.0', 'public' => ['prefix' => 'shop'], 'hooks' => []],
    JSON_UNESCAPED_SLASHES
));
file_put_contents($site . '/plugins/shoplib/plugin.php', '<?php return static function (PluginContext $plugin): void { $plugin->publicRoute("GET", "/", static fn() => print("shop")); };');
file_put_contents($site . '/plugins/shoplib/assets/logo.png', 'png');

[$st, $page] = $public('/shop/');
check($st === 503 && !str_starts_with($page, 'shop'), 'публичный роут плагина — 503, не вывод плагина', (string) $st);
[$st] = $public('/plugin-assets/shoplib/logo.png');
check($st === 503, '/plugin-assets/ — 503', (string) $st);

out("\n[админка вне гейта]\n");
$adminIndex = (string) file_get_contents($corePath . '/skeleton/admin-panel/index.php');
check(!str_contains($adminIndex, 'Maintenance::'), 'вход админки (core/admin-panel/index.php) не проходит через гейт');

out("\n[выключение]\n");
SiteMeta::setMaintenance($config, false);
Maintenance::removeFlag($config);
check(!is_file(Maintenance::flagPath($config)), 'флаг снят при выключении (как в админ-сохранении)');
[$st, $page] = $public('/');
check($st === 200 && $page !== '', 'выключение: страница снова рендерится для всех');
$loginAdmin();
[$st] = $public('/');
check($st === 200, 'гостям и админу — одинаково при выключенном режиме');

mt_rm($site);
ob_end_clean();
fwrite(STDOUT, "\n{$passed} passed, {$failures} failed\n");
exit($failures === 0 ? 0 : 1);
