<?php

/**
 * Лёгкие тесты системы плагинов (без PHPUnit): php tests/plugins.php
 *
 * Создаёт временный сайт (skeleton + примеры плагинов) в sys_get_temp_dir() и проверяет
 * PluginManager, PluginContext, маршрутизацию админки и сайта, статику и поведение без plugins/.
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
Env::set('APP_SECRET', 'plugins-test-secret');

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

function tp_plugin(string $site, string $folder, array $manifest, ?string $entry = null, array $files = []): void
{
    $dir = $site . '/plugins/' . $folder;
    @mkdir($dir, 0775, true);
    file_put_contents($dir . '/plugin.json', json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    if ($entry !== null) {
        file_put_contents($dir . '/plugin.php', $entry);
    }
    foreach ($files as $name => $content) {
        @mkdir(dirname($dir . '/' . $name), 0775, true);
        file_put_contents($dir . '/' . $name, $content);
    }
}

/** Выполнить $fn с буфером вывода; вернуть [status, body]. */
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

$site = sys_get_temp_dir() . '/motorchk-plugins-test-' . getmypid();
tp_rm($site);
mkdir($site, 0775, true);
foreach (['content', 'themes', 'config'] as $dir) {
    tp_copy($corePath . '/skeleton/' . $dir, $site . '/' . $dir);
}
@mkdir($site . '/cache', 0775, true);
// Без сетевых запросов к GitHub при рендере админки.
file_put_contents($site . '/cache/update-check.json', json_encode(['checked_at' => time() + 3600, 'current' => Version::current(), 'update' => null]));
file_put_contents($site . '/content/about.md', "---\ntitle: О нас\nslug: about\nparent: null\norder: 0\npublished: true\ntemplate: default\ndescription: ''\nog_title: ''\nog_description: ''\nog_image: ''\n---\n\n## block: content\n\nСтраница about.\n");
$config = tp_config($corePath, $site);
$_SESSION = [];
$_GET = $_POST = $_FILES = [];
$_SERVER['REQUEST_METHOD'] = 'GET';

out("motorchk plugins tests (core v" . Version::current() . ")\n");

// ---------------------------------------------------------------- satisfies
out("\n[версии]\n");
$cases = [
    ['0.3.0', '^0.3', true], ['0.3.9', '^0.3', true], ['0.4.0', '^0.3', false], ['0.2.11', '^0.3', false],
    ['1.2.0', '^1.0', true], ['2.0.0', '^1.0', false], ['0.3.1', '~0.3.1', true], ['0.4.0', '~0.3', false],
    ['0.3.0', '>=0.3.0 <0.4.0', true], ['0.4.0', '>=0.3.0 <0.4.0', false], ['0.3.0', '>= 0.3, < 1', true],
    ['0.3.0', '0.3.0', true], ['0.3.1', '=0.3.0', false], ['0.3.0', '*', true], ['0.3.0', 'abc', null], ['0.3.0', '', null],
];
foreach ($cases as [$v, $c, $expected]) {
    $got = PluginManager::satisfies($v, $c);
    check($got === $expected, "satisfies({$v}, '{$c}')", 'получено ' . var_export($got, true));
}

// ---------------------------------------------------------------- без plugins/
out("\n[без папки plugins/]\n");
$manager = new PluginManager($config);
check(!$manager->hasPluginsDir() && $manager->discover() === [], 'discover() пуст, папка не создаётся');
check(!is_dir($site . '/plugins') && !is_dir($site . '/plugin-data'), 'plugins/ и plugin-data/ не созданы');
[$st, $body] = tp_capture(fn() => (new Router($config))->dispatchPublic('/about'));
check($st === 200 && str_contains($body, 'Страница about.'), 'Markdown-страница /about рендерится', "status {$st}");
$aboutBefore = $body;
[$st, $body] = tp_capture(fn() => (new Router($config))->dispatchPublic('/plugin-assets/hello/hello.css'));
check($st === 404 && str_contains($body, 'Страница не найдена'), '/plugin-assets/... без plugins/ — обычный 404 Markdown-роутера', "status {$st}");
$_SESSION = ['admin_authenticated' => true, 'csrf_token' => 'tok'];
[$st, $body] = tp_capture(fn() => (new AdminRouter($config))->dispatchAdmin('/admin-panel/system'));
check($st === 200 && str_contains($body, 'Плагины не установлены'), '«Система»: «Плагины не установлены»');
check(!str_contains($body, '/admin-panel/plugins/'), 'в меню админки нет пунктов плагинов');
$_SESSION = [];

