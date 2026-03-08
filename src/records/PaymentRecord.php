<?php

namespace justinholtweb\stub\records;

use craft\db\ActiveRecord;

class PaymentRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%stub_payments}}';
    }
}
