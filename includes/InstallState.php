<?php

class InstallState
{
    public static function isInstalled(string $sitePath): bool
    {
        return is_file($sitePath . '/.env')
            && is_file($sitePath . '/config/site.php');
    }
}
