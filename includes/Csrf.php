<?php

require_once __DIR__ . '/Session.php';

class Csrf
{
    public static function token(): string
    {
        Session::start();
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['csrf_token'];
    }

    public static function field(): string
    {
        $token = htmlspecialchars(self::token(), ENT_QUOTES, 'UTF-8');
        return '<input type="hidden" name="_csrf" value="' . $token . '">';
    }

    public static function validate(?string $token): bool
    {
        Session::startIfCookie();
        $expected = $_SESSION['csrf_token'] ?? null;
        if ($token === null || $expected === null || $expected === '') {
            return false;
        }

        return hash_equals((string) $expected, $token);
    }
}
