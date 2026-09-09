<?php
declare(strict_types=1);

namespace MageOS\ClaudeConsumerAgent\Test\Unit\Cron;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use MageOS\ClaudeConsumerAgent\Cron\Retention;
use MageOS\ClaudeConsumerAgent\Model\Config\StoreConfig;
use MageOS\ClaudeConsumerAgent\Model\Session\ResourceModel\Session;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;

final class RetentionTest extends TestCase
{
    private function buildStoreConfig(?int $retentionDays): StoreConfig
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(
            static function (string $path) use ($retentionDays) {
                if ($path === 'aiagent/privacy/retention_days') {
                    return $retentionDays;
                }
                return null;
            }
        );
        $scopeConfig->method('isSetFlag')->willReturn(false);
        $store = $this->createMock(StoreInterface::class);
        $store->method('getName')->willReturn('');
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);
        return new StoreConfig($scopeConfig, $storeManager);
    }

    public function testExecuteDeletesOlderSessionsAndLogsCount(): void
    {
        $resource = $this->createMock(Session::class);
        $resource->expects($this->once())
            ->method('deleteOlderThan')
            ->willReturn(7);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('info')
            ->with('retention removed 7 sessions');

        $retention = new Retention($this->buildStoreConfig(30), $resource, $logger);
        $retention->execute();
    }

    public function testExecuteSkipsWhenRetentionDaysIsZeroOrLess(): void
    {
        $resource = $this->createMock(Session::class);
        $resource->expects($this->never())->method('deleteOlderThan');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method('info');

        $retention = new Retention($this->buildStoreConfig(0), $resource, $logger);
        $retention->execute();
    }
}
