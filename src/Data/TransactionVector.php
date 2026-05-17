<?php

namespace App\Data;

class TransactionVector
{
    public function __construct(
        private readonly float $amount,
        private readonly float $installments,
        private readonly float $amount_vs_avg,
        private readonly float $hour_of_day,
        private readonly float $day_of_week,
        private readonly float $minutes_since_last_tx,
        private readonly float $km_from_last_tx,
        private readonly float $km_from_home,
        private readonly float $tx_count_24h,
        private readonly bool $is_online,
        private readonly bool $card_present,
        private readonly bool $unknown_merchant,
        private readonly float $mcc_risk, 
        private readonly float $merchant_avg_amount
    ){}

    public function getVector(): array
    {
        return [
            $this->amount,
            $this->installments,
            $this->amount_vs_avg,
            $this->hour_of_day,
            $this->day_of_week,
            $this->minutes_since_last_tx,
            $this->km_from_last_tx,
            $this->km_from_home,
            $this->tx_count_24h,
            $this->is_online ? 1 : 0,
            $this->card_present ? 1 : 0,
            $this->unknown_merchant ? 1 : 0,
            $this->mcc_risk,
            $this->merchant_avg_amount
        ];
    }
}