<?php

/**
 * Ленивая сессия: стартует только когда ядру нужны $_SESSION
 * (вход в админку, CSRF, inline-edit, обход техобслуживания), а не на каждом запросе.
 *
 * PHP при session_start() и session.cache_limiter=nocache подмешивает Pragma и Expires 1981,
 * из‑за чего публичные файлы с длинным Cache-Control не кешируются. Limiter отключается
 * здесь и в bootstrap, чтобы старт сессии не портил заголовки кеша.
 */
class Session
{
    public static function start(): void
    {
        if (session_status() !== PHP_SESSION_NONE) {
            return;
        }

        session_cache_limiter('');
        $preset = isset($_SESSION) && is_array($_SESSION) ? $_SESSION : [];
        session_start();
        if ($preset !== []) {
            $_SESSION = array_merge($_SESSION, $preset);
        }
    }

    public static function startIfCookie(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        $name = session_name();
        if ($name !== '' && !empty($_COOKIE[$name])) {
            self::start();
        }
    }

    public static function isStarted(): bool
    {
        return session_status() === PHP_SESSION_ACTIVE;
    }
}
