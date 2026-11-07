<?php

/**
 * Вкладки заявок, столбцы и страницы списка (без PHPUnit): php tests/submissions.php
 *
 * Фикстуры — синтетические: example.test, диапазон IP 203.0.113.0/24, без персональных данных.
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
Env::set('APP_SECRET', 'submissions-test-secret');
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

function sa_rm(string $path): void
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
            sa_rm($path . '/' . $item);
        }
    }
    rmdir($path);
}

function sa_config(string $corePath, string $site): array
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
        'plugins_path' => $site . '/plugins',
        'plugin_data_path' => $site . '/plugins-data',
        'installed' => true,
    ];
}

function sa_plugin(string $site, string $id, array $extra, string $entry): void
{
    $dir = $site . '/plugins/' . $id;
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('plugins');
    }
    $manifest = array_merge([
        'id' => $id,
        'name' => $id,
        'version' => '1.0.0',
        'core' => '>=0.5.0',
        'entry' => 'plugin.php',
    ], $extra);
    file_put_contents($dir . '/plugin.json', json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    file_put_contents($dir . '/plugin.php', $entry);
}

/**
 * @param array<string, mixed> $payload
 * @param list<array<string, mixed>> $contract
 */
function sa_submission(string $site, string $formId, string $id, array $payload, array $contract, string $mail = 'pending'): void
{
    $dir = $site . '/submissions/' . $formId;
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('submissions');
    }
    $data = [
        'id' => $id,
        'form_id' => $formId,
        'created_at' => str_replace('_', 'T', substr($id, 0, 17)) . '+00:00',
        'ip' => '203.0.113.10',
        'payload' => $payload,
        'contract' => $contract,
        'mail_status' => $mail,
        'mail_error' => null,
    ];
    file_put_contents(
        $dir . '/' . $id . '.json',
        json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)
    );
}

/** @return array{code: int, headers: list<string>, body: string} */
function sa_http(string $corePath, string $site, array $config, string $method, string $uri, array $session, array $get, array $post): array
{
    $in = $site . '/dispatch-in.json';
    $out = $site . '/dispatch-out.json';
    $probe = $site . '/dispatch-probe.php';
    @unlink($out);
    if (!is_file($probe)) {
        file_put_contents($probe, <<<'PHP'
<?php
$in = json_decode((string) file_get_contents($argv[1]), true);
$outPath = $in['out'];
$corePath = $in['core'];
ini_set('error_log', dirname($outPath) . '/php-error.log');
require_once $corePath . '/vendor/autoload.php';
spl_autoload_register(static function (string $class) use ($corePath): void {
    $file = $corePath . '/includes/' . $class . '.php';
    if (is_file($file)) {
        require $file;
    }
});
Env::set('APP_SECRET', 'submissions-test-secret');
$_SESSION = is_array($in['session']) ? $in['session'] : [];
$_GET = is_array($in['get']) ? $in['get'] : [];
$_POST = is_array($in['post']) ? $in['post'] : [];
$_SERVER['REQUEST_METHOD'] = (string) $in['method'];
$_SERVER['REQUEST_URI'] = (string) $in['uri'];
http_response_code(200);
ob_start();
register_shutdown_function(static function () use ($outPath): void {
    $body = ob_get_contents();
    file_put_contents($outPath, json_encode([
        'code' => http_response_code(),
        'headers' => headers_list(),
        'body' => is_string($body) ? $body : '',
    ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR));
});
(new AdminRouter($in['config']))->dispatchAdmin((string) $in['uri']);
PHP);
    }
    file_put_contents($in, json_encode([
        'core' => $corePath,
        'out' => $out,
        'config' => $config,
        'method' => $method,
        'uri' => $uri,
        'session' => $session,
        'get' => $get,
        'post' => $post,
    ], JSON_THROW_ON_ERROR));
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($probe) . ' ' . escapeshellarg($in);
    exec($cmd, $unused, $exit);
    $raw = is_file($out) ? (string) file_get_contents($out) : '';
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return ['code' => 0, 'headers' => [], 'body' => 'probe exit ' . $exit . ' ' . substr($raw, 0, 200)];
    }

    return [
        'code' => (int) ($data['code'] ?? 0),
        'headers' => is_array($data['headers'] ?? null) ? $data['headers'] : [],
        'body' => (string) ($data['body'] ?? ''),
    ];
}