// ---------------------------------------------------------------- discovery
out("\n[обнаружение и валидация]\n");
tp_copy($corePath . '/docs/examples/plugins/hello', $site . '/plugins/hello');
$ok = static fn(array $extra = []) => $extra + ['name' => 'T', 'version' => '1.0.0', 'core' => '^0.5', 'entry' => 'plugin.php'];
$noop = "<?php\nreturn static function (PluginContext \$p): void {};\n";
tp_plugin($site, 'bad-json', [], null, []);
file_put_contents($site . '/plugins/bad-json/plugin.json', '{oops');
tp_plugin($site, 'Bad_Name', $ok(['id' => 'Bad_Name']), $noop);
tp_plugin($site, 'mismatch', $ok(['id' => 'other']), $noop);
tp_plugin($site, 'too-new', $ok(['id' => 'too-new', 'core' => '>=9.0']), $noop);
tp_plugin($site, 'zz-conflict', $ok(['id' => 'zz-conflict', 'public' => ['prefix' => 'hello']]), $noop);
tp_plugin($site, 'reserved', $ok(['id' => 'reserved', 'public' => ['prefix' => 'api']]), $noop);
tp_plugin($site, 'no-entry', $ok(['id' => 'no-entry']), null);
tp_plugin($site, 'bad-version', $ok(['id' => 'bad-version', 'version' => '1.0']), $noop);
tp_plugin($site, 'escape-entry', $ok(['id' => 'escape-entry', 'entry' => '../hello/plugin.php']), $noop);
tp_plugin($site, 'throws', $ok(['id' => 'throws']), "<?php\nthrow new RuntimeException('boom');\n");
tp_plugin($site, 'bad-return', $ok(['id' => 'bad-return']), "<?php\nreturn 42;\n");
tp_plugin($site, 'about', $ok(['id' => 'about', 'public' => ['prefix' => 'about']]), $noop);
tp_plugin($site, 'hooks', $ok(['id' => 'hooks']), "<?php\nreturn static function (PluginContext \$p): void {\n"
    . "    \$p->onInstall(static function (?string \$from, string \$to, PluginContext \$p): void {\n"
    . "        file_put_contents(\$p->dataDir() . '/calls.log', (\$from ?? 'null') . '->' . \$to . \"\\n\", FILE_APPEND);\n"
    . "    });\n};\n", ['assets/ok.js' => 'ok', 'assets/.hidden.js' => 'x', 'assets/x.php' => '<?php echo 1;', 'assets/data.json' => '{}']);

$manager = new PluginManager($config);
$all = $manager->discover();
$expectError = [
    'bad-json' => 'некорректный JSON',
    'Bad_Name' => 'Имя папки',
    'mismatch' => 'не совпадает',
    'too-new' => 'Несовместим',
    'zz-conflict' => 'уже занят плагином «hello»',
    'reserved' => 'зарезервирован',
    'no-entry' => 'не найден',
    'bad-version' => 'X.Y.Z',
    'escape-entry' => 'entry',
];
foreach ($expectError as $id => $needle) {
    $p = $all[$id] ?? null;
    check($p !== null && $p['status'] === 'error' && str_contains(implode(' ', $p['errors']), $needle), "{$id}: ошибка «{$needle}»", json_encode($p['errors'] ?? null, JSON_UNESCAPED_UNICODE));
}
check(($all['too-new']['compatible'] ?? null) === false, 'too-new: compatible = false');
check(($all['hello']['status'] ?? '') === 'ok' && ($all['hello']['compatible'] ?? null) === true, 'hello валиден и совместим');
check(in_array('Префикс /hello/ также заявлен плагином «zz-conflict» (он отключён)', $all['hello']['warnings'], true), 'hello: предупреждение о конфликте префикса');
check(($all['about']['status'] ?? '') === 'ok' && str_contains(implode(' ', $all['about']['warnings']), 'перекрыты'), 'about: префикс перекрывает Markdown-страницу (предупреждение)');
check(is_file($site . '/plugins/.htaccess') && str_contains((string) file_get_contents($site . '/plugins/.htaccess'), 'Require all denied'), 'plugins/.htaccess создан (deny)');
check($manager->prefixOwner('hello') === 'hello' && $manager->prefixOwner('nope') === null && $manager->prefixOwner('Hello') === null, 'prefixOwner()');
check(array_column($manager->adminMenu(), 'label') === ['Hello'], 'adminMenu(): только «Hello»');

