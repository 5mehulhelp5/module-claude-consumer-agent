<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Test\Unit\Client;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use MageOS\AiBase\Api\AiClientFactoryInterface;
use MageOS\AiBase\Api\AiClientInterface;
use MageOS\AiShoppingAssistant\Model\Client\ConfiguredClientResolver;
use MageOS\AiShoppingAssistant\Model\Config\StoreConfig;
use PHPUnit\Framework\TestCase;

final class ConfiguredClientResolverTest extends TestCase
{
    public function testNoSelectedServiceAsksAiBaseForTheFirstUsableOne(): void
    {
        $client = $this->createMock(AiClientInterface::class);
        $factory = $this->createMock(AiClientFactoryInterface::class);
        $factory->expects($this->once())->method('create')
            ->with(null, ConfiguredClientResolver::CONSUMER)->willReturn($client);
        $factory->expects($this->never())->method('createById');

        $this->assertSame($client, (new ConfiguredClientResolver($factory, $this->storeConfig('')))->forStore(1));
    }

    public function testASelectedServiceIsResolvedByItsRowId(): void
    {
        $client = $this->createMock(AiClientInterface::class);
        $factory = $this->createMock(AiClientFactoryInterface::class);
        $factory->expects($this->once())->method('createById')
            ->with('_row_1', ConfiguredClientResolver::CONSUMER)->willReturn($client);

        $this->assertSame($client, (new ConfiguredClientResolver($factory, $this->storeConfig(' _row_1 ')))->forStore(1));
    }

    public function testAMissingServiceReachesTheCaller(): void
    {
        $factory = $this->createMock(AiClientFactoryInterface::class);
        $factory->method('createById')->willThrowException(new LocalizedException(__('deleted')));

        $this->expectException(LocalizedException::class);
        (new ConfiguredClientResolver($factory, $this->storeConfig('_row_gone')))->forStore(1);
    }

    private function storeConfig(string $serviceId): StoreConfig
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(
            static fn (string $path): ?string => $path === 'ai_integration/aiagent/model/ai_service' ? $serviceId : null
        );
        $store = $this->createMock(StoreInterface::class);
        $store->method('getName')->willReturn('');
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);
        return new StoreConfig($scopeConfig, $storeManager);
    }
}
