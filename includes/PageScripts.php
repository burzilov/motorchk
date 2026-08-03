<?php

class PageScripts
{
    public static function normalize(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        if (is_string($value)) {
            $value = preg_split('/\r?\n/', $value) ?: [];
        }

        if (!is_array($value)) {
            return [];
        }

        $scripts = [];
        foreach ($value as $item) {
            $path = self::sanitizePath((string) $item);
            if ($path !== null) {
                $scripts[] = $path;
            }
        }

        return array_values(array_unique($scripts));
    }

    public static function fromTextarea(string $text): array
    {
        return self::normalize(preg_split('/\r?\n/', $text) ?: []);
    }

    public static function toTextarea(array $scripts): string
    {
        return implode("\n", self::normalize($scripts));
    }

    public static function sanitizePath(string $path): ?string
    {
        $path = trim($path);
        if ($path === '') {
            return null;
        }

        if (!str_starts_with($path, '/')) {
            $path = '/' . ltrim($path, '/');
        }

        if (str_contains($path, '..')) {
            return null;
        }

        if (!preg_match('#^/assets/js/[a-zA-Z0-9._/-]+\.js$#', $path)) {
            return null;
        }

        return $path;
    }
}