// ---------------------------------------------------------------- boot / install
out("\n[загрузка и install-хуки]\n");
check($manager->boot('too-new') === null, 'несовместимый плагин не загружается');
check($manager->boot('throws') === null && str_contains(implode(' ', $manager->discover()['throws']['errors']), 'boom'), 'исключение в точке входа → ошибка в списке');
check($manager->boot('bad-return') === null && str_contains(implode(' ', $manager->discover()['bad-return']['errors']), 'должна вернуть'), 'точка входа без функции → ошибка');
$hello = $manager->boot('hello');
check($hello instanceof PluginContext, 'hello загружен');
check(is_file($site . '/plugin-data/.htaccess'), 'plugin-data/.htaccess создан (deny)');
check(is_file($site . '/plugin-data/hello/greeting.json'), 'install-хук hello создал greeting.json');
$state = json_decode((string) file_get_contents($site . '/plugin-data/hello/.plugin-state.json'), true);
check(($state['version'] ?? null) === '1.0.0' && array_key_exists('previous_version', $state) && $state['previous_version'] === null, '.plugin-state.json: version 1.0.0, previous null');
check(class_exists('Hello\\GreetingStore'), 'autoload плагина: Hello\\GreetingStore');

$manager->boot('hooks');
(new PluginManager($config))->boot('hooks');
check(file_get_contents($site . '/plugin-data/hooks/calls.log') === "null->1.0.0\n", 'install-хук вызван один раз при повторной загрузке');
$m = json_decode((string) file_get_contents($site . '/plugins/hooks/plugin.json'), true);
$m['version'] = '1.1.0';
file_put_contents($site . '/plugins/hooks/plugin.json', json_encode($m));
(new PluginManager($config))->boot('hooks');
(new PluginManager($config))->boot('hooks');
check(file_get_contents($site . '/plugin-data/hooks/calls.log') === "null->1.0.0\n1.0.0->1.1.0\n", 'upgrade-хук: 1.0.0 → 1.1.0 ровно один раз');
check((new PluginManager($config))->discover()['hooks']['installed_version'] === '1.1.0', 'installed_version из plugin-data');

