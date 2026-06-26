<?php

declare(strict_types=1);

/*
 |--------------------------------------------------------------------------
 | Test Case bindings
 |--------------------------------------------------------------------------
 |
 | The unit suite covers framework-agnostic logic — payment-token signing,
 | the rate-limit decision, and the pure formatting/timezone helpers — so it
 | needs no Craft bootstrap and runs on plain PHPUnit. Craft-integration
 | (Feature) tests will bind markhuot/craft-pest's TestCase once the
 | companion test site exists.
 |
 */

uses()->group('unit')->in('Unit');
