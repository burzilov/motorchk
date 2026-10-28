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
    'forms_path' => $sitePath . '/content/forms',
    'submissions_path' => $sitePath . '/submissions',
    'plugins_path' => $sitePath . '/plugins',
    'plugin_data_path' => $sitePath . '/plugin-data',
    'admin_templates_path' => $corePath . '/admin-panel/templates',
    'menu_file' => $sitePath . '/content/_menus.yaml',
    'skeleton_path' => $corePath . '/skeleton',
    // sitemap.xml, robots.txt, llms.txt (core/docs/13-seo.md); без ключа — всё включено
    // 'seo' => [
    //     'site_url' => 'https://example.ru',
    //     'robots_disallow' => [],
    //     'site_name' => '',
    //     'llms_description' => '',
    // ],
];