// ---------------------------------------------------------------- context
out("\n[PluginContext]\n");
check($hello->adminUrl('/api/greeting') === '/admin-panel/plugins/hello/api/greeting' && $hello->adminUrl() === '/admin-panel/plugins/hello', 'adminUrl()');
check($hello->publicUrl('/') === '/hello' && $hello->publicUrl('to/x') === '/hello/to/x', 'publicUrl()');
check($hello->assetUrl('hello.css') === '/plugin-assets/hello/hello.css?v=1.0.0', 'assetUrl()');
$threw = false;
try {
    $hello->dataPath('../secret');
} catch (InvalidArgumentException) {
    $threw = true;
}
check($threw, 'dataPath() отклоняет ..');
$threw = false;
try {
    $hello->dataPath('.plugin-state.json');
} catch (InvalidArgumentException) {
    $threw = true;
}
check($threw, 'dataPath() отклоняет скрытые файлы');
$hello->writeJson('sub/x.json', ['a' => 1]);
check($hello->readJson('sub/x.json') === ['a' => 1] && $hello->readJson('missing.json', 'def') === 'def', 'writeJson()/readJson()');
check($hello->deleteFile('sub/x.json') && $hello->readFile('sub/x.json') === null, 'deleteFile()');
// readJsonStrict(): нет файла — default, повреждённый — исключение с именем файла, файл не меняется
$strictErr = static function (callable $fn): ?PluginDataException {
    try {
        $fn();
    } catch (PluginDataException $e) {
        return $e;
    }
    return null;
};
check($hello->readJsonStrict('missing.json') === [] && $hello->readJsonStrict('missing.json', ['d' => 1]) === ['d' => 1] && $hello->readJsonStrict('missing.json', null) === null, 'readJsonStrict(): нет файла — значение по умолчанию');
$hello->writeJson('strict/ok.json', ['a' => [1, 2]]);
$hello->writeFile('strict/list.json', '[1, 2]');
check($hello->readJsonStrict('strict/ok.json') === ['a' => [1, 2]] && $hello->readJsonStrict('strict/list.json') === [1, 2], 'readJsonStrict(): объект и массив');
$hello->writeFile('strict/bad.json', '{"a": [ broken');
$e = $strictErr(fn() => $hello->readJsonStrict('strict/bad.json', ['fallback']));
check($e !== null && str_contains($e->getMessage(), 'plugin-data/hello/strict/bad.json') && str_contains($e->getMessage(), 'не JSON') && $e->relativePath() === 'strict/bad.json' && $e instanceof RuntimeException, 'readJsonStrict(): не JSON — PluginDataException с именем файла');
check($hello->readFile('strict/bad.json') === '{"a": [ broken' && $hello->readJson('strict/bad.json', 'def') === 'def', 'повреждённый файл не перезаписан; readJson() по-прежнему возвращает default');
foreach (['"text"', '42', 'null', 'true', ''] as $scalar) {
    $hello->writeFile('strict/scalar.json', $scalar);
    $e = $strictErr(fn() => $hello->readJsonStrict('strict/scalar.json'));
    check($e !== null && str_contains($e->getMessage(), 'plugin-data/hello/strict/scalar.json'), 'readJsonStrict(): верхний уровень ' . ($scalar === '' ? 'пустой файл' : $scalar) . ' — исключение');
}
mkdir($hello->dataPath('strict/dir.json'), 0775, true);
$e = $strictErr(fn() => $hello->readJsonStrict('strict/dir.json'));
check($e !== null && str_contains($e->getMessage(), 'не читается'), 'readJsonStrict(): не файл — исключение «не читается»');
$threw = false;
try {
    $hello->readJsonStrict('../x.json');
} catch (InvalidArgumentException) {
    $threw = true;
}
check($threw, 'readJsonStrict(): путь проверяется как в dataPath()');
// формы, почта, заявки, окружение (0.5.0)
$_SERVER['HTTP_HOST'] = 'example.test';
$_SERVER['HTTPS'] = 'on';
check($hello->absoluteUrl('/hello/x?a=1') === 'https://example.test/hello/x?a=1' && $hello->absoluteUrl('relative') === 'relative', 'absoluteUrl()');
unset($_SERVER['HTTP_HOST'], $_SERVER['HTTPS']);
check($hello->honeypotName() === Form::honeypotName() && $hello->honeypotName() !== '', 'honeypotName()');
$other = new PluginContext($config, $all['hooks'], $manager);
check($hello->rateLimit('ip-1', 2, 60) && $hello->rateLimit('ip-1', 2, 60) && !$hello->rateLimit('ip-1', 2, 60), 'rateLimit(): третья попытка при лимите 2 — false');
check($hello->rateLimit('ip-2', 2, 60), 'rateLimit(): другой ключ — свой счётчик');
check($other->rateLimit('ip-1', 2, 60), 'rateLimit(): тот же ключ у другого плагина — свой счётчик');
$rlFiles = array_map('basename', glob($config['cache_path'] . '/rate-limit/*.json') ?: []);
check($rlFiles !== [] && array_filter($rlFiles, static fn(string $f): bool => !str_starts_with($f, 'plugin_hello_') && !str_starts_with($f, 'plugin_hooks_')) === [], 'rateLimit(): файлы с id плагина в имени', implode(', ', $rlFiles));
$threw = false;
try {
    $hello->rateLimit('k', 0, 60);
} catch (InvalidArgumentException) {
    $threw = true;
}
check($threw, 'rateLimit(): limit 0 — исключение');
$mailErr = static function (callable $fn): ?Throwable {
    try {
        $fn();
    } catch (Throwable $e) {
        return $e;
    }
    return null;
};
$e = $mailErr(fn() => $hello->mail('S', 'B', ''));
check($e instanceof InvalidArgumentException, 'mail(): пустой получатель — исключение, без SMTP_TO');
Env::set('SMTP_TO', 'fallback@example.test');
$e = $mailErr(fn() => $hello->mail('S', 'B', ''));
check($e instanceof InvalidArgumentException, 'mail(): SMTP_TO не подставляется');
$e = $mailErr(fn() => $hello->mail('S', 'B', "a@example.test\nBcc: x@example.test"));
check($e instanceof InvalidArgumentException, 'mail(): перевод строки в адресе — исключение');
$e = $mailErr(fn() => $hello->mail('S', 'B', 'a@example.test', 'not-an-email'));
check($e instanceof InvalidArgumentException, 'mail(): некорректный Reply-To — исключение');
$e = $mailErr(fn() => $hello->mail('S', 'B', 'a@example.test', 'client@example.test'));
check($e instanceof RuntimeException && !$e instanceof InvalidArgumentException, 'mail(): верные адреса доходят до Mailer (без SMTP — RuntimeException)', $e ? get_class($e) . ': ' . $e->getMessage() : 'нет исключения');
$sub = $hello->submissions()->create('hello-form', ['name' => 'Ivan'], [['name' => 'name', 'type' => 'text', 'required' => true, 'label' => 'Имя']], '127.0.0.1');
$stored = (new SubmissionStore($config))->get('hello-form', $sub['id']);
check(($stored['mail_status'] ?? null) === 'pending' && ($stored['payload']['name'] ?? null) === 'Ivan' && ($stored['ip'] ?? null) === '127.0.0.1', 'submissions()->create(): заявка в «Заявках», письмо pending');
$hello->submissions()->markMail('hello-form', $sub['id'], 'failed', 'smtp down');
$stored = (new SubmissionStore($config))->get('hello-form', $sub['id']);
check(($stored['mail_status'] ?? null) === 'failed' && ($stored['mail_error'] ?? null) === 'smtp down', 'markMail(failed) с ошибкой');
$hello->submissions()->markMail('hello-form', $sub['id'], 'sent', 'ignored');
$stored = (new SubmissionStore($config))->get('hello-form', $sub['id']);
check(($stored['mail_status'] ?? null) === 'sent' && array_key_exists('mail_error', $stored) && $stored['mail_error'] === null, 'markMail(sent) очищает ошибку');
$e = $mailErr(fn() => $hello->submissions()->markMail('hello-form', $sub['id'], 'pending'));
check($e instanceof InvalidArgumentException, 'markMail(): только sent/failed');
Env::set('HELLO_PLUGIN_KEY', 'v1');
check($hello->env('HELLO_PLUGIN_KEY') === 'v1' && $hello->env('HELLO_PLUGIN_MISSING') === null, 'env()');
$r = $hello->matchRoute('public', 'GET', '/to/Ivan');
check(($r['params']['name'] ?? null) === 'Ivan', 'маршрут /to/{name}');
$r = $hello->matchRoute('admin', 'POST', '/api/greeting');
check(($r['allowed'] ?? null) === ['GET', 'PUT'], 'неподходящий метод → allowed [GET, PUT]');
check($hello->matchRoute('admin', 'GET', '/nope') === null, 'нет маршрута → null');
$star = new PluginContext($config, $all['hooks'], $manager);
$star->adminRoute('GET', '/files/{path*}', static fn() => null);
check(($star->matchRoute('admin', 'GET', '/files/a/b.png')['params']['path'] ?? null) === 'a/b.png', '{path*} захватывает путь');
check($star->matchRoute('admin', 'GET', '/files/a/../b.png') === null, '{path*} отклоняет ..');

