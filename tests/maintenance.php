<?php

/**
 * Тесты режима технического обслуживания (без PHPUnit): php tests/maintenance.php
 *
 * Гейт Router::dispatchPublic → Maintenance::gate: 503 при включённом режиме, вход по паролю
 * (POST /maintenance c CSRF) и по секретной ссылке /?maintenance_key=…, разрешение в сессии,
 * отзыв при смене секрета и повторном включении, SEO-файлы, API форм и plugin-роуты под гейтом,
 * лимит попыток, секрет не попадает в HTML (docs/14-maintenance.md).
 *
 * В CLI header() — тихий no-op, а http_response_code() работает: ответы-редиректы
 * (пароль/ключ) поэтому проверяются через сессию и последующую навигацию, а не через Location.
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

/**
 * Запрос через публичный роутер: $_SERVER['REQUEST_URI'] — для stripKey/редиректов гейта,
 * $get — заменяет $_GET (в CLI его создаёт только настоящий веб-запрос).
 */
$public = static function (string $uri, string $ip = '10.0.0.1', array $get = []): array {
    $_SERVER['REQUEST_URI'] = $uri;
    $_SERVER['REMOTE_ADDR'] = $ip;
    $_SERVER['QUERY_STRING'] = str_contains($uri, '?') ? explode('?', $uri, 2)[1] : '';
    $_GET = $get;
    return mt_capture(fn() => (new Router($GLOBALS['config']))->dispatchPublic($uri));
};

$grantLogout = static function (): void {
    $_SESSION = [];
    $_GET = [];
};

out("motorchk maintenance tests (core v" . Version::current() . ")\n");

out("\n[выключенный режим]\n");
check(!Maintenance::enabled($config), 'по умолчанию режим выключен');
[$st, $home] = $public('/');
check($st === 200 && $home !== '', 'выключенный режим: главная рендерится');

out("\n[включённый режим: 503]\n");
$secret1 = 'maint-secret-one-5f8d2a1c';
SiteMeta::setMaintenance($config, true, $secret1);
check(Maintenance::enabled($config), 'включён через SiteMeta::setMaintenance');
$metaYaml = (string) file_get_contents(SiteMeta::path($config));
check(SiteMeta::maintenanceHash($config) !== '' && !str_contains($metaYaml, $secret1), 'секрет хранится только хэшем, не открытым текстом');
check(is_file(SiteMeta::path($config)), '_meta.yaml создан');
// Флаг пишет админ-хендлер (AdminRouter::maintenanceSave) при сохранении, как на сайте:
Maintenance::writeFlag($config);
check(is_file(Maintenance::flagPath($config)), 'флаг-файл content/.maintenance создан (как в админ-сохранении)');
check(str_contains((string) file_get_contents($corePath . '/includes/AdminRouter.php'), 'Maintenance::writeFlag')
    && str_contains((string) file_get_contents($corePath . '/includes/AdminRouter.php'), 'Maintenance::removeFlag'), 'админка управляет флагом: включение пишет, выключение снимает');
check(!str_contains($metaYaml, "maintenance_epoch: ''"), 'эпоха секрета установлена');

[$st, $page] = $public('/');
check($st === 503, 'GET / — 503', (string) $st);
check(str_contains($page, 'Закрыто на техобслуживание'), 'заголовок страницы обслуживания');
check(str_contains($page, 'action="/maintenance"') && str_contains($page, 'name="_csrf"'), 'форма пароля POST /maintenance с CSRF');
check(!str_contains($page, $secret1), 'секрет не попадает в HTML');
check(!str_contains($page, '<script') && !str_contains($page, '<link') && !str_contains($page, '<img'), 'страница самодостаточна: без внешних скриптов, стилей и картинок');

out("\n[заголовки 503 (по исходнику: в CLI header() не читается)]\n");
$maintSource = (string) file_get_contents($corePath . '/includes/Maintenance.php');
check(str_contains($maintSource, "http_response_code(503)"), 'код ответа 503');
check(str_contains($maintSource, "'X-Robots-Tag: noindex, nofollow'"), 'X-Robots-Tag: noindex, nofollow');
check(str_contains($maintSource, "'Retry-After: 3600'"), 'Retry-After: 3600');
check(str_contains($maintSource, "'Cache-Control: no-store'"), 'Cache-Control: no-store');
check(str_contains($maintSource, "'Referrer-Policy: no-referrer'"), 'Referrer-Policy: no-referrer');

