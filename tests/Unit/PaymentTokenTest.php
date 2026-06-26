<?php

declare(strict_types=1);

use justinholtweb\stub\helpers\BookingHelper;

/**
 * The payment token gates the anonymous create-intent endpoint: only the client that
 * just created a booking can trigger its Stripe PaymentIntent. These tests pin the
 * signing contract and the constant-time verification independently of Craft.
 */

const SECRET = 'security-key:stub:payment';

it('produces a deterministic sha256 hex token', function () {
    $a = BookingHelper::signPayment(42, 'STB-20260626-AB12', SECRET);
    $b = BookingHelper::signPayment(42, 'STB-20260626-AB12', SECRET);

    expect($a)->toBe($b)
        ->and($a)->toMatch('/^[0-9a-f]{64}$/');
});

it('binds the token to the booking id', function () {
    expect(BookingHelper::signPayment(42, 'STB-20260626-AB12', SECRET))
        ->not->toBe(BookingHelper::signPayment(43, 'STB-20260626-AB12', SECRET));
});

it('binds the token to the reference number', function () {
    expect(BookingHelper::signPayment(42, 'STB-20260626-AB12', SECRET))
        ->not->toBe(BookingHelper::signPayment(42, 'STB-20260626-ZZ99', SECRET));
});

it('binds the token to the secret', function () {
    expect(BookingHelper::signPayment(42, 'STB-20260626-AB12', SECRET))
        ->not->toBe(BookingHelper::signPayment(42, 'STB-20260626-AB12', 'different-secret'));
});

it('accepts a token it signed', function () {
    $token = BookingHelper::signPayment(42, 'STB-20260626-AB12', SECRET);

    expect(BookingHelper::checkPayment($token, 42, 'STB-20260626-AB12', SECRET))->toBeTrue();
});

it('rejects a tampered token', function () {
    $token = BookingHelper::signPayment(42, 'STB-20260626-AB12', SECRET);

    expect(BookingHelper::checkPayment($token, 99, 'STB-20260626-AB12', SECRET))->toBeFalse();
});

it('rejects a token signed with another secret', function () {
    $token = BookingHelper::signPayment(42, 'STB-20260626-AB12', 'leaked-guess');

    expect(BookingHelper::checkPayment($token, 42, 'STB-20260626-AB12', SECRET))->toBeFalse();
});

it('rejects an empty token', function () {
    expect(BookingHelper::checkPayment('', 42, 'STB-20260626-AB12', SECRET))->toBeFalse();
});

it('rejects verification when the reference number is empty', function () {
    $token = BookingHelper::signPayment(42, '', SECRET);

    expect(BookingHelper::checkPayment($token, 42, '', SECRET))->toBeFalse();
});
