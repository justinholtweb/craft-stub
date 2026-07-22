<?php

declare(strict_types=1);

use justinholtweb\stub\helpers\TimeHelper;

/**
 * The dashboard counts every booking falling inside one of these windows, so a bound
 * that is off by an hour (UTC vs. local) or by a day (week rollover) silently reports
 * the wrong number rather than failing — which is exactly how the 5.0.0 counts broke.
 *
 * `$now` is injected throughout so the windows are deterministic.
 */

function at(string $iso): DateTime
{
    // The trailing `Z` sets the offset, so the zone has to be normalized afterwards —
    // otherwise PHP names it `Z` and the timezone assertions read confusingly.
    return (new DateTime($iso))->setTimezone(new DateTimeZone('UTC'));
}

it('bounds a day to local midnight, not UTC midnight', function() {
    // 03:00 UTC on the 23rd is still the evening of the 22nd in New York.
    [$start, $end] = TimeHelper::periodBounds('day', 'America/New_York', at('2026-07-23T03:00:00Z'));

    expect($start->format('Y-m-d H:i:s'))->toBe('2026-07-22 00:00:00')
        ->and($end->format('Y-m-d H:i:s'))->toBe('2026-07-22 23:59:59');
});

it('returns bounds in the requested timezone', function() {
    [$start] = TimeHelper::periodBounds('day', 'America/New_York', at('2026-07-22T15:00:00Z'));

    expect($start->getTimezone()->getName())->toBe('America/New_York');
});

it('converts day bounds back to the UTC window actually queried', function() {
    [$start, $end] = TimeHelper::periodBounds('day', 'America/New_York', at('2026-07-22T15:00:00Z'));

    $utc = new DateTimeZone('UTC');

    // EDT is UTC-4, so the local day spans 04:00 on the 22nd to 03:59:59 on the 23rd.
    expect($start->setTimezone($utc)->format('Y-m-d H:i:s'))->toBe('2026-07-22 04:00:00')
        ->and($end->setTimezone($utc)->format('Y-m-d H:i:s'))->toBe('2026-07-23 03:59:59');
});

it('bounds a week from Monday to Sunday', function() {
    [$start, $end] = TimeHelper::periodBounds('week', 'America/New_York', at('2026-07-22T15:00:00Z'));

    expect($start->format('Y-m-d H:i:s'))->toBe('2026-07-20 00:00:00')
        ->and($end->format('Y-m-d H:i:s'))->toBe('2026-07-26 23:59:59');
});

it('treats Sunday as the end of the current week, not the start of the next', function() {
    // Sunday 2026-07-26. PHP's "this week" is ISO, so Monday resolves backwards.
    [$start, $end] = TimeHelper::periodBounds('week', 'America/New_York', at('2026-07-26T16:00:00Z'));

    expect($start->format('Y-m-d'))->toBe('2026-07-20')
        ->and($end->format('Y-m-d'))->toBe('2026-07-26');
});

it('treats Monday as the start of its own week', function() {
    [$start, $end] = TimeHelper::periodBounds('week', 'America/New_York', at('2026-07-20T16:00:00Z'));

    expect($start->format('Y-m-d'))->toBe('2026-07-20')
        ->and($end->format('Y-m-d'))->toBe('2026-07-26');
});

it('bounds a month to its real last day', function() {
    [$start, $end] = TimeHelper::periodBounds('month', 'America/New_York', at('2026-07-22T15:00:00Z'));

    expect($start->format('Y-m-d H:i:s'))->toBe('2026-07-01 00:00:00')
        ->and($end->format('Y-m-d H:i:s'))->toBe('2026-07-31 23:59:59');
});

it('bounds February correctly in a leap year', function() {
    [$start, $end] = TimeHelper::periodBounds('month', 'UTC', at('2028-02-10T12:00:00Z'));

    expect($start->format('Y-m-d'))->toBe('2028-02-01')
        ->and($end->format('Y-m-d'))->toBe('2028-02-29');
});

it('anchors the day to local time across a DST transition', function() {
    // 2026-03-08 is the spring-forward date in the US; the local day is only 23 hours.
    [$start, $end] = TimeHelper::periodBounds('day', 'America/New_York', at('2026-03-08T18:00:00Z'));

    expect($start->format('Y-m-d H:i:s'))->toBe('2026-03-08 00:00:00')
        ->and($end->format('Y-m-d H:i:s'))->toBe('2026-03-08 23:59:59')
        ->and($start->getOffset())->toBe(-5 * 3600)  // EST before the 2am jump
        ->and($end->getOffset())->toBe(-4 * 3600);   // EDT after it
});

it('gives different days for the same instant in different zones', function() {
    // 2026-07-23 03:00 UTC: still the 22nd in New York, already the 23rd in Berlin.
    $instant = at('2026-07-23T03:00:00Z');

    [$ny] = TimeHelper::periodBounds('day', 'America/New_York', $instant);
    [$berlin] = TimeHelper::periodBounds('day', 'Europe/Berlin', $instant);

    expect($ny->format('Y-m-d'))->toBe('2026-07-22')
        ->and($berlin->format('Y-m-d'))->toBe('2026-07-23');
});

it('does not mutate the datetime it was given', function() {
    $now = at('2026-07-22T15:00:00Z');

    TimeHelper::periodBounds('month', 'America/New_York', $now);

    expect($now->format('Y-m-d H:i:s'))->toBe('2026-07-22 15:00:00')
        ->and($now->getTimezone()->getName())->toBe('UTC');
});

it('rejects an unsupported period', function() {
    TimeHelper::periodBounds('fortnight', 'UTC');
})->throws(InvalidArgumentException::class);