function sa_table(string $html): string
{
    $cut = strpos($html, '<section class="mc-sheet">');
    $head = $cut === false ? $html : substr($html, 0, $cut);
    $start = strpos($head, '<table');
    $end = strrpos($head, '</table>');
    if ($start === false || $end === false) {
        return '';
    }

    return substr($head, $start, $end - $start + strlen('</table>'));
}

$site = sys_get_temp_dir() . '/motorchk-submissions-test-' . getmypid();
sa_rm($site);
mkdir($site . '/content/forms', 0775, true);
mkdir($site . '/submissions', 0775, true);
mkdir($site . '/cache', 0775, true);
mkdir($site . '/plugins', 0775, true);
ini_set('error_log', $site . '/php-error.log');
file_put_contents($site . '/content/forms/contact.yaml', "id: contact\nlabel: Обратная связь\nsubject: Заявка\nsuccess_message: Спасибо\n");
file_put_contents($site . '/content/forms/emptyform.yaml', "id: emptyform\nlabel: Пустая\nsubject: Заявка\nsuccess_message: Спасибо\n");
file_put_contents($site . '/cache/update-check.json', json_encode([
    'checked_at' => time() + 3600,
    'current' => Version::current(),
    'update' => null,
]));
$config = sa_config($corePath, $site);

$contractName = static fn (string $label): array => [
    ['name' => 'name', 'type' => 'text', 'required' => true, 'label' => $label],
    ['name' => 'email', 'type' => 'email', 'required' => false, 'label' => 'Email'],
    ['name' => 'news', 'type' => 'checkbox', 'required' => false, 'label' => 'Рассылка', 'multiple' => false],
];

sa_submission($site, 'contact', '2020-01-01_000000_old1', [
    'name' => 'Старая заявка',
    'email' => 'old@example.test',
    'news' => false,
], $contractName('Старое имя'), 'sent');
sa_submission($site, 'contact', '2024-05-01_000000_new1', [
    'name' => '<script>alert(1)</script>',
    'email' => 'new@example.test',
    'news' => true,
    'topics' => ['a', 'b'],
], $contractName('Новое имя'), 'failed');
sa_submission($site, 'legacy-order', '2023-03-03_000000_leg1', [
    'name' => 'Старая форма',
], [['name' => 'name', 'type' => 'text', 'required' => true, 'label' => 'Имя']], 'pending');

$ordersEntry = <<<'PHP'
<?php
return static function (PluginContext $plugin): void {
    file_put_contents(dirname($plugin->dir(), 2) . '/orders-booted.txt', '1');
    $plugin->submissions()->registerForm('customizer-order', 'Заказы конфигуратора', [
        'item' => 'Изделие',
        'product_model' => 'Модель',
        'name' => 'Заказчик',
        'email' => 'Email',
        'office' => 'Исполнитель',
    ]);
};
PHP;
// dirname(plugin dir, 2) из plugins/orders — это корень временного сайта только если plugins лежит в корне.
// Надёжнее писать маркер рядом с плагином через известный путь: подменим после heredoc.
$ordersEntry = str_replace(
    "dirname(\$plugin->dir(), 2) . '/orders-booted.txt'",
    var_export($site . '/orders-booted.txt', true),
    $ordersEntry
);
sa_plugin($site, 'orders', ['submission_forms' => true], $ordersEntry);
sa_plugin($site, 'quiet', [], "<?php\nreturn static function (PluginContext \$plugin): void {\n    file_put_contents(" . var_export($site . '/quiet-booted.txt', true) . ", '1');\n};\n");
sa_plugin($site, 'boom', [], "<?php\nreturn static function (PluginContext \$plugin): void {\n    file_put_contents(" . var_export($site . '/boom-booted.txt', true) . ", '1');\n    throw new RuntimeException('boom');\n};\n");
sa_plugin($site, 'nofile', [], "<?php\nreturn static function (PluginContext \$plugin): void {\n    \$plugin->submissions()->registerForm('x-form', 'X', ['a' => 'A']);\n};\n");
sa_plugin($site, 'badflag', ['submission_forms' => 'yes'], "<?php\nreturn static function (PluginContext \$plugin): void {};\n");
sa_plugin($site, 'a-first', ['submission_forms' => true], "<?php\nreturn static function (PluginContext \$plugin): void {\n    \$plugin->submissions()->registerForm('shared-form', 'Первый', ['item' => 'Изделие']);\n};\n");
sa_plugin($site, 'b-second', ['submission_forms' => true], "<?php\nreturn static function (PluginContext \$plugin): void {\n    \$plugin->submissions()->registerForm('shared-form', 'Второй', ['item' => 'Другое']);\n};\n");

