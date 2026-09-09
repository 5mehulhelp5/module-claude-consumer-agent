<?php
declare(strict_types=1);

namespace MageOS\ClaudeConsumerAgent\Model\Client;

final class RawEvent
{
    public function __construct(
        public readonly string $type,
        public readonly array $data
    ) {
    }
}
