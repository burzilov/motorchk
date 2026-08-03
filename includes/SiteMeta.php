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
            return ['engine_version' => Version::current()];
        }

        $data = \Symfony\Component\Yaml\Yaml::parseFile($file);
        if (!is_array($data)) {
            return ['engine_version' => Version::current()];
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
}
