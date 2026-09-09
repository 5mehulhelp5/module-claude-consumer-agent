<?php
declare(strict_types=1);

namespace MageOS\ClaudeConsumerAgent\Controller\Request;

final class TurnRequest
{
    public function __construct(
        public readonly ?string $sessionId,
        public readonly string $message,
        public readonly array $page,
        public readonly bool $wantsStream
    ) {
    }
}
