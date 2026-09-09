<?php
declare(strict_types=1);

namespace MageOS\ClaudeConsumerAgent\Api\Backend;

use MageOS\ClaudeConsumerAgent\Api\Data\SearchFiltersInterface;
use MageOS\ClaudeConsumerAgent\Model\Agent\SessionContext;

interface SearchProviderInterface
{
    /**
     * @return int[]
     */
    public function search(
        SessionContext $ctx,
        string $query,
        ?SearchFiltersInterface $filters,
        int $limit
    ): array;
}
