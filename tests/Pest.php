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
 | Model validation rules are covered here too. Those need Yii's validator
 | infrastructure (`Yii::createObject`), but not a booted application — Yii.php
 | isn't PSR-4 autoloadable, so it has to be required explicitly. With no
 | `Yii::$app` set, `Yii::t()` falls back to returning the untranslated
 | message, which is all the assertions need.
 |
 */

if (!class_exists('Yii', false)) {
    require_once dirname(__DIR__) . '/vendor/yiisoft/yii2/Yii.php';
}

uses()->group('unit')->in('Unit');
