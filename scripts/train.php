<?php

use JsonMachine\Items;
use JsonMachine\JsonDecoder\ExtJsonDecoder;

require __DIR__ . '/../vendor/autoload.php';

ini_set('memory_limit', '150M');

const VECTOR_DIMENSIONS = 14;
const PRIMARY_CLUSTERS = 144;
const SECONDARY_CLUSTERS = 144;
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

function clearDirectory(string $path): void
{
    if (!is_dir($path)) {
        mkdir($path, 0755, true);
        return;
    }

    $items = scandir($path);
    if ($items === false) {
        throw new RuntimeException("Falha ao listar diretório {$path}");
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $itemPath = $path . '/' . $item;

        if (is_dir($itemPath)) {
            clearDirectory($itemPath);
            rmdir($itemPath);
            continue;
        }

        unlink($itemPath);
    }
}

function partitionReferencesByPrimaryCluster(
    string $referencesPath,
    array $primaryCentroids,
    string $partitionsPath
): array {
    clearDirectory($partitionsPath);

    $primaryQty = count($primaryCentroids);
    $counts = array_fill(0, $primaryQty, 0);
    $handles = [];
    $openOrder = [];

    $acquireHandle = function (int $primaryCluster) use (&$handles, &$openOrder, $partitionsPath) {
        if (isset($handles[$primaryCluster])) {
            $pos = array_search($primaryCluster, $openOrder, true);
            if ($pos !== false) {
                unset($openOrder[$pos]);
                $openOrder = array_values($openOrder);
            }
            $openOrder[] = $primaryCluster;
            return $handles[$primaryCluster];
        }

        if (count($handles) >= MAX_OPEN_BUCKET_FILES) {
            $oldestCluster = array_shift($openOrder);
            if ($oldestCluster !== null && isset($handles[$oldestCluster])) {
                fclose($handles[$oldestCluster]);
                unset($handles[$oldestCluster]);
            }
        }

        $partitionFile = $partitionsPath . '/' . $primaryCluster . '.jsonl';
        $handle = fopen($partitionFile, 'ab');
        if ($handle === false) {
            throw new RuntimeException("Falha ao abrir arquivo de partição {$partitionFile}");
        }

        $handles[$primaryCluster] = $handle;
        $openOrder[] = $primaryCluster;

        return $handle;
    };

    $total = 0;
    foreach (referenceIterator($referencesPath) as $key => $reference) {
        if (BATCH_SIZE && $key >= BATCH_SIZE) {
            break;
        }

        $primaryCluster = findNearestCentroid($reference['vector'], $primaryCentroids);
        $handle = $acquireHandle($primaryCluster);

        fwrite($handle, json_encode($reference, JSON_UNESCAPED_SLASHES) . "\n");

        $counts[$primaryCluster]++;
        $total++;

        if ($total % 100000 === 0) {
            echo "  Particionados: {$total}" . PHP_EOL;
        }
    }

    foreach ($handles as $handle) {
        fclose($handle);
    }

    for ($cluster = 0; $cluster < $primaryQty; $cluster++) {
        $partitionFile = $partitionsPath . '/' . $cluster . '.jsonl';
        if (!file_exists($partitionFile)) {
            file_put_contents($partitionFile, '');
        }
    }

    return $counts;
}

function partitionReferenceIterator(string $partitionFile): iterable
{
    $handle = fopen($partitionFile, 'rb');
    if ($handle === false) {
        return;
    }

    try {
        while (($line = fgets($handle)) !== false) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $decoded = json_decode($line, true);
            if (!is_array($decoded) || !isset($decoded['vector'])) {
                continue;
            }

            yield $decoded;
        }
    } finally {
        fclose($handle);
    }
}

