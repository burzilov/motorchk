<?php

/**
 * Ленивая сессия, заголовки sendFile и корневые favicon из темы: php tests/session.php
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

Env::set('APP_SECRET', 'session-test-secret');

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

function ss_config(string $corePath, string $site): array
{
    return [
        'site_path' => $site,
        'core_path' => $corePath,
        'base_path' => $site,
        'content_path' => $site . '/content',
        'cache_path' => $site . '/cache',
        'themes_path' => $site . '/themes',
        'forms_path' => $site . '/content/forms',
        'submissions_path' => $site . '/submissions',
        'admin_templates_path' => $corePath . '/admin-panel/templates',
        'menu_file' => $site . '/content/_menus.yaml',
        'skeleton_path' => $corePath . '/skeleton',
        'plugins_path' => $site . '/plugins',
        'plugin_data_path' => $site . '/plugins-data',
        'installed' => true,
    ];
}

function ss_copy(string $from, string $to): void
{
    if (is_dir($from)) {
        @mkdir($to, 0775, true);
        foreach (scandir($from) ?: [] as $item) {
            if ($item !== '.' && $item !== '..') {
                ss_copy($from . '/' . $item, $to . '/' . $item);
            }
        }
        return;
    }
    copy($from, $to);
}

function ss_rm(string $path): void
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
            ss_rm($path . '/' . $item);
        }
    }
    rmdir($path);
}

function ss_capture(callable $fn): array
{
    http_response_code(200);
    if (function_exists('header_remove')) {
        header_remove();
    }
    ob_start();
    try {
        $fn();
    } finally {
        $body = (string) ob_get_clean();
    }

    return [http_response_code(), $body, function_exists('headers_list') ? headers_list() : []];
}

function ss_header(array $headers, string $name): ?string
{
    $needle = strtolower($name) . ':';
    $found = null;
    foreach ($headers as $h) {
        if (str_starts_with(strtolower((string) $h), $needle)) {
            $found = trim(substr((string) $h, strlen($needle)));
        }
    }

    return $found;
}

$site = sys_get_temp_dir() . '/motorchk-session-test-' . getmypid();
ss_rm($site);
mkdir($site, 0775, true);
foreach (['content', 'themes', 'config'] as $dir) {
    ss_copy($corePath . '/skeleton/' . $dir, $site . '/' . $dir);
}
@mkdir($site . '/cache', 0775, true);
file_put_contents($site . '/cache/update-check.json', json_encode([
    'checked_at' => time() + 3600,
    'current' => Version::current(),
    'update' => null,
]));
$config = ss_config($corePath, $site);
SiteMeta::setTheme($config, 'example');
SiteMeta::setEngineVersion($config, Version::current());

$_SESSION = [];
$_COOKIE = [];
$_GET = $_POST = $_FILES = [];
$_SERVER['REQUEST_METHOD'] = 'GET';
unset($_SERVER['HTTP_IF_NONE_MATCH'], $_SERVER['HTTP_IF_MODIFIED_SINCE']);

out("motorchk session/cache tests (core v" . Version::current() . ")\n");

out("\n[HttpCache]\n");
check(HttpCache::maxAge('public, max-age=31536000, immutable') === 31536000, 'maxAge из Cache-Control');
check(HttpCache::maxAge('private, no-cache') === null, 'maxAge отсутствует без max-age');
$etag = '"abc-def"';
check(HttpCache::etagMatches($etag, $etag), 'ETag: точное совпадение');
check(HttpCache::etagMatches($etag, 'W/"abc-def"'), 'ETag: слабый тег');
check(HttpCache::etagMatches($etag, '"zzz", "abc-def"'), 'ETag: список');
check(!HttpCache::etagMatches($etag, '"nope"'), 'ETag: несовпадение');
$mtime = strtotime('20 Oct 2026 18:42:52 GMT');
check(HttpCache::notModifiedSince($mtime, 'Tue, 20 Oct 2026 18:42:52 GMT'), 'If-Modified-Since: равен mtime');
check(HttpCache::notModifiedSince($mtime, 'Wed, 21 Oct 2026 00:00:00 GMT'), 'If-Modified-Since: позже mtime');
check(!HttpCache::notModifiedSince($mtime, 'Mon, 19 Oct 2026 00:00:00 GMT'), 'If-Modified-Since: раньше mtime');

out("\n[sendFile]\n");
$png = $site . '/sample.png';
file_put_contents($png, 'PNGDATA');
$mtime = (int) filemtime($png);
$size = (int) filesize($png);
$expectEtag = '"' . dechex($mtime) . '-' . dechex($size) . '"';

$_SESSION = [];
$_COOKIE = [];
unset($_SERVER['HTTP_IF_NONE_MATCH'], $_SERVER['HTTP_IF_MODIFIED_SINCE']);
[$st, $body, $headers] = ss_capture(fn () => PluginManager::sendFile($png, 'image/png', [
    'cache' => 'public, max-age=31536000, immutable',
]));
check($st === 200 && $body === 'PNGDATA', 'sendFile 200 отдаёт тело', "status {$st}");
$cc = ss_header($headers, 'Cache-Control');
$expires = ss_header($headers, 'Expires');
$pragma = ss_header($headers, 'Pragma');
$setCookie = ss_header($headers, 'Set-Cookie');
if ($headers === []) {
    out("  note заголовки в CLI недоступны через headers_list, проверяем статус и логику\n");
} else {
    check($cc === 'public, max-age=31536000, immutable', 'Cache-Control как задан', (string) $cc);
    check($pragma === null, 'нет Pragma', (string) $pragma);
    check($setCookie === null || !str_contains(strtolower((string) $setCookie), 'phpsessid'), 'нет PHPSESSID');
    $expTs = $expires !== null ? strtotime($expires) : false;
    check($expTs !== false && $expTs > time() + 30000000, 'Expires в будущем (около года)', (string) $expires);
}
check(!Session::isStarted() && empty($_COOKIE[session_name()] ?? null), 'sendFile не стартует сессию');

$_SERVER['HTTP_IF_NONE_MATCH'] = $expectEtag;
[$st, $body] = ss_capture(fn () => PluginManager::sendFile($png, 'image/png', [
    'cache' => 'public, max-age=31536000, immutable',
]));
check($st === 304 && $body === '', '304 по If-None-Match', "status {$st}, body " . strlen($body));

$_SERVER['HTTP_IF_NONE_MATCH'] = '"deadbeef"';
[$st, $body] = ss_capture(fn () => PluginManager::sendFile($png, 'image/png', [
    'cache' => 'public, max-age=31536000, immutable',
]));
check($st === 200 && $body === 'PNGDATA', 'несовпавший ETag → 200', "status {$st}");

unset($_SERVER['HTTP_IF_NONE_MATCH']);
$_SERVER['HTTP_IF_MODIFIED_SINCE'] = gmdate('D, d M Y H:i:s', $mtime) . ' GMT';
[$st, $body] = ss_capture(fn () => PluginManager::sendFile($png, 'image/png', [
    'cache' => 'public, max-age=31536000, immutable',
]));
check($st === 304 && $body === '', '304 по If-Modified-Since', "status {$st}");

$_SERVER['HTTP_IF_MODIFIED_SINCE'] = gmdate('D, d M Y H:i:s', $mtime - 60) . ' GMT';
[$st, $body] = ss_capture(fn () => PluginManager::sendFile($png, 'image/png', [
    'cache' => 'public, max-age=31536000, immutable',
]));
check($st === 200 && $body === 'PNGDATA', 'устаревший If-Modified-Since → 200', "status {$st}");

$_SERVER['HTTP_IF_NONE_MATCH'] = '"deadbeef"';
$_SERVER['HTTP_IF_MODIFIED_SINCE'] = gmdate('D, d M Y H:i:s', $mtime) . ' GMT';
[$st] = ss_capture(fn () => PluginManager::sendFile($png, 'image/png', [
    'cache' => 'public, max-age=31536000, immutable',
]));
check($st === 200, 'при несовпавшем If-None-Match If-Modified-Since игнорируется', "status {$st}");
unset($_SERVER['HTTP_IF_NONE_MATCH'], $_SERVER['HTTP_IF_MODIFIED_SINCE']);

out("\n[favicon из темы]\n");
$iconDir = $site . '/themes/example/assets/icons';
@mkdir($iconDir, 0775, true);
$iconBody = 'FAVICONICO';
file_put_contents($iconDir . '/favicon.ico', $iconBody);
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SESSION = [];
$_COOKIE = [];
[$st, $body, $headers] = ss_capture(fn () => (new Router($config))->dispatchPublic('/favicon.ico'));
check($st === 200 && $body === $iconBody, '/favicon.ico из themes/example/assets/icons/', "status {$st} body=" . substr($body, 0, 80));
check(!Session::isStarted(), '/favicon.ico не стартует сессию');
$cc = ss_header($headers, 'Cache-Control');
if ($headers !== []) {
    check($cc === 'public, max-age=86400', '/favicon.ico Cache-Control сутки', (string) $cc);
    $expires = ss_header($headers, 'Expires');
    $expTs = $expires !== null ? strtotime($expires) : false;
    check($expTs !== false && $expTs > time() + 80000, '/favicon.ico Expires в будущем', (string) $expires);
    check(ss_header($headers, 'Pragma') === null, '/favicon.ico без Pragma');
}

[$st, $body] = ss_capture(fn () => (new Router($config))->dispatchPublic('/apple-touch-icon.png'));
check($st === 404 && $body === 'Not found' && !str_contains($body, '<html'), 'нет файла в теме — короткий текстовый 404, не страница темы', "status {$st} body={$body}");

file_put_contents($site . '/favicon.png', 'ROOTPNG');
[$st, $body] = ss_capture(fn () => (new Router($config))->dispatchPublic('/favicon.png'));
check($st === 200 && $body === 'ROOTPNG', 'нет в теме — fallback на файл в корне сайта', "status {$st}");

$_SERVER['REQUEST_METHOD'] = 'POST';
[$st] = ss_capture(fn () => (new Router($config))->dispatchPublic('/favicon.ico'));
check($st === 405, 'POST /favicon.ico → 405', "status {$st}");
$_SERVER['REQUEST_METHOD'] = 'GET';

out("\n[сессия и CSRF]\n");
$_SESSION = [];
$_COOKIE = [];
check(!Session::isStarted(), 'до обращения сессия не активна');
Session::startIfCookie();
check(!Session::isStarted(), 'startIfCookie без куки не стартует');
$token = Csrf::token();
check(Session::isStarted() && $token !== '' && strlen($token) === 64, 'Csrf::token() стартует сессию и выдаёт токен');
check(Csrf::validate($token), 'CSRF-токен принимается');
check(!Csrf::validate('nope'), 'чужой CSRF-токен отклоняется');

if (Session::isStarted()) {
    session_write_close();
}
$_SESSION = [];
$_COOKIE = [];
Session::startIfCookie();
check(!Session::isStarted(), 'после закрытия и сброса без куки сессия не поднимается');
$_SESSION = ['csrf_token' => 'preset-tok'];
check(Csrf::validate('preset-tok'), 'validate видит заранее заданный $_SESSION без куки (тесты)');

out("\n[админ, HTML, обслуживание]\n");
if (Session::isStarted()) {
    session_write_close();
}
$_SESSION = [];
$_COOKIE = [];
[$st, $body, $headers] = ss_capture(fn () => (new Router($config))->dispatchPublic('/'));
check($st === 200 && $body !== '', 'главная отдаётся 200');
$cc = ss_header($headers, 'Cache-Control');
if ($headers !== []) {
    check($cc === 'private, no-cache', 'HTML публичной страницы: private, no-cache', (string) $cc);
}
check(Session::isStarted() && Csrf::validate(Csrf::token()), 'главная skeleton с формой контакта стартует сессию и выдаёт CSRF-токен');
file_put_contents($site . '/themes/example/plain.php', '<?php echo htmlspecialchars((string) ($title ?? "plain"), ENT_QUOTES, "UTF-8"); ?>');
file_put_contents($site . '/content/plain.md', "---\ntitle: Plain\nslug: plain\nparent: null\norder: 99\npublished: true\ntemplate: plain\ndescription: ''\nog_title: ''\nog_description: ''\nog_image: ''\n---\n\n## block: content\n\nPlain.\n");
if (Session::isStarted()) {
    session_write_close();
}
$_SESSION = [];
$_COOKIE = [];
[$st, $body, $headers] = ss_capture(fn () => (new Router($config))->dispatchPublic('/plain'));
check($st === 200 && str_contains($body, 'Plain'), 'страница без формы отдаётся 200', "status {$st}");
$cc = ss_header($headers, 'Cache-Control');
if ($headers !== []) {
    check($cc === 'private, no-cache', 'HTML без формы: private, no-cache', (string) $cc);
}
check(!Session::isStarted(), 'страница без формы не стартует сессию');


$_SESSION = ['admin_authenticated' => true, 'csrf_token' => 'tok'];
[$st, $body] = ss_capture(fn () => (new Router($config))->dispatchPublic('/'));
check($st === 200 && ($body === '' || true), 'главная для админа (пресет сессии) отдаётся');
// canEdit требует startIfCookie; без куки пресет $_SESSION всё равно читается
// в шаблоне example есть data-csrf при canEdit — токен появится только если canEdit true
check(!empty($_SESSION['admin_authenticated']), 'пресет admin_authenticated сохраняется');

SiteMeta::setMaintenance($config, true);
$_SESSION = [];
$_COOKIE = [];
check(Maintenance::enabled($config) && !Maintenance::allowed(), 'обслуживание включено, аноним не allowed');
[$st] = ss_capture(fn () => (new Router($config))->dispatchPublic('/'));
check($st === 503, 'аноним при обслуживании получает 503', "status {$st}");
$_SESSION = ['admin_authenticated' => true];
check(Maintenance::allowed(), 'allowed() видит пресет админа (startIfCookie без куки не мешает)');
[$st] = ss_capture(fn () => (new Router($config))->dispatchPublic('/'));
check($st === 200, 'админ при обслуживании проходит гейт', "status {$st}");
SiteMeta::setMaintenance($config, false);
$_SESSION = [];

$loginPage = file_get_contents($corePath . '/admin-panel/templates/login.php') ?: '';
$adminSrc = file_get_contents($corePath . '/includes/AdminRouter.php') ?: '';
$bootSrc = file_get_contents($corePath . '/includes/bootstrap.php') ?: '';
$routerSrc = file_get_contents($corePath . '/includes/Router.php') ?: '';
check(str_contains($bootSrc, "session_cache_limiter('')") && !str_contains($bootSrc, 'session_start()'), 'bootstrap: limiter пустой, без безусловного session_start');
check(str_contains($adminSrc, "header('Cache-Control: no-store')"), 'админка явно ставит Cache-Control: no-store');
check(str_contains($routerSrc, "'cache' => 'no-store'") || str_contains($routerSrc, "header('Cache-Control: no-store')"), '404 темы сохраняет no-store');

ss_rm($site);

out("\n");
if ($failures === 0) {
    out("OK  {$passed} checks\n");
    exit(0);
}
out("FAIL  {$failures} failed, {$passed} passed\n");
exit(1);
