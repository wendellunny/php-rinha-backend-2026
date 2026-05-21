<?php

use App\Controllers\FraudScoreController;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\Http\Server;

require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/config/bootstrap.php';

$port = 8080;

$server = new Server('0.0.0.0', $port);

$server->set([
    'worker_num' => (int) 3,
    'max_request' => 20000,
    'enable_coroutine' => true,
    'log_level' => SWOOLE_LOG_ERROR,
    'http_compression' => false,
    'open_tcp_nodelay' => true,
    'buffer_output_size' => 1024 * 1024,
    'package_max_length' => 512 * 1024,
]);

$server->on('workerStart', function () {
    $GLOBALS['fraudController'] = new FraudScoreController();

    $GLOBALS['primaryCentroids'] = require __DIR__ . '/resources/centroids.php';
});

$server->on('request', function (Request $request, Response $response) {
    $method = $request->server['request_method'] ?? 'GET';
    $uri = $request->server['request_uri'] ?? '/';

    if ($method === 'GET' && $uri === '/ready') {
        $response->status(200);
        $response->header('Content-Type', 'application/json');
        $response->end('{"message":"ok","status":200}');
        return;
    }

    if ($method !== 'POST' || $uri !== '/fraud-score') {
        $response->status(404);
        $response->header('Content-Type', 'application/json');
        $response->end('{"error":"not_found"}');
        return;
    }

    $payload = json_decode($request->rawContent(), true);

    if (!is_array($payload)) {
        $response->status(400);
        $response->header('Content-Type', 'application/json');
        $response->end('{"error":"invalid_json"}');
        return;
    }

    try {
        $result = $GLOBALS['fraudController']->handle($payload);

        $response->status(200);
        $response->header('Content-Type', 'application/json');
        $response->end($result);
    } catch (Throwable $e) {
        $response->status(500);
        $response->header('Content-Type', 'application/json');
        $response->end('{"error":"internal_error"}');
    }
});

$server->start();