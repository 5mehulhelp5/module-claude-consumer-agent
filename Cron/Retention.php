<?php
declare(strict_types=1);

namespace MageOS\ClaudeConsumerAgent\Cron;

use DateTimeImmutable;

final class Retention
{
    public function __construct(
        private readonly \MageOS\ClaudeConsumerAgent\Model\Config\StoreConfig $storeConfig,
        private readonly \MageOS\ClaudeConsumerAgent\Model\Session\ResourceModel\Session $resource,
        private readonly \Psr\Log\LoggerInterface $logger
    ) {
    }

    public function execute(): void
    {
        $days = $this->storeConfig->agent(0)->retentionDays;
        if ($days <= 0) {
            return;
        }
        $before = new DateTimeImmutable(sprintf('-%d days', $days));
        $deleted = $this->resource->deleteOlderThan($before);
        $this->logger->info(sprintf('retention removed %d sessions', $deleted));
    }
}
