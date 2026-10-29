<?php

/**
 * Тесты обновления плагинов из GitHub Releases (без PHPUnit, без сети): php tests/plugin-updates.php
 *
 * Сеть подменяется fetcher-ом (PluginUpdater::__construct, третий аргумент). Проверяются repository и
 * разрешённые хосты, проверка версий/совместимости и кеш, validateZip (zip-slip, лишние папки, версия),
 * замена папки с резервной копией, onInstall-миграции и откат, кнопки на странице «Система».
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
Env::set('APP_SECRET', 'plugin-updates-test-secret');

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

function tp_config(string $corePath, string $site): array
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
        'plugin_data_path' => $site . '/plugin-data',
        'installed' => true,
    ];
}

function tp_copy(string $from, string $to): void
{
    if (is_dir($from)) {
        @mkdir($to, 0775, true);
        foreach (scandir($from) ?: [] as $item) {
            if ($item !== '.' && $item !== '..') {
                tp_copy($from . '/' . $item, $to . '/' . $item);
            }
        }
        return;
    }
    copy($from, $to);
}

function tp_rm(string $path): void
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
            tp_rm($path . '/' . $item);
        }
    }
    rmdir($path);
}

function tp_capture(callable $fn): array
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

/** @return string|null текст исключения или null */
function tp_throws(callable $fn): ?string
{
    try {
        $fn();
    } catch (Throwable $e) {
        return $e->getMessage();
    }

    return null;
}

/** Zip из [имя => содержимое|null (папка)]; $symlinks — имена, помечаемые как unix-симлинки. */
function tp_zip(string $path, array $entries, array $symlinks = []): string
{
    @unlink($path);
    $zip = new ZipArchive();
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    foreach ($entries as $name => $content) {
        if ($content === null) {
            $zip->addEmptyDir(rtrim($name, '/'));
        } else {
            $zip->addFromString($name, $content);
        }
    }
    foreach ($symlinks as $name) {
        $zip->setExternalAttributesName($name, ZipArchive::OPSYS_UNIX, (0120777) << 16);
    }
    $zip->close();

    return $path;
}

$coreVersion = Version::current();
[$major, $minor] = array_map('intval', explode('.', $coreVersion));
$coreOk = '^' . $major . '.' . $minor;
$coreBad = '^' . ($major + 1) . '.0';
$repo = 'owner/motorchk-plugin-demo';

function tp_manifest(string $version, string $core, string $repo, string $id = 'demo'): array
{
    return ['id' => $id, 'name' => 'Demo', 'version' => $version, 'core' => $core, 'repository' => $repo];
}

/** Содержимое архива релиза demo-<version>.zip; onInstall пишет миграцию в data dir (или бросает). */
function tp_release_entries(string $version, string $core, string $repo, bool $failInstall = false): array
{
    $hook = $failInstall
        ? "throw new RuntimeException('migration failed');"
        : "file_put_contents(\$p->dataDir() . '/migrations.log', (\$from ?? '-') . '>' . \$to . \"\\n\", FILE_APPEND);";

    return [
        'demo/' => null,
        'demo/plugin.json' => json_encode(tp_manifest($version, $core, $repo), JSON_UNESCAPED_SLASHES),
        'demo/plugin.php' => "<?php\nreturn function (PluginContext \$plugin): void {\n    \$plugin->onInstall(function (?string \$from, string \$to, PluginContext \$p): void {\n        {$hook}\n    });\n};\n",
        'demo/lib/' => null,
        'demo/lib/Version.txt' => $version,
    ];
}

$site = sys_get_temp_dir() . '/motorchk-plugin-updates-test-' . getmypid();
tp_rm($site);
mkdir($site, 0775, true);
foreach (['content', 'themes', 'config'] as $dir) {
    tp_copy($corePath . '/skeleton/' . $dir, $site . '/' . $dir);
}
@mkdir($site . '/cache', 0775, true);
@mkdir($site . '/plugins', 0775, true);
file_put_contents($site . '/cache/update-check.json', json_encode(['checked_at' => time() + 3600, 'current' => $coreVersion, 'update' => null]));
$config = tp_config($corePath, $site);
$_SESSION = [];
$_GET = $_POST = $_FILES = [];
$_SERVER['REQUEST_METHOD'] = 'GET';

