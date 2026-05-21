<?php

namespace App\Calculators;

class EucladianDistanceCalculator
{
    public function calculate(array $vector1, array $vector2): float
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


        // $sum = 0.0;
        // for ($i = 0; $i < VECTOR_DIMENSIONS; $i++) {
        //     $sub = $vector1[$i] - $vector2[$i];
        //     $squared = $sub * $sub;
        //     $sum += $squared;
        // }

        // // return sqrt($sum);

        // return $sum;
        
    }

    public function calculateWithLimit(array $vector1, array $vector2, float $limit)
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
}