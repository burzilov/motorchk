<?php

class Version
{
    public static function current(): string
    {
        $file = dirname(__DIR__) . '/VERSION';
        if (!is_file($file)) {
            return '0.0.0';
        }

        $version = trim((string) file_get_contents($file));

        return $version !== '' ? $version : '0.0.0';
    }

    public static function compare(string $a, string $b): int
    {
        return version_compare(ltrim($a, 'v'), ltrim($b, 'v'));
    }

    public static function normalizeTag(string $tag): string
    {
        return ltrim(trim($tag), 'v');
    }
}