$installDemo = static function (string $version) use ($site, $coreOk, $repo): void {
    tp_rm($site . '/plugins/demo');
    mkdir($site . '/plugins/demo/lib', 0775, true);
    file_put_contents($site . '/plugins/demo/plugin.json', json_encode(tp_manifest($version, $coreOk, $repo), JSON_UNESCAPED_SLASHES));
    file_put_contents($site . '/plugins/demo/plugin.php', "<?php\nreturn function (PluginContext \$plugin): void {};\n");
    file_put_contents($site . '/plugins/demo/lib/Version.txt', $version);
};
$installDemo('1.0.0');
// плагин без repository — обновляется только вручную
mkdir($site . '/plugins/local', 0775, true);
file_put_contents($site . '/plugins/local/plugin.json', json_encode(['id' => 'local', 'name' => 'Local', 'version' => '0.1.0', 'core' => $coreOk]));
file_put_contents($site . '/plugins/local/plugin.php', "<?php\nreturn function (PluginContext \$plugin): void {};\n");

// ---------------------------------------------------------------- мок GitHub
$calls = [];
$routes = [];
$fetcher = static function (string $url, array $headers, int $maxBytes, int $timeout) use (&$calls, &$routes): array {
    $calls[] = $url;
    foreach ($routes as $prefix => $response) {
        if (str_starts_with($url, $prefix)) {
            if ($response instanceof Closure) {
                return $response($url);
            }
            return $response + ['headers' => []];
        }
    }

    return ['status' => 404, 'body' => '{"message":"Not Found"}', 'headers' => []];
};
$api = 'https://api.github.com/repos/' . $repo;
$assetUrl = static fn(string $v): string => 'https://github.com/' . $repo . '/releases/download/v' . $v . '/demo-' . $v . '.zip';
$release = static function (string $v, ?array $assets = null) use ($assetUrl): array {
    return ['status' => 200, 'body' => json_encode([
        'tag_name' => 'v' . $v,
        'name' => 'demo ' . $v,
        'html_url' => 'https://github.com/owner/motorchk-plugin-demo/releases/tag/v' . $v,
        'body' => 'Что нового',
        'assets' => $assets ?? [['name' => 'demo-' . $v . '.zip', 'browser_download_url' => $assetUrl($v)]],
    ])];
};
$contents = static fn(array $manifest): array => ['status' => 200, 'body' => json_encode(['encoding' => 'base64', 'content' => chunk_split(base64_encode(json_encode($manifest, JSON_UNESCAPED_SLASHES)), 60, "\n")])];
$setRelease = static function (string $v, ?string $core = null, ?array $assets = null) use (&$routes, $api, $release, $contents, $coreOk, $repo): void {
    $routes = [
        $api . '/releases/latest' => $release($v, $assets),
        $api . '/contents/plugin.json' => $contents(tp_manifest($v, $core ?? $coreOk, $repo)),
    ];
};
$updater = static fn() => new PluginUpdater($config, null, $fetcher);
$cacheFile = $site . '/cache/plugin-updates.json';

// ---------------------------------------------------------------- repository и хосты
out("[repository и разрешённые адреса]\n");
check(PluginUpdater::normalizeRepository('burzilov/motorchk-plugin-blog') === 'burzilov/motorchk-plugin-blog', 'owner/repo принимается');
check(PluginUpdater::normalizeRepository('https://github.com/burzilov/motorchk-plugin-blog.git') === 'burzilov/motorchk-plugin-blog', 'https://github.com/owner/repo(.git) нормализуется');
$badRepos = ['', 'owner', 'owner/repo/extra', '../repo', 'owner/..', 'http://github.com/owner/repo', 'https://gitlab.com/owner/repo', 'own er/repo', '-owner/repo', 'owner/re po', 'owner/repo?x=1'];
$accepted = array_values(array_filter($badRepos, static fn(string $r): bool => PluginUpdater::normalizeRepository($r) !== null));
check($accepted === [], 'некорректные repository отклоняются', implode(', ', $accepted));
check(PluginUpdater::normalizeRepository(['a/b']) === null, 'repository не строка → null');
foreach ([
    'https://api.github.com/repos/a/b' => true,
    'https://github.com/a/b/releases/download/v1/a.zip' => true,
    'https://objects.githubusercontent.com/x' => true,
    'https://release-assets.githubusercontent.com/x' => true,
    'http://github.com/a' => false,
    'https://evil.example/a' => false,
    'https://github.com.evil.example/a' => false,
    'https://user:pass@github.com/a' => false,
    'https://github.com:8443/a' => false,
    'ftp://github.com/a' => false,
    'file:///etc/passwd' => false,
] as $url => $expected) {
    check(PluginUpdater::isAllowedUrl($url) === $expected, 'isAllowedUrl(' . $url . ') = ' . ($expected ? 'true' : 'false'));
}