sa_submission($site, 'customizer-order', '2024-06-01_000000_ord1', [
    'item' => 'Стол',
    'product_model' => 'A1',
    'name' => 'Заказчик',
    'email' => 'buyer@example.test',
    'office' => 'Северный',
    'model' => 'серверная модель',
], [
    ['name' => 'item', 'type' => 'text', 'required' => true, 'label' => 'Изделие'],
    ['name' => 'product_model', 'type' => 'text', 'required' => true, 'label' => 'Модель'],
    ['name' => 'name', 'type' => 'text', 'required' => true, 'label' => 'Заказчик'],
    ['name' => 'email', 'type' => 'email', 'required' => true, 'label' => 'Email'],
    ['name' => 'office', 'type' => 'text', 'required' => true, 'label' => 'Исполнитель'],
    ['name' => 'model', 'type' => 'text', 'required' => false, 'label' => 'Представлена модель'],
], 'sent');
sa_submission($site, 'customizer-order', '2024-06-02_000000_ord2', [
    'item' => 'Стул',
    'product_model' => 'B2',
    'name' => 'Второй',
    'email' => 'other@example.test',
], [
    ['name' => 'item', 'type' => 'text', 'required' => true, 'label' => 'Изделие'],
    ['name' => 'model', 'type' => 'text', 'required' => false, 'label' => 'Представлена модель'],
], 'pending');

for ($i = 0; $i <= 200; $i++) {
    $mail = $i % 2 === 0 ? 'sent' : 'failed';
    sa_submission($site, 'paged', sprintf('2020-01-01_%06d_aa', $i), ['name' => 'row-' . $i], [
        ['name' => 'name', 'type' => 'text', 'required' => true, 'label' => 'Имя'],
    ], $mail);
}
for ($i = 0; $i < 60; $i++) {
    sa_submission($site, 'letters', sprintf('2021-02-02_%06d_ff', $i), ['token' => 'FAILEDTOKEN'], [
        ['name' => 'token', 'type' => 'text', 'required' => false, 'label' => 'Метка'],
    ], 'failed');
}
for ($i = 0; $i < 10; $i++) {
    sa_submission($site, 'letters', sprintf('2021-01-01_%06d_ss', $i), ['token' => 'SENTTOKEN'], [
        ['name' => 'token', 'type' => 'text', 'required' => false, 'label' => 'Метка'],
    ], 'sent');
}

$beforeHash = hash_file('sha256', $site . '/submissions/contact/2020-01-01_000000_old1.json');
$orderHash = hash_file('sha256', $site . '/submissions/customizer-order/2024-06-01_000000_ord1.json');

out("Столбцы и хранение\n");
$store = new SubmissionColumnStore($config);
check(str_ends_with($store->path(), '/content/_submission-columns.json'), 'файл настроек лежит в content/, не в каталоге заявок');
check(!str_contains($store->path(), '/content/forms/'), 'файл настроек не в content/forms');
check($store->get('contact') === null, 'пока админ не сохранял столбцы, настройки нет');
$store->save('contact', [
    ['key' => 'email', 'label' => 'Почта клиента'],
    ['key' => 'name', 'label' => 'Имя'],
]);
check($store->get('contact')[0]['key'] === 'email' && $store->get('contact')[1]['label'] === 'Имя', 'порядок и подпись сохраняются');
check(hash_file('sha256', $site . '/submissions/contact/2020-01-01_000000_old1.json') === $beforeHash, 'сохранение столбцов не меняет файл заявки');
$store->reset('contact');
check($store->get('contact') === null, 'сброс удаляет настройку формы');
$store->reset('contact');
check(is_file($store->path()), 'повторный сброс не падает');
$badLabel = false;
try {
    SubmissionColumns::fromMap(['name' => '']);
} catch (InvalidArgumentException) {
    $badLabel = true;
}
check($badLabel, 'пустая подпись столбца отклоняется');
$badList = false;
try {
    SubmissionColumns::fromMap([['key' => 'name', 'label' => 'Имя']]);
} catch (InvalidArgumentException) {
    $badList = true;
}
check($badList, 'список вместо ключ => подпись отклоняется');
check(SubmissionColumns::format(false) === 'нет' && SubmissionColumns::format(true) === 'да', 'флажок в ячейке: нет / да');
check(SubmissionColumns::format(['a', 'b']) === 'a, b', 'список в ячейке через запятую');
check(SubmissionColumns::format(null) === '—', 'null в ячейке — тире');

