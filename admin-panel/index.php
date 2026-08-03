<?php

require_once dirname(__DIR__) . '/includes/bootstrap.php';

$router = new AdminRouter($config);
$router->dispatchAdmin($_SERVER['REQUEST_URI'] ?? '/admin-panel/');
