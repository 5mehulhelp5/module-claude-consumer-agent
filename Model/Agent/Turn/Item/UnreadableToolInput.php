<?php
declare(strict_types=1);

namespace MageOS\ClaudeConsumerAgent\Model\Agent\Turn\Item;

class UnreadableToolInput
{
    public function __construct(
        public readonly string $id,
        public readonly string $name
    ) {
    }
}
