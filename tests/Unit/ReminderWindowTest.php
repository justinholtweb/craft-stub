<?php

declare(strict_types=1);

use justinholtweb\stub\services\Reminders;

/**
 * The reminder sweep is driven by cron, so its window is the only thing standing between
 * "one email, roughly a day ahead" and either a duplicate or a reminder about an
 * appointment that has already happened.
 *
 * `$now` is injected so the boundaries are deterministic.
 */

function utc(string $iso): DateTime
{
    return (new DateTime($iso))->setTimezone(new DateTimeZone('UTC'));
}

it('opens the window at now and closes it one lead time later', function() {
    [$start, $end] = Reminders::dueWindow(utc('2026-07-22T09:00:00Z'), 24);

    expect($start)->toBe('2026-07-22 09:00:00')
        ->and($end)->toBe('2026-07-23 09:00:00');
});

it('never reaches backwards', function() {
    // An appointment that started a minute ago must fall outside the window, however long
    // the sweep has been down — nobody wants a reminder about this morning.
    [$start] = Reminders::dueWindow(utc('2026-07-22T09:00:00Z'), 72);

    expect($start)->toBe('2026-07-22 09:00:00');
});

it('normalizes a non-UTC now to the UTC the column is stored in', function() {
    $now = new DateTime('2026-07-22 05:00:00', new DateTimeZone('America/New_York'));

    [$start, $end] = Reminders::dueWindow($now, 24);

    // EDT is UTC-4.
    expect($start)->toBe('2026-07-22 09:00:00')
        ->and($end)->toBe('2026-07-23 09:00:00');
});

it('measures the lead time in real hours across a DST change', function() {
    // 2026-03-08 is the US spring-forward date. The window is UTC arithmetic, so 24 hours
    // means 24 hours — a local-time "+1 day" would have shifted by an hour here.
    [$start, $end] = Reminders::dueWindow(utc('2026-03-07T18:00:00Z'), 24);

    expect($start)->toBe('2026-03-07 18:00:00')
        ->and($end)->toBe('2026-03-08 18:00:00');
});

it('handles a short lead time', function() {
    [$start, $end] = Reminders::dueWindow(utc('2026-07-22T09:30:00Z'), 1);

    expect($start)->toBe('2026-07-22 09:30:00')
        ->and($end)->toBe('2026-07-22 10:30:00');
});

it('does not mutate the datetime it was given', function() {
    $now = new DateTime('2026-07-22 05:00:00', new DateTimeZone('America/New_York'));

    Reminders::dueWindow($now, 24);

    expect($now->format('Y-m-d H:i:s'))->toBe('2026-07-22 05:00:00')
        ->and($now->getTimezone()->getName())->toBe('America/New_York');
});
