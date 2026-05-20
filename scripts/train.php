<?php

use JsonMachine\Items;
use JsonMachine\JsonDecoder\ExtJsonDecoder;

require __DIR__ . '/../vendor/autoload.php';

ini_set('memory_limit', '150M');

const VECTOR_DIMENSIONS = 14;
const CLUSTER_QTY = 1024;
const MAX_OPEN_BUCKET_FILES = 256;
const ITERATIONS = 1;
const BATCH_SIZE = false;

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
    $content = "<?php\n\nreturn " . var_export($centroids, true) . ";\n";
    file_put_contents($path, $content);
}

function loadControids(string $path): array
{
    /** @var array $centroids */
    $centroids = require $path;
    return $centroids;
}

function clearBucketsDirectory(string $bucketsPath): void
{
    foreach (glob($bucketsPath . '/*.php') as $file) {
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
    $counts = array_fill(0, $qty, 0);
    $bucketInitialized = array_fill(0, $qty, false);
    $bucketHasItems = array_fill(0, $qty, false);

    $handles = [];
    $openOrder = [];

    $acquireHandle = function (int $bucket) use (&$handles, &$openOrder, &$bucketInitialized, $bucketsPath) {
        if (isset($handles[$bucket])) {
            $pos = array_search($bucket, $openOrder, true);
            if ($pos !== false) {
                unset($openOrder[$pos]);
                $openOrder = array_values($openOrder);
            }
            $openOrder[] = $bucket;
            return $handles[$bucket];
        }

        if (count($handles) >= MAX_OPEN_BUCKET_FILES) {
            $oldestBucket = array_shift($openOrder);
            if ($oldestBucket !== null && isset($handles[$oldestBucket])) {
                fclose($handles[$oldestBucket]);
                unset($handles[$oldestBucket]);
            }
        }

        $bucketFile = $bucketsPath . '/' . $bucket . '.php';

        if (!$bucketInitialized[$bucket]) {
            file_put_contents($bucketFile, "<?php\n\nreturn [\n");
            $bucketInitialized[$bucket] = true;
        }

        $handle = fopen($bucketFile, 'ab');
        if ($handle === false) {
            throw new RuntimeException("Falha ao abrir bucket {$bucketFile}");
        }

        $handles[$bucket] = $handle;
        $openOrder[] = $bucket;

        return $handle;
    };

    $total = 0;

    foreach (referenceIterator($referencesPath) as $key => $reference) {
        if (BATCH_SIZE && $key >= BATCH_SIZE) {
            break;
        }

        $vector = $reference['vector'];
        $nearest = findNearestCentroid($vector, $centroids);

        $entry = [
            'vector' => $reference['vector'],
            'label' => $reference['label'],
        ];

        $handle = $acquireHandle($nearest);

        if ($bucketHasItems[$nearest]) {
            fwrite($handle, ",\n");
        }

        fwrite($handle, '    ' . var_export($entry, true));
        $bucketHasItems[$nearest] = true;

        $counts[$nearest]++;
        $total++;

        if ($total % 50000 === 0) {
            echo "Processados: {$total}" . PHP_EOL;
        }
    }

    for ($bucket = 0; $bucket < $qty; $bucket++) {
        $bucketFile = $bucketsPath . '/' . $bucket . '.php';

        if (!$bucketInitialized[$bucket]) {
            file_put_contents($bucketFile, "<?php\n\nreturn [];\n");
            continue;
        }

        if (isset($handles[$bucket])) {
            fwrite($handles[$bucket], "\n];\n");
            fclose($handles[$bucket]);
            continue;
        }

        file_put_contents($bucketFile, "\n];\n", FILE_APPEND);
    }

    file_put_contents(
        $bucketsIndexPath,
        "<?php\n\nreturn " . var_export([
            'total' => $total,
            'clusters' => $qty,
            'counts' => $counts,
        ], true) . ";\n"
    );

    echo 'Total de referências processadas: ' . $total . PHP_EOL;
}


$time_start = microtime(true);

echo "Treinando centróides..." . PHP_EOL;

$centroids = trainCentroidsFromFile(__DIR__ . '/../resources/references.json', CLUSTER_QTY, ITERATIONS);

saveCentroids(__DIR__ . '/../resources/centroids.php', $centroids);

echo "Centroids salvos em " . __DIR__ . '/../resources/centroids.php' . PHP_EOL;

echo "Construindo buckets..." . PHP_EOL;

buildBucketsFromFile(
    __DIR__ . '/../resources/references.json',
    $centroids,
    __DIR__ . '/../resources/buckets',
    __DIR__ . '/../resources/buckets_index.php'
);

echo "Buckets construídos e salvos em " . __DIR__ . '/../resources/buckets' . PHP_EOL;
echo "Índice dos buckets salvo em " . __DIR__ . '/../resources/buckets_index.php' . PHP_EOL;
echo "Processo concluído!" . PHP_EOL;

$time_end = microtime(true);
$execution_time = $time_end - $time_start;

if ($execution_time > 120) {
    $execution_time = round($execution_time / 60, 2) . " minutos";
} else {
    $execution_time = round($execution_time, 2) . " segundos";
}

echo "Tempo de execução: " . $execution_time . PHP_EOL;