function trainSecondaryCentroidsFromPartitionFile(
    string $partitionFile,
    int $qty,
    int $iterations
): array {
    $secondaryCentroids = [];

    foreach (partitionReferenceIterator($partitionFile) as $reference) {
        $secondaryCentroids[] = $reference['vector'];

        if (count($secondaryCentroids) >= $qty) {
            break;
        }
    }

    if (empty($secondaryCentroids)) {
        for ($i = 0; $i < $qty; $i++) {
            $secondaryCentroids[] = array_fill(0, VECTOR_DIMENSIONS, 0.0);
        }
        return $secondaryCentroids;
    }

    while (count($secondaryCentroids) < $qty) {
        $secondaryCentroids[] = $secondaryCentroids[0];
    }

    for ($iteration = 0; $iteration < $iterations; $iteration++) {
        $sums = [];
        $counts = array_fill(0, $qty, 0);

        for ($cluster = 0; $cluster < $qty; $cluster++) {
            $sums[$cluster] = array_fill(0, VECTOR_DIMENSIONS, 0.0);
        }

        foreach (partitionReferenceIterator($partitionFile) as $reference) {
            $vector = $reference['vector'];
            $nearest = findNearestCentroid($vector, $secondaryCentroids);
            $counts[$nearest]++;

            for ($dimension = 0; $dimension < VECTOR_DIMENSIONS; $dimension++) {
                $sums[$nearest][$dimension] += $vector[$dimension];
            }
        }

        for ($cluster = 0; $cluster < $qty; $cluster++) {
            if ($counts[$cluster] === 0) {
                continue;
            }

            for ($dimension = 0; $dimension < VECTOR_DIMENSIONS; $dimension++) {
                $secondaryCentroids[$cluster][$dimension] =
                    $sums[$cluster][$dimension] / $counts[$cluster];
            }
        }
    }

    return $secondaryCentroids;
}

function saveSecondaryCentroids(string $path, int $cluster, array $centroids): void
{
    $content = "<?php\n\nreturn " . var_export($centroids, true) . ";\n";
    file_put_contents($path . '/' . $cluster . '.php', $content);
}

function saveCentroids(string $path, array $centroids): void
{
    $content = "<?php\n\nreturn " . var_export($centroids, true) . ";\n";
    file_put_contents($path, $content);
}

function loadSecondaryCentroids(string $path, int $cluster): array
{
    $file = $path . '/' . $cluster . '.php';
    /** @var array $centroids */
    $centroids = require $file;
    return $centroids;
}

function buildHierarchicalBuckets(
    string $referencesPath,
    array $primaryCentroids,
    string $secondaryCentroidsPath,
    string $bucketsPath
): void {
    $primaryQty = count($primaryCentroids);
    $secondaryQty = SECONDARY_CLUSTERS;

    clearDirectory($bucketsPath);

    if (!is_dir($bucketsPath)) {
        mkdir($bucketsPath, 0755, true);
    }

    for ($i = 0; $i < $primaryQty; $i++) {
        $clusterDir = $bucketsPath . '/' . $i;
        if (!is_dir($clusterDir)) {
            mkdir($clusterDir, 0755, true);
        }
    }

    $primaryCounts = array_fill(0, $primaryQty, 0);
    $secondaryCounts = [];
    for ($i = 0; $i < $primaryQty; $i++) {
        $secondaryCounts[$i] = array_fill(0, $secondaryQty, 0);
    }

    $secondaryCentroidsByPrimary = [];
    for ($cluster = 0; $cluster < $primaryQty; $cluster++) {
        $secondaryCentroidsByPrimary[$cluster] = loadSecondaryCentroids($secondaryCentroidsPath, $cluster);
    }

    $handles = [];
    $openOrder = [];
    $total = 0;

    $acquireHandle = function (int $primaryCluster, int $secondaryCluster) use (
        &$handles,
        &$openOrder,
        $bucketsPath
    ) {
        $key = $primaryCluster . '_' . $secondaryCluster;

        if (isset($handles[$key])) {
            $pos = array_search($key, $openOrder, true);
            if ($pos !== false) {
                unset($openOrder[$pos]);
                $openOrder = array_values($openOrder);
            }
            $openOrder[] = $key;
            return $handles[$key];
        }

        if (count($handles) >= MAX_OPEN_BUCKET_FILES) {
            $oldestKey = array_shift($openOrder);
            if ($oldestKey !== null && isset($handles[$oldestKey])) {
                fclose($handles[$oldestKey]);
                unset($handles[$oldestKey]);
            }
        }

        $bucketFile = $bucketsPath . '/' . $primaryCluster . '/' . $secondaryCluster . '.php';

        if (!file_exists($bucketFile)) {
            file_put_contents($bucketFile, "<?php\n\nreturn [\n");
        }

        $handle = fopen($bucketFile, 'ab');
        if ($handle === false) {
            throw new RuntimeException("Falha ao abrir bucket {$bucketFile}");
        }

        $handles[$key] = $handle;
        $openOrder[] = $key;

        return $handle;
    };

    foreach (referenceIterator($referencesPath) as $key => $reference) {
        if (BATCH_SIZE && $key >= BATCH_SIZE) {
            break;
        }

        $vector = $reference['vector'];
        $primaryCluster = findNearestCentroid($vector, $primaryCentroids);
        $secondaryCentroids = $secondaryCentroidsByPrimary[$primaryCluster];
        $secondaryCluster = findNearestCentroid($vector, $secondaryCentroids);

        $entry = [
            'vector' => $reference['vector'],
            'label' => $reference['label'],
        ];

        $handle = $acquireHandle($primaryCluster, $secondaryCluster);

        if ($secondaryCounts[$primaryCluster][$secondaryCluster] > 0) {
            fwrite($handle, ",\n");
        }

        fwrite($handle, '    ' . var_export($entry, true));
        $secondaryCounts[$primaryCluster][$secondaryCluster]++;
        $primaryCounts[$primaryCluster]++;
        $total++;

        if ($total % 50000 === 0) {
            echo "Processados: {$total}" . PHP_EOL;
        }
    }

    foreach ($handles as $handle) {
        fwrite($handle, "\n];\n");
        fclose($handle);
    }

    for ($primary = 0; $primary < $primaryQty; $primary++) {
        for ($secondary = 0; $secondary < $secondaryQty; $secondary++) {
            $bucketFile = $bucketsPath . '/' . $primary . '/' . $secondary . '.php';

            if (!file_exists($bucketFile)) {
                file_put_contents($bucketFile, "<?php\n\nreturn [];\n");
            } elseif (!isset($handles[$primary . '_' . $secondary])) {
                if (filesize($bucketFile) > 0) {
                    file_put_contents($bucketFile, "\n];\n", FILE_APPEND);
                }
            }
        }
    }

    echo 'Total de referências processadas: ' . $total . PHP_EOL;
}