// ---------------------------------------------------------------- проверка обновлений
out("\n[проверка обновлений и кеш]\n");
$setRelease('1.1.0');
$calls = [];
$u = $updater()->checkForUpdate('demo', true);
check(is_array($u) && $u['version'] === '1.1.0' && $u['tag'] === 'v1.1.0', 'найдена новая версия 1.1.0', json_encode($u));
check(($u['zip_url'] ?? null) === $assetUrl('1.1.0') && $u['asset'] === 'demo-1.1.0.zip', 'архив — ровно demo-1.1.0.zip из релиза');
check(($u['compatible'] ?? null) === true && ($u['core'] ?? null) === $coreOk && ($u['installable'] ?? false) === true, 'совместимость с ядром проверена по plugin.json релиза');
check(count($calls) === 2 && str_ends_with($calls[0], '/releases/latest') && str_contains($calls[1], '/contents/plugin.json?ref=v1.1.0'), 'запросы: releases/latest и plugin.json по тегу', implode(' | ', $calls));
$cache = json_decode((string) @file_get_contents($cacheFile), true);
check(($cache['plugins']['demo']['update']['version'] ?? null) === '1.1.0' && ($cache['plugins']['demo']['current'] ?? null) === '1.0.0', 'результат в cache/plugin-updates.json');

$calls = [];
$u2 = $updater()->checkForUpdate('demo');
check($calls === [] && ($u2['version'] ?? null) === '1.1.0', 'повторная проверка без force — из кеша, без сети');
$cache['plugins']['demo']['checked_at'] = time() - 21600 - 5;
file_put_contents($cacheFile, json_encode($cache));
$updater()->checkForUpdate('demo');
check(count($calls) === 2, 'кеш старше TTL (21600 с) — новый запрос', (string) count($calls));
Env::set('UPDATE_CHECK_TTL', '120');
$cache = json_decode((string) file_get_contents($cacheFile), true);
$cache['plugins']['demo']['checked_at'] = time() - 200;
file_put_contents($cacheFile, json_encode($cache));
$calls = [];
$updater()->checkForUpdate('demo');
check(count($calls) === 2, 'TTL берётся из UPDATE_CHECK_TTL, как у ядра');
Env::set('UPDATE_CHECK_TTL', '');
$cache = json_decode((string) file_get_contents($cacheFile), true);
$cache['plugins']['demo']['current'] = '0.9.0';
file_put_contents($cacheFile, json_encode($cache));
$calls = [];
$updater()->checkForUpdate('demo');
check(count($calls) === 2, 'кеш для другой установленной версии не используется');
$calls = [];
$updater()->checkForUpdate('demo', true);
check(count($calls) === 2, 'force — всегда по сети');

$setRelease('1.0.0');
check($updater()->checkForUpdate('demo', true) === null, 'последний релиз = текущей версии → обновления нет');
$setRelease('0.9.0');
check($updater()->checkForUpdate('demo', true) === null, 'релиз старше текущей версии → обновления нет');

$setRelease('2.0.0', $coreBad);
$u = $updater()->checkForUpdate('demo', true);
check(is_array($u) && $u['compatible'] === false && $u['installable'] === false && $u['core'] === $coreBad, 'релиз для другого ядра: показан, но установка недоступна');

$setRelease('1.1.0', null, [['name' => 'demo-1.1.0.tar.gz', 'browser_download_url' => $assetUrl('1.1.0')], ['name' => 'demo.zip', 'browser_download_url' => $assetUrl('1.1.0')]]);
$u = $updater()->checkForUpdate('demo', true);
check(is_array($u) && $u['zip_url'] === null && $u['installable'] === false && str_contains((string) $u['problem'], 'demo-1.1.0.zip'), 'нет архива с точным именем → установка недоступна');
$setRelease('1.1.0', null, [['name' => 'demo-1.1.0.zip', 'browser_download_url' => 'https://github.com/evil/repo/releases/download/v1.1.0/demo-1.1.0.zip']]);
$u = $updater()->checkForUpdate('demo', true);
check(is_array($u) && $u['zip_url'] === null, 'архив из чужого репозитория не принимается');

$setRelease('1.1.0');
$routes[$api . '/contents/plugin.json'] = $contents(tp_manifest('1.1.0', $coreOk, $repo, 'other'));
$u = $updater()->checkForUpdate('demo', true);
check(is_array($u) && $u['installable'] === false && str_contains((string) $u['problem'], 'другого плагина'), 'plugin.json релиза с другим id → установка недоступна');
$routes[$api . '/contents/plugin.json'] = ['status' => 404, 'body' => '{}'];
$u = $updater()->checkForUpdate('demo', true);
check(is_array($u) && $u['compatible'] === null && $u['installable'] === true, 'plugin.json по тегу недоступен → совместимость проверится по архиву');

