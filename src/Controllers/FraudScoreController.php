<?php

namespace App\Controllers;

use App\Calculators\EucladianDistanceCalculator;
use App\Data\TransactionVector;
use DateTime;

class FraudScoreController
{

    public function __construct(private readonly EucladianDistanceCalculator $eucladianDistanceCalculator){}

    public function handle(array $request): void
    {
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

        $vector = $vector->getVector();

        $centroids = require __DIR__ . '/../../resources/centroids.php';
        
        $fiveShortestDistances = [];
        $eucladianDistances = [];
        $clusterId = null;
        $minDistance = PHP_FLOAT_MAX;
        foreach ($centroids as $key => $centroid) {
            $eucladianDistance = $this->eucladianDistanceCalculator->calculate($vector, $centroid);

            if ($eucladianDistance < $minDistance) {
                $minDistance = $eucladianDistance;
                $clusterId = $key;
            }
        }
        unset($centroids);

        $cluster = require __DIR__ . '/../../resources/buckets/' . $clusterId . '.php';
        
        $fiveShortestDistances = [];

        foreach ($cluster as $item) {
                if (!isset($item['vector'])) {
                    continue;
                }

                $distance = $this->eucladianDistanceCalculator->calculate($vector, $item['vector']);

                if (count($fiveShortestDistances) < 5) {
                    $fiveShortestDistances[] = [
                        'distance' => $distance,
                        'item' => $item,
                    ];

                    continue;
                }

                $worstIndex = 0;
                $worstDistance = $fiveShortestDistances[0]['distance'];

                for ($i = 1; $i < 5; $i++) {
                    if ($fiveShortestDistances[$i]['distance'] > $worstDistance) {
                        $worstDistance = $fiveShortestDistances[$i]['distance'];
                        $worstIndex = $i;
                    }
                }

                if ($distance < $worstDistance) {
                    $fiveShortestDistances[$worstIndex] = [
                        'distance' => $distance,
                        'item' => $item,
                    ];
                }
        }
        unset($cluster);
        unset($item);

        $fraudCount = 0;
        foreach ($fiveShortestDistances as $distanceInfo) {
            if ($distanceInfo['item']['label'] === 'fraud') {
                $fraudCount++;
            }
        }

        $score = $fraudCount / 5;
        $approved = $score < FRAUD_THRESHOLD;

        echo '{"approved": ' . ($approved ? 'true' : 'false') . ', "fraud_score": ' . $score . '}';
    }
}