<?php

namespace justinholtweb\stub\records;

use craft\db\ActiveRecord;

class BookingRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%stub_bookings}}';
    }
}