$routes = [$api . '/releases/latest' => ['status' => 404, 'body' => '{}']];
$err = tp_throws(fn() => $updater()->checkForUpdate('demo', true));
check($err !== null && str_contains($err, 'нет опубликованных релизов'), 'нет релизов (404) + force → понятная ошибка', (string) $err);
$st = $updater()->status('demo');
check($st['update'] === null && str_contains((string) $st['error'], 'нет опубликованных релизов'), 'ошибка сохраняется в статусе (без force — без исключения)');

$routes = [$api . '/releases/latest' => ['status' => 403, 'body' => '{}']];
$err = tp_throws(fn() => $updater()->checkForUpdate('demo', true));
check($err !== null && str_contains($err, '403'), 'HTTP 403 (лимит GitHub) → ошибка с кодом', (string) $err);

$routes = [$api . '/releases/latest' => ['status' => 301, 'body' => '', 'headers' => ['location' => 'https://api.github.com/repositories/42/releases/latest']], 'https://api.github.com/repositories/42/releases/latest' => $release('1.1.0'), $api . '/contents/' => $contents(tp_manifest('1.1.0', $coreOk, $repo))];
$u = $updater()->checkForUpdate('demo', true);
check(($u['version'] ?? null) === '1.1.0', 'редирект в пределах api.github.com — проходит');
$routes = [$api . '/releases/latest' => ['status' => 302, 'body' => '', 'headers' => ['location' => 'https://evil.example/latest']]];
$calls = [];
$err = tp_throws(fn() => $updater()->checkForUpdate('demo', true));
check($err !== null && str_contains($err, 'Запрещённый адрес') && count($calls) === 1, 'редирект на чужой хост — отказ без запроса туда', (string) $err);
$routes = [$api . '/releases/latest' => ['status' => 302, 'body' => '', 'headers' => ['location' => 'http://api.github.com/x']]];
$err = tp_throws(fn() => $updater()->checkForUpdate('demo', true));
check($err !== null && str_contains($err, 'Запрещённый адрес'), 'редирект на http — отказ');
$routes = [$api . '/releases/latest' => ['status' => 302, 'body' => '', 'headers' => ['location' => $api . '/releases/latest']]];
$calls = [];
$err = tp_throws(fn() => $updater()->checkForUpdate('demo', true));
check($err !== null && str_contains($err, 'редирект') && count($calls) === PluginUpdater::MAX_REDIRECTS + 1, 'цикл редиректов — ограничен', (string) $err . ' / ' . count($calls));

$routes = [$api . '/releases/latest' => static function (): array { throw new RuntimeException('timeout'); }];
$err = tp_throws(fn() => $updater()->checkForUpdate('demo', true));
check($err !== null && str_contains($err, 'Ошибка сети: timeout'), 'ошибка сети → понятное сообщение', (string) $err);

$calls = [];
$st = $updater()->status('local', true);
check($st['repository'] === null && $st['error'] === null && $calls === [], 'плагин без repository — без запросов');
@mkdir($site . '/plugins/badrepo', 0775, true);
file_put_contents($site . '/plugins/badrepo/plugin.json', json_encode(['id' => 'badrepo', 'name' => 'B', 'version' => '1.0.0', 'core' => $coreOk, 'repository' => 'https://evil.example/a/b']));
file_put_contents($site . '/plugins/badrepo/plugin.php', "<?php\nreturn function (PluginContext \$plugin): void {};\n");
$st = $updater()->status('badrepo', true);
check($st['repository'] === null && str_contains((string) $st['error'], 'Некорректное поле repository') && $calls === [], 'repository не на github.com → ошибка, без запросов');
tp_rm($site . '/plugins/badrepo');
check(tp_throws(fn() => $updater()->status('missing')) !== null, 'неустановленный плагин → исключение');

$setRelease('1.1.0');
$all = $updater()->checkAll(true);
check(isset($all['demo'], $all['local']) && ($all['demo']['update']['version'] ?? null) === '1.1.0' && $all['local']['repository'] === null, 'checkAll: статусы всех плагинов');

