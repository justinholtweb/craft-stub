<?php

namespace justinholtweb\stub\events;

use justinholtweb\stub\elements\Booking;
use yii\base\Event;

class BookingEvent extends Event
{
    public Booking $booking;
    public bool $isNew = false;
}
