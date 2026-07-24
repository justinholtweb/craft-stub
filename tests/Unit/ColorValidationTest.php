<?php

declare(strict_types=1);

use justinholtweb\stub\models\Provider;
use justinholtweb\stub\models\Service;
use justinholtweb\stub\models\Settings;

/**
 * Craft's `colorField` renders the hex without a leading `#` (the `#` is a static
 * prefix in the UI, and Craft.ColorInput actively strips one if it's typed), so the
 * CP posts `2563eb`, not `#2563eb`. The models must normalize before matching —
 * a bare `/^#[0-9a-fA-F]{6}$/` rule can never pass, which failed every save.
 */

function serviceWithColor(string $color): Service
{
    return new Service([
        'name' => 'Consultation',
        'handle' => 'consultation',
        'duration' => 60,
        'currency' => 'USD',
        'color' => $color,
    ]);
}

it('accepts the hash-less hex that the control panel posts', function() {
    $service = serviceWithColor('2563eb');

    expect($service->validate())->toBeTrue()
        ->and($service->color)->toBe('#2563eb');
});

it('still accepts a hex that already has a hash', function() {
    $service = serviceWithColor('#2563eb');

    expect($service->validate())->toBeTrue()
        ->and($service->color)->toBe('#2563eb');
});

it('normalizes uppercase hex to lowercase', function() {
    $service = serviceWithColor('2563EB');

    expect($service->validate())->toBeTrue()
        ->and($service->color)->toBe('#2563eb');
});

it('expands shorthand hex to the full six digits', function() {
    $service = serviceWithColor('abc');

    expect($service->validate())->toBeTrue()
        ->and($service->color)->toBe('#aabbcc');
});

it('rejects transparent, which would overflow the char(7) column', function() {
    $service = serviceWithColor('transparent');

    expect($service->validate())->toBeFalse()
        ->and($service->getErrors('color'))->not->toBeEmpty();
});

it('rejects a value that is not a hex color', function() {
    $service = serviceWithColor('not-a-color');

    expect($service->validate())->toBeFalse()
        ->and($service->getErrors('color'))->not->toBeEmpty();
});

it('saves a service whose color is left at the default', function() {
    $service = new Service([
        'name' => 'Consultation',
        'handle' => 'consultation',
        'duration' => 60,
        'currency' => 'USD',
    ]);

    expect($service->validate())->toBeTrue();
});

it('normalizes the provider color the same way', function() {
    $provider = new Provider([
        'name' => 'Dana Reed',
        'handle' => 'danaReed',
        'timezone' => 'America/New_York',
        'color' => '2563eb',
    ]);

    expect($provider->validate())->toBeTrue()
        ->and($provider->color)->toBe('#2563eb');
});

it('normalizes the settings primary color the same way', function() {
    $settings = new Settings(['primaryColor' => '2563eb']);

    expect($settings->validate())->toBeTrue()
        ->and($settings->primaryColor)->toBe('#2563eb');
});
