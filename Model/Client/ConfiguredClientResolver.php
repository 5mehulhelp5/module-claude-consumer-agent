<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Model\Client;

use MageOS\AiBase\Api\AiClientInterface;
use MageOS\AiShoppingAssistant\Api\Client\ClientResolverInterface;

class ConfiguredClientResolver implements ClientResolverInterface
{
    public const CONSUMER = 'MageOS_AiShoppingAssistant';

    public function __construct(
        private readonly \MageOS\AiBase\Api\AiClientFactoryInterface $clientFactory,
        private readonly \MageOS\AiShoppingAssistant\Model\Config\StoreConfig $storeConfig
    ) {
    }

    public function forStore(int $storeId): AiClientInterface
    {
        $serviceId = $this->storeConfig->aiServiceId($storeId);
        if ($serviceId === '') {
            return $this->clientFactory->create(null, self::CONSUMER);
        }
        return $this->clientFactory->createById($serviceId, self::CONSUMER);
    }
}
