<?php

/**
 * SMTP_BCC: разбор списка и скрытая копия в письме (без реальной отправки).
 * php tests/mail.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

use PHPMailer\PHPMailer\PHPMailer;

$corePath = dirname(__DIR__);
require_once $corePath . '/vendor/autoload.php';
spl_autoload_register(static function (string $class) use ($corePath): void {
    $file = $corePath . '/includes/' . $class . '.php';
    if (is_file($file)) {
        require $file;
    }
});

ob_start();
Env::set('APP_SECRET', 'mail-test-secret');

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

function mail_throws(callable $fn): ?Throwable
{
    try {
        $fn();
    } catch (Throwable $e) {
        return $e;
    }

    return null;
}

function mail_bcc(PHPMailer $mail): array
{
    $out = [];
    foreach ($mail->getBccAddresses() as $row) {
        $out[] = (string) ($row[0] ?? '');
    }

    return $out;
}

/** PHPMailer без сети: postSend не ходит на SMTP. */
class MailProbeMessage extends MotorchkMailMessage
{
    public bool $delivered = false;

    public function postSend()
    {
        $this->delivered = true;

        return true;
    }
}

class MailProbe extends Mailer
{
    public MailProbeMessage $message;

    protected function createPhpMailer(): PHPMailer
    {
        return $this->message = new MailProbeMessage(true);
    }
}

function mail_base_env(): void
{
    foreach ([
        'SMTP_HOST' => 'smtp.example.test',
        'SMTP_PORT' => '587',
        'SMTP_ENCRYPTION' => 'tls',
        'SMTP_USERNAME' => '',
        'SMTP_PASSWORD' => '',
        'SMTP_FROM' => 'from@example.test',
        'SMTP_FROM_NAME' => 'Motorchk',
        'SMTP_TO' => 'to@example.test',
    ] as $key => $value) {
        Env::set($key, $value);
    }
}

/** @return array<string, string> */
function mail_dotenv(string $file): array
{
    $vars = [];
    foreach (file($file, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || str_starts_with($trimmed, '#')) {
            continue;
        }
        $pos = strpos($trimmed, '=');
        if ($pos === false) {
            continue;
        }
        $key = trim(substr($trimmed, 0, $pos));
        $value = trim(substr($trimmed, $pos + 1));
        if (strlen($value) >= 2) {
            $first = $value[0];
            $last = $value[strlen($value) - 1];
            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                $inner = substr($value, 1, -1);
                $value = $first === '"' ? stripcslashes($inner) : $inner;
            }
        }
        $vars[$key] = $value;
    }

    return $vars;
}

/**
 * Отдельный процесс: redirect() в админке вызывает exit.
 *
 * @param array<string, mixed> $config
 * @param array<string, string> $post
 * @return array{code: int, body: string}
 */
