<?php

namespace justinholtweb\stub\assetbundles\calendar;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;

class CalendarAssetBundle extends AssetBundle
{
    public function init(): void
    {
        $this->sourcePath = __DIR__;
        $this->depends = [CpAsset::class];

        $this->css = [
            'https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.min.css',
        ];

        $this->js = [
            'https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.min.js',
            'calendar-init.js',
        ];

        parent::init();
    }
}
