<?php

namespace justinholtweb\stub\helpers;

use justinholtweb\stub\Plugin;

class BookingHelper
{
    public static function generateReferenceNumber(): string
    {
        $prefix = Plugin::getInstance()->getSettings()->referencePrefix;
        $date = date('Ymd');
        $random = strtoupper(substr(bin2hex(random_bytes(2)), 0, 4));

        return "{$prefix}-{$date}-{$random}";
    }

    public static function formatPrice(float $price, string $currency): string
    {
        $symbols = [
            'USD' => '$',
            'EUR' => '€',
            'GBP' => '£',
            'CAD' => 'CA$',
            'AUD' => 'A$',
        ];

        $symbol = $symbols[$currency] ?? $currency . ' ';
        return $symbol . number_format($price, 2);
    }

    public static function formatDuration(int $minutes): string
    {
        if ($minutes < 60) {
            return "{$minutes} min";
        }

        $hours = floor($minutes / 60);
        $mins = $minutes % 60;

        if ($mins === 0) {
            return "{$hours}h";
        }

        return "{$hours}h {$mins}m";
    }
}
