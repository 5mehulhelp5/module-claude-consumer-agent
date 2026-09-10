<?php
declare(strict_types=1);

namespace MageOS\ClaudeConsumerAgent\Cron;

use DateTimeImmutable;

final class Retention
{
    public function __construct(
        private readonly \MageOS\ClaudeConsumerAgent\Model\Config\StoreConfig $storeConfig,
        private readonly \MageOS\ClaudeConsumerAgent\Model\Session\ResourceModel\Session $resource,
        private readonly \Magento\Store\Model\StoreManagerInterface $storeManager,
        private readonly \Psr\Log\LoggerInterface $logger
    ) {
    }

    public function execute(): void
    {
        foreach ($this->storeManager->getStores() as $store) {
            $this->purgeStore((int)$store->getId());
        }
    }

    private function purgeStore(int $storeId): void
    {
        $days = $this->storeConfig->agent($storeId)->retentionDays;
        if ($days <= 0) {
            return;
        }
        $before = new DateTimeImmutable(sprintf('-%d days', $days));
        $deleted = $this->resource->deleteOlderThan($before, $storeId);
        $this->logger->info(sprintf('retention removed %d sessions store=%d', $deleted, $storeId));
    }
}
