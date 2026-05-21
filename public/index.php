<?php

require __DIR__ . '/../vendor/autoload.php';

use App\Controllers\FraudScoreController;
use App\Kernel\Router;

const NORMALIZATION =[
    'max_amount' => 10000,
    'max_installments' => 12,
    'amount_vs_avg_ratio' => 10,
    'max_minutes' => 1440,
    'max_km' => 1000,
    'max_tx_count_24h' => 100,
    'max_merchant_avg_amount' => 10000
];

const MCC_RISK = [
    '5411' => 0.15,
    '5812' => 0.30,
    '5912' => 0.20,
    '5944' => 0.45,
    '7801' => 0.80,
    '7802' => 0.75,
    '7995' => 0.85,
    '4511' => 0.35,
    '5311' => 0.25,
    '5999' => 0.50
];

const FRAUD_THRESHOLD = 0.6;
const VECTOR_DIMENSIONS = 14;
const PRIMARY_CLUSTERS = 144;
const SECONDARY_CLUSTERS = 144;

$router =  new Router(
    $_SERVER['REQUEST_METHOD'],
    parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH),
    $_GET,
    getBodyParams()
);

$router->get('/ready', function() {
    http_response_code(200);
    echo json_encode(['message' => 'ok', 'status' => 200]);
});

$router->post('/fraud-score', [new FraudScoreController(), 'handle']);

$router->handleRequest();