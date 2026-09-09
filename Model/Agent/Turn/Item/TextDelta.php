<?php
declare(strict_types=1);

namespace MageOS\ClaudeConsumerAgent\Model\Agent\Turn\Item;

final class TextDelta
{
    public function __construct(
        public readonly string $text
    ) {
    }
}
