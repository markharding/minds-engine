<?php
declare(strict_types=1);

namespace Minds\Core\Payments\InAppPurchases\Clients;

use Minds\Core\Payments\InAppPurchases\Models\InAppPurchase;

class AppleInAppPurchasesClient implements InAppPurchaseClientInterface
{
    /**
     * TODO
     */
    public function acknowledgePurchase(InAppPurchase $inAppPurchase): bool
    {
        return false;
    }
}
