<?php

require __DIR__ . '/../vendor/autoload.php';

use App\Kernel\Router;

$router =  new Router(
    $_SERVER['REQUEST_METHOD'],
    parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH),
    $_GET,
    $_POST
);

$router->get('/ready', function() {
    http_response_code(200);
    echo json_encode(['message' => 'ok', 'status' => 200]);
});

$router->handleRequest();