// ---------------------------------------------------------------- assets
out("\n[статика /plugin-assets/]\n");
check(($manager->resolveAsset('hello/hello.css')['type'] ?? '') === 'text/css; charset=UTF-8', 'hello/hello.css отдаётся');
check(($manager->resolveAsset('hooks/ok.js')['type'] ?? '') === 'text/javascript; charset=UTF-8', 'hooks/ok.js отдаётся');
foreach (['hello/../plugin.json', 'hello/plugin.json', 'hello/plugin.php', 'hooks/x.php', 'hooks/data.json', 'hooks/.hidden.js', 'hello/%2e%2e/plugin.php', 'too-new/ok.js', 'nope/a.css', 'hello/lib/Hello/GreetingStore.php', 'hello/'] as $bad) {
    check($manager->resolveAsset($bad) === null, "отказ: {$bad}");
}
[$st, $body] = tp_capture(fn() => (new Router($config))->dispatchPublic('/plugin-assets/hello/hello.css?v=1.0.0'));
check($st === 200 && str_contains($body, '.hello-card'), 'Router: /plugin-assets/hello/hello.css → 200');
[$st] = tp_capture(fn() => (new Router($config))->dispatchPublic('/plugin-assets/hello/../plugin.json'));
check($st === 404, 'Router: /plugin-assets/hello/../plugin.json → 404');

