<?php
declare(strict_types=1);

namespace MageOS\ClaudeConsumerAgent\Model\Agent\Presentation;

final class EnrichmentContext
{
    public function __construct(
        public readonly \MageOS\ClaudeConsumerAgent\Api\StorefrontBackendInterface $backend,
        public readonly \MageOS\ClaudeConsumerAgent\Model\Agent\AgentConfig $config,
        public readonly \MageOS\ClaudeConsumerAgent\Model\Agent\SessionContext $context,
        public readonly \MageOS\ClaudeConsumerAgent\Model\Agent\SessionState $state,
        public array $notes = []
    ) {
    }
}
