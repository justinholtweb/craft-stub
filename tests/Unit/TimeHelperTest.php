<?php

declare(strict_types=1);

use justinholtweb\stub\helpers\TimeHelper;

/**
 * Availability math hinges on correct UTC conversion and overlap detection. Dates are
 * pinned to January to keep the offsets DST-free (America/New_York = UTC-5).
 */

it('converts a local time to UTC', function() {
    $utc = TimeHelper::convertToUtc('2026-01-15 12:00:00', 'America/New_York');

    expect($utc->format('Y-m-d H:i:s'))->toBe('2026-01-15 17:00:00')
        ->and($utc->getTimezone()->getName())->toBe('UTC');
});

it('converts a UTC time back to a local zone', function() {
    $local = TimeHelper::convertFromUtc('2026-01-15 17:00:00', 'America/New_York');

    expect($local->format('Y-m-d H:i:s'))->toBe('2026-01-15 12:00:00');
});

it('converts directly between two zones', function() {
    $la = TimeHelper::convertTimezone('2026-01-15 12:00:00', 'America/New_York', 'America/Los_Angeles');

    expect($la->format('Y-m-d H:i:s'))->toBe('2026-01-15 09:00:00');
});

it('detects overlapping ranges', function() {
    $start1 = new DateTime('2026-01-15 09:00:00', new DateTimeZone('UTC'));
    $end1 = new DateTime('2026-01-15 10:00:00', new DateTimeZone('UTC'));
    $start2 = new DateTime('2026-01-15 09:30:00', new DateTimeZone('UTC'));
    $end2 = new DateTime('2026-01-15 10:30:00', new DateTimeZone('UTC'));

    expect(TimeHelper::dateRangeOverlaps($start1, $end1, $start2, $end2))->toBeTrue();
});

it('treats touching ranges as non-overlapping', function() {
    $start1 = new DateTime('2026-01-15 09:00:00', new DateTimeZone('UTC'));
    $end1 = new DateTime('2026-01-15 10:00:00', new DateTimeZone('UTC'));
    $start2 = new DateTime('2026-01-15 10:00:00', new DateTimeZone('UTC'));
    $end2 = new DateTime('2026-01-15 11:00:00', new DateTimeZone('UTC'));

    expect(TimeHelper::dateRangeOverlaps($start1, $end1, $start2, $end2))->toBeFalse();
});

it('treats disjoint ranges as non-overlapping', function() {
    $start1 = new DateTime('2026-01-15 09:00:00', new DateTimeZone('UTC'));
    $end1 = new DateTime('2026-01-15 10:00:00', new DateTimeZone('UTC'));
    $start2 = new DateTime('2026-01-15 14:00:00', new DateTimeZone('UTC'));
    $end2 = new DateTime('2026-01-15 15:00:00', new DateTimeZone('UTC'));

    expect(TimeHelper::dateRangeOverlaps($start1, $end1, $start2, $end2))->toBeFalse();
});

it('detects overlap regardless of argument order', function() {
    $start1 = new DateTime('2026-01-15 09:30:00', new DateTimeZone('UTC'));
    $end1 = new DateTime('2026-01-15 10:30:00', new DateTimeZone('UTC'));
    $start2 = new DateTime('2026-01-15 09:00:00', new DateTimeZone('UTC'));
    $end2 = new DateTime('2026-01-15 10:00:00', new DateTimeZone('UTC'));

    expect(TimeHelper::dateRangeOverlaps($start1, $end1, $start2, $end2))->toBeTrue()
        ->and(TimeHelper::dateRangeOverlaps($start2, $end2, $start1, $end1))->toBeTrue();
});

it('builds a datetime from a separate time and date in a given zone', function() {
    $dt = TimeHelper::timeToDateTime('09:30:00', '2026-01-15', 'America/New_York');

    expect($dt->format('Y-m-d H:i:s'))->toBe('2026-01-15 09:30:00')
        ->and($dt->getTimezone()->getName())->toBe('America/New_York');
});

it('formats a UTC datetime for display in a target zone', function() {
    $dt = new DateTime('2026-01-15 17:00:00', new DateTimeZone('UTC'));

    expect(TimeHelper::formatForDisplay($dt, 'America/New_York'))->toBe('12:00 PM');
});

it('does not mutate the datetime it formats for display', function() {
    $dt = new DateTime('2026-01-15 17:00:00', new DateTimeZone('UTC'));
    TimeHelper::formatForDisplay($dt, 'America/Los_Angeles');

    expect($dt->getTimezone()->getName())->toBe('UTC')
        ->and($dt->format('H:i:s'))->toBe('17:00:00');
});

it('honors a custom display format', function() {
    $dt = new DateTime('2026-01-15 17:00:00', new DateTimeZone('UTC'));

    expect(TimeHelper::formatForDisplay($dt, 'America/New_York', 'Y-m-d H:i'))->toBe('2026-01-15 12:00');
});