out("\n[вход по паролю]\n");
$csrf = Csrf::token();
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = ['_csrf' => $csrf, 'maintenance_secret' => $secret1];
[$st, $body] = $public('/maintenance', '10.0.0.2');
check($body === '' && Maintenance::allowed($config), 'верный пароль: разрешение выдано (ответ — редирект)');
$_POST = [];
$_SERVER['REQUEST_METHOD'] = 'GET';
[$st, $page] = $public('/');
check($st === 200 && $page !== '' && !str_contains($page, 'Закрыто на техобслуживание'), 'навигация по сессии после входа: главная рендерится');
check(($_SESSION['maintenance_grant']['ok'] ?? null) === true, 'разрешение лежит в сессии');
check(($_SESSION['maintenance_grant']['epoch'] ?? '') === SiteMeta::maintenanceEpoch($config), 'разрешение привязано к эпохе секрета');

$grantLogout();

out("\n[неверные попытки]\n");
$csrf = Csrf::token();
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = ['_csrf' => $csrf, 'maintenance_secret' => 'wrong-password'];
[$st, $page] = $public('/maintenance', '10.0.0.3');
check($st === 503 && str_contains($page, 'Неверный пароль') && !Maintenance::allowed($config), 'неверный пароль: снова 503, без разрешения');
check(!str_contains($page, 'wrong-password') && !str_contains($page, $secret1), 'введённый пароль и секрет не отражаются в ответе');
$_POST = ['_csrf' => $csrf, 'maintenance_secret' => ''];
[$st, $page] = $public('/maintenance', '10.0.0.3');
check($st === 503 && str_contains($page, 'Введите ключ или пароль'), 'пустой пароль: подсказка вместо входа');
$_POST = ['maintenance_secret' => $secret1];
[$st, $page] = $public('/maintenance', '10.0.0.3');
check($st === 503 && str_contains($page, 'Сессия устарела') && !Maintenance::allowed($config), 'без CSRF: вход запрещён');
$_POST = [];
$_SERVER['REQUEST_METHOD'] = 'GET';

out("\n[секретная ссылка]\n");
$otherKey = 'maint-secret-other-9b7c3e41';
[$st, $page] = $public('/?maintenance_key=' . rawurlencode($otherKey), '10.0.0.4', ['maintenance_key' => $otherKey]);
check($st === 503 && str_contains($page, 'Ключ недействителен.') && !Maintenance::allowed($config), 'неверный ключ: 503, без разрешения');
check(!str_contains($page, $otherKey), 'неверный ключ не отражается в ответе');
[$st, $page] = $public('/?maintenance_key=' . rawurlencode($secret1), '10.0.0.4', ['maintenance_key' => $secret1]);
check(Maintenance::allowed($config), 'верный ключ: разрешение выдано');
[$st, $page] = $public('/');
check($st === 200 && $page !== '', 'GET / в той же сессии: 200');
check(!Maintenance::secretMatches($config, 'нет такого'), 'чужой ключ не проходит');

out("\n[очистка ключа из адреса при редиректе]\n");
check(Maintenance::stripKey('/?maintenance_key=abc') === '/', 'только ключ — адрес без query');
check(Maintenance::stripKey('/blog?p=2&maintenance_key=abc&r=3') === '/blog?p=2&r=3', 'остальные параметры сохраняются');
check(Maintenance::stripKey('/blog?maintenance_key=abc&p=2') === '/blog?p=2', 'ключ не первым параметром');
check(Maintenance::stripKey('/about') === '/about', 'без query — без изменений');
check(Maintenance::stripKey('/?maintenance_key=') === '/', 'пустой ключ убирается');
$cleaned = Maintenance::stripKey('/?x=1&maintenance_key=t0psecret');
check(!str_contains($cleaned, 'maintenance_key') && $cleaned === '/?x=1', 'секретная часть не остаётся в адресе');
check(Maintenance::stripKey('/?x=1&maintenance_key=t0psecret') === Maintenance::stripKey(Maintenance::stripKey('/?x=1&maintenance_key=t0psecret')), 'stripKey идемпотентен');

