<?php

$sitePath = dirname(__DIR__);
$corePath = $sitePath . '/core';

return [
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
