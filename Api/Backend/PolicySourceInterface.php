<?php
declare(strict_types=1);

namespace MageOS\ClaudeConsumerAgent\Api\Backend;

use MageOS\ClaudeConsumerAgent\Model\Agent\SessionContext;

interface PolicySourceInterface
{
    /**
     * @return \MageOS\ClaudeConsumerAgent\Api\Data\PolicyInterface[]
     */
    public function search(SessionContext $ctx, string $query): array;
}