out("Список и страницы\n");
$submissions = new SubmissionStore($config);
$limited = $submissions->list('paged', null, 200);
check(count($limited) === 200, 'list() по-прежнему отдаёт не больше запрошенного лимита', (string) count($limited));
$page1 = $submissions->paginate('paged', null, 1);
check($page1['total'] === 201 && $page1['pages'] === 5 && count($page1['items']) === 50, 'paginate снимает потолок 200: 201 заявка, 5 страниц');
check(($page1['items'][0]['id'] ?? '') === '2020-01-01_000200_aa', 'первая страница — самые новые');
check(($page1['items'][49]['id'] ?? '') === '2020-01-01_000151_aa', 'на первой странице 50 записей до 000151');
$page5 = $submissions->paginate('paged', null, 99);
check($page5['page'] === 5 && count($page5['items']) === 1 && ($page5['items'][0]['id'] ?? '') === '2020-01-01_000000_aa', 'страница за последней прижимается к концу, старая заявка на месте');
$failed = $submissions->paginate('letters', 'failed', 2, 50);
check($failed['total'] === 60 && $failed['page'] === 2 && count($failed['items']) === 10, 'фильтр почты учитывает все записи, не только первую страницу');
check(array_filter($failed['items'], static fn (array $row): bool => ($row['mail_status'] ?? '') !== 'failed') === [], 'на странице фильтра нет чужого статуса');
$dates = $submissions->paginate('contact', null, 1);
check(($dates['items'][0]['id'] ?? '') === '2024-05-01_000000_new1' && ($dates['items'][1]['id'] ?? '') === '2020-01-01_000000_old1', 'внутри формы новые выше старых');
$labels = $submissions->fieldLabels('contact');
check(($labels['name'] ?? '') === 'Новое имя' && ($labels['email'] ?? '') === 'Email' && ($labels['topics'] ?? '') === 'topics', 'кандидаты: подпись из новой заявки, ключ без контракта остаётся ключом');
check($submissions->formIds() !== [] && in_array('legacy-order', $submissions->formIds(), true) && in_array('emptyform', $submissions->formIds(), true) === false, 'каталоги заявок видны, пустая YAML-форма каталога не создаёт');

out("Вкладки и регистрация плагина\n");
$plugins = new PluginManager($config);
check(($plugins->discover()['badflag']['status'] ?? '') === 'error', 'submission_forms не true — плагин невалиден');
$noFlag = $plugins->boot('nofile');
$threw = false;
try {
    $noFlag?->submissions()->registerForm('x-form', 'X', ['a' => 'A']);
} catch (LogicException) {
    $threw = true;
}
check($noFlag === null || $threw, 'без submission_forms форма не регистрируется');
$plugins->bootSubmissionForms();
check(is_file($site . '/orders-booted.txt'), 'плагин с submission_forms загружается для «Заявок»');
check(!is_file($site . '/quiet-booted.txt') && !is_file($site . '/boom-booted.txt'), 'плагины без флага на «Заявках» не загружаются');
$registered = $plugins->submissionForms()['customizer-order'] ?? null;
check(
    is_array($registered)
    && $registered['label'] === 'Заказы конфигуратора'
    && array_column($registered['columns'], 'key') === ['item', 'product_model', 'name', 'email', 'office'],
    'registerForm задаёт подпись вкладки и порядок столбцов'
);
check(!isset($plugins->submissionForms()['shared-form']) || ($plugins->submissionForms()['shared-form']['plugin'] ?? '') === 'a-first', 'повторный id формы остаётся у первого плагина');
check(($plugins->discover()['b-second']['status'] ?? '') === 'error', 'второй плагин с тем же id формы не загружается');
@unlink($site . '/orders-booted.txt');