// ---------------------------------------------------------------- public
out("\n[сайт]\n");
$_SESSION = [];
[$st, $body] = tp_capture(fn() => (new Router($config))->dispatchPublic('/hello/'));
check($st === 200 && str_contains($body, 'Привет из плагина hello!') && str_contains($body, '<header'), '/hello/ в шаблоне темы', "status {$st}");
check(str_contains($body, '/plugin-assets/hello/hello-public.js?v=1.0.0'), '/hello/: скрипт плагина подключён через scripts');
[$st, $body] = tp_capture(fn() => (new Router($config))->dispatchPublic('/hello'));
check($st === 200 && str_contains($body, 'Привет из плагина hello!'), '/hello без слеша');
[$st, $body] = tp_capture(fn() => (new Router($config))->dispatchPublic('/hello/to/Ivan'));
check($st === 200 && str_contains($body, 'Привет из плагина hello!, Ivan'), '/hello/to/Ivan');
[$st, $body] = tp_capture(fn() => (new Router($config))->dispatchPublic('/hello/to/%3Cscript%3E'));
check($st === 200 && str_contains($body, 'Ivan') === false && str_contains($body, '&lt;script&gt;') && !str_contains($body, ', <script>'), '/hello/to/%3Cscript%3E — параметр экранирован', "status {$st}");
[$st] = tp_capture(fn() => (new Router($config))->dispatchPublic('/hello/to/a%2Fb'));
check($st === 404, '/hello/to/a%2Fb — закодированный / в {name} отклонён', "status {$st}");
[$st, $body] = tp_capture(fn() => (new Router($config))->dispatchPublic('/hello/api/greeting'));
check($st === 200 && json_decode($body, true)['greeting'] === 'Привет из плагина hello!', '/hello/api/greeting — сырой JSON');
[$st] = tp_capture(fn() => (new Router($config))->dispatchPublic('/hello/nope'));
check($st === 404, '/hello/nope → 404');
$_SERVER['REQUEST_METHOD'] = 'POST';
[$st, $body] = tp_capture(fn() => (new Router($config))->dispatchPublic('/hello/api/echo'));
check($st === 403 && str_contains($body, 'CSRF'), 'POST /hello/api/echo без CSRF → 403');
$_SESSION = ['csrf_token' => 'pub'];
$_POST = ['_csrf' => 'pub'];
[$st, $body] = tp_capture(fn() => (new Router($config))->dispatchPublic('/hello/api/echo'));
check($st === 200 && (json_decode($body, true)['ok'] ?? false) === true, 'POST /hello/api/echo с CSRF → 200');
[$st] = tp_capture(fn() => (new Router($config))->dispatchPublic('/hello/api/greeting'));
check($st === 405, 'POST на GET-маршрут → 405');
$_POST = [];
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SESSION = [];
[$st, $body] = tp_capture(fn() => (new Router($config))->dispatchPublic('/about'));
check($st === 404 && !str_contains($body, 'Страница about.'), '/about занят плагином about без маршрутов → 404 (префикс важнее Markdown)', "status {$st}");
tp_rm($site . '/plugins/about');
[$st, $body] = tp_capture(fn() => (new Router($config))->dispatchPublic('/about'));
$normalize = static fn(string $html): string => preg_replace('/name="(_csrf|form_ts|_form_contract)" value="[^"]*"/', '', $html);
check($st === 200 && $normalize($body) === $normalize($aboutBefore), '/about с плагинами (без конфликта) — тот же HTML, что без plugins/');
$_SESSION = ['admin_authenticated' => true, 'csrf_token' => 'tok'];
[$st, $body] = tp_capture(fn() => (new Router($config))->dispatchPublic('/hello/'));
check(!str_contains($body, 'data-inline-edit') && !str_contains($body, 'inline-edit.js'), 'страница плагина без inline-edit для админа');
$_SESSION = [];

