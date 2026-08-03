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

    SiteMeta::setEngineVersion($config, $toVersion);

    $cachePath = $config['cache_path'] ?? '';
    if ($cachePath !== '' && is_dir($cachePath)) {
        $cache = new Cache($cachePath);
        $cache->flush();
    }
}
