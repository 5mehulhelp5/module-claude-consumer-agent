<?php
declare(strict_types=1);

namespace MageOS\ClaudeConsumerAgent\Api\Backend;

use MageOS\ClaudeConsumerAgent\Model\Agent\SessionContext;

interface FulfillmentProviderInterface
{
    /**
     * @return \MageOS\ClaudeConsumerAgent\Api\Data\FulfillmentOptionInterface[]
     */
    public function options(SessionContext $ctx, array $productIds): array;
}
