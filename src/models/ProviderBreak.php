<?php

namespace justinholtweb\stub\models;

use craft\base\Model;

class ProviderBreak extends Model
{
    public ?int $id = null;
    public ?int $providerId = null;
    public int $dayOfWeek = 0;
    public string $startTime = '12:00';
    public string $endTime = '13:00';
    public ?string $label = null;
    public ?string $dateCreated = null;
    public ?string $dateUpdated = null;
    public ?string $uid = null;

    public function defineRules(): array
    {
        return [
            [['providerId', 'dayOfWeek', 'startTime', 'endTime'], 'required'],
            [['dayOfWeek'], 'integer', 'min' => 0, 'max' => 6],
        ];
    }
}