// ---------------------------------------------------------------- validateZip
out("\n[проверка архива]\n");
$tmp = $site . '/cache/test.zip';
$v = $updater();
$valid = tp_release_entries('1.1.0', $coreOk, $repo);
$m = $v->validateZip(tp_zip($tmp, $valid), 'demo', '1.1.0', $repo);
check(($m['version'] ?? null) === '1.1.0', 'корректный архив принимается');
$m = $v->validateZip(tp_zip($tmp, array_diff_key($valid, ['demo/' => 1, 'demo/lib/' => 1])), 'demo', '1.1.0', $repo);
check(($m['id'] ?? null) === 'demo', 'архив без записей-папок тоже принимается');
$zipCases = [
    'zip-slip ../' => [$valid + ['../evil.php' => '<?php'], 'Недопустимый путь'],
    'zip-slip demo/../../' => [$valid + ['demo/../../evil.php' => '<?php'], 'Недопустимый путь'],
    'абсолютный путь' => [$valid + ['/tmp/evil.php' => '<?php'], 'Недопустимый путь'],
    'путь с диском Windows' => [$valid + ['C:/evil.php' => '<?php'], 'Недопустимый путь'],
    'обратный слэш' => [$valid + ['demo\\..\\..\\evil.php' => '<?php'], 'Недопустимый путь'],
    'лишняя папка рядом' => [$valid + ['other/plugin.php' => '<?php'], 'вне папки demo/'],
    'файл в корне архива' => [$valid + ['README.md' => 'x'], 'вне папки demo/'],
    'папка с похожим именем' => [$valid + ['demo-evil/x.php' => '<?php'], 'вне папки demo/'],
    'другой id в plugin.json' => [array_merge($valid, ['demo/plugin.json' => json_encode(tp_manifest('1.1.0', $coreOk, $repo, 'other'))]), 'id в plugin.json'],
    'другая версия' => [array_merge($valid, ['demo/plugin.json' => json_encode(tp_manifest('1.2.0', $coreOk, $repo), JSON_UNESCAPED_SLASHES)]), 'Версия в plugin.json архива не 1.1.0'],
    'другой repository' => [array_merge($valid, ['demo/plugin.json' => json_encode(tp_manifest('1.1.0', $coreOk, 'evil/repo'), JSON_UNESCAPED_SLASHES)]), 'repository в plugin.json'],
    'несовместимое ядро' => [array_merge($valid, ['demo/plugin.json' => json_encode(tp_manifest('1.1.0', $coreBad, $repo), JSON_UNESCAPED_SLASHES)]), 'несовместима с ядром'],
    'нет plugin.json' => [array_diff_key($valid, ['demo/plugin.json' => 1]), 'нет demo/plugin.json'],
    'нет точки входа' => [array_diff_key($valid, ['demo/plugin.php' => 1]), 'точки входа'],
    'plugin.json — не объект' => [array_merge($valid, ['demo/plugin.json' => '[1,2]']), 'некорректный JSON'],
];
foreach ($zipCases as $label => [$entries, $expected]) {
    $err = tp_throws(fn() => $v->validateZip(tp_zip($tmp, $entries), 'demo', '1.1.0', $repo));
    check($err !== null && str_contains($err, $expected), 'отклонён: ' . $label, (string) $err);
}
$err = tp_throws(fn() => $v->validateZip(tp_zip($tmp, $valid + ['demo/link' => '/etc/passwd'], ['demo/link']), 'demo', '1.1.0', $repo));
check($err !== null && str_contains($err, 'Символические ссылки'), 'отклонён: симлинк в архиве', (string) $err);
$many = $valid;
for ($i = 0; $i <= PluginUpdater::MAX_FILES; $i++) {
    $many['demo/f/' . $i . '.txt'] = '';
}
$err = tp_throws(fn() => $v->validateZip(tp_zip($tmp, $many), 'demo', '1.1.0', $repo));
check($err !== null && str_contains($err, 'файлов'), 'отклонён: слишком много файлов', (string) $err);
file_put_contents($tmp, 'not a zip');
$err = tp_throws(fn() => $v->validateZip($tmp, 'demo', '1.1.0', $repo));
check($err !== null && str_contains($err, 'zip'), 'отклонён: не zip', (string) $err);
@unlink($tmp);

