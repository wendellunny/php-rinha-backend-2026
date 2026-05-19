<?php

use JsonMachine\Items;
use JsonMachine\JsonDecoder\ExtJsonDecoder;

require __DIR__ . '/../vendor/autoload.php';

ini_set('memory_limit', '150M');

const VECTOR_DIMENSIONS = 14;
const CLUSTER_QTY = 256;
const ITERATIONS = 1;
const BATCH_SIZE = false;
const WRITE_BUCKETS_BUFFER_SIZE = 1000;

function referenceIterator(string $file): iterable
{
    return Items::fromFile($file, [
        'decoder' => new ExtJsonDecoder(true),
    ]);
}

function pickInitialCentroidsFromFile(string $referencesPath, int $qty): array
{
    $centroids = [];

    foreach(referenceIterator($referencesPath) as $key => $reference) {
        if (BATCH_SIZE && $key >= BATCH_SIZE) {
            break;
        }
        $centroids[] = $reference['vector'];

        if (count($centroids) >= $qty) {
            break;
        }
    }

    if (count($centroids) < $qty) {
        throw new RuntimeException("Não há referências suficientes para escolher os centróides iniciais.");
    }

    return $centroids;
}

function findNearestCentroid(array $vector, array $centroids): int
{
    $bestIndex = 0;
    $bestDistance = PHP_FLOAT_MAX;

    foreach ($centroids as $index => $centroid) {
        $distance = 0.0;
        for ($i = 0; $i < VECTOR_DIMENSIONS; $i++) {
            $sub = $vector[$i] - $centroid[$i];
            $squared = $sub * $sub;
            $distance += $squared;
        }

        if ($distance < $bestDistance) {
            $bestDistance = $distance;
            $bestIndex = $index;
        }
    }

    return $bestIndex;
}

function trainCentroidsFromFile(string $referencesPath, int $qty, int $iterations): array
{
    $centroids = pickInitialCentroidsFromFile($referencesPath, $qty);

    for ($iteration = 0; $iteration < $iterations; $iteration++) {
        echo "Treinando iteração " . ($iteration + 1) . "/{$iterations}" . PHP_EOL;

        $sums = [];
        $counts = array_fill(0, $qty, 0);

        for ($cluster = 0; $cluster < $qty; $cluster++) {
            $sums[$cluster] = array_fill(0, VECTOR_DIMENSIONS, 0.0);
        }

        $total = 0;

        foreach (referenceIterator($referencesPath) as $key => $reference) {
            if (BATCH_SIZE && $key >= BATCH_SIZE) {
                break;
            }
            $vector = $reference['vector'];

            $nearest = findNearestCentroid($vector, $centroids);

            $counts[$nearest]++;

            for ($dimension = 0; $dimension < VECTOR_DIMENSIONS; $dimension++) {
                $sums[$nearest][$dimension] += $vector[$dimension];
            }

            $total++;

            if ($total % 100000 === 0) {
                echo "  Processados: {$total}" . PHP_EOL;
            }
        }

        for ($cluster = 0; $cluster < $qty; $cluster++) {
            if ($counts[$cluster] === 0) {
                continue;
            }

            for ($dimension = 0; $dimension < VECTOR_DIMENSIONS; $dimension++) {
                $centroids[$cluster][$dimension] =
                    $sums[$cluster][$dimension] / $counts[$cluster];
            }
        }

        echo "  Total processado na iteração: {$total}" . PHP_EOL;
    }

    return $centroids;
}

function saveCentroids(string $path, array $centroids): void
{
    file_put_contents($path, json_encode($centroids, JSON_UNESCAPED_SLASHES));
}

function loadControids(string $path): array
{
    return json_decode(file_get_contents($path), true);
}

function clearBucketsDirectory(string $bucketsPath): void
{
    foreach (glob($bucketsPath . '/*.ndjson') as $file) {
        unlink($file);
    }
}


function buildBucketsFromFile(
    string $referencesPath,
    array $centroids,
    string $bucketsPath,
    string $bucketsIndexPath
): void {

    clearBucketsDirectory($bucketsPath);

    $qty = count($centroids);

    $handles = [];
    $counts = array_fill(0, $qty, 0);

    for ($i = 0; $i < $qty; $i++) {
        $bucketFile = $bucketsPath . '/' . $i . '.ndjson';
        $handles[$i] = fopen($bucketFile, 'wb');
    }

    $total = 0;

    $buffers = [];
    foreach(referenceIterator($referencesPath) as $key =>$reference) {
        if (BATCH_SIZE && $key >= BATCH_SIZE) {
            break;
        }
        $vector = $reference['vector'];
        $nearest = findNearestCentroid($vector, $centroids);

        $line = json_encode([
            'vector' => $reference['vector'],
            'label' => $reference['label'],
        ], JSON_UNESCAPED_SLASHES);

        if (!isset($buffers[$nearest])) {
            $buffers[$nearest] = [];
        }

        $buffers[$nearest][] = $line;

        if (count($buffers[$nearest]) >= WRITE_BUCKETS_BUFFER_SIZE) {
            fwrite($handles[$nearest], implode(PHP_EOL, $buffers[$nearest]) . PHP_EOL);
            $buffers[$nearest] = [];
        }

        $counts[$nearest]++;
        $total++;

        if ($total % 50000 === 0) {
            echo "Processados: {$total}" . PHP_EOL;
        }
    }

    foreach ($buffers as $nearest => $buffer) {
        if (count($buffer) > 0) {
            fwrite($handles[$nearest], implode(PHP_EOL, $buffer) . PHP_EOL);
        }
    } 

    foreach ($handles as $handle) {
        fclose($handle);
    }

    file_put_contents(
        $bucketsIndexPath,
        json_encode([
            'total' => $total,
            'clusters' => $qty,
            'counts' => $counts,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
    ));

    echo 'Total de referências processadas: ' . $total . PHP_EOL;
}


// function buildBuckets(array $references, array $centroids): array
// {
//     $buckets = [];

//     foreach ($centroids as $index => $_ ){
//         $buckets[$index] = [];
//     }

//     foreach ($references as $reference) {
//         $vector = $reference['vector'];
//         $nearest = findNearestCentroid($vector, $centroids);
//         $buckets[$nearest][] = $reference;
//     }

//     return $buckets;
// }
$time_start = microtime(true);

echo "Treinando centróides..." . PHP_EOL;

$centroids = trainCentroidsFromFile(__DIR__ . '/../resources/references.json', CLUSTER_QTY, ITERATIONS);

saveCentroids(__DIR__ . '/../resources/centroids.json', $centroids);

echo "Centroids salvos em " . __DIR__ . '/../resources/centroids.json' . PHP_EOL;

echo "Construindo buckets..." . PHP_EOL;

buildBucketsFromFile(
    __DIR__ . '/../resources/references.json',
    $centroids,
    __DIR__ . '/../resources/buckets',
    __DIR__ . '/../resources/buckets_index.json'
);

echo "Buckets construídos e salvos em " . __DIR__ . '/../resources/buckets' . PHP_EOL;
echo "Índice dos buckets salvo em " . __DIR__ . '/../resources/buckets_index.json' . PHP_EOL;
echo "Processo concluído!" . PHP_EOL;

$time_end = microtime(true);
$execution_time = $time_end - $time_start;

if ($execution_time > 120) {
    $execution_time = round($execution_time / 60, 2) . " minutos";
} else {
    $execution_time = round($execution_time, 2) . " segundos";
}

echo "Tempo de execução: " . $execution_time . PHP_EOL;
