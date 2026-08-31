<?php

declare(strict_types=1);

use justinholtweb\stub\models\Settings;

/**
 * The settings screen used to build the "Default Timezone" dropdown from
 * `craft.app.i18n.allLocales`, so it offered languages and happily stored a locale id
 * such as `en-US`. That value flows straight into a new provider's timezone, where it
 * blows up the moment a `DateTimeZone` is constructed from it. The picker is now Craft's
 * `timeZoneField`; this rule is the backstop for settings saved before the fix.
 */

function settingsWithTimezone(string $timezone): Settings
{
    return new Settings(['defaultTimezone' => $timezone]);
}

it('accepts a real timezone identifier', function() {
    expect(settingsWithTimezone('America/New_York')->validate())->toBeTrue();
});

it('accepts UTC', function() {
    expect(settingsWithTimezone('UTC')->validate())->toBeTrue();
});

it('rejects a locale id left behind by the old language dropdown', function() {
    $settings = settingsWithTimezone('en-US');

    expect($settings->validate())->toBeFalse()
        ->and($settings->getErrors('defaultTimezone'))->not->toBeEmpty();
});

it('rejects an empty timezone', function() {
    $settings = settingsWithTimezone('');

    expect($settings->validate())->toBeFalse()
        ->and($settings->getErrors('defaultTimezone'))->not->toBeEmpty();
});
