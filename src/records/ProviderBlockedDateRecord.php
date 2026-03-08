<?php

namespace justinholtweb\stub\records;

use craft\db\ActiveRecord;

class ProviderBlockedDateRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%stub_provider_blocked_dates}}';
    }
}
