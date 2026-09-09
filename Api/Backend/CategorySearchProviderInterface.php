<?php
declare(strict_types=1);

namespace MageOS\ClaudeConsumerAgent\Api\Backend;

use MageOS\ClaudeConsumerAgent\Model\Agent\SessionContext;

interface CategorySearchProviderInterface
{
    /**
     * @return \MageOS\ClaudeConsumerAgent\Api\Data\CategoryMatchInterface[]
     */
    public function search(SessionContext $ctx, string $keywords, int $limit): array;
}
