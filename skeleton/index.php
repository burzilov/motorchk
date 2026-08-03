<?php

require_once __DIR__ . '/core/includes/bootstrap.php';

if (empty($config['installed'])) {
    header('Location: /admin-panel/install', true, 302);
    exit;
}

$router = new Router($config);
$router->dispatchPublic($_SERVER['REQUEST_URI'] ?? '/');
