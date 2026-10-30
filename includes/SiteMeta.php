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
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException('Не удалось создать каталог content/ для _meta.yaml');
        }
        if (!is_writable($dir) || (is_file($file) && !is_writable($file))) {
            throw new RuntimeException(
                'Нет прав на запись в ' . $file . ' (нужен доступ PHP-пользователя к content/)'
            );
        }
        if (file_put_contents($file, $yaml) === false) {
            throw new RuntimeException('Не удалось сохранить ' . $file);
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

    /** Включён ли режим технического обслуживания (content/_meta.yaml). */
    public static function isMaintenanceEnabled(array $config): bool
    {
        return (bool) (self::load($config)['maintenance_enabled'] ?? false);
    }

    /**
     * Хэш секрета обслуживания (password_hash) или '' — если не задан.
     * Открытый секрет нигде не хранится.
     */
    public static function maintenanceHash(array $config): string
    {
        $hash = self::load($config)['maintenance_hash'] ?? '';
        return is_string($hash) ? $hash : '';
    }

    public static function setMaintenance(
        array $config,
        bool $enabled,
        ?string $plainSecret = null,
        bool $rotateEpoch = false,
    ): void {
        $data = self::load($config);
        $data['maintenance_enabled'] = $enabled;
        if ($plainSecret !== null && $plainSecret !== '') {
            $hash = password_hash($plainSecret, PASSWORD_BCRYPT, ['cost' => 12]);
            if ($hash === false) {
                throw new RuntimeException('Не удалось создать хэш секрета обслуживания');
            }
            $data['maintenance_hash'] = $hash;
            // Установка/смена секрета инвалидирует ранее выданные разрешения
            $rotateEpoch = true;
        }
        if ($rotateEpoch) {
            // Повторное включение после выключения тоже ротирует эпоху: старый
            // доступ не возобновляется автоматически (docs/14-maintenance.md)
            $data['maintenance_epoch'] = bin2hex(random_bytes(8));
        }
        self::save($config, $data);
    }

    /** Удалить секрет (хэш): вход по паролю/ссылке становится невозможным. */
    public static function clearMaintenanceSecret(array $config): void
    {
        $data = self::load($config);
        unset($data['maintenance_hash']);
        $data['maintenance_epoch'] = bin2hex(random_bytes(8));
        self::save($config, $data);
    }

    /** Эпоха секрета: меняется при каждой установке нового секрета. */
    public static function maintenanceEpoch(array $config): string
    {
        return (string) (self::load($config)['maintenance_epoch'] ?? '');
    }
}
