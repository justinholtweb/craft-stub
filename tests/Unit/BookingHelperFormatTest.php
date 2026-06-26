<?php

declare(strict_types=1);

use justinholtweb\stub\helpers\BookingHelper;

it('formats known currencies with their symbol', function () {
    expect(BookingHelper::formatPrice(10.0, 'USD'))->toBe('$10.00')
        ->and(BookingHelper::formatPrice(10.0, 'EUR'))->toBe('€10.00')
        ->and(BookingHelper::formatPrice(10.0, 'GBP'))->toBe('£10.00')
        ->and(BookingHelper::formatPrice(10.0, 'CAD'))->toBe('CA$10.00')
        ->and(BookingHelper::formatPrice(10.0, 'AUD'))->toBe('A$10.00');
});

it('falls back to the currency code for unknown currencies', function () {
    expect(BookingHelper::formatPrice(10.0, 'JPY'))->toBe('JPY 10.00');
});

it('always formats to two decimal places with thousands separators', function () {
    expect(BookingHelper::formatPrice(1234.5, 'USD'))->toBe('$1,234.50')
        ->and(BookingHelper::formatPrice(0.0, 'USD'))->toBe('$0.00');
});

it('formats sub-hour durations in minutes', function () {
    expect(BookingHelper::formatDuration(30))->toBe('30 min')
        ->and(BookingHelper::formatDuration(59))->toBe('59 min');
});

it('formats whole-hour durations without minutes', function () {
    expect(BookingHelper::formatDuration(60))->toBe('1h')
        ->and(BookingHelper::formatDuration(120))->toBe('2h');
});

it('formats mixed durations as hours and minutes', function () {
    expect(BookingHelper::formatDuration(90))->toBe('1h 30m')
        ->and(BookingHelper::formatDuration(125))->toBe('2h 5m');
});
