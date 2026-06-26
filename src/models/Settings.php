<?php

namespace justinholtweb\stub\models;

use craft\base\Model;

class Settings extends Model
{
    // General
    public string $pluginName = 'Stub';
    public string $defaultCurrency = 'USD';
    public string $defaultTimezone = 'America/New_York';
    public int $minimumNotice = 60; // minutes
    public int $maxAdvanceBooking = 90; // days
    public int $slotInterval = 15; // minutes

    // Booking
    public bool $autoConfirmFreeBookings = true;
    public bool $requirePhone = false;
    public bool $allowCustomerNotes = true;
    public string $referencePrefix = 'STB';
    public string $bookingPageUrl = '';
    public string $termsUrl = '';

    // Stripe
    public string $stripePublishableKey = '';
    public string $stripeSecretKey = '';
    public string $stripeWebhookSecret = '';
    public bool $paymentEnabled = false;

    // Notifications
    public string $adminEmail = '';
    public bool $sendCustomerConfirmation = true;
    public bool $sendAdminNotification = true;
    public bool $sendCancellationEmail = true;

    // Appearance
    public string $primaryColor = '#2563eb';
    public bool $embedStripeJs = true;

    // Anti-abuse
    public bool $enableHoneypot = true;
    public string $honeypotFieldName = 'stub_hp';
    public int $bookingsPerHour = 10;
    public int $paymentIntentsPerHour = 30;

    public function defineRules(): array
    {
        return [
            [['pluginName', 'defaultCurrency', 'defaultTimezone'], 'required'],
            [['pluginName'], 'string', 'max' => 50],
            [['defaultCurrency'], 'string', 'length' => 3],
            [['minimumNotice', 'maxAdvanceBooking', 'slotInterval'], 'integer', 'min' => 1],
            [['adminEmail'], 'email', 'skipOnEmpty' => true],
            [['primaryColor'], 'match', 'pattern' => '/^#[0-9a-fA-F]{6}$/'],
            [['bookingsPerHour', 'paymentIntentsPerHour'], 'integer', 'min' => 0],
            [['honeypotFieldName'], 'string', 'max' => 50],
            [['enableHoneypot'], 'boolean'],
        ];
    }
}