$views = new SubmissionViews($config, $plugins);
$tabIds = array_column($views->tabs(), 'id');
check(in_array('contact', $tabIds, true) && in_array('emptyform', $tabIds, true) && in_array('legacy-order', $tabIds, true) && in_array('customizer-order', $tabIds, true), 'вкладки: YAML, пустая форма, старый id и форма плагина');
check($views->label('emptyform') === 'Пустая' && $views->label('legacy-order') === 'legacy-order' && $views->label('customizer-order') === 'Заказы конфигуратора', 'подпись: YAML, иначе id, у плагина — регистрация');
check($views->columns('emptyform') === [], 'без настройки и без плагина столбцов данных нет');
check(array_column($views->columns('customizer-order'), 'key') === ['item', 'product_model', 'name', 'email', 'office'], 'по умолчанию столбцы плагина, без серверного model');
$store->save('customizer-order', []);
check($views->columns('customizer-order') === [], 'сохранённый пустой список не равен «настройки ещё нет»');
$store->reset('customizer-order');
check(array_column($views->columns('customizer-order'), 'key') === ['item', 'product_model', 'name', 'email', 'office'], 'сброс пустой настройки возвращает столбцы registerForm');
$editorKeys = array_column($views->editor('customizer-order'), 'key');
check(in_array('model', $editorKeys, true), 'ключ из заявок остаётся кандидатом, даже если плагин его не зарегистрировал');
$modelRow = null;
foreach ($views->editor('customizer-order') as $row) {
    if ($row['key'] === 'model') {
        $modelRow = $row;
    }
}
check(is_array($modelRow) && $modelRow['enabled'] === false && $modelRow['label'] === 'Представлена модель', 'незарегистрированное поле выключено, подпись из контракта');
check(!is_file($site . '/content/forms/customizer-order.yaml'), 'регистрация плагина не создаёт YAML в content/forms');

$created = (new SubmissionStore($config))->create('contact', ['name' => 'Новая заявка', 'email' => 'fresh@example.test'], $contractName('Новое имя'), '203.0.113.11');
$createdData = json_decode((string) file_get_contents($created['path']), true);
check(is_array($createdData) && array_keys($createdData) === ['id', 'form_id', 'created_at', 'ip', 'payload', 'contract', 'mail_status', 'mail_error'], 'новая заявка хранит прежний набор полей');
check(($submissions->paginate('contact', null, 1)['items'][0]['id'] ?? '') === $created['id'], 'новая заявка выше старых');

sa_rm($site . '/plugins/a-first');
sa_rm($site . '/plugins/b-second');
out("Админка\n");
$admin = ['admin_authenticated' => true, 'csrf_token' => 'tok'];
$res = sa_http($corePath, $site, $config, 'GET', '/admin-panel/submissions', [], [], []);
check($res['code'] === 302 && str_contains($res['body'], 'Redirect: /admin-panel/login'), 'без входа список заявок не открывается', (string) $res['code'] . ' ' . $res['body']);
check(!is_file($store->path()) || trim((string) file_get_contents($store->path())) === '{}', 'чужой запрос не записывает настройки');
$res = sa_http($corePath, $site, $config, 'POST', '/admin-panel/submissions/columns', [], [], [
    '_csrf' => 'tok',
    'form_id' => 'contact',
    'reset' => '1',
]);
check($res['code'] === 302 && str_contains($res['body'], 'Redirect: /admin-panel/login'), 'без входа столбцы не сбрасываются', $res['body']);

$res = sa_http($corePath, $site, $config, 'GET', '/admin-panel/submissions?form_id=%3Cscript%3E&mail_status=%3Cscript%3E&page=0', $admin, [
    'form_id' => '<script>',
    'mail_status' => '<script>',
    'page' => '0',
], []);
check(
    $res['code'] === 200
    && !str_contains($res['body'], 'name="form_id" value="<script>"')
    && !str_contains($res['body'], 'name="mail_status" value="<script>"')
    && !str_contains($res['body'], '<script>alert'),
    'чужой form_id и статус не попадают в разметку сырьём',
    (string) $res['code']
);
$table = sa_table($res['body']);
check(str_contains($table, '>Дата<') && str_contains($table, '>Почта<') && str_contains($table, 'visually-hidden">Открыть'), 'без настройки столбцы дата, почта и открыть');
check(!str_contains($table, '>Форма<'), 'колонки «Форма» в таблице нет');
check(str_contains($res['body'], 'Обратная связь') && str_contains($res['body'], 'Пустая') && str_contains($res['body'], 'legacy-order') && str_contains($res['body'], 'Заказы конфигуратора'), 'у каждой формы своя вкладка');
check(is_file($site . '/orders-booted.txt') && !is_file($site . '/quiet-booted.txt') && !is_file($site . '/boom-booted.txt'), 'страница «Заявки» поднимает только плагины с формами');

