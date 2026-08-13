<?php

declare(strict_types=1);

use justinholtweb\stub\services\Currencies;

/**
 * The site-dependent half of Currencies (the Commerce lookup, the picker list) needs a
 * booted app and belongs in the Feature suite. What's covered here is the pure half:
 * the minor-unit arithmetic that decides what Stripe actually charges, and the display
 * formatting. Both are pinned to an explicit locale so ICU's locale defaults can't
 * quietly change an assertion.
 */

it('treats most currencies as two-decimal', function() {
    expect(Currencies::minorUnitDigits('USD'))->toBe(2)
        ->and(Currencies::minorUnitDigits('EUR'))->toBe(2)
        ->and(Currencies::minorUnitDigits('CHF'))->toBe(2);
});

it('treats Stripe zero-decimal currencies as having no minor unit', function() {
    expect(Currencies::minorUnitDigits('JPY'))->toBe(0)
        ->and(Currencies::minorUnitDigits('KRW'))->toBe(0)
        ->and(Currencies::minorUnitDigits('VND'))->toBe(0)
        ->and(Currencies::minorUnitDigits('XOF'))->toBe(0);
});

it('treats Stripe three-decimal currencies as thousandths', function() {
    expect(Currencies::minorUnitDigits('KWD'))->toBe(3)
        ->and(Currencies::minorUnitDigits('BHD'))->toBe(3)
        ->and(Currencies::minorUnitDigits('TND'))->toBe(3);
});

it('follows Stripe rather than ICU where the two disagree', function() {
    // ICU reports ISK as zero-decimal; Stripe bills it in aurar. Following ICU here
    // would undercharge every Icelandic booking by 100x.
    expect(Currencies::minorUnitDigits('ISK'))->toBe(2);
});

it('is case-insensitive about currency codes', function() {
    expect(Currencies::minorUnitDigits('jpy'))->toBe(0)
        ->and(Currencies::minorUnitDigits('Kwd'))->toBe(3);
});

it('converts two-decimal prices to cents', function() {
    expect(Currencies::toMinorUnits(10.0, 'USD'))->toBe(1000)
        ->and(Currencies::toMinorUnits(49.99, 'EUR'))->toBe(4999)
        ->and(Currencies::toMinorUnits(0.05, 'CHF'))->toBe(5);
});

it('charges zero-decimal currencies in whole units', function() {
    // The bug this guards: 1000 * 100 would have billed ¥100,000 for a ¥1,000 booking.
    expect(Currencies::toMinorUnits(1000.0, 'JPY'))->toBe(1000)
        ->and(Currencies::toMinorUnits(50000.0, 'KRW'))->toBe(50000);
});

it('rounds three-decimal currencies to the nearest ten minor units', function() {
    // Stripe rejects three-decimal amounts that aren't a multiple of 10.
    expect(Currencies::toMinorUnits(1.234, 'KWD'))->toBe(1230)
        ->and(Currencies::toMinorUnits(1.236, 'KWD'))->toBe(1240)
        ->and(Currencies::toMinorUnits(10.0, 'BHD'))->toBe(10000);
});

it('avoids binary float rounding errors in the minor amount', function() {
    // 1.15 * 100 is 114.99999... in binary floating point; truncating would undercharge.
    expect(Currencies::toMinorUnits(1.15, 'USD'))->toBe(115)
        ->and(Currencies::toMinorUnits(8.20, 'USD'))->toBe(820)
        ->and(Currencies::toMinorUnits(1234.56, 'USD'))->toBe(123456);
});

it('formats currencies with their own symbol and placement', function() {
    expect(Currencies::format(10.0, 'USD', 'en-US'))->toBe('$10.00')
        ->and(Currencies::format(10.0, 'GBP', 'en-US'))->toBe('£10.00')
        ->and(Currencies::format(1234.5, 'USD', 'en-US'))->toBe('$1,234.50');
});

it('formats CHF, the currency that prompted all this', function() {
    // ICU separates a letter-code symbol from the amount with a non-breaking space.
    expect(Currencies::format(10.0, 'CHF', 'en-US'))->toBe("CHF\u{A0}10.00");
});

it('omits decimals for zero-decimal currencies', function() {
    expect(Currencies::format(1000.0, 'JPY', 'en-US'))->toBe('¥1,000');
});

it('respects the locale when placing the symbol', function() {
    // The old symbol map hardcoded symbol-then-amount, which is wrong for most of Europe.
    expect(Currencies::format(10.0, 'EUR', 'de-DE'))->toBe("10,00\u{A0}€");
});

it('falls back to a bare number for malformed currency codes', function() {
    expect(Currencies::format(10.0, 'NOTACODE', 'en-US'))->toBe('10.00')
        ->and(Currencies::format(10.0, '', 'en-US'))->toBe('10.00');
});

it('accepts well-formed ISO codes and rejects the rest', function() {
    expect(Currencies::isValidCode('CHF'))->toBeTrue()
        ->and(Currencies::isValidCode('chf'))->toBeFalse()
        ->and(Currencies::isValidCode('CHFX'))->toBeFalse()
        ->and(Currencies::isValidCode('CH'))->toBeFalse();
});

it('offers CHF and the other common currencies in the built-in list', function() {
    $common = Currencies::commonCurrencies();

    expect($common)->toHaveKey('CHF')
        ->and($common)->toHaveKey('USD')
        ->and($common)->toHaveKey('JPY')
        ->and($common['CHF'])->toBe('Swiss Franc');
});

it('lists only well-formed codes, so every entry can be charged and formatted', function() {
    foreach (array_keys(Currencies::commonCurrencies()) as $code) {
        expect(Currencies::isValidCode($code))->toBeTrue();
    }
});
