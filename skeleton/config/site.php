<?php

$sitePath = dirname(__DIR__);
$corePath = $sitePath . '/core';

return [
    'site_path' => $sitePath,
    'core_path' => $corePath,
    'base_path' => $sitePath,
    'content_path' => $sitePath . '/content',
    'cache_path' => $sitePath . '/cache',
    'templates_path' => $sitePath . '/templates',
    'admin_templates_path' => $corePath . '/admin-panel/templates',
    'menu_file' => $sitePath . '/content/_menu.yaml',
    'skeleton_path' => $corePath . '/skeleton',
];
