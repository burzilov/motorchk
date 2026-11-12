<?php

/**
 * Устаревшие функции PHP 8.5 в ядре и HTTP-запрос без E_DEPRECATED.
 * php tests/deprecations.php
 *
 * curl_close(), curl_share_close(), finfo_close(), imagedestroy() и xml_parser_free()
 * с PHP 8.0 ничего не делают: объект освобождается сам. С PHP 8.5 вызов даёт E_DEPRECATED.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$corePath = dirname(__DIR__);
require_once $corePath . '/includes/Updater.php';

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

$roots = ['includes', 'admin-panel', 'install', 'skeleton'];
$pattern = '/\b(?:curl_close|curl_share_close|finfo_close|imagedestroy|xml_parser_free)\s*\(/';
$hits = [];
foreach ($roots as $root) {
    $dir = $corePath . '/' . $root;
    if (!is_dir($dir)) {
        continue;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        /** @var SplFileInfo $file */
        if (!$file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }
        $path = $file->getPathname();
        if (str_contains(str_replace('\\', '/', $path), '/vendor/')) {
            continue;
        }
        $source = (string) file_get_contents($path);
        if (preg_match($pattern, $source) === 1) {
            $hits[] = substr($path, strlen($corePath) + 1);
        }
    }
}
sort($hits);
check(
    $hits === [],
    'в ядре нет curl_close, curl_share_close, finfo_close, imagedestroy, xml_parser_free',
    implode(', ', $hits)
);

$workflow = (string) file_get_contents($corePath . '/.github/workflows/tests.yml');
check(
    str_contains($workflow, '8.5')
        && str_contains($workflow, 'auto_prepend_file=tests/fail-on-deprecation.php')
        && preg_match('/for t in [^;]*\bdeprecations\b/', $workflow) === 1,
    'CI на PHP 8.5 подключает fail-on-deprecation.php и гоняет tests/deprecations.php'
);

$probe = 'http://127.0.0.1:9/motorchk-deprecation-probe';
$updater = new Updater([]);
$httpCode = Closure::bind(
    fn (string $url): int => $this->httpCode($url),
    $updater,
    Updater::class
);
$httpGet = Closure::bind(
    fn (string $url, array $headers = []): ?string => $this->httpGet($url, $headers),
    $updater,
    Updater::class
);

$deprecations = [];
set_error_handler(static function (int $severity, string $message, string $file, int $line) use (&$deprecations): bool {
    if ((error_reporting() & $severity) === 0) {
        return true;
    }
    if (($severity & (E_DEPRECATED | E_USER_DEPRECATED)) !== 0) {
        $normalized = str_replace('\\', '/', $file);
        if (preg_match('~(^|/)vendor/~', $normalized) !== 1) {
            $deprecations[] = "{$message} ({$file}:{$line})";
        }
    }

    return true;
});
try {
    $code = $httpCode($probe);
    $body = $httpGet($probe, ['User-Agent: motorchk-cms']);
} finally {
    restore_error_handler();
}

check(function_exists('curl_init'), 'зонд идёт через cURL');
check($code === 0, 'httpCode на закрытый порт возвращает 0', (string) $code);
check($body === null, 'httpGet на закрытый порт возвращает null', var_export($body, true));
check($deprecations === [], 'HTTP-запрос ядра не даёт E_DEPRECATED', implode('; ', $deprecations));

fwrite(STDOUT, "\n{$passed} passed, {$failures} failed\n");
exit($failures === 0 ? 0 : 1);
