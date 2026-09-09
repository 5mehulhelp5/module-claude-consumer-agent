<?php
declare(strict_types=1);

namespace MageOS\ClaudeConsumerAgent\Model\Backend\Provider;

use MageOS\ClaudeConsumerAgent\Api\Backend\FulfillmentProviderInterface;
use MageOS\ClaudeConsumerAgent\Api\Data\FulfillmentOptionInterface;
use MageOS\ClaudeConsumerAgent\Model\Agent\SessionContext;
use MageOS\ClaudeConsumerAgent\Model\Data\FulfillmentOption;

final class ConfiguredLines implements FulfillmentProviderInterface
{
    public function __construct(
        private readonly \MageOS\ClaudeConsumerAgent\Model\Config\StoreConfig $storeConfig
    ) {
    }

    public function options(SessionContext $ctx, array $productIds): array
    {
        $config = $this->storeConfig->agent($ctx->storeId);
        $options = [];

        if (trim($config->deliveryLine) !== '') {
            $options[] = new FulfillmentOption(FulfillmentOptionInterface::METHOD_DELIVERY, $config->deliveryLine);
        }

        if (trim($config->pickupLine) !== '') {
            $options[] = new FulfillmentOption(FulfillmentOptionInterface::METHOD_PICKUP, $config->pickupLine);
        }

        return $options;
    }
}
