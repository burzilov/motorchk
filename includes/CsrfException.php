<?php

/**
 * Запрос не прошёл проверку CSRF. AdminRouter::dispatchAdmin() отвечает на него 403.
 */
final class CsrfException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('CSRF validation failed');
    }
}
