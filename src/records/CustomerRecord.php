<?php

namespace justinholtweb\stub\records;

use craft\db\ActiveRecord;

class CustomerRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%stub_customers}}';
    }
}
