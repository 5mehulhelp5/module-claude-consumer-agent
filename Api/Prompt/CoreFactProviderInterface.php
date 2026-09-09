<?php
declare(strict_types=1);

namespace MageOS\ClaudeConsumerAgent\Api\Prompt;

interface CoreFactProviderInterface
{
    public function line(int $storeId): ?string;
}
