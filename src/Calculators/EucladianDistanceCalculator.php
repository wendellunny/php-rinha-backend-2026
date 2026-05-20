<?php

namespace App\Calculators;

class EucladianDistanceCalculator
{
    public function calculate(array $vector1, array $vector2): float
    {
        $sum = 0.0;
        for ($i = 0; $i < count($vector1); $i++) {
            $sub = $vector1[$i] - $vector2[$i];
            $squared = $sub * $sub;
            $sum += $squared;
        }

        // return sqrt($sum);

        return $sum;
    }
}