// ---------------------------------------------------------------- admin
out("\n[админка]\n");
$_SESSION = ['admin_authenticated' => true, 'csrf_token' => 'tok'];
[$st, $body] = tp_capture(fn() => (new AdminRouter($config))->dispatchAdmin('/admin-panel/plugins/hello'));
check($st === 200 && str_contains($body, 'id="hello-admin"') && str_contains($body, '<title>Hello — Админка motorchk</title>'), 'GET /admin-panel/plugins/hello в layout', "status {$st}");
check(str_contains($body, 'href="/admin-panel/plugins/hello" class="font-medium text-sky-700'), 'пункт меню «Hello» активен');
check(str_contains($body, '/plugin-assets/hello/hello.css?v=1.0.0" rel="stylesheet"') && str_contains($body, 'hello-admin.js?v=1.0.0'), 'styles/scripts плагина в <head>');
check(strpos($body, '>Система</a>') < strpos($body, '>Hello</a>'), 'пункт плагина после встроенных');
[$st, $body] = tp_capture(fn() => (new AdminRouter($config))->dispatchAdmin('/admin-panel/plugins/hello/fullscreen'));
check($st === 200 && str_contains($body, 'mc-admin--fullscreen') && str_contains($body, 'id="mc-admin-main"'), 'полноэкранный layout');
[$st, $body] = tp_capture(fn() => (new AdminRouter($config))->dispatchAdmin('/admin-panel/plugins/hello/api/greeting'));
check($st === 200 && (json_decode($body, true)['greeting'] ?? '') === 'Привет из плагина hello!', 'GET API → JSON');
[$st, $body] = tp_capture(fn() => (new AdminRouter($config))->dispatchAdmin('/admin-panel/plugins/hello/nope'));
check($st === 404, 'неизвестный маршрут плагина → 404');
[$st, $body] = tp_capture(fn() => (new AdminRouter($config))->dispatchAdmin('/admin-panel/plugins/too-new'));
check($st === 404 && str_contains($body, 'Несовместим'), 'несовместимый плагин → 404 с причиной');
[$st, $body] = tp_capture(fn() => (new AdminRouter($config))->dispatchAdmin('/admin-panel/pages'));
check($st === 200 && str_contains($body, '<title>Админка — motorchk</title>') && str_contains($body, '>Hello</a>'), 'встроенные страницы: прежний title, меню с плагином');
// «Заявки» при плагине с пунктом меню: переменная шаблона $item (заявка) не затирается меню плагинов
$sub = (new SubmissionStore($config))->create('contact', ['name' => 'Тест заявки'], [['name' => 'name', 'type' => 'text', 'required' => true, 'label' => 'Имя']], '127.0.0.1');
[$st, $body] = tp_capture(fn() => (new AdminRouter($config))->dispatchAdmin('/admin-panel/submissions/contact/' . $sub['id']));
check($st === 200 && str_contains($body, 'Тест заявки') && str_contains($body, $sub['id']) && str_contains($body, '>Hello</a>'), 'заявка открывается при плагине с пунктом меню');
tp_rm($config['submissions_path'] . '/contact');

