<?php

namespace justinholtweb\stub\helpers;

use DateTime;
use DateTimeZone;

class TimeHelper
{
    public static function convertToUtc(string $dateTime, string $fromTimezone): DateTime
    {
        $tz = new DateTimeZone($fromTimezone);
        $dt = new DateTime($dateTime, $tz);
        $dt->setTimezone(new DateTimeZone('UTC'));
        return $dt;
    }

    public static function convertFromUtc(string $dateTime, string $toTimezone): DateTime
    {
        $dt = new DateTime($dateTime, new DateTimeZone('UTC'));
        $dt->setTimezone(new DateTimeZone($toTimezone));
        return $dt;
    }

    public static function convertTimezone(string $dateTime, string $fromTimezone, string $toTimezone): DateTime
    {
        $dt = new DateTime($dateTime, new DateTimeZone($fromTimezone));
        $dt->setTimezone(new DateTimeZone($toTimezone));
        return $dt;
    }

    public static function timeToDateTime(string $time, string $date, string $timezone): DateTime
    {
        return new DateTime("{$date} {$time}", new DateTimeZone($timezone));
    }

    public static function formatForDisplay(DateTime $dt, string $timezone, string $format = 'g:i A'): string
    {
        $dt = clone $dt;
        $dt->setTimezone(new DateTimeZone($timezone));
        return $dt->format($format);
    }

    public static function nowUtc(): DateTime
    {
        return new DateTime('now', new DateTimeZone('UTC'));
    }

    public static function nowInTimezone(string $timezone): DateTime
    {
        return new DateTime('now', new DateTimeZone($timezone));
    }

    public static function dateRangeOverlaps(
        DateTime $start1,
        DateTime $end1,
        DateTime $start2,
        DateTime $end2,
    ): bool {
        return $start1 < $end2 && $start2 < $end1;
    }
}
