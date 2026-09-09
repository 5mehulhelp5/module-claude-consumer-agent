<?php
declare(strict_types=1);

namespace MageOS\ClaudeConsumerAgent\Test\Unit\Limits;

use Magento\Framework\App\CacheInterface;
use MageOS\ClaudeConsumerAgent\Model\Agent\AgentConfig;
use MageOS\ClaudeConsumerAgent\Model\Limits\Counters;
use MageOS\ClaudeConsumerAgent\Model\Limits\Exception\LimitExceeded;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;

final class CountersTest extends TestCase
{
    public function testSessionWindowCapThrowsWithRetryAfterSixty(): void
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('load')->willReturnCallback(
            static function (string $key): string {
                return str_starts_with($key, 'aiagent_cnt_s_') ? '1' : '0';
            }
        );
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('info');
        $counters = new Counters($cache, $logger);
        $config = new AgentConfig(turnsPerSessionWindow: 1, turnsPerIpMinute: 100);

        try {
            $counters->bump('session-1', '10.0.0.1', $config);
            $this->fail('Expected LimitExceeded was not thrown.');
        } catch (LimitExceeded $exception) {
            $this->assertSame(60, $exception->getRetryAfter());
        }
    }

    public function testIpCapThrowsWithRetryAfterTwenty(): void
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('load')->willReturnCallback(
            static function (string $key): string {
                return str_starts_with($key, 'aiagent_cnt_ip_') ? '1' : '0';
            }
        );
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('info');
        $counters = new Counters($cache, $logger);
        $config = new AgentConfig(turnsPerSessionWindow: 100, turnsPerIpMinute: 1);

        try {
            $counters->bump('session-2', '10.0.0.2', $config);
            $this->fail('Expected LimitExceeded was not thrown.');
        } catch (LimitExceeded $exception) {
            $this->assertSame(20, $exception->getRetryAfter());
        }
    }

    public function testWithinLimitsDoesNotThrow(): void
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('load')->willReturn('0');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method('info');
        $counters = new Counters($cache, $logger);
        $config = new AgentConfig();

        $counters->bump('session-3', '10.0.0.3', $config);

        $this->addToAssertionCount(1);
    }
}
