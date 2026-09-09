<?php
declare(strict_types=1);

namespace MageOS\ClaudeConsumerAgent\Model\Session;

final class Binding
{
    public function __construct(
        public readonly string $sessionId,
        public ?array $row,
        public readonly \MageOS\ClaudeConsumerAgent\Model\Agent\SessionState $state,
        public readonly bool $isNew,
        public int $expectedVersion,
        public readonly \MageOS\ClaudeConsumerAgent\Model\Agent\SessionContext $context
    ) {
    }
}
