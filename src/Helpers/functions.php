<?php

function limitValue(float $value): float 
{
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

function reorderArray(float $newValue, array &$data, int $currentIndex) {
    $old = $data;
    $prevIndex = $currentIndex - 1;

    if ($prevIndex >= 0 &&$newValue < $old[$prevIndex]) {
        reorderArray($newValue, $data, $prevIndex);
        return;
    }

    for ($i = $currentIndex; $i < count($data); $i++) {

        if($i > 5) {
            break;
        }

        if($i )

        $data[$i] = $old[$i - 1];
    }

    $data[$currentIndex] = $newValue;
}