<?php

/**
 * Тосты админки через HX-Trigger (без PHPUnit): php tests/toast.php
 *
 * Значение заголовка должно быть чистым ASCII: браузеры декодируют заголовки как latin1,
 * и сырой UTF-8 показывался кракозябрами («Ð¡Ð¾Ñ…Ñ€Ð°Ð½ÐµÐ½Ð¾» вместо «Сохранено»).
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

$failures = 0;
$passed = 0;

function check(bool $ok, string $name, string $details = ''): void
{
    global $failures, $passed;
    if ($ok) {
        $passed++;
        fwrite(STDOUT, "  ok   {$name}\n");
        return;
    }
    $failures++;
    fwrite(STDOUT, "  FAIL {$name}" . ($details !== '' ? " — {$details}" : '') . "\n");
}

/** Как браузер читает значение заголовка: байты → latin1 → строка, затем JSON.parse. */
function browserDecode(string $header): mixed
{
    $asBrowserSees = mb_convert_encoding($header, 'UTF-8', 'ISO-8859-1');
    return json_decode($asBrowserSees, true);
}

fwrite(STDOUT, "toast: HX-Trigger\n");

$cases = [
    'кириллица' => 'Сохранено',
    'эмодзи' => 'Готово 🎉 👍🏽',
    'кавычки и слэши' => 'Страница «/about» "x" \\ сохранена',
    'перевод строки' => "Ошибка:\r\nвторая строка",
    'латиница' => 'Saved',
];

foreach ($cases as $label => $message) {
    $header = AdminRouter::toastTriggerHeader($message, 'success');
    check(preg_match('/^[\x20-\x7E]+$/', $header) === 1, "{$label}: заголовок только из печатного ASCII", $header);
    check(!str_contains($header, "\r") && !str_contains($header, "\n"), "{$label}: без CR/LF (нет инъекции заголовков)");
    $decoded = browserDecode($header);
    check(
        ($decoded['mc:toast']['message'] ?? null) === $message,
        "{$label}: браузер получает исходный текст",
        var_export($decoded, true)
    );
    check(($decoded['mc:toast']['type'] ?? null) === 'success', "{$label}: тип сохранён");
}

$error = AdminRouter::toastTriggerHeader('Не удалось сохранить', 'error');
check((browserDecode($error)['mc:toast']['type'] ?? null) === 'error', 'тип error передаётся');
check(str_contains($error, '\u041d'), 'кириллица экранирована как \\uXXXX', $error);

$broken = AdminRouter::toastTriggerHeader("bad \xC3\x28 utf8", 'error');
check(preg_match('/^[\x20-\x7E]+$/', $broken) === 1, 'битый UTF-8 не роняет тост и не ломает заголовок', $broken);

$src = (string) file_get_contents($corePath . '/includes/AdminRouter.php');
check(
    preg_match_all("/header\\('HX-Trigger: '/", $src) === 1
        && str_contains($src, "header('HX-Trigger: ' . self::toastTriggerHeader("),
    'HX-Trigger выставляется только через toastTriggerHeader()'
);

fwrite(STDOUT, "\n{$passed} passed, {$failures} failed\n");
exit($failures === 0 ? 0 : 1);
