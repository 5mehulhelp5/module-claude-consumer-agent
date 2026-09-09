<?php
declare(strict_types=1);

namespace MageOS\ClaudeConsumerAgent\Test\Unit\Backend;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use MageOS\ClaudeConsumerAgent\Api\Data\FulfillmentOptionInterface;
use MageOS\ClaudeConsumerAgent\Model\Agent\SessionContext;
use MageOS\ClaudeConsumerAgent\Model\Backend\Provider\ConfiguredLines;
use MageOS\ClaudeConsumerAgent\Model\Config\StoreConfig;
use MageOS\ClaudeConsumerAgent\Model\Data\PageContext;
use PHPUnit\Framework\TestCase;

final class ConfiguredLinesTest extends TestCase
{
    private function context(): SessionContext
    {
        return new SessionContext('session-1', null, 1, 1, new PageContext(), new \DateTimeImmutable('now'));
    }

    private function storeConfig(string $deliveryLine, string $pickupLine): StoreConfig
    {
        $values = [
            'aiagent/content/delivery_line' => $deliveryLine,
            'aiagent/content/pickup_line' => $pickupLine,
        ];
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(
            static fn (string $path): ?string => $values[$path] ?? null
        );
        $scopeConfig->method('isSetFlag')->willReturn(false);

        $store = $this->createMock(StoreInterface::class);
        $store->method('getName')->willReturn('Test Store');
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        return new StoreConfig($scopeConfig, $storeManager);
    }

    public function testBothLinesConfigured(): void
    {
        $provider = new ConfiguredLines($this->storeConfig('arrives in 2 to 4 days', 'ready in store within an hour'));

        $options = $provider->options($this->context(), []);

        $this->assertCount(2, $options);
        $this->assertSame(FulfillmentOptionInterface::METHOD_DELIVERY, $options[0]->getMethod());
        $this->assertSame('arrives in 2 to 4 days', $options[0]->getEta());
        $this->assertSame(FulfillmentOptionInterface::METHOD_PICKUP, $options[1]->getMethod());
        $this->assertSame('ready in store within an hour', $options[1]->getEta());
    }

    public function testEmptyLinesAreSkipped(): void
    {
        $provider = new ConfiguredLines($this->storeConfig('', ''));

        $this->assertSame([], $provider->options($this->context(), []));
    }

    public function testOnlyDeliveryLineConfigured(): void
    {
        $provider = new ConfiguredLines($this->storeConfig('arrives in 2 to 4 days', ''));

        $options = $provider->options($this->context(), []);

        $this->assertCount(1, $options);
        $this->assertSame(FulfillmentOptionInterface::METHOD_DELIVERY, $options[0]->getMethod());
    }
}
