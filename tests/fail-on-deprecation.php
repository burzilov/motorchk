<?php

/**
 * auto_prepend_file для прогона тестов на PHP 8.5.
 * E_DEPRECATED и E_USER_DEPRECATED из кода ядра завершают процесс.
 * Предупреждения из vendor/ не роняют прогон и не печатаются: зависимости ядро не правит.
 */

if (PHP_SAPI !== 'cli') {
    return;
}

set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (($severity & (E_DEPRECATED | E_USER_DEPRECATED)) === 0) {
        return false;
    }
    if ((error_reporting() & $severity) === 0) {
        return false;
    }
    $normalized = str_replace('\\', '/', $file);
    if (preg_match('~(^|/)vendor/~', $normalized) === 1) {
        return true;
    }
    fwrite(STDERR, "E_DEPRECATED: {$message} in {$file} on line {$line}\n");
    exit(1);
});
