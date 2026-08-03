<?php

class AdminUrl
{
    public static function page(string $slug): string
    {
        $slug = trim($slug, '/');

        if ($slug === '' || $slug === 'index') {
            return '/admin-panel/pages/index';
        }

        $segments = explode('/', $slug);
        $encoded = array_map(static fn(string $segment): string => rawurlencode($segment), $segments);

        return '/admin-panel/pages/' . implode('/', $encoded);
    }

    public static function publicPage(string $slug): string
    {
        $slug = trim($slug, '/');

        if ($slug === '' || $slug === 'index') {
            return '/';
        }

        $segments = explode('/', $slug);
        $encoded = array_map(static fn(string $segment): string => rawurlencode($segment), $segments);

        return '/' . implode('/', $encoded);
    }

    public static function previewPage(string $slug): string
    {
        $url = self::publicPage($slug);

        return $url === '/' ? '/?preview=1' : $url . '?preview=1';
    }

    public static function viewPage(string $slug, bool $published): string
    {
        return $published ? self::publicPage($slug) : self::previewPage($slug);
    }

    public static function pageBlocks(string $slug): string
    {
        return self::page($slug) . '/blocks';
    }

    public static function slugFromPath(string $path): ?string
    {
        if (!preg_match('#^/pages(?:/(.+))?$#', $path, $matches)) {
            return null;
        }

        if (!isset($matches[1]) || $matches[1] === '') {
            return 'index';
        }

        if ($matches[1] === 'create' || str_ends_with($matches[1], '/blocks') || $matches[1] === 'blocks') {
            return null;
        }

        $segments = explode('/', $matches[1]);
        $segments = array_map('rawurldecode', $segments);

        return implode('/', $segments);
    }

    public static function slugFromBlocksPath(string $path): ?string
    {
        if (!preg_match('#^/pages/(.+)/blocks$#', $path, $matches)) {
            return null;
        }

        $segments = explode('/', $matches[1]);
        $segments = array_map('rawurldecode', $segments);
        $slug = implode('/', $segments);

        return $slug === '' ? null : $slug;
    }
}
