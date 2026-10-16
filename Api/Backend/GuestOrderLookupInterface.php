<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Api\Backend;

use MageOS\AiShoppingAssistant\Api\Data\OrderInterface;
use MageOS\AiShoppingAssistant\Model\Agent\SessionContext;

interface GuestOrderLookupInterface
{
    /**
     * A guest order of the session's store whose number and checkout email both match, else null.
     */
    public function find(SessionContext $ctx, string $orderId, string $email): ?OrderInterface;
}