$res = sa_http($corePath, $site, $config, 'GET', '/admin-panel/submissions?form_id=emptyform', $admin, ['form_id' => 'emptyform'], []);
check($res['code'] === 200 && str_contains(sa_table($res['body']), 'Заявок пока нет'), 'пустая форма доступна и без заявок', (string) $res['code'] . ' table=' . substr(sa_table($res['body']), 0, 180));
check(str_contains($res['body'], 'emptyform'), 'вкладка пустой формы ведёт на её id');

$res = sa_http($corePath, $site, $config, 'GET', '/admin-panel/submissions?form_id=contact', $admin, ['form_id' => 'contact'], []);
$table = sa_table($res['body']);
check(str_contains($table, '2020-01-01_000000_old1') && str_contains($table, '2024-05-01_000000_new1') && str_contains($table, $created['id']), 'старая и новая заявки в одной таблице');
check(!str_contains($table, '2023-03-03_000000_leg1') && !str_contains($table, '2024-06-01_000000_ord1'), 'в таблице contact нет строк других форм');
check(!str_contains($table, 'old@example.test') && !str_contains($table, 'new@example.test') && !str_contains($table, '<script>alert(1)</script>'), 'пока столбцы не включены, payload в таблицу не выводится');
check(str_contains($res['body'], 'value="Новое имя"'), 'в настройке подпись кандидата из новой заявки');

$res = sa_http($corePath, $site, $config, 'GET', '/admin-panel/submissions?form_id=legacy-order', $admin, ['form_id' => 'legacy-order'], []);
$table = sa_table($res['body']);
check(str_contains($table, '2023-03-03_000000_leg1') && !str_contains($table, '2020-01-01_000000_old1'), 'старый id без YAML открывается отдельной вкладкой');

$res = sa_http($corePath, $site, $config, 'GET', '/admin-panel/submissions?form_id=customizer-order', $admin, ['form_id' => 'customizer-order'], []);
$table = sa_table($res['body']);
$theadEnd = strpos($table, '</thead>');
$thead = $theadEnd === false ? '' : substr($table, 0, $theadEnd);
check(str_contains($thead, 'Изделие') && str_contains($thead, 'Модель') && str_contains($thead, 'Заказчик') && str_contains($thead, 'Email') && str_contains($thead, 'Исполнитель'), 'столбцы плагина в заданном порядке');
check(!str_contains($thead, 'Представлена модель'), 'незарегистрированное поле не становится столбцом само');
check(strpos($thead, 'Изделие') < strpos($thead, 'Модель') && strpos($thead, 'Модель') < strpos($thead, 'Заказчик'), 'порядок столбцов как в registerForm');
check(str_contains($table, '>Северный<') && str_contains($table, '>A1<') && !str_contains($table, 'серверная модель'), 'в ячейке то значение, которое лежит в заявке');
check(substr_count($table, '<td>—</td>') >= 1, 'нет ключа office во второй заявке — тире');
check(str_contains($res['body'], 'name="columns[') && str_contains($res['body'], 'value="model"') && str_contains($res['body'], 'Представлена модель'), 'ключ model можно включить вручную, ядро его не выкидывает');
check(hash_file('sha256', $site . '/submissions/customizer-order/2024-06-01_000000_ord1.json') === $orderHash, 'просмотр не меняет заявку');
$res = sa_http($corePath, $site, $config, 'GET', '/admin-panel/submissions/customizer-order/2024-06-01_000000_ord1', $admin, [], []);
check(
    $res['code'] === 200
    && str_contains($res['body'], 'Заказы конфигуратора')
    && str_contains($res['body'], 'Северный')
    && str_contains($res['body'], 'серверная модель'),
    'карточка показывает подпись плагина и все поля заявки, включая невыведенный в таблице ключ'
);

