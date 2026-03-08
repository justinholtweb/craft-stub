<?php

namespace justinholtweb\stub\records;

use craft\db\ActiveRecord;
use craft\db\SoftDeleteTrait;

class ProviderRecord extends ActiveRecord
{
    use SoftDeleteTrait;

    public static function tableName(): string
    {
        return '{{%stub_providers}}';
    }
}
