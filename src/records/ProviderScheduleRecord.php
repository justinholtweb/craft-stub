<?php

namespace justinholtweb\stub\records;

use craft\db\ActiveRecord;

class ProviderScheduleRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%stub_provider_schedules}}';
    }
}