$res = sa_http($corePath, $site, $config, 'GET', '/admin-panel/submissions?form_id=paged&page=2', $admin, ['form_id' => 'paged', 'page' => '2'], []);
$table = sa_table($res['body']);
check(str_contains($res['body'], 'Всего 201') && str_contains($res['body'], 'Страница 2 из 5') && str_contains($table, '2020-01-01_000150_aa') && !str_contains($table, '2020-01-01_000200_aa'), 'вторая страница не повторяет первую и не теряет хвост');
$res = sa_http($corePath, $site, $config, 'GET', '/admin-panel/submissions?form_id=letters&mail_status=failed&page=2', $admin, [
    'form_id' => 'letters',
    'mail_status' => 'failed',
    'page' => '2',
], []);
$table = sa_table($res['body']);
check(str_contains($res['body'], 'Всего 60') && str_contains($table, '2021-02-02_000000_ff') && !str_contains($table, '2021-01-01_'), 'фильтр почты сохраняется на следующей странице');
check(str_contains($res['body'], 'mail_status=failed'), 'вкладки удерживают фильтр почты');

$res = sa_http($corePath, $site, $config, 'POST', '/admin-panel/submissions/columns', $admin, [], [
    'form_id' => 'contact',
    'columns' => [
        ['key' => 'name', 'label' => 'Имя', 'order' => '1', 'enabled' => '1'],
    ],
]);
check($res['code'] === 403 && str_contains($res['body'], 'CSRF'), 'сохранение столбцов без CSRF — 403');
check($store->get('contact') === null, 'без CSRF настройка не записывается');

$rawLabel = '"><img src=x onerror=alert(1)>';
$res = sa_http($corePath, $site, $config, 'POST', '/admin-panel/submissions/columns', $admin, [], [
    '_csrf' => 'tok',
    'form_id' => 'contact',
    'mail_status' => 'failed',
    'columns' => [
        ['key' => 'email', 'label' => 'Почта клиента', 'order' => '0', 'enabled' => '1'],
        ['key' => 'name', 'label' => $rawLabel, 'order' => '1', 'enabled' => '1'],
        ['key' => 'topics', 'label' => 'Темы', 'order' => '2', 'enabled' => '1'],
        ['key' => 'news', 'label' => 'Рассылка', 'order' => '3', 'enabled' => '1'],
        ['key' => 'not_a_field', 'label' => 'Чужое', 'order' => '0', 'enabled' => '1'],
    ],
]);
check($res['code'] === 302 && str_contains($res['body'], 'columns=saved') && str_contains($res['body'], 'mail_status=failed') && str_contains($res['body'], 'form_id=contact'), 'сохранение столбцов возвращает на ту же форму и фильтр', $res['body']);
$saved = $store->get('contact');
check(is_array($saved) && array_column($saved, 'key') === ['email', 'name', 'topics', 'news'] && $saved[0]['label'] === 'Почта клиента' && $saved[1]['label'] === $rawLabel, 'включены только известные поля, порядок по числам, чужой ключ отброшен');
check(str_ends_with($store->path(), '/content/_submission-columns.json') && !is_file($site . '/content/forms/contact.json'), 'настройка не притворяется публичной формой');
check(hash_file('sha256', $site . '/submissions/contact/2020-01-01_000000_old1.json') === $beforeHash, 'после сохранения столбцов старая заявка та же');

$res = sa_http($corePath, $site, $config, 'GET', '/admin-panel/submissions?form_id=contact&mail_status=failed&columns=saved', $admin, [
    'form_id' => 'contact',
    'mail_status' => 'failed',
    'columns' => 'saved',
], []);
$table = sa_table($res['body']);
$theadEnd = strpos($table, '</thead>');
$thead = $theadEnd === false ? '' : substr($table, 0, $theadEnd);
check(str_contains($thead, 'Почта клиента') && str_contains($thead, $rawLabel) === false && str_contains($thead, '&quot;&gt;&lt;img'), 'переименованный столбец экранирован');
check(strpos($thead, 'Почта клиента') < strpos($thead, '&quot;&gt;&lt;img'), 'порядок столбцов в таблице как сохранили');
check(str_contains($table, 'new@example.test') && str_contains($table, 'a, b') && str_contains($table, '>да<') && !str_contains($table, 'old@example.test'), 'фильтр failed оставляет новую заявку; список и флажок видны после включения');
check(!str_contains($table, '<script>alert(1)</script>') && str_contains($table, '&lt;script&gt;'), 'значение ячейки экранировано и после включения столбца');
check(str_contains($res['body'], 'Столбцы сохранены'), 'после сохранения есть подтверждение');

