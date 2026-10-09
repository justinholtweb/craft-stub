<?php

declare(strict_types=1);

use justinholtweb\stub\enums\PaymentMode;
use justinholtweb\stub\enums\PaymentStatus;
use justinholtweb\stub\helpers\PaymentHelper;

/**
 * The deposit and part-payment arithmetic. Every amount here is something a customer is
 * charged or told they owe, so each rounding rule is pinned.
 */

it('takes a percentage deposit, rounded to the minor unit', function() {
    expect(PaymentHelper::depositAmount(100.0, 'percent', 25, 'USD'))->toBe(25.0)
        ->and(PaymentHelper::depositAmount(100.0, 'percent', 33.333, 'USD'))->toBe(33.33)
        ->and(PaymentHelper::depositAmount(35.0, 'percent', 50, 'USD'))->toBe(17.5);
});

it('rounds a zero-decimal currency deposit to whole units', function() {
    expect(PaymentHelper::depositAmount(5000.0, 'percent', 33.3, 'JPY'))->toBe(1665.0);
});

it('takes a fixed deposit, but never more than the price', function() {
    expect(PaymentHelper::depositAmount(120.0, 'fixed', 20, 'USD'))->toBe(20.0)
        ->and(PaymentHelper::depositAmount(15.0, 'fixed', 20, 'USD'))->toBe(15.0);
});

it('asks no deposit on a free price or a zero deposit', function() {
    expect(PaymentHelper::depositAmount(0.0, 'percent', 50, 'USD'))->toBe(0.0)
        ->and(PaymentHelper::depositAmount(100.0, 'fixed', 0, 'USD'))->toBe(0.0);
});

it('works out the balance and never lets it go negative', function() {
    expect(PaymentHelper::balance(100.0, 25.0, 'USD'))->toBe(75.0)
        ->and(PaymentHelper::balance(100.0, 120.0, 'USD'))->toBe(0.0)
        ->and(PaymentHelper::balance(0.3, 0.1 + 0.2, 'USD'))->toBe(0.0);
});

it('derives the payment status from what has been received', function() {
    expect(PaymentHelper::statusFor(100.0, 0.0, 'USD'))->toBe(PaymentStatus::Unpaid)
        ->and(PaymentHelper::statusFor(100.0, 25.0, 'USD'))->toBe(PaymentStatus::PartiallyPaid)
        ->and(PaymentHelper::statusFor(100.0, 100.0, 'USD'))->toBe(PaymentStatus::Paid)
        ->and(PaymentHelper::statusFor(0.0, 0.0, 'USD'))->toBe(PaymentStatus::Paid);
});

it('treats float noise in a sum of part payments as paid', function() {
    // 33.33 + 33.33 + 33.34 is not exactly 100.0 in binary floating point.
    expect(PaymentHelper::statusFor(100.0, 33.33 + 33.33 + 33.34, 'USD'))->toBe(PaymentStatus::Paid);
});

it('charges online what each payment mode asks for', function() {
    expect(PaymentHelper::amountDueOnline(PaymentMode::Full, 100.0, 0.0, 0.0, 'USD'))->toBe(100.0)
        ->and(PaymentHelper::amountDueOnline(PaymentMode::Deposit, 100.0, 0.0, 25.0, 'USD'))->toBe(25.0)
        ->and(PaymentHelper::amountDueOnline(PaymentMode::InPerson, 100.0, 0.0, 0.0, 'USD'))->toBe(0.0);
});

it('charges a deposit once, and nothing online for its balance', function() {
    expect(PaymentHelper::amountDueOnline(PaymentMode::Deposit, 100.0, 25.0, 25.0, 'USD'))->toBe(0.0);
});

it('charges only the outstanding balance in full-payment mode', function() {
    expect(PaymentHelper::amountDueOnline(PaymentMode::Full, 100.0, 40.0, 0.0, 'USD'))->toBe(60.0)
        ->and(PaymentHelper::amountDueOnline(PaymentMode::Full, 100.0, 100.0, 0.0, 'USD'))->toBe(0.0);
});
