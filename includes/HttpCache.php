<?php

/**
 * Заголовки HTTP-кеша без следов сессии (Pragma, Expires 1981).
 */
class HttpCache
{
    public static function apply(string $cacheControl): void
    {
        header_remove('Pragma');
        header_remove('Expires');
        header('Cache-Control: ' . $cacheControl);
        $maxAge = self::maxAge($cacheControl);
        if ($maxAge !== null) {
            header('Expires: ' . gmdate('D, d M Y H:i:s', time() + $maxAge) . ' GMT');
        }
    }

    public static function maxAge(string $cacheControl): ?int
    {
        if (preg_match('/max-age=(\d+)/i', $cacheControl, $m) === 1) {
            return (int) $m[1];
        }

        return null;
    }

    public static function etagMatches(string $etag, string $header): bool
    {
        $header = trim($header);
        if ($header === '') {
            return false;
        }
        foreach (explode(',', $header) as $part) {
            $part = trim($part);
            if ($part === '*') {
                return true;
            }
            if (str_starts_with($part, 'W/')) {
                $part = trim(substr($part, 2));
            }
            if ($part === $etag) {
                return true;
            }
        }

        return false;
    }

    public static function notModifiedSince(int $mtime, string $header): bool
    {
        $header = trim($header);
        if ($header === '') {
            return false;
        }
        $since = strtotime($header);
        if ($since === false) {
            return false;
        }

        return $mtime <= $since;
    }
}
