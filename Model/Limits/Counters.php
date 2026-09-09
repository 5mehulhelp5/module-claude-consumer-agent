<?php
declare(strict_types=1);

namespace MageOS\ClaudeConsumerAgent\Model\Limits;

use MageOS\ClaudeConsumerAgent\Model\Agent\AgentConfig;
use MageOS\ClaudeConsumerAgent\Model\Limits\Exception\LimitExceeded;

final class Counters
{
    private const CACHE_TAG = 'AIAGENT';
    private const SESSION_TTL = 600;
    private const IP_TTL = 60;
    private const SESSION_RETRY_AFTER = 60;
    private const IP_RETRY_AFTER = 20;

    public function __construct(
        private readonly \Magento\Framework\App\CacheInterface $cache,
        private readonly \Psr\Log\LoggerInterface $logger
    ) {
    }

    public function bump(string $sessionId, string $ip, AgentConfig $config): void
    {
        $sessionKey = 'aiagent_cnt_s_' . substr(sha1($sessionId), 0, 24);
        $ipKey = 'aiagent_cnt_ip_' . substr(sha1($ip), 0, 24);
        $sessionCount = $this->increment($sessionKey, self::SESSION_TTL);
        $ipCount = $this->increment($ipKey, self::IP_TTL);
        if ($sessionCount > $config->turnsPerSessionWindow) {
            $this->trip($sessionId, 'window');
            throw new LimitExceeded('session turns per window exceeded', self::SESSION_RETRY_AFTER);
        }
        if ($ipCount > $config->turnsPerIpMinute) {
            $this->trip($sessionId, 'ip');
            throw new LimitExceeded('turns per ip minute exceeded', self::IP_RETRY_AFTER);
        }
    }

    private function increment(string $key, int $ttl): int
    {
        $current = (int)$this->cache->load($key);
        $next = $current + 1;
        $this->cache->save((string)$next, $key, [self::CACHE_TAG], $ttl);
        return $next;
    }

    private function trip(string $sessionId, string $kind): void
    {
        $this->logger->info(sprintf('limit session=%s kind=%s', substr(sha1($sessionId), 0, 12), $kind));
    }
}
