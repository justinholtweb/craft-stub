<?php

declare(strict_types=1);

use justinholtweb\stub\helpers\RateLimitHelper;

/**
 * The cache/IP plumbing lives in check(); shouldAllow() holds the branch logic that
 * decides allow vs deny, which is what these tests exercise.
 */

it('allows a request below the limit', function() {
    expect(RateLimitHelper::shouldAllow('203.0.113.7', 4, 10))->toBeTrue();
});

it('denies a request once the count reaches the limit', function() {
    expect(RateLimitHelper::shouldAllow('203.0.113.7', 10, 10))->toBeFalse();
});

it('denies a request beyond the limit', function() {
    expect(RateLimitHelper::shouldAllow('203.0.113.7', 11, 10))->toBeFalse();
});

it('bypasses the limit when the IP is unknown', function() {
    expect(RateLimitHelper::shouldAllow(null, 999, 10))->toBeTrue()
        ->and(RateLimitHelper::shouldAllow('', 999, 10))->toBeTrue();
});

it('treats the first request as allowed', function() {
    expect(RateLimitHelper::shouldAllow('203.0.113.7', 0, 10))->toBeTrue();
});
