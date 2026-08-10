<?php

class SiteMeta
{
    public static function path(array $config): string
    {
        return rtrim($config['content_path'], '/') . '/_meta.yaml';
    }

    public static function load(array $config): array
    {
        $file = self::path($config);
        if (!is_file($file)) {
            return ['engine_version' => Version::current(), 'theme' => 'example'];
        }

        $data = \Symfony\Component\Yaml\Yaml::parseFile($file);
        if (!is_array($data)) {
            return ['engine_version' => Version::current(), 'theme' => 'example'];
        }

        return $data;
    }

    public static function save(array $config, array $data): void
    {
        $yaml = \Symfony\Component\Yaml\Yaml::dump($data, 4, 2);
        $file = self::path($config);
        $dir = dirname($file);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        if (file_put_contents($file, $yaml) === false) {
            throw new RuntimeException('Не удалось сохранить _meta.yaml');
        }
    }

    public static function setEngineVersion(array $config, string $version): void
    {
        $data = self::load($config);
        $data['engine_version'] = Version::normalizeTag($version);
        self::save($config, $data);
    }

    public static function getTheme(array $config): string
    {
        $theme = self::load($config)['theme'] ?? 'example';

        return is_string($theme) && preg_match('/^[a-zA-Z0-9_-]+$/', $theme)
            ? $theme
            : 'example';
    }

    public static function setTheme(array $config, string $theme): void
    {
        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $theme)) {
            throw new InvalidArgumentException('Некорректное имя темы');
        }

        $data = self::load($config);
        $data['theme'] = $theme;
        self::save($config, $data);
    }
}
