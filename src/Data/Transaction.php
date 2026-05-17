<?php

namespace App\Data;

class Transaction
{
    public function __construct(
        private readonly int $amount,
        private readonly int $installments,
        private readonly int $amount_vs_avg,
        private readonly int $hour_of_day,
        private readonly int $day_of_week,
        private readonly int $minutes_since_last_tx,
        private readonly int $km_from_last_tx,
        private readonly int $km_from_home,
        private readonly int $tx_count_24h,
        private readonly bool $is_online,
        private readonly bool $card_present,
        private readonly bool $unknow_merchant,
        private readonly float $mcc_risk, 
        private readonly int $merchant_avg_amount
    )
}