// ---------------------------------------------------------------- установка
out("\n[установка, резервная копия, миграции, откат]\n");
$zipBytes = static function (array $entries) use ($site): string {
    $path = tp_zip($site . '/release-build.zip', $entries);
    $bytes = (string) file_get_contents($path);
    unlink($path);

    return $bytes;
};
$serveRelease = static function (string $v, array $entries) use (&$routes, $setRelease, $assetUrl, $zipBytes): void {
    $setRelease($v);
    $cdn = 'https://release-assets.githubusercontent.com/github-production-release-asset/demo-' . $v . '.zip?sig=x';
    $routes[$assetUrl($v)] = ['status' => 302, 'body' => '', 'headers' => ['location' => $cdn]];
    $routes[$cdn] = ['status' => 200, 'body' => $zipBytes($entries)];
};
$demoVersion = static fn(): ?string => json_decode((string) @file_get_contents($site . '/plugins/demo/plugin.json'), true)['version'] ?? null;
$leftovers = static fn(): array => array_values(array_filter(scandir($site . '/plugins') ?: [], static fn(string $f): bool => str_starts_with($f, '.demo')));
$stateVersion = static fn(): ?string => json_decode((string) @file_get_contents($site . '/plugin-data/demo/.plugin-state.json'), true)['version'] ?? null;

// данные старой версии: install-state 1.0.0 и пользовательский файл
(new PluginManager($config))->boot('demo');
file_put_contents($site . '/plugin-data/demo/user.json', '{"keep":true}');
check($stateVersion() === '1.0.0', 'исходно: данные плагина на 1.0.0');

$serveRelease('1.1.0', tp_release_entries('1.1.0', $coreOk, $repo));
file_put_contents($site . '/cache/pagecache.json', '{}');
$calls = [];
$result = $updater()->apply('demo', 'v1.1.0');
check($result['from'] === '1.0.0' && $result['to'] === '1.1.0', 'apply: 1.0.0 → 1.1.0', json_encode($result));
check($demoVersion() === '1.1.0' && trim((string) file_get_contents($site . '/plugins/demo/lib/Version.txt')) === '1.1.0', 'файлы плагина заменены');
check(in_array('https://release-assets.githubusercontent.com/github-production-release-asset/demo-1.1.0.zip?sig=x', $calls, true), 'архив скачан через редирект на release-assets.githubusercontent.com');
check($stateVersion() === '1.1.0' && trim((string) @file_get_contents($site . '/plugin-data/demo/migrations.log')) === '1.0.0>1.1.0', 'onInstall выполнен (1.0.0 → 1.1.0)');
check(file_get_contents($site . '/plugin-data/demo/user.json') === '{"keep":true}', 'plugin-data не тронут');
check(is_file($site . '/plugins/local/plugin.json'), 'другие плагины не тронуты');
$backup = $site . '/cache/plugin-backup-demo-1.0.0.zip';
$bz = new ZipArchive();
$backupOk = is_file($backup) && $bz->open($backup) === true && json_decode((string) $bz->getFromName('demo/plugin.json'), true)['version'] === '1.0.0';
check($backupOk && $result['backup'] === $backup, 'резервная копия cache/plugin-backup-demo-1.0.0.zip (папка demo/)');
check($leftovers() === [], 'временные папки .demo.* удалены', implode(',', $leftovers()));
check(!is_file($site . '/cache/plugin-update-demo-1.1.0.zip'), 'скачанный архив удалён');
check(!is_file($site . '/cache/pagecache.json'), 'кеш страниц сброшен');
$cache = json_decode((string) file_get_contents($cacheFile), true);
check(($cache['plugins']['demo']['current'] ?? null) === '1.1.0' && array_key_exists('update', $cache['plugins']['demo'] ?? []) && $cache['plugins']['demo']['update'] === null, 'кеш проверок: обновлений нет');

// откат: onInstall новой версии падает
$serveRelease('1.2.0', tp_release_entries('1.2.0', $coreOk, $repo, true));
$err = tp_throws(fn() => $updater()->apply('demo', '1.2.0'));
check($err !== null && str_contains($err, 'отменено') && str_contains($err, 'migration failed'), 'ошибка onInstall → обновление отменено', (string) $err);
check($demoVersion() === '1.1.0' && trim((string) file_get_contents($site . '/plugins/demo/lib/Version.txt')) === '1.1.0', 'откат: возвращена 1.1.0');
check($stateVersion() === '1.1.0', 'откат: install-state остался 1.1.0');
check($leftovers() === [], 'откат: временные папки удалены', implode(',', $leftovers()));
check(is_file($site . '/cache/plugin-backup-demo-1.1.0.zip'), 'резервная копия 1.1.0 сохранена до замены');

