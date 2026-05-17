<?php

require __DIR__ . '/../vendor/autoload.php';

use App\Data\TransactionVector;
use App\Kernel\Router;

define('NORMALIZATION', [
    'max_amount' => 10000,
    'max_installments' => 12,
    'amount_vs_avg_ratio' => 10,
    'max_minutes' => 1440,
    'max_km' => 1000,
    'max_tx_count_24h' => 100,
    'max_merchant_avg_amount' => 10000
]);

define('MCC_RISK', [
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
]);

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

$router->post('/fraud-score', function($request){
    header('Content-Type: application/json');    

    $transaction = $request['transaction'] ?? null;
    $customer = $request['customer'] ?? null;
    $merchant = $request['merchant'] ?? null;
    $terminal = $request['terminal'] ?? null;
    $lastTransaction = $request['last_transaction'] ?? null;
    $lastTransactionTimestamp = $lastTransaction ? new DateTime($lastTransaction['timestamp']) : null;
    $requestedAt = new DateTime($transaction['requested_at'] ?? null);
    $minutesSinceLasTransaction = $lastTransactionTimestamp ? ($requestedAt->getTimestamp() - $lastTransactionTimestamp->getTimestamp()) / 60 : null;

    $vector = new TransactionVector(
        amount: limitValue($transaction['amount'] / NORMALIZATION['max_amount']),
        installments: limitValue($transaction['installments'] / NORMALIZATION['max_installments']),
        amount_vs_avg: limitValue(($transaction['amount'] / ($merchant['avg_amount']) / NORMALIZATION['amount_vs_avg_ratio'])),
        hour_of_day:  $requestedAt->format('H') / 23,
        day_of_week: $requestedAt->format('N') / 6,
        minutes_since_last_tx: $lastTransaction ? limitValue($minutesSinceLasTransaction / NORMALIZATION['max_minutes']) : -1,
        km_from_last_tx: $lastTransaction ? limitValue($lastTransaction['km_from_current'] / NORMALIZATION['max_km']) : -1,
        km_from_home: limitValue($terminal['km_from_home'] / NORMALIZATION['max_km']),
        tx_count_24h: limitValue($customer['tx_count_24h'] / NORMALIZATION['max_tx_count_24h']),
        is_online: $terminal['is_online'],
        card_present: $terminal['card_present'],
        unknown_merchant: !in_array($merchant['id'], $customer['known_merchants']),
        mcc_risk: MCC_RISK[$merchant['mcc']] ?? 0.5,
        merchant_avg_amount: limitValue($merchant['avg_amount'] / NORMALIZATION['max_merchant_avg_amount'])
    );


    echo json_encode(['approved' => false, 'fraud_score' => 1.0, 'vector' => $vector->getVector()]);
});

$router->handleRequest();


function limitValue($value) {
    return max(0, min(1, $value));
}

function getBodyParams(): array
{
    $contentType = strtolower($_SERVER['CONTENT_TYPE'] ?? '');

    if (str_contains($contentType, 'application/json')) {
        $rawBody = file_get_contents('php://input');

        if ($rawBody === false || trim($rawBody) === '') {
            return [];
        }

        $decoded = json_decode($rawBody, true);

        return is_array($decoded) ? $decoded : [];
    }

    return $_POST;
}