// PUT-обработчик напрямую (тело запроса в CLI не передать через php://input)
$route = $hello->matchRoute('admin', 'PUT', '/api/greeting');
[$st, $body] = tp_capture(fn() => ($route['handler'])(new PluginRequest('PUT', '/api/greeting', [], [], [], [], [], '{"greeting":"Здравствуй"}'), $hello));
check($st === 200 && (json_decode($body, true)['greeting'] ?? '') === 'Здравствуй', 'PUT-обработчик сохраняет приветствие');
[$st, $body] = tp_capture(fn() => ($route['handler'])(new PluginRequest('PUT', '/api/greeting', [], [], [], [], [], '{"greeting":""}'), $hello));
check($st === 422, 'PUT с пустым приветствием → 422');

// CSRF в админке: AdminRouter::requireCsrf() бросает CsrfException, dispatchAdmin() отвечает 403
$adminCase = static function (string $method, string $uri, ?string $header = null, ?string $post = null) use ($config): array {
    $_SERVER['REQUEST_METHOD'] = $method;
    unset($_SERVER['HTTP_X_CSRF_TOKEN']);
    if ($header !== null) {
        $_SERVER['HTTP_X_CSRF_TOKEN'] = $header;
    }
    $_POST = $post !== null ? ['_csrf' => $post] : [];
    try {
        return tp_capture(fn() => (new AdminRouter($config))->dispatchAdmin($uri));
    } finally {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        unset($_SERVER['HTTP_X_CSRF_TOKEN']);
        $_POST = [];
    }
};
[$st, $body] = $adminCase('PUT', '/admin-panel/plugins/hello/api/greeting');
check($st === 403 && $body === 'CSRF validation failed', 'PUT без X-CSRF-Token → 403 CSRF', "status {$st}: {$body}");
[$st, $body] = $adminCase('PUT', '/admin-panel/plugins/hello/api/greeting', 'wrong');
check($st === 403 && $body === 'CSRF validation failed', 'PUT с неверным X-CSRF-Token → 403 CSRF', "status {$st}: {$body}");
[$st, $body] = $adminCase('PUT', '/admin-panel/plugins/hello/api/greeting', 'tok');
check($st === 422, 'PUT с верным X-CSRF-Token доходит до плагина (пустое тело → 422)', "status {$st}: {$body}");
[$st] = $adminCase('DELETE', '/admin-panel/plugins/hello/api/greeting', 'tok');
check($st === 405, 'DELETE на GET/PUT-маршрут → 405');
$themeBefore = SiteMeta::getTheme($config);
[$st, $body] = $adminCase('POST', '/admin-panel/system/theme', null, 'wrong');
check($st === 403 && $body === 'CSRF validation failed' && SiteMeta::getTheme($config) === $themeBefore, 'встроенный маршрут: POST /system/theme с неверным _csrf → 403, тема не меняется', "status {$st}: {$body}");
[$st, $body] = $adminCase('POST', '/admin-panel/logout');
check($st === 403 && !empty($_SESSION['admin_authenticated']), 'POST /logout без _csrf → 403, сессия цела', "status {$st}");

[$st, $body] = tp_capture(fn() => (new AdminRouter($config))->dispatchAdmin('/admin-panel/system'));
check($st === 200 && str_contains($body, 'Hello — пример плагина') && str_contains($body, '✗ Не загружен: Несовместим'), '«Система»: список плагинов с причинами');
check(str_contains($body, 'Ошибка загрузки: boom'), '«Система»: ошибка загрузки видна');
check(str_contains($body, 'Запись в <code>' . $site . '/plugin-data/hello'), '«Система»: запись в data dir');

$_SESSION = [];

tp_rm($site);
ob_end_clean();
out("\n{$passed} passed, {$failures} failed\n");
exit($failures > 0 ? 1 : 0);