function mail_admin(string $corePath, array $config, string $method, string $uri, array $post = []): array
{
    $site = (string) $config['site_path'];
    $probe = $site . '/dispatch-probe.php';
    $in = $site . '/dispatch-in.json';
    $out = $site . '/dispatch-out.json';
    @unlink($out);
    if (!is_file($probe)) {
        file_put_contents($probe, <<<'PHP'
<?php
$in = json_decode((string) file_get_contents($argv[1]), true);
$outPath = $in['out'];
$corePath = $in['core'];
require_once $corePath . '/vendor/autoload.php';
spl_autoload_register(static function (string $class) use ($corePath): void {
    $file = $corePath . '/includes/' . $class . '.php';
    if (is_file($file)) {
        require $file;
    }
});
Env::load($in['config']['site_path'] . '/.env');
$_SESSION = is_array($in['session']) ? $in['session'] : [];
$_GET = [];
$_POST = is_array($in['post']) ? $in['post'] : [];
$_SERVER['REQUEST_METHOD'] = (string) $in['method'];
http_response_code(200);
ob_start();
register_shutdown_function(static function () use ($outPath): void {
    $body = ob_get_contents();
    file_put_contents($outPath, json_encode([
        'code' => http_response_code(),
        'body' => is_string($body) ? $body : '',
    ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
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
        'session' => ['admin_authenticated' => true, 'csrf_token' => 'tok'],
        'post' => $post,
    ], JSON_THROW_ON_ERROR));
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($probe) . ' ' . escapeshellarg($in);
    exec($cmd, $unused, $exit);
    $data = json_decode((string) (@file_get_contents($out) ?: ''), true);
    if (!is_array($data)) {
        return ['code' => 0, 'body' => 'probe exit ' . $exit];
    }

    return ['code' => (int) ($data['code'] ?? 0), 'body' => (string) ($data['body'] ?? '')];
}

function mail_rm(string $path): void
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
            mail_rm($path . '/' . $item);
        }
    }
    rmdir($path);
}

out("Разбор SMTP_BCC\n");
check(Mailer::parseBccList(null) === [] && Mailer::parseBccList('') === [] && Mailer::parseBccList('   ') === [] && Mailer::parseBccList(", ,\t,") === [], 'пусто, пробелы и пустые фрагменты — пустой список');
check(!Env::has('SMTP_BCC') && (new Mailer())->settingsFromEnv()['bcc'] === '', 'нет ключа SMTP_BCC — поведение без скрытой копии');

check(Mailer::parseBccList('  copy@example.test  ') === ['copy@example.test'], 'один адрес, пробелы обрезаются');
check(
    Mailer::parseBccList(" copy@example.test,  other@example.test , \n third@example.test, ") === ['copy@example.test', 'other@example.test', 'third@example.test'],
    'несколько адресов через запятую'
);
check(Mailer::parseBccList('Copy@Example.test, copy@example.test, OTHER@example.test') === ['Copy@Example.test', 'OTHER@example.test'], 'повтор без учёта регистра схлопывается, первое написание сохраняется');

$bad = mail_throws(static fn () => Mailer::parseBccList('copy@example.test, not-an-email, other@example.test'));
check($bad instanceof RuntimeException && str_contains($bad->getMessage(), 'not-an-email'), 'некорректный адрес — ошибка с этим адресом', (string) $bad?->getMessage());
$semi = mail_throws(static fn () => Mailer::parseBccList('copy@example.test;other@example.test'));
check($semi instanceof RuntimeException, 'разделитель только запятая, точка с запятой не принимается', (string) $semi?->getMessage());
$broken = mail_throws(static fn () => Mailer::parseBccList("copy@example.test\nother@example.test"));
check($broken instanceof RuntimeException, 'перевод строки внутри адреса не принимается');

Env::set('SMTP_BCC', '  copy@example.test , other@example.test  ');
check((new Mailer())->settingsFromEnv()['bcc'] === '  copy@example.test , other@example.test  ', 'settingsFromEnv отдаёт сырое значение SMTP_BCC');

out("Письмо\n");
mail_base_env();
Env::set('SMTP_BCC', '');
$probe = new MailProbe();
$probe->send('Тема', 'Текст письма');
check($probe->message->delivered === true && mail_bcc($probe->message) === [], 'пустой SMTP_BCC — письмо без скрытой копии', implode(',', mail_bcc($probe->message)));
$mime = $probe->message->getSentMIMEMessage();
check(str_contains($mime, 'To: to@example.test') && !preg_match('/^Bcc:/mi', $mime), 'получатель To в письме, заголовка Bcc нет');

Env::set('SMTP_BCC', 'copy@example.test, other@example.test');
$probe = new MailProbe();
$probe->send('Тема', 'Текст письма', null, null, 'reply@example.test');
check($probe->message->delivered === true, 'отправка с BCC не ходит в сеть');
check(mail_bcc($probe->message) === ['copy@example.test', 'other@example.test'], 'оба адреса BCC попадают в письмо', implode(',', mail_bcc($probe->message)));
$mime = $probe->message->getSentMIMEMessage();
check(str_contains($mime, 'Reply-To: reply@example.test') && !preg_match('/^Bcc:/mi', $mime) && !str_contains($mime, 'copy@example.test'), 'скрытая копия не попадает в заголовки SMTP-письма');

Env::set('SMTP_TO', 'to@example.test');
Env::set('SMTP_BCC', 'TO@example.test, copy@example.test');
$probe = new MailProbe();
$probe->send('Тема', 'Текст');
check(mail_bcc($probe->message) === ['copy@example.test'], 'адрес, совпадающий с To без учёта регистра, не дублируется', implode(',', mail_bcc($probe->message)));

$probe = new MailProbe();
$probe->send('Тема', 'Текст', 'other@example.test');
check(mail_bcc($probe->message) === ['TO@example.test', 'copy@example.test'], 'сравнение с фактическим получателем, а не только с SMTP_TO', implode(',', mail_bcc($probe->message)));

$probe = new MailProbe();
$probe->send('Тема', 'Текст', 'copy@example.test');
check(mail_bcc($probe->message) === ['TO@example.test'], 'из BCC убирается только адрес текущего To', implode(',', mail_bcc($probe->message)));

Env::set('SMTP_BCC', 'to@example.test');
$probe = new MailProbe();
$probe->send('Тема', 'Текст');
check($probe->message->delivered === true && mail_bcc($probe->message) === [], 'если BCC совпал с To целиком — письмо уходит без скрытой копии');

$probe = new MailProbe();
$err = mail_throws(static fn () => $probe->send('Тема', 'Текст', null, ['bcc' => 'copy@example.test, bad address']));
check($err instanceof RuntimeException && str_contains($err->getMessage(), 'bad address') && $probe->message->delivered === false, 'некорректный SMTP_BCC не отправляет письмо', (string) $err?->getMessage());

$probe = new MailProbe();
$probe->send('Тема', 'Текст', null, ['bcc' => 'override@example.test']);
check(mail_bcc($probe->message) === ['override@example.test'], 'override bcc заменяет список из окружения');

out("HTML и текстовая альтернатива\n");
mail_base_env();
Env::set('SMTP_BCC', 'copy@example.test');
$raw = "Строка 1\r\nСтрока 2\n\nАбзац <b> & https://example.com/a?x=1&y=2.";
$probe = new MailProbe();
$probe->send('Тема', $raw, null, null, 'reply@example.test');
check($probe->message->ContentType === 'multipart/alternative', 'после отправки конверт — multipart/alternative', $probe->message->ContentType);
check($probe->message->AltBody === $raw, 'AltBody обычного текста — исходная строка, включая CRLF');
$html = $probe->message->Body;
check(str_contains($html, "Строка 1<br>\nСтрока 2") && substr_count($html, '<p style="margin:0 0 1em;">') === 2, 'одиночный перенос — br, пустая строка — абзац', $html);
check(str_contains($html, '&lt;b&gt;') && str_contains($html, '&amp;') && !str_contains($html, '<b>'), 'символы HTML экранируются', $html);
check(
    str_contains($html, 'href="https://example.com/a?x=1&amp;y=2"') && str_contains($html, '</a>.'),
    'ссылка http(s) кликабельна, точка после адреса остаётся снаружи',
    $html
);
check(str_contains($html, '<meta charset="UTF-8">') && str_contains($html, 'font-family:Arial,Helvetica,sans-serif'), 'обёртка: UTF-8 и шрифт', $html);
check(substr_count($html, '<!DOCTYPE html>') === 1, 'обёртка одна');
$mime = $probe->message->getSentMIMEMessage();
$plainPos = strpos($mime, 'Content-Type: text/plain');
$htmlPos = strpos($mime, 'Content-Type: text/html');
check(str_contains($mime, 'multipart/alternative') && $plainPos !== false && $htmlPos !== false && $plainPos < $htmlPos, 'MIME: multipart/alternative, сначала text/plain, затем text/html');
check(str_contains($mime, 'Reply-To: reply@example.test') && str_contains($mime, 'from@example.test') && mail_bcc($probe->message) === ['copy@example.test'], 'HTML-письмо сохраняет From, Reply-To и BCC');

$linked = Mailer::textToHtml('seehttp://example.com и javascript:alert(1) и (https://ok.test/a_(b)).');
check(!str_contains($linked, 'href="http://example.com"'), 'схема внутри слова не становится ссылкой', $linked);
check(!preg_match('/href="[^"]*javascript:/', $linked), 'javascript: не становится ссылкой', $linked);
check(str_contains($linked, 'href="https://ok.test/a_(b)"') && str_contains($linked, '</a>).'), 'скобки адреса сохраняются, хвост пунктуации снаружи', $linked);

$given = "<p>Готово</p>\n<script>x</script>";
$probe = new MailProbe();
$probe->send('Тема', 'не используется', null, null, null, ['html' => $given, 'text' => "Готово\n"]);
check($probe->message->Body === $given, 'готовый HTML попадает в Body как есть');
check($probe->message->AltBody === "Готово\n", 'AltBody готового HTML — переданный text');
check(!str_contains($probe->message->Body, '<!DOCTYPE html>'), 'готовый HTML не оборачивается');

$fragment = '<p>A &amp; B<br>C</p><p>D</p>';
$probe = new MailProbe();
$probe->send('Тема', 'мой текст', null, null, null, ['html' => $fragment]);
check($probe->message->Body === $fragment && $probe->message->AltBody === 'мой текст', 'без text непустой body становится текстовой альтернативой');

$generatedFrom = '<head><title>T</title><style>p{}</style></head><p>A &amp; B<br>C</p><p>D</p><script>alert(1)</script>';
$expectedText = Mailer::htmlToText($generatedFrom);
check($expectedText === "A & B\nC\n\nD", 'htmlToText: сущности, br, абзацы, без head и script', $expectedText);
$probe = new MailProbe();
$probe->send('Тема', '', null, null, null, ['html' => $generatedFrom]);
check($probe->message->Body === $generatedFrom && $probe->message->AltBody === $expectedText, 'без text и с пустым body AltBody собирается из HTML');

$probe = new MailProbe();
$probe->send('Тема', '0');
check($probe->message->AltBody === '0' && str_contains($probe->message->Body, '>0</p>'), 'текст «0» не теряется', $probe->message->AltBody);
check(str_contains($probe->message->getSentMIMEMessage(), 'multipart/alternative'), 'текст «0» тоже уходит как multipart/alternative');

$probe = new MailProbe();
$probe->send('Тема', 'исходный', null, null, null, ['html' => '<p>H</p>', 'text' => '']);
check($probe->message->AltBody === '' && str_contains($probe->message->getSentMIMEMessage(), 'multipart/alternative'), 'явный пустой text всё равно даёт текстовую часть');

Env::set('SMTP_TO', 'to@example.test');
Env::set('SMTP_BCC', 'TO@example.test, copy@example.test');
$probe = new MailProbe();
$probe->send('Тема', '', 'to@example.test', null, 'reply@example.test', ['html' => '<p>H</p>', 'text' => 'H']);
check(mail_bcc($probe->message) === ['copy@example.test'], 'готовое HTML-письмо не дублирует To в BCC', implode(',', mail_bcc($probe->message)));
$mime = $probe->message->getSentMIMEMessage();
check(str_contains($mime, 'Reply-To: reply@example.test') && str_contains($mime, 'from@example.test') && !preg_match('/^Bcc:/mi', $mime), 'готовое HTML сохраняет From и Reply-To, Bcc в заголовки не попадает');

$probe = new MailProbe();
$err = mail_throws(static fn () => $probe->send('Тема', 'Текст', null, null, null, ['html' => 1]));
check($err instanceof InvalidArgumentException && str_contains((string) $err?->getMessage(), 'html'), 'html не строка — исключение до отправки', (string) $err?->getMessage());
$err = mail_throws(static fn () => (new MailProbe())->send('Тема', 'Текст', null, null, null, ['text' => null]));
check($err instanceof InvalidArgumentException && str_contains((string) $err?->getMessage(), 'text'), 'text не строка — исключение', (string) $err?->getMessage());

out("Inline embed (CID)\n");
mail_base_env();
Env::set('SMTP_BCC', '');
$embedDir = sys_get_temp_dir() . '/motorchk-mail-embed-' . getmypid();
@mkdir($embedDir, 0775, true);
$embedFile = $embedDir . '/preview.jpg';
file_put_contents($embedFile, 'fake-jpeg-bytes');
$probe = new MailProbe();
$probe->send('Тема', 'Текст', null, null, null, [
    'html' => '<img src="cid:order-preview" alt="">',
    'text' => 'Текст',
    'embed' => [['cid' => 'order-preview', 'path' => $embedFile, 'type' => 'image/jpeg', 'name' => 'preview.jpg']],
]);
$atts = $probe->message->getAttachments();
check(count($atts) === 1 && ($atts[0][7] ?? null) === 'order-preview' && ($atts[0][6] ?? null) === 'inline' && ($atts[0][4] ?? null) === 'image/jpeg' && ($atts[0][5] ?? true) === false, 'path-embed: одно inline-вложение с cid', json_encode($atts));
$mime = $probe->message->getSentMIMEMessage();
check(str_contains($mime, 'Content-ID: <order-preview>') && str_contains($mime, 'Content-Disposition: inline'), 'MIME: Content-ID и disposition inline', $mime);

$probe = new MailProbe();
$probe->send('Тема', 'Текст', null, null, null, [
    'html' => '<img src="cid:bytes">',
    'embed' => [['cid' => 'bytes', 'data' => 'PNGDATA', 'type' => 'image/png', 'name' => 'a.png']],
]);
$atts = $probe->message->getAttachments();
check(count($atts) === 1 && ($atts[0][7] ?? null) === 'bytes' && ($atts[0][5] ?? false) === true && ($atts[0][0] ?? null) === 'PNGDATA', 'data-embed: строковые байты', json_encode($atts));

$err = mail_throws(static fn () => (new MailProbe())->send('Тема', 'T', null, null, null, ['embed' => 'nope']));
check($err instanceof InvalidArgumentException && str_contains((string) $err?->getMessage(), 'embed'), 'embed не список — исключение', (string) $err?->getMessage());
$err = mail_throws(static fn () => (new MailProbe())->send('Тема', 'T', null, null, null, ['embed' => [['cid' => 'x', 'type' => 'image/jpeg']]]));
check($err instanceof InvalidArgumentException && str_contains((string) $err?->getMessage(), 'path или data'), 'без path/data — исключение', (string) $err?->getMessage());
$err = mail_throws(static fn () => (new MailProbe())->send('Тема', 'T', null, null, null, ['embed' => [['cid' => 'x', 'type' => 'image/jpeg', 'path' => $embedFile, 'data' => 'z']]]));
check($err instanceof InvalidArgumentException && str_contains((string) $err?->getMessage(), 'path или data'), 'path и data вместе — исключение', (string) $err?->getMessage());
$err = mail_throws(static fn () => (new MailProbe())->send('Тема', 'T', null, null, null, ['embed' => [['cid' => '<bad>', 'path' => $embedFile, 'type' => 'image/jpeg']]]));
check($err instanceof InvalidArgumentException && str_contains((string) $err?->getMessage(), 'cid'), 'cid с <> — исключение', (string) $err?->getMessage());
$err = mail_throws(static fn () => (new MailProbe())->send('Тема', 'T', null, null, null, ['embed' => [['cid' => 'x', 'path' => $embedDir . '/missing.jpg', 'type' => 'image/jpeg']]]));
check($err instanceof InvalidArgumentException && str_contains((string) $err?->getMessage(), 'path'), 'несуществующий path — исключение', (string) $err?->getMessage());
mail_rm($embedDir);

Env::set('SMTP_HOST', '');
$err = mail_throws(static fn () => (new MailProbe())->send('Тема', 'Текст'));
check($err instanceof RuntimeException && str_contains($err->getMessage(), 'SMTP_HOST'), 'без SMTP_HOST по-прежнему ошибка хоста', (string) $err?->getMessage());
Env::set('SMTP_HOST', 'smtp.example.test');
Env::set('SMTP_TO', '');
$err = mail_throws(static fn () => (new MailProbe())->send('Тема', 'Текст'));
check($err instanceof RuntimeException && str_contains($err->getMessage(), 'SMTP_TO'), 'без получателя по-прежнему ошибка SMTP_TO', (string) $err?->getMessage());

$mailerSrc = (string) file_get_contents($corePath . '/includes/Mailer.php');
$formHandler = (string) file_get_contents($corePath . '/includes/FormSubmitHandler.php');
$pluginMail = (string) file_get_contents($corePath . '/includes/PluginContext.php');
check(str_contains($mailerSrc, '$mail->isHTML(true);'), 'Mailer всегда вызывает isHTML(true)');
check(
    str_contains($formHandler, "(new Mailer(\$this->config))->send(\$subject, \$text, null, null, null, [")
        && str_contains($formHandler, "'html' => \$html")
        && str_contains($formHandler, "'text' => \$text"),
    'заявки форм отправляются через Mailer::send с HTML и текстом'
);
check(str_contains($pluginMail, '(new Mailer($this->config))->send($subject, $body, $to, null, $replyTo, $options)'), 'почта ядра для плагинов отправляется через Mailer::send');

out("Админка и .env\n");
$site = sys_get_temp_dir() . '/motorchk-mail-test-' . getmypid();
mail_rm($site);
mkdir($site . '/content', 0775, true);
mkdir($site . '/themes/example', 0775, true);
mkdir($site . '/cache', 0775, true);
file_put_contents($site . '/themes/example/default.php', '<?php ');
file_put_contents($site . '/cache/update-check.json', json_encode(['checked_at' => time() + 3600, 'current' => Version::current(), 'update' => null]));
$envBefore = "APP_SECRET=mail-test-secret\nSMTP_HOST=smtp.example.test\nSMTP_PORT=587\nSMTP_TO=to@example.test\nSMTP_FROM=from@example.test\nSMTP_BCC=keep@example.test\n";
file_put_contents($site . '/.env', $envBefore);
$config = [
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
$smtpPost = [
    '_csrf' => 'tok',
    'smtp_host' => 'smtp.example.test',
    'smtp_port' => '2525',
    'smtp_encryption' => 'ssl',
    'smtp_username' => 'user',
    'smtp_password' => '',
    'smtp_from' => 'from@example.test',
    'smtp_from_name' => 'Сайт',
    'smtp_to' => 'to@example.test',
    'smtp_bcc' => 'not-an-email',
];
$res = mail_admin($corePath, $config, 'POST', '/admin-panel/system/smtp', $smtpPost);
check($res['code'] === 302 && str_contains($res['body'], 'smtp_error') && str_contains(rawurldecode($res['body']), 'not-an-email'), 'некорректный BCC не сохраняется', $res['body']);
check(file_get_contents($site . '/.env') === $envBefore, '.env не меняется, если адрес BCC некорректный');

$smtpPost['smtp_bcc'] = '  copy@example.test , OTHER@example.test , copy@example.test , ';
$res = mail_admin($corePath, $config, 'POST', '/admin-panel/system/smtp', $smtpPost);
check($res['code'] === 302 && str_contains($res['body'], 'Redirect: /admin-panel/system?smtp=1'), 'корректный BCC сохраняется', $res['body']);
$storedVars = mail_dotenv($site . '/.env');
check(($storedVars['SMTP_BCC'] ?? null) === 'copy@example.test, OTHER@example.test', 'в .env нормализованный список', (string) ($storedVars['SMTP_BCC'] ?? ''));
check(($storedVars['SMTP_PORT'] ?? '') === '2525' && ($storedVars['APP_SECRET'] ?? '') === 'mail-test-secret' && ($storedVars['SMTP_ENCRYPTION'] ?? '') === 'ssl', '.env сохраняет остальные ключи SMTP');

$res = mail_admin($corePath, $config, 'GET', '/admin-panel/system');
check($res['code'] === 200 && str_contains($res['body'], 'Скрытая копия (BCC)') && str_contains($res['body'], 'name="smtp_bcc"'), 'на странице «Система» есть поле BCC', 'status ' . $res['code']);
check(str_contains($res['body'], 'value="copy@example.test, OTHER@example.test"'), 'поле показывает сохранённые адреса');

$smtpPost['smtp_port'] = '587';
$smtpPost['smtp_encryption'] = 'tls';
$smtpPost['smtp_username'] = '';
$smtpPost['smtp_from_name'] = '';
$smtpPost['smtp_bcc'] = '   ';
$res = mail_admin($corePath, $config, 'POST', '/admin-panel/system/smtp', $smtpPost);
check($res['code'] === 302 && (mail_dotenv($site . '/.env')['SMTP_BCC'] ?? null) === '', 'пустое поле очищает SMTP_BCC', $res['body']);

$example = (string) file_get_contents($corePath . '/skeleton/.env.example');
check(str_contains($example, "# SMTP_BCC=\n") || str_ends_with($example, '# SMTP_BCC='), '.env.example содержит закомментированный пустой ключ');

$installSite = sys_get_temp_dir() . '/motorchk-mail-install-' . getmypid();
mail_rm($installSite);
mkdir($installSite, 0775, true);
(new Installer([
    'site_path' => $installSite,
    'core_path' => $corePath,
    'content_path' => $installSite . '/content',
    'skeleton_path' => $corePath . '/skeleton',
]))->install('admin', 'password12');
$installedEnv = (string) file_get_contents($installSite . '/.env');
$installedExample = (string) file_get_contents($installSite . '/.env.example');
check(str_contains($installedEnv, "# SMTP_BCC=\n"), 'установщик пишет закомментированный SMTP_BCC в .env', $installedEnv);
check(str_contains($installedExample, '# SMTP_BCC='), 'установщик копирует .env.example с ключом');
check(!preg_match('/^SMTP_BCC=/m', $installedEnv), 'в новом .env ключ только в комментарии и не включает скрытую копию');

mail_rm($site);
mail_rm($installSite);

ob_end_clean();
fwrite(STDOUT, "\n{$passed} passed, {$failures} failed\n");
exit($failures === 0 ? 0 : 1);
