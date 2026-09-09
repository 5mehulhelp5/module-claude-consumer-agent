<?php
declare(strict_types=1);

namespace MageOS\ClaudeConsumerAgent\Model\Backend;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\InventorySalesApi\Api\Data\SalesChannelInterface;
use Magento\InventorySalesApi\Api\IsProductSalableInterface;
use Magento\InventorySalesApi\Api\StockResolverInterface;
use MageOS\ClaudeConsumerAgent\Model\Agent\SessionContext;

/**
 * Reads Magento\Framework\ObjectManagerInterface directly to resolve the two optional
 * MSI (Multi Source Inventory) interfaces without hard-coupling the module to them.
 */
final class Salability
{
    public function __construct(
        private readonly \Magento\Framework\ObjectManagerInterface $objectManager,
        private readonly \Magento\CatalogInventory\Api\StockRegistryInterface $stockRegistry,
        private readonly \Magento\Store\Model\StoreManagerInterface $storeManager
    ) {
    }

    public function isSalable(ProductInterface $p, SessionContext $ctx): bool
    {
        if (interface_exists(IsProductSalableInterface::class) && interface_exists(StockResolverInterface::class)) {
            $result = $this->isSalableByMsi($p, $ctx);
            if ($result !== null) {
                return $result;
            }
        }

        $websiteId = (int)$this->storeManager->getStore($ctx->storeId)->getWebsiteId();
        return $this->stockRegistry->getStockStatus((int)$p->getId(), $websiteId)->getStockStatus() === 1;
    }

    private function isSalableByMsi(ProductInterface $p, SessionContext $ctx): ?bool
    {
        try {
            $stockResolver = $this->objectManager->get(StockResolverInterface::class);
            $isProductSalable = $this->objectManager->get(IsProductSalableInterface::class);
            $websiteCode = $this->storeManager->getStore($ctx->storeId)->getWebsite()->getCode();
            $stockId = $stockResolver->execute(SalesChannelInterface::TYPE_WEBSITE, $websiteCode)->getStockId();
            if ($stockId === null) {
                return null;
            }
            return $isProductSalable->execute((string)$p->getSku(), $stockId);
        } catch (\Throwable $exception) {
            return null;
        }
    }
}
