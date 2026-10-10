<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Api\Client;

interface ClientResolverInterface
{
    /**
     * @throws \Magento\Framework\Exception\LocalizedException When no usable AI service is configured
     */
    public function forStore(int $storeId): \MageOS\AiBase\Api\AiClientInterface;
}
