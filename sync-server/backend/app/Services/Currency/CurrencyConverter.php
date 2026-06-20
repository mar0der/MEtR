<?php

declare(strict_types=1);

namespace App\Services\Currency;

final class CurrencyConverter
{
    public const AED_PER_USD = 3.6725;

    public static function aedToUsd(float $amount): float
    {
        return $amount / self::AED_PER_USD;
    }

    public static function usdToAed(float $amount): float
    {
        return $amount * self::AED_PER_USD;
    }

    public static function convert(float $amount, string $from, string $to): float
    {
        $from = strtoupper($from);
        $to = strtoupper($to);

        if ($from === $to) {
            return $amount;
        }

        if ($from === 'AED' && $to === 'USD') {
            return self::aedToUsd($amount);
        }

        if ($from === 'USD' && $to === 'AED') {
            return self::usdToAed($amount);
        }

        throw new \InvalidArgumentException("Unsupported currency pair: {$from} -> {$to}");
    }
}
