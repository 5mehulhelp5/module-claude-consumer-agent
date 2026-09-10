<?php
declare(strict_types=1);

namespace MageOS\ClaudeConsumerAgent\Test\Unit\Backend;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\CatalogInventory\Api\Data\StockStatusInterface;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\Framework\ObjectManagerInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use MageOS\ClaudeConsumerAgent\Model\Agent\SessionContext;
use MageOS\ClaudeConsumerAgent\Model\Backend\Salability;
use MageOS\ClaudeConsumerAgent\Model\Data\PageContext;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class SalabilityTest extends TestCase
{
    private function context(): SessionContext
    {
        return new SessionContext('session-1', null, 1, 1, new PageContext(), new \DateTimeImmutable('now'));
    }

    private function product(int $id): ProductInterface&MockObject
    {
        $product = $this->createMock(ProductInterface::class);
        $product->method('getId')->willReturn($id);
        return $product;
    }

    private function legacyOnlySalability(mixed $rawStockStatus): Salability
    {
        $objectManager = $this->createMock(ObjectManagerInterface::class);
        $objectManager->method('get')->willThrowException(new \RuntimeException('MSI not available'));

        $store = $this->createMock(Store::class);
        $store->method('getWebsiteId')->willReturn(1);
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        $stockStatus = $this->createMock(StockStatusInterface::class);
        $stockStatus->method('getStockStatus')->willReturn($rawStockStatus);
        $stockRegistry = $this->createMock(StockRegistryInterface::class);
        $stockRegistry->method('getStockStatus')->willReturn($stockStatus);

        return new Salability($objectManager, $stockRegistry, $storeManager);
    }

    public function testInStockStatusCodeIsSalable(): void
    {
        $salability = $this->legacyOnlySalability(StockStatusInterface::STATUS_IN_STOCK);

        $this->assertTrue($salability->isSalable($this->product(1), $this->context()));
    }

    public function testOutOfStockStatusCodeIsNotSalable(): void
    {
        $salability = $this->legacyOnlySalability(StockStatusInterface::STATUS_OUT_OF_STOCK);

        $this->assertFalse($salability->isSalable($this->product(1), $this->context()));
    }

    public function testStringStockStatusFromTheUntypedColumnIsCastBeforeComparison(): void
    {
        $salability = $this->legacyOnlySalability((string)StockStatusInterface::STATUS_IN_STOCK);

        $this->assertTrue($salability->isSalable($this->product(1), $this->context()));
    }
}