// архив не прошёл проверку — папка плагина не трогается
$serveRelease('1.2.0', array_merge(tp_release_entries('1.2.0', $coreOk, $repo), ['demo/plugin.json' => json_encode(tp_manifest('1.3.0', $coreOk, $repo), JSON_UNESCAPED_SLASHES)]));
$routes[$api . '/contents/plugin.json'] = ['status' => 404, 'body' => '{}'];
$err = tp_throws(fn() => $updater()->apply('demo', '1.2.0'));
check($err !== null && str_contains($err, 'Версия в plugin.json архива'), 'архив с неверной версией — отказ', (string) $err);
check($demoVersion() === '1.1.0' && $leftovers() === [], 'после отказа: плагин 1.1.0, без временных папок');
$serveRelease('1.2.0', tp_release_entries('1.2.0', $coreOk, $repo) + ['other/x.php' => '<?php']);
$err = tp_throws(fn() => $updater()->apply('demo', '1.2.0'));
check($err !== null && !is_dir($site . '/plugins/other') && $demoVersion() === '1.1.0', 'архив с лишней папкой — отказ, чужая папка не создана', (string) $err);
$serveRelease('1.2.0', ['../../evil.php' => '<?php echo 1;'] + tp_release_entries('1.2.0', $coreOk, $repo));
$err = tp_throws(fn() => $updater()->apply('demo', '1.2.0'));
check($err !== null && !is_file(dirname($site) . '/evil.php') && !is_file($site . '/evil.php'), 'zip-slip при установке — отказ, файл не записан', (string) $err);

$serveRelease('1.2.0', tp_release_entries('1.2.0', $coreOk, $repo));
$err = tp_throws(fn() => $updater()->apply('demo', '1.3.0'));
check($err !== null && str_contains($err, 'недоступна'), 'версия не совпадает с последним релизом → отказ', (string) $err);
$routes[$api . '/contents/plugin.json'] = $contents(tp_manifest('1.2.0', $coreBad, $repo));
$err = tp_throws(fn() => $updater()->apply('demo', '1.2.0'));
check($err !== null && str_contains($err, 'требует ядро'), 'релиз для другого ядра → отказ до скачивания', (string) $err);
check(tp_throws(fn() => $updater()->apply('../demo', '1.2.0')) !== null, 'некорректный id → отказ');
check(str_contains((string) tp_throws(fn() => $updater()->apply('nope', '1.2.0')), 'не установлен'), 'неустановленный плагин → отказ');

$serveRelease('1.2.0', tp_release_entries('1.2.0', $coreOk, $repo));
mkdir($site . '/plugins/demo/.git');
$err = tp_throws(fn() => $updater()->apply('demo', '1.2.0'));
check($err !== null && str_contains($err, 'git'), 'рабочая копия git — отказ', (string) $err);
rmdir($site . '/plugins/demo/.git');
$lock = fopen($site . '/cache/plugin-update.lock', 'c');
flock($lock, LOCK_EX);
$err = tp_throws(fn() => $updater()->apply('demo', '1.2.0'));
check($err !== null && str_contains($err, 'уже выполняется'), 'параллельное обновление блокируется', (string) $err);
flock($lock, LOCK_UN);
fclose($lock);
if (function_exists('symlink')) {
    rename($site . '/plugins/demo', $site . '/demo-real');
    symlink($site . '/demo-real', $site . '/plugins/demo');
    $err = tp_throws(fn() => $updater()->apply('demo', '1.2.0'));
    check($err !== null && str_contains($err, 'символическая ссылка'), 'папка-симлинк — отказ', (string) $err);
    unlink($site . '/plugins/demo');
    rename($site . '/demo-real', $site . '/plugins/demo');
}
$result = $updater()->apply('demo', '1.2.0');
check($result['to'] === '1.2.0' && $demoVersion() === '1.2.0' && $stateVersion() === '1.2.0', 'повторное обновление до 1.2.0 после отказов');
check(trim((string) file_get_contents($site . '/plugin-data/demo/migrations.log')) === "1.0.0>1.1.0\n1.1.0>1.2.0", 'миграции выполнены по порядку');

