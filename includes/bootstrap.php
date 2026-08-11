<?php

$corePath = dirname(__DIR__);
$sitePath = dirname($corePath);

require_once $corePath . '/vendor/autoload.php';

spl_autoload_register(function (string $class) use ($corePath): void {
    $file = $corePath . '/includes/' . $class . '.php';
    if (is_file($file)) {
        require $file;
    }
});

require_once $corePath . '/includes/Env.php';
require_once $corePath . '/includes/InstallState.php';

$defaultConfig = [
    'site_path' => $sitePath,
    'core_path' => $corePath,
    'base_path' => $sitePath,
    'content_path' => $sitePath . '/content',
    'cache_path' => $sitePath . '/cache',
    'themes_path' => $sitePath . '/themes',
    'admin_templates_path' => $corePath . '/admin-panel/templates',
    'menu_file' => $sitePath . '/content/_menus.yaml',
    'skeleton_path' => $corePath . '/skeleton',
];

$installed = InstallState::isInstalled($sitePath);

if ($installed) {
    Env::load($sitePath . '/.env');
    $siteConfig = require $sitePath . '/config/site.php';
    $config = array_merge($defaultConfig, $siteConfig);
} else {
    $config = $defaultConfig;
}

$config['site_path'] = $sitePath;
$config['core_path'] = $corePath;
$config['installed'] = $installed;

foreach ([$config['cache_path'], $config['content_path']] as $dir) {
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
}

$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443);

session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $isHttps,
    'httponly' => true,
    'samesite' => 'Lax',
]);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
