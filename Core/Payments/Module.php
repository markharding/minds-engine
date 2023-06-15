<?php

declare(strict_types=1);

namespace Minds\Core\Payments;

use Minds\Interfaces\ModuleInterface;

class Module implements ModuleInterface
{
    public array $submodules = [
        InAppPurchases\Module::class,
        GiftCards\Module::class,
    ];
    
    /**
     * OnInit.
     */
    public function onInit()
    {
        (new Provider())->register();
        (new Routes())->register();
    }
}
