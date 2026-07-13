<?php

declare(strict_types=1);

use craft\ecs\SetList;
use Symplify\EasyCodingStandard\Config\ECSConfig;

return static function(ECSConfig $ecsConfig): void {
    $ecsConfig->parallel();
    $ecsConfig->paths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
        __FILE__,
    ]);

    // craftcms/ecs has no Craft 5-specific set yet; the 4.x set (PSR-12-based) applies unchanged.
    $ecsConfig->sets([SetList::CRAFT_CMS_4]);
};