$res = sa_http($corePath, $site, $config, 'GET', '/admin-panel/submissions/contact/' . $created['id'], $admin, [], []);
check($res['code'] === 200 && str_contains($res['body'], 'Новое имя') && str_contains($res['body'], 'Новая заявка') && str_contains($res['body'], 'form_id=contact'), 'карточка заявки сохраняет подпись контракта и возвращает на вкладку', (string) $res['code'] . ' ' . substr($res['body'], 0, 240));
check(!str_contains($res['body'], 'Почта клиента'), 'переименование столбца списка не переписывает карточку');

$res = sa_http($corePath, $site, $config, 'POST', '/admin-panel/submissions/columns', $admin, [], [
    '_csrf' => 'tok',
    'form_id' => 'customizer-order',
    'columns' => [
        ['key' => 'product_model', 'label' => 'Выбранная модель', 'order' => '0', 'enabled' => '1'],
        ['key' => 'office', 'label' => 'Исполнитель', 'order' => '1', 'enabled' => '1'],
        ['key' => 'model', 'label' => 'Представлена модель', 'order' => '2'],
    ],
]);
check($res['code'] === 302, 'админ может заменить столбцы плагина');
$res = sa_http($corePath, $site, $config, 'GET', '/admin-panel/submissions?form_id=customizer-order', $admin, ['form_id' => 'customizer-order'], []);
$table = sa_table($res['body']);
check(str_contains($table, 'Выбранная модель') && str_contains($table, '>A1<') && str_contains($table, '>Северный<') && !str_contains(substr($table, 0, (int) strpos($table, '</thead>')), 'Изделие'), 'настройка админа перекрывает столбцы по умолчанию и старые заявки');
check(hash_file('sha256', $site . '/submissions/customizer-order/2024-06-01_000000_ord1.json') === $orderHash, 'переименование не записывается в заявку');

$res = sa_http($corePath, $site, $config, 'POST', '/admin-panel/submissions/columns', $admin, [], [
    '_csrf' => 'tok',
    'form_id' => 'customizer-order',
    'reset' => '1',
]);
check($res['code'] === 302 && str_contains($res['body'], 'columns=reset') && str_contains($res['body'], 'form_id=customizer-order'), 'сброс столбцов', $res['body']);
check($store->get('customizer-order') === null, 'после сброса сохранённой настройки нет');
$res = sa_http($corePath, $site, $config, 'GET', '/admin-panel/submissions?form_id=customizer-order', $admin, ['form_id' => 'customizer-order'], []);
$table = sa_table($res['body']);
check(str_contains($table, '>Изделие<') && str_contains($table, '>Модель<') && !str_contains($table, 'Выбранная модель'), 'сброс возвращает столбцы registerForm, а не пустую таблицу');

file_put_contents($store->path(), "{");
$corruptBefore = (string) file_get_contents($store->path());
$res = sa_http($corePath, $site, $config, 'POST', '/admin-panel/submissions/columns', $admin, [], [
    '_csrf' => 'tok',
    'form_id' => 'contact',
    'reset' => '1',
]);
check($res['code'] === 302 && str_contains($res['body'], 'columns_error=') && (string) file_get_contents($store->path()) === $corruptBefore, 'повреждённый файл настроек не перезаписывается', $res['body']);
$res = sa_http($corePath, $site, $config, 'GET', '/admin-panel/submissions?form_id=contact', $admin, ['form_id' => 'contact'], []);
check($res['code'] === 200 && str_contains($res['body'], 'повреждён') && str_contains(sa_table($res['body']), '2020-01-01_000000_old1'), 'при битых настройках список заявок всё ещё открывается', (string) $res['code'] . ' ' . substr(strip_tags($res['body']), 0, 300));

$yaml = glob($site . '/content/forms/*.yaml') ?: [];
check(count($yaml) === 2 && !is_file($site . '/content/forms/customizer-order.yaml'), 'каталог публичных форм не пополняется настройками и формами плагина');

sa_rm($site);
ob_end_clean();
fwrite(STDOUT, "\n{$passed} passed, {$failures} failed\n");
exit($failures === 0 ? 0 : 1);
