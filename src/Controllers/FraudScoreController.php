<?php

namespace App\Controllers;

use App\Calculators\EucladianDistanceCalculator;
use App\Data\TransactionVector;
use DateTime;
use Swoole\Http\Request;
use Swoole\Http\Response;

class FraudScoreController
{

    public function handle(array $data): string
    {
        $transaction = $data['transaction'] ?? null;
        $customer = $data['customer'] ?? null;
        $merchant = $data['merchant'] ?? null;
        $terminal = $data['terminal'] ?? null;
        $lastTransaction = $data['last_transaction'] ?? null;

        $requestedAtTimeStamp = $transaction ? strtotime($transaction['requested_at']) : null;
        $requestAtHourOfDay = $requestedAtTimeStamp ? date('H', $requestedAtTimeStamp) : null;
        $requestAtDayOfWeek = $requestedAtTimeStamp ? date('N', $requestedAtTimeStamp) : null;

        $lastTransactionTimeStamp = $lastTransaction ? strtotime($lastTransaction['timestamp']) : null;
        $minutesSinceLasTransaction = ($requestedAtTimeStamp && $lastTransactionTimeStamp) ? ($requestedAtTimeStamp - $lastTransactionTimeStamp) / 60 : null;

        $vector = new TransactionVector(
            amount: limitValue($transaction['amount'] / NORMALIZATION['max_amount']),
            installments: limitValue($transaction['installments'] / NORMALIZATION['max_installments']),
            amount_vs_avg: limitValue(($transaction['amount'] / ($merchant['avg_amount']) / NORMALIZATION['amount_vs_avg_ratio'])),
            hour_of_day:  $requestAtHourOfDay / 23,
            day_of_week: $requestAtDayOfWeek / 6,
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


        $primaryCentroids = $GLOBALS['primaryCentroids'];
        
        $primaryClusterId = null;
        $primaryMinDistance = PHP_FLOAT_MAX;
        for ($i = 0; $i < PRIMARY_CLUSTERS; $i++) {
            $centroid = $primaryCentroids[$i];
            $euclidianDistance = $this->calculateEucladianDistanceWithLimit($vector, $centroid, $primaryMinDistance);

            if ($euclidianDistance < $primaryMinDistance) {
                $primaryMinDistance = $euclidianDistance;
                $primaryClusterId = $i;
            }
        }
        unset($primaryCentroids);

        $secondaryCentroids = require __DIR__ . '/../../resources/bucket_centroids/' . $primaryClusterId . '.php';
        
        $secondaryClusterId = null;
        $secondaryMinDistance = PHP_FLOAT_MAX;
        for ($i = 0; $i < SECONDARY_CLUSTERS; $i++) {
            $centroid = $secondaryCentroids[$i];
            $euclidianDistance = $this->calculateEucladianDistanceWithLimit($vector, $centroid, $secondaryMinDistance);

            if ($euclidianDistance < $secondaryMinDistance) {
                $secondaryMinDistance = $euclidianDistance;
                $secondaryClusterId = $i;
            }
        }
        unset($secondaryCentroids);

        $cluster = require __DIR__ . '/../../resources/buckets/' . $primaryClusterId . '/' . $secondaryClusterId . '.php';
        
        $fiveShortestDistances = [];
        $fiveShortestDistancesQty = 0;

        $worstIndex = 0;
        $worstDistance = PHP_FLOAT_MAX;

        foreach ($cluster as $item) {

            if ($fiveShortestDistancesQty < 5) {
                $distance = $this->calculateEucladianDistance($vector, $item['vector']);

                $fiveShortestDistances[] = [
                    'distance' => $distance,
                    'item' => $item,
                ];

                $fiveShortestDistancesQty++;

                if ($fiveShortestDistancesQty === 5) {
                    $worstIndex = 0;
                    $worstDistance = $fiveShortestDistances[0]['distance'];

                    for ($i = 1; $i < 5; $i++) {
                        if ($fiveShortestDistances[$i]['distance'] > $worstDistance) {
                            $worstDistance = $fiveShortestDistances[$i]['distance'];
                            $worstIndex = $i;
                        }
                    }
                }

                continue;
            }

            $distance = $this->calculateEucladianDistanceWithLimit(
                $vector,
                $item['vector'],
                $worstDistance
            );

            if ($distance < $worstDistance) {
                $fiveShortestDistances[$worstIndex] = [
                    'distance' => $distance,
                    'item' => $item,
                ];

                $worstIndex = 0;
                $worstDistance = $fiveShortestDistances[0]['distance'];

                for ($i = 1; $i < 5; $i++) {
                    if ($fiveShortestDistances[$i]['distance'] > $worstDistance) {
                        $worstDistance = $fiveShortestDistances[$i]['distance'];
                        $worstIndex = $i;
                    }
                }
            }
        }

        unset($cluster, $item);

        $fraudCount = 0;
        foreach ($fiveShortestDistances as $distanceInfo) {
            if ($distanceInfo['item']['label'] === 'fraud') {
                $fraudCount++;
            }
        }

        $score = $fraudCount / 5;
        $approved = $score < FRAUD_THRESHOLD;

        return '{"approved": ' . ($approved ? 'true' : 'false') . ', "fraud_score": ' . $score . '}';
    }


    public function calculateEucladianDistanceWithLimit(array $vector1, array $vector2, float $limit)
    {
        $sum = 0.0;

        $dimension = $vector1[0] - $vector2[0];
        $sum += $dimension * $dimension;
        if ($sum > $limit) return $sum;

        $dimension = $vector1[1] - $vector2[1];
        $sum += $dimension * $dimension;
        if ($sum > $limit) return $sum;

        $dimension = $vector1[2] - $vector2[2];
        $sum += $dimension * $dimension;
        if ($sum > $limit) return $sum;

        $dimension = $vector1[3] - $vector2[3];
        $sum += $dimension * $dimension;
        if ($sum > $limit) return $sum;

        $dimension = $vector1[4] - $vector2[4];
        $sum += $dimension * $dimension;
        if ($sum > $limit) return $sum;

        $dimension = $vector1[5] - $vector2[5];
        $sum += $dimension * $dimension;
        if ($sum > $limit) return $sum;

        $dimension = $vector1[6] - $vector2[6];
        $sum += $dimension * $dimension;
        if ($sum > $limit) return $sum;

        $dimension = $vector1[7] - $vector2[7];
        $sum += $dimension * $dimension;
        if ($sum > $limit) return $sum;

        $dimension = $vector1[8] - $vector2[8];
        $sum += $dimension * $dimension;
        if ($sum > $limit) return $sum;

        $dimension = $vector1[9] - $vector2[9];
        $sum += $dimension * $dimension;
        if ($sum > $limit) return $sum;

        $dimension = $vector1[10] - $vector2[10];
        $sum += $dimension * $dimension;
        if ($sum > $limit) return $sum;

        $dimension = $vector1[11] - $vector2[11];
        $sum += $dimension * $dimension;
        if ($sum > $limit) return $sum;

        $dimension = $vector1[12] - $vector2[12];
        $sum += $dimension * $dimension;
        if ($sum > $limit) return $sum;

        $dimension = $vector1[13] - $vector2[13];
        $sum += $dimension * $dimension;

        return $sum;
    }

    public function calculateEucladianDistance(array $vector1, array $vector2): float
    {
        $dimension1 = $vector1[0] - $vector2[0];
        $dimension2 = $vector1[1] - $vector2[1];
        $dimension3 = $vector1[2] - $vector2[2];
        $dimension4 = $vector1[3] - $vector2[3];
        $dimension5 = $vector1[4] - $vector2[4];
        $dimension6 = $vector1[5] - $vector2[5];
        $dimension7 = $vector1[6] - $vector2[6];
        $dimension8 = $vector1[7] - $vector2[7];
        $dimension9 = $vector1[8] - $vector2[8];
        $dimension10 = $vector1[9] - $vector2[9];
        $dimension11 = $vector1[10] - $vector2[10];
        $dimension12 = $vector1[11] - $vector2[11];
        $dimension13 = $vector1[12] - $vector2[12];
        $dimension14 = $vector1[13] - $vector2[13];
        
        return 
            $dimension1 * $dimension1 +
            $dimension2 * $dimension2 +
            $dimension3 * $dimension3 +
            $dimension4 * $dimension4 +
            $dimension5 * $dimension5 +
            $dimension6 * $dimension6 +
            $dimension7 * $dimension7 +
            $dimension8 * $dimension8 +
            $dimension9 * $dimension9 +
            $dimension10 * $dimension10 +
            $dimension11 * $dimension11 +
            $dimension12 * $dimension12 +
            $dimension13 * $dimension13 +
            $dimension14 * $dimension14;
        
    }

}