<?php

/**
 * Тесты приёма заявок (без PHPUnit): php tests/forms.php
 *
 * Флажки (checkbox): снятый — false, отмеченный — true, обязательный требует отметки,
 * группы флажков — списки; что сохраняется в submissions/, что попадает в письмо и в «Заявки».
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
Env::set('APP_SECRET', 'forms-test-secret');
Env::set('SMTP_HOST', '');
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

function fm_rm(string $path): void
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
            fm_rm($path . '/' . $item);
        }
    }
    rmdir($path);
}

$site = sys_get_temp_dir() . '/motorchk-forms-test-' . getmypid();
fm_rm($site);
mkdir($site . '/content/forms', 0775, true);
mkdir($site . '/submissions', 0775, true);
mkdir($site . '/cache', 0775, true);
file_put_contents($site . '/content/forms/contact.yaml', "id: contact\nlabel: Обратная связь\nsubject: \"Заявка: {{name}}, рассылка {{news}}\"\nsuccess_message: Спасибо\n");
$config = [
    'forms_path' => $site . '/content/forms',
    'submissions_path' => $site . '/submissions',
    'cache_path' => $site . '/cache',
];

$html = <<<'HTML'
<label for="f-name">Имя</label><input id="f-name" name="name" type="text" required>
<label for="f-pd">Согласие</label><input id="f-pd" type="checkbox" name="consent" value="Да" required>
<label for="f-news">Рассылка</label><input id="f-news" type="checkbox" name="news">
<input type="checkbox" name="topics[]" value="a"><input type="checkbox" name="topics[]" value="b">
<input type="checkbox" name="choices" value="a"><input type="checkbox" name="choices" value="b" required>
<input type="checkbox" name="one[]" value="a">
HTML;
$fields = Form::parseFields($html);
$byName = array_column($fields, null, 'name');
check(($byName['consent']['type'] ?? '') === 'checkbox' && ($byName['consent']['required'] ?? false) === true, 'контракт: consent — обязательный checkbox');
check(($byName['news']['type'] ?? '') === 'checkbox' && ($byName['news']['required'] ?? true) === false, 'контракт: news — необязательный checkbox');
check(isset($byName['topics']), 'контракт: группа topics[] — одно поле topics');
check(($byName['news']['multiple'] ?? null) === false && ($byName['topics']['multiple'] ?? null) === true && ($byName['choices']['multiple'] ?? null) === true && ($byName['one']['multiple'] ?? null) === true, 'контракт: одиночный и оба вида групп');
$contractFields = $fields;
$contract = Form::signContract('contact', $contractFields);
$tampered = $contractFields;
foreach ($tampered as &$field) {
    if ($field['name'] === 'news') {
        $field['multiple'] = true;
    }
}
unset($field);
[$body] = explode('.', Form::signContract('contact', $tampered), 2);
[, $signature] = explode('.', $contract, 2);
try {
    Form::verifyContract($body . '.' . $signature);
    check(false, 'подмена признака группы нарушает подпись');
} catch (Throwable) {
    check(true, 'подмена признака группы нарушает подпись');
}

$_SESSION = [];
$csrf = Csrf::token();
$ipN = 0;
$submit = static function (array $values) use ($config, $contract, $csrf, &$ipN): array {
    $ipN++;
    $input = $values + [
        'choices' => ['a'],
        'form_id' => 'contact',
        '_csrf' => $csrf,
        '_form_contract' => $contract,
        Form::honeypotName() => '',
        'form_ts' => time() - 10,
    ];

    return (new FormSubmitHandler($config))->handle($input, '10.0.0.' . $ipN);
};
$lastPayload = static function () use ($site): array {
    $files = glob($site . '/submissions/contact/*.json') ?: [];
    usort($files, static fn (string $a, string $b): int => filemtime($a) <=> filemtime($b) ?: strcmp($a, $b));
    $data = json_decode((string) file_get_contents((string) end($files)), true);
    fm_rm($site . '/submissions/contact');

    return is_array($data['payload'] ?? null) ? $data['payload'] : [];
};

out("Одиночный флажок\n");
// новый forms.js: JSON true/false
$r = $submit(['name' => 'Иван', 'consent' => true, 'news' => false, 'topics' => ['a']]);
$p = $lastPayload();
check($r['ok'] === true && ($p['consent'] ?? null) === true && ($p['news'] ?? null) === false, 'новый JS: true/false сохраняются как есть', json_encode($p, JSON_UNESCAPED_UNICODE));
check(($p['topics'] ?? null) === ['a'], 'группа: один отмеченный — список', json_encode($p['topics'] ?? null));

// без JS (обычная отправка): снятого флажка нет в запросе, отмеченный — «on»
$r = $submit(['name' => 'Иван', 'consent' => 'on']);
$p = $lastPayload();
check($r['ok'] === true && ($p['news'] ?? null) === false && ($p['consent'] ?? null) === true, 'без JS: отсутствует → false, «on» → true', json_encode($p, JSON_UNESCAPED_UNICODE));
check(($p['topics'] ?? null) === [], 'группа без отмеченных без JS — пустой список', json_encode($p['topics'] ?? null));

$r = $submit(['name' => 'Иван', 'consent' => true, 'news' => false, 'topics' => []]);
$p = $lastPayload();
check($r['ok'] && ($p['news'] ?? null) === false, 'новый JS: одиночный без отметки — false');
check(($p['topics'] ?? null) === [], 'новый JS: группа без отметок — пустой список');

$r = $submit(['name' => 'Иван', 'consent' => true]);
$p = $lastPayload();
check($r['ok'] && ($p['news'] ?? null) === false, 'без JS: одиночный без отметки — false');
check(($p['topics'] ?? null) === [], 'без JS: группа без отметок — пустой список');
foreach (['без JS' => null, 'новый JS' => []] as $label => $emptyGroup) {
    $r = $submit(['name' => 'Иван', 'consent' => true, 'choices' => $emptyGroup]);
    check(!$r['ok'] && $r['status'] === 422 && isset($r['errors']['choices']), "{$label}: обязательная пустая группа — 422");
}

$legacyFields = array_map(static function (array $field): array {
    unset($field['multiple']);
    return $field;
}, $fields);
$legacyToken = Form::signContract('contact', $legacyFields);
$legacyInput = ['form_id' => 'contact', '_csrf' => $csrf, '_form_contract' => $legacyToken, Form::honeypotName() => '', 'form_ts' => time() - 10, 'name' => 'Иван', 'consent' => 'on', 'choices' => 'on'];
$r = (new FormSubmitHandler($config))->handle($legacyInput, '10.1.0.1');
check(!$r['ok'] && $r['status'] === 400, 'контракт без признака группы отклоняется с 400');

$r = $submit(['name' => 'Иван', 'consent' => 'Да', 'news' => 'on', 'topics' => ['a', 'b']]);
$p = $lastPayload();
check(($p['news'] ?? null) === true && ($p['topics'] ?? null) === ['a', 'b'], 'отмеченный «on» → true, группа — список значений', json_encode($p, JSON_UNESCAPED_UNICODE));
$r = $submit(['name' => 'Иван', 'consent' => 'on', 'choices' => 'b', 'one' => 'a']);
$p = $lastPayload();
check($r['ok'] && ($p['choices'] ?? null) === ['b'] && ($p['one'] ?? null) === ['a'], 'без JS: одиночное значение группы сохраняется списком');

out("Обязательный флажок\n");
foreach (['пустая строка' => '', 'JSON false (новый JS)' => false, 'нет в запросе (без JS)' => null, "'0'" => '0', "'off'" => 'off'] as $label => $value) {
    $values = ['name' => 'Иван', 'news' => true];
    if ($value !== null) {
        $values['consent'] = $value;
    }
    $r = $submit($values);
    check($r['ok'] === false && $r['status'] === 422 && isset($r['errors']['consent']), "снятый обязательный флажок — ошибка: {$label}", json_encode($r, JSON_UNESCAPED_UNICODE));
}
check((glob($site . '/submissions/contact/*.json') ?: []) === [], 'отклонённые заявки не сохраняются');

out("Нормализация значений\n");
$cases = [
    [true, true], [false, false], [1, true], [0, false], ['1', true], ['0', false],
    ['true', true], ['false', false], ['on', true], ['off', false], ['yes', true], ['no', false],
    ['Да', true], ['НЕТ', false], ['', false], ['  ', false], ['newsletter', 'newsletter'],
];
foreach ($cases as [$in, $expected]) {
    $got = FormSubmitHandler::normalizeCheckbox($in);
    check($got === $expected, 'normalizeCheckbox(' . var_export($in, true) . ') → ' . var_export($expected, true), var_export($got, true));
}

out("Письмо и «Заявки»\n");
$handler = new FormSubmitHandler($config);
$body = (new ReflectionMethod($handler, 'renderBody'))->invoke($handler, 'Обратная связь', $contractFields, ['name' => 'Иван', 'consent' => true, 'news' => false, 'topics' => ['a', 'b']], 'x1');
check(str_contains($body, 'Согласие: да') && str_contains($body, 'Рассылка: нет') && str_contains($body, 'topics: a, b'), 'письмо: да / нет / список через запятую', $body);
$subject = (new ReflectionMethod($handler, 'renderSubject'))->invoke($handler, 'Заявка: {{name}}, рассылка {{news}}', ['name' => 'Иван', 'news' => false]);
check($subject === 'Заявка: Иван, рассылка нет', 'тема письма: false → «нет»', $subject);

$view = (string) file_get_contents($corePath . '/admin-panel/templates/submissions/view.php');
check(str_contains($view, "is_bool(\$value)") && str_contains($view, "'да'") && str_contains($view, "'нет'"), '«Заявки»: булевы значения показываются как да/нет');

out("forms.js\n");
$js = (string) file_get_contents($corePath . '/assets/js/forms.js');
check(str_contains($js, 'data[name] = false;') && str_contains($js, 'data[name] = true;'), 'forms.js: одиночный флажок отправляется true/false');
check(!str_contains($js, "data[name] = '';"), "forms.js: пустая строка для снятого флажка больше не отправляется");
check(str_contains($js, "el.name.endsWith('[]')"), 'forms.js: name="…[]" — всегда список');

fm_rm($site);
ob_end_clean();
fwrite(STDOUT, "\n{$passed} passed, {$failures} failed\n");
exit($failures === 0 ? 0 : 1);
