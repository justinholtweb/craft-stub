<?php

namespace justinholtweb\stub\models;

use craft\base\Model;

class Provider extends Model
{
    public ?int $id = null;
    public ?int $userId = null;
    public string $name = '';
    public string $handle = '';
    public ?string $email = null;
    public ?string $phone = null;
    public ?string $bio = null;
    public string $color = '#2563eb';
    public string $timezone = 'America/New_York';
    public bool $enabled = true;
    public int $sortOrder = 0;
    public ?string $dateCreated = null;
    public ?string $dateUpdated = null;
    public ?string $dateDeleted = null;
    public ?string $uid = null;

    /** @var ProviderSchedule[] */
    public array $schedules = [];
    /** @var ProviderBreak[] */
    public array $breaks = [];
    /** @var ProviderBlockedDate[] */
    public array $blockedDates = [];
    /** @var int[] */
    public array $serviceIds = [];

    /**
     * @inheritdoc
     * These audit columns are stored and consumed as strings, so opt out of
     * Craft's automatic DateTime casting (which would violate the ?string types).
     */
    public function datetimeAttributes(): array
    {
        return [];
    }

    public function defineRules(): array
    {
        return [
            [['name', 'handle', 'timezone'], 'required'],
            [['name', 'handle'], 'string', 'max' => 255],
            [['handle'], 'match', 'pattern' => '/^[a-zA-Z][a-zA-Z0-9_]*$/'],
            [['email'], 'email', 'skipOnEmpty' => true],
            [['color'], 'match', 'pattern' => '/^#[0-9a-fA-F]{6}$/'],
        ];
    }
}
