<?php

/**
 * Post-update migrations for motorchk core.
 *
 * @param array $config Site+core config from bootstrap
 * @param string $toVersion Target engine version (without v)
 */
function motorchk_migrate(array $config, string $toVersion): void
{
    $toVersion = ltrim(trim($toVersion), 'v');

    if (!class_exists('SiteMeta', false)) {
        require_once dirname(__DIR__) . '/includes/SiteMeta.php';
    }
    if (!class_exists('Version', false)) {
        require_once dirname(__DIR__) . '/includes/Version.php';
    }
    if (!class_exists('Cache', false)) {
        require_once dirname(__DIR__) . '/includes/Cache.php';
    }

    motorchk_replaceExampleTheme($config);
    motorchk_removeLegacyThemePaths($config['site_path']);
    motorchk_patch_htaccess($config['site_path'] . '/.htaccess');
    SiteMeta::setEngineVersion($config, $toVersion);

    $cachePath = $config['cache_path'] ?? '';
    if ($cachePath !== '' && is_dir($cachePath)) {
        $cache = new Cache($cachePath);
        $cache->flush();
    }
}

function motorchk_replaceExampleTheme(array $config): void
{
    $source = rtrim($config['core_path'], '/') . '/skeleton/themes/example';
    $themesPath = rtrim((string) ($config['themes_path'] ?? $config['site_path'] . '/themes'), '/');
    $target = $themesPath . '/example';
    $next = $themesPath . '/.example-next';
    $old = $themesPath . '/.example-old';

    if (!is_file($source . '/default.php')) {
        throw new RuntimeException('Не найдена поставляемая тема example');
    }
    if (!is_dir($themesPath) && !mkdir($themesPath, 0755, true) && !is_dir($themesPath)) {
        throw new RuntimeException('Не удалось создать каталог themes/');
    }

    motorchk_removeTree($next);
    motorchk_removeTree($old);
    motorchk_copyTree($source, $next);

    if (!is_file($next . '/default.php')) {
        motorchk_removeTree($next);
        throw new RuntimeException('Не удалось подготовить тему example');
    }

    $hasOldTheme = is_dir($target);
    try {
        if ($hasOldTheme && !rename($target, $old)) {
            throw new RuntimeException('Не удалось создать резервную копию темы example');
        }
        if (!rename($next, $target)) {
            if ($hasOldTheme) {
                rename($old, $target);
            }
            throw new RuntimeException('Не удалось активировать тему example');
        }
        motorchk_removeTree($old);
    } catch (Throwable $e) {
        motorchk_removeTree($next);
        throw $e;
    }
}

function motorchk_removeLegacyThemePaths(string $sitePath): void
{
    motorchk_removeTree(rtrim($sitePath, '/') . '/templates');
    motorchk_removeTree(rtrim($sitePath, '/') . '/assets');
}

function motorchk_patch_htaccess(string $path): void
{
    if (!is_file($path)) {
        return;
    }

    $contents = file_get_contents($path);
    if ($contents === false) {
        throw new RuntimeException('Не удалось прочитать .htaccess');
    }

    $patched = preg_replace(
        '/^[ \t]*RedirectMatch 403 \^\/templates\/\R?/m',
        '',
        $contents
    );
    if ($patched === null) {
        throw new RuntimeException('Не удалось обновить .htaccess');
    }

    if (!str_contains($patched, '# motorchk:theme-assets')) {
        $marker = '    # Админка — front controller ядра';
        $rules = "    # motorchk:theme-assets\n"
            . "    RewriteCond %{REQUEST_URI} ^/themes/ [NC]\n"
            . "    RewriteCond %{REQUEST_URI} !^/themes/[^/]+/assets/ [NC]\n"
            . "    RewriteRule ^ - [F,L]\n\n";

        if (!str_contains($patched, $marker)) {
            throw new RuntimeException('Не удалось найти секцию mod_rewrite в .htaccess');
        }
        $patched = str_replace($marker, $rules . $marker, $patched);
    }

    if ($patched !== $contents && file_put_contents($path, $patched) === false) {
        throw new RuntimeException('Не удалось записать .htaccess');
    }
}

function motorchk_copyTree(string $source, string $target): void
{
    if (!mkdir($target, 0755, true) && !is_dir($target)) {
        throw new RuntimeException('Не удалось создать временный каталог темы');
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($iterator as $item) {
        $relative = substr($item->getPathname(), strlen($source) + 1);
        $destination = $target . '/' . $relative;
        if ($item->isDir()) {
            if (!mkdir($destination, 0755, true) && !is_dir($destination)) {
                throw new RuntimeException('Не удалось создать каталог темы');
            }
            continue;
        }
        if (!copy($item->getPathname(), $destination)) {
            throw new RuntimeException('Не удалось скопировать файл темы');
        }
    }
}

function motorchk_removeTree(string $path): void
{
    if (!is_dir($path)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        if ($item->isDir()) {
            if (!rmdir($item->getPathname())) {
                throw new RuntimeException('Не удалось удалить каталог ' . $item->getPathname());
            }
            continue;
        }
        if (!unlink($item->getPathname())) {
            throw new RuntimeException('Не удалось удалить файл ' . $item->getPathname());
        }
    }
    if (!rmdir($path)) {
        throw new RuntimeException('Не удалось удалить каталог ' . $path);
    }
}