out("\n[отзыв: смена секрета]\n");
$secret2 = 'maint-secret-two-1c4e8b2f';
SiteMeta::setMaintenance($config, true, $secret2);
check(!Maintenance::allowed($config), 'смена секрета отзывает разрешение прежней сессии');
check(Maintenance::secretMatches($config, $secret2) && !Maintenance::secretMatches($config, $secret1), 'новый ключ действует, старый — нет');
[$st] = $public('/');
check($st === 503, 'сессия со старой эпохой: снова 503');

$grantLogout();

out("\n[вход и отзыв: выключение и повторное включение]\n");
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = ['_csrf' => Csrf::token(), 'maintenance_secret' => $secret2];
$public('/maintenance', '10.0.0.6');
check(Maintenance::allowed($config), 'вход новым секретом — новое разрешение');
Maintenance::writeFlag($config);
check(is_file(Maintenance::flagPath($config)), 'флаг на месте при включённом режиме');
SiteMeta::setMaintenance($config, false, null, false);
check(!Maintenance::enabled($config), 'режим выключен');
Maintenance::removeFlag($config);
check(!is_file(Maintenance::flagPath($config)), 'флаг снят при выключении (как в админ-сохранении)');
$_POST = [];
$_SERVER['REQUEST_METHOD'] = 'GET';
[$st] = $public('/');
check($st === 200, 'выключение: страница снова рендерится');
check(Maintenance::secretMatches($config, $secret2), 'при выключении хэш секрета сохраняется');
$oldEpoch = SiteMeta::maintenanceEpoch($config);
SiteMeta::setMaintenance($config, true, null, true);
check(SiteMeta::maintenanceEpoch($config) !== $oldEpoch, 'повторное включение ротирует эпоху');
check(!Maintenance::allowed($config), 'разрешение старой сессии не возобновляется');

out("\n[гейт в Router::dispatchPublic — раньше SEO и хуков]\n");
SiteMeta::setMaintenance($config, true, $secret1);
$_GET = [];
$routerSource = (string) file_get_contents($corePath . '/includes/Router.php');
$gatePos = strpos($routerSource, 'Maintenance::gate($this->config');
$seoPos = strpos($routerSource, 'SeoFiles::kind($path)');
$beforePos = strpos($routerSource, 'beforeRouting($path');
check($gatePos !== false && $seoPos !== false && $gatePos < $seoPos && $gatePos < $beforePos, 'гейт вызывается раньше SEO-файлов и beforeRouting');

out("\n[SEO-файлы и API под гейтом]\n");
[$st, $page] = $public('/sitemap.xml');
check($st === 503 && str_contains($page, 'Закрыто на техобслуживание'), '/sitemap.xml — 503');
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = ['form_id' => 'contact'];
[$st] = $public('/api/forms/submit');
check($st === 503, 'POST /api/forms/submit — 503 (гейт раньше API)', (string) $st);
$_POST = [];
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
check(str_contains($page, 'admin-panel'), '503-страница ведёт администратора в админку');

out("\n[лимит попыток]\n");
$csrf = Csrf::token();
$_SERVER['REQUEST_METHOD'] = 'POST';
$burglarIp = '10.9.9.9';
$exhausted = true;
for ($i = 0; $i < 10; $i++) {
    if (!Maintenance::attemptAllowed($config, $burglarIp)) {
        $exhausted = false;
        break;
    }
}
check($exhausted, '10 попыток в окне пропускаются');
check(!Maintenance::attemptAllowed($config, $burglarIp), '11-я попытка заблокирована');
check(Maintenance::attemptAllowed($config, '10.9.9.10'), 'другой IP не затронут');
$_POST = ['_csrf' => $csrf, 'maintenance_secret' => $secret1];
[$st, $page] = $public('/maintenance', $burglarIp);
check($st === 503 && str_contains($page, 'Слишком много попыток') && !Maintenance::allowed($config), 'форма при исчерпанном лимите: блок, без входа');
check(Maintenance::secretMatches($config, $secret1), 'после лимита секрет по-прежнему действителен');
$_POST = [];
$_SERVER['REQUEST_METHOD'] = 'GET';

mt_rm($site);
ob_end_clean();
fwrite(STDOUT, "\n{$passed} passed, {$failures} failed\n");
exit($failures === 0 ? 0 : 1);
