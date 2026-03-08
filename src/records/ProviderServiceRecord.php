<?php

namespace justinholtweb\stub\records;

use craft\db\ActiveRecord;

class ProviderServiceRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%stub_provider_services}}';
    }
}
