<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Model\Eval;

use MageOS\AiBase\Api\AiClientInterface;
use MageOS\AiShoppingAssistant\Api\Client\ClientResolverInterface;

final class FixedClientResolver implements ClientResolverInterface
{
    public function __construct(
        private readonly \MageOS\AiBase\Api\AiClientInterface $client
    ) {
    }

    public function forStore(int $storeId): AiClientInterface
    {
        return $this->client;
    }
}
