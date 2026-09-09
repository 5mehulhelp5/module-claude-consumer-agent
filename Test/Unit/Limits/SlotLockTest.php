<?php
declare(strict_types=1);

namespace MageOS\ClaudeConsumerAgent\Test\Unit\Limits;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use MageOS\ClaudeConsumerAgent\Model\Config\StoreConfig;
use MageOS\ClaudeConsumerAgent\Model\Limits\SlotHandle;
use MageOS\ClaudeConsumerAgent\Model\Limits\SlotLock;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;

final class SlotLockTest extends TestCase
{
    private function buildStoreConfig(int $concurrentTurns): StoreConfig
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(
            static function (string $path) use ($concurrentTurns) {
                if ($path === 'aiagent/limits/concurrent_turns') {
                    return $concurrentTurns;
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

    public function testAcquireReturnsFirstFreeSlot(): void
    {
        $lockManager = $this->createMock(LockManagerInterface::class);
        $lockManager->expects($this->exactly(2))
            ->method('lock')
            ->withConsecutive(
                ['aiagent:turn:1:1', 0],
                ['aiagent:turn:1:2', 0]
            )
            ->willReturnOnConsecutiveCalls(false, true);
        $lockManager->expects($this->once())->method('unlock')->with('aiagent:turn:1:2');
        $logger = $this->createMock(LoggerInterface::class);
        $slotLock = new SlotLock($lockManager, $this->buildStoreConfig(4), $logger);

        $handle = $slotLock->acquire(1);

        $this->assertInstanceOf(SlotHandle::class, $handle);
        $handle->release();
    }

    public function testAcquireReturnsNullWhenNoneFree(): void
    {
        $lockManager = $this->createMock(LockManagerInterface::class);
        $lockManager->method('lock')->willReturn(false);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('info')
            ->with('busy store=1 slots=4');
        $slotLock = new SlotLock($lockManager, $this->buildStoreConfig(4), $logger);

        $handle = $slotLock->acquire(1);

        $this->assertNull($handle);
    }

    public function testReleaseIsIdempotent(): void
    {
        $lockManager = $this->createMock(LockManagerInterface::class);
        $lockManager->expects($this->once())->method('unlock')->with('aiagent:turn:1:1');
        $handle = new SlotHandle($lockManager, 'aiagent:turn:1:1');

        $handle->release();
        $handle->release();
    }

    public function testLockNamesAreUnderSixtyFourCharacters(): void
    {
        $lockManager = $this->createMock(LockManagerInterface::class);
        $logger = $this->createMock(LoggerInterface::class);
        $slotLock = new SlotLock($lockManager, $this->buildStoreConfig(4), $logger);

        $this->assertLessThan(64, strlen($slotLock->name(99999, 99)));
    }
}