$time_start = microtime(true);

echo "=== TREINO HIERÁRQUICO DE CLUSTERS ===" . PHP_EOL;
echo "Nível 1: Treinando " . PRIMARY_CLUSTERS . " centróides primários..." . PHP_EOL;

$primaryCentroids = trainCentroidsFromFile(
    __DIR__ . '/../resources/references.json',
    PRIMARY_CLUSTERS,
    ITERATIONS
);

saveCentroids(__DIR__ . '/../resources/centroids.php', $primaryCentroids);
echo "Centróides primários salvos!" . PHP_EOL;

$secondaryCentroidsPath = __DIR__ . '/../resources/bucket_centroids';
if (!is_dir($secondaryCentroidsPath)) {
    mkdir($secondaryCentroidsPath, 0755, true);
}
clearDirectory($secondaryCentroidsPath);

echo "Nível 2: Treinando " . SECONDARY_CLUSTERS . " centróides secundários para cada cluster primário..." . PHP_EOL;

$partitionsPath = __DIR__ . '/../resources/tmp_primary_partitions';
echo "Particionando referências por cluster primário (passada única)..." . PHP_EOL;
$primaryCounts = partitionReferencesByPrimaryCluster(
    __DIR__ . '/../resources/references.json',
    $primaryCentroids,
    $partitionsPath
);

for ($cluster = 0; $cluster < PRIMARY_CLUSTERS; $cluster++) {
    echo "  Treinando centróides para cluster primário {$cluster} ({$primaryCounts[$cluster]} itens)..." . PHP_EOL;

    $secondaryCentroids = trainSecondaryCentroidsFromPartitionFile(
        $partitionsPath . '/' . $cluster . '.jsonl',
        SECONDARY_CLUSTERS,
        ITERATIONS
    );

    saveSecondaryCentroids(
        $secondaryCentroidsPath,
        $cluster,
        $secondaryCentroids
    );
}

clearDirectory($partitionsPath);
rmdir($partitionsPath);

echo "Centróides secundários salvos em " . $secondaryCentroidsPath . PHP_EOL;

echo "Nível 3: Construindo estrutura hierárquica de buckets..." . PHP_EOL;

buildHierarchicalBuckets(
    __DIR__ . '/../resources/references.json',
    $primaryCentroids,
    $secondaryCentroidsPath,
    __DIR__ . '/../resources/buckets'
);

echo "Buckets construídos em " . __DIR__ . '/../resources/buckets' . PHP_EOL;
echo "Processo concluído!" . PHP_EOL;

$time_end = microtime(true);
$execution_time = $time_end - $time_start;

if ($execution_time > 120) {
    $execution_time = round($execution_time / 60, 2) . " minutos";
} else {
    $execution_time = round($execution_time, 2) . " segundos";
}

echo "Tempo de execução: " . $execution_time . PHP_EOL;