// ---------------------------------------------------------------- админка
out("\n[страница «Система»]\n");
$now = time();
file_put_contents($cacheFile, json_encode(['plugins' => [
    'demo' => ['checked_at' => $now, 'current' => '1.2.0', 'core' => $coreVersion, 'repository' => $repo, 'error' => null, 'update' => [
        'version' => '1.3.0', 'tag' => 'v1.3.0', 'name' => 'v1.3.0', 'url' => 'https://github.com/' . $repo . '/releases/tag/v1.3.0',
        'zip_url' => $assetUrl('1.3.0'), 'asset' => 'demo-1.3.0.zip', 'core' => $coreOk, 'compatible' => true, 'problem' => null, 'installable' => true, 'body' => '',
    ]],
]]));
$_SESSION = ['admin_authenticated' => true, 'csrf_token' => 'tok'];
[$st, $body] = tp_capture(fn() => (new AdminRouter($config))->dispatchAdmin('/admin-panel/system'));
check($st === 200 && str_contains($body, 'Доступна v1.3.0'), '«Система»: бейдж «Доступна v1.3.0» (из кеша, без сети)', "status {$st}");
check(str_contains($body, 'action="/admin-panel/system/plugins/update"') && str_contains($body, 'name="plugin" value="demo"') && str_contains($body, 'name="version" value="1.3.0"'), '«Система»: кнопка «Обновить до v1.3.0» (POST)');
check(str_contains($body, 'action="/admin-panel/system/plugins/check-updates"') && str_contains($body, 'Проверить обновления плагинов'), '«Система»: кнопка «Проверить обновления плагинов»');
check(str_contains($body, 'data-update-check="system-plugins"') && str_contains($body, 'data-update-check="system-update"') && str_contains($body, 'Проверить обновления ядра'), '«Система»: асинхронные проверки ядра и плагинов');
check(str_contains($body, 'data-confirm-update=') && str_contains($body, 'id="update-confirm"') && !str_contains($body, 'return confirm('), '«Система»: подтверждение обновления в диалоге');
check(substr_count($body, 'name="_csrf"') >= 3, '«Система»: формы с CSRF-токеном');
check(str_contains($body, 'нет <code>repository</code>'), '«Система»: плагин без repository — «Автообновление недоступно»');
check(str_contains($body, 'Подписей нет'), '«Система»: предупреждение о доверии к репозиториям');
$cache = json_decode((string) file_get_contents($cacheFile), true);
$cache['plugins']['demo']['update']['compatible'] = false;
$cache['plugins']['demo']['update']['installable'] = false;
file_put_contents($cacheFile, json_encode($cache));
[$st, $body] = tp_capture(fn() => (new AdminRouter($config))->dispatchAdmin('/admin-panel/system'));
check(str_contains($body, 'несовместима с ядром') && !str_contains($body, 'name="plugin" value="demo"'), '«Система»: несовместимый релиз — без кнопки');
$cache['plugins']['demo']['update'] = null;
$cache['plugins']['demo']['error'] = 'GitHub API ответил HTTP 403 (лимит запросов?)';
file_put_contents($cacheFile, json_encode($cache));
[$st, $body] = tp_capture(fn() => (new AdminRouter($config))->dispatchAdmin('/admin-panel/system'));
check(str_contains($body, 'Не удалось проверить обновления: GitHub API ответил HTTP 403'), '«Система»: ошибка проверки видна у плагина');

$_GET = ['plugin_updated' => 'demo', 'plugin_version' => '1.2.0'];
[$st, $body] = tp_capture(fn() => (new AdminRouter($config))->dispatchAdmin('/admin-panel/system'));
check(str_contains($body, 'demo') && str_contains($body, 'обновлён до v1.2.0'), '«Система»: тост после обновления');
$_GET = ['plugins_checked' => '2', 'plugins_check_errors' => '1'];
[$st, $body] = tp_capture(fn() => (new AdminRouter($config))->dispatchAdmin('/admin-panel/system'));
check(str_contains($body, 'доступно обновлений — 2') && str_contains($body, 'Не удалось проверить плагинов: 1'), '«Система»: тосты проверки обновлений');
$_GET = [];

$post = static function (string $uri, array $post) use ($config): array {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST = $post;
    try {
        return tp_capture(fn() => (new AdminRouter($config))->dispatchAdmin($uri));
    } finally {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_POST = [];
    }
};
[$st, $body] = $post('/admin-panel/system/plugins/update', ['plugin' => 'demo', 'version' => '1.3.0']);
check($st === 403 && $body === 'CSRF validation failed' && $demoVersion() === '1.2.0', 'POST /system/plugins/update без _csrf → 403, плагин не тронут', "status {$st}");
[$st, $body] = $post('/admin-panel/system/plugins/update', ['plugin' => 'demo', 'version' => '1.3.0', '_csrf' => 'wrong']);
check($st === 403 && $demoVersion() === '1.2.0', 'POST /system/plugins/update с неверным _csrf → 403');
[$st, $body] = $post('/admin-panel/system/plugins/check-updates', []);
check($st === 403 && $body === 'CSRF validation failed', 'POST /system/plugins/check-updates без _csrf → 403');
$_SESSION = [];

tp_rm($site);
ob_end_clean();
out("\n{$passed} passed, {$failures} failed\n");
exit($failures > 0 ? 1 : 0);
