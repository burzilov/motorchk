<?php

/**
 * Публичная папка файлов содержимого: public_html/media/ → /media/… (core/docs/12-themes.md).
 *
 * Apache отдаёт файлы напрямую; media/.htaccess пропускает только картинки, видео, аудио и PDF
 * и выключает исполнение кода. Ядро создаёт папку при установке (skeleton) и в install/migrate.php.
 */
final class Media
{
    public const URL_PREFIX = '/media';

    /**
     * Адрес файла: Media::url('products/p5-1.webp') → /media/products/p5-1.webp.
     * Имена — латиница, цифры, «_», «-», «.» (не в начале сегмента), как PluginManager::isSafeRelativePath().
     */
    public static function url(string $relative): string
    {
        $relative = trim(str_replace('\\', '/', $relative), '/');
        if ($relative === '') {
            return self::URL_PREFIX . '/';
        }
        if (!PluginManager::isSafeRelativePath($relative)) {
            throw new InvalidArgumentException('Недопустимый путь в media/: ' . $relative);
        }

        return self::URL_PREFIX . '/' . $relative;
    }

    /** Абсолютный путь файла в media/ или null, если путь небезопасный. */
    public static function path(array $config, string $relative): ?string
    {
        $relative = trim(str_replace('\\', '/', $relative), '/');
        if ($relative !== '' && !PluginManager::isSafeRelativePath($relative)) {
            return null;
        }

        return rtrim(self::root($config), '/') . ($relative === '' ? '' : '/' . $relative);
    }

    public static function root(array $config): string
    {
        return (string) ($config['media_path'] ?? rtrim((string) ($config['site_path'] ?? ''), '/') . '/media');
    }

    /**
     * Создать media/ и защитный .htaccess из core/skeleton/media/, если их нет.
     * Существующий .htaccess не перезаписывается.
     */
    public static function ensure(array $config): void
    {
        $root = self::root($config);
        if (!is_dir($root) && !@mkdir($root, 0755, true) && !is_dir($root)) {
            throw new RuntimeException('Не удалось создать папку media/');
        }
        $target = $root . '/.htaccess';
        if (is_file($target)) {
            return;
        }
        $source = rtrim((string) ($config['skeleton_path'] ?? rtrim((string) $config['core_path'], '/') . '/skeleton'), '/') . '/media/.htaccess';
        if (!is_file($source) || !@copy($source, $target)) {
            throw new RuntimeException('Не удалось записать media/.htaccess');
        }
    }
}
