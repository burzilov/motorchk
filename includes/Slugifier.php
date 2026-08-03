<?php

class Slugifier
{
    public const MAP = [
        'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd',
        'е' => 'e', 'ё' => 'yo', 'ж' => 'zh', 'з' => 'z', 'и' => 'i',
        'й' => 'y', 'к' => 'k', 'л' => 'l', 'м' => 'm', 'н' => 'n',
        'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't',
        'у' => 'u', 'ф' => 'f', 'х' => 'h', 'ц' => 'ts', 'ч' => 'ch',
        'ш' => 'sh', 'щ' => 'sch', 'ъ' => '', 'ы' => 'y', 'ь' => '',
        'э' => 'e', 'ю' => 'yu', 'я' => 'ya',
        'А' => 'a', 'Б' => 'b', 'В' => 'v', 'Г' => 'g', 'Д' => 'd',
        'Е' => 'e', 'Ё' => 'yo', 'Ж' => 'zh', 'З' => 'z', 'И' => 'i',
        'Й' => 'y', 'К' => 'k', 'Л' => 'l', 'М' => 'm', 'Н' => 'n',
        'О' => 'o', 'П' => 'p', 'Р' => 'r', 'С' => 's', 'Т' => 't',
        'У' => 'u', 'Ф' => 'f', 'Х' => 'h', 'Ц' => 'ts', 'Ч' => 'ch',
        'Ш' => 'sh', 'Щ' => 'sch', 'Ъ' => '', 'Ы' => 'y', 'Ь' => '',
        'Э' => 'e', 'Ю' => 'yu', 'Я' => 'ya',
    ];

    public static function slugify(string $text): string
    {
        $text = strtr($text, self::MAP);
        $text = mb_strtolower($text, 'UTF-8');
        $text = preg_replace('/[^a-z0-9]+/', '-', $text) ?? '';
        $text = trim($text, '-');

        return $text !== '' ? $text : 'page';
    }

    public static function isValidLeaf(string $slug): bool
    {
        return (bool) preg_match('/^[a-z0-9_-]+$/', $slug);
    }

    public static function buildFullSlug(?string $parent, string $leaf): string
    {
        $parent = $parent !== null && $parent !== '' ? trim($parent, '/') : '';

        if ($parent === '') {
            return $leaf;
        }

        return $parent . '/' . $leaf;
    }

    public static function leafFromSlug(string $slug): string
    {
        $parts = explode('/', trim($slug, '/'));

        return end($parts) ?: 'index';
    }

    public static function parentFromSlug(string $slug): ?string
    {
        $slug = trim($slug, '/');
        if ($slug === '' || $slug === 'index') {
            return null;
        }

        $parts = explode('/', $slug);
        if (count($parts) <= 1) {
            return null;
        }

        array_pop($parts);

        return implode('/', $parts);
    }
}
