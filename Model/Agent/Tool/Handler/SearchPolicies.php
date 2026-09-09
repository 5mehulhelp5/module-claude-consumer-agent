<?php
declare(strict_types=1);

namespace MageOS\ClaudeConsumerAgent\Model\Agent\Tool\Handler;

use MageOS\ClaudeConsumerAgent\Api\Tool\HandlerInterface;
use MageOS\ClaudeConsumerAgent\Model\Agent\AgentConfig;
use MageOS\ClaudeConsumerAgent\Model\Agent\SessionContext;
use MageOS\ClaudeConsumerAgent\Model\Agent\SessionState;
use MageOS\ClaudeConsumerAgent\Model\Agent\ToolOutcome;

final class SearchPolicies implements HandlerInterface
{
    public function __construct(
        private readonly \MageOS\ClaudeConsumerAgent\Api\StorefrontBackendInterface $backend,
        private readonly \MageOS\ClaudeConsumerAgent\Model\Agent\Serializer $serializer,
        private readonly \MageOS\ClaudeConsumerAgent\Model\Agent\Fencing\Fence $fence
    ) {
    }

    public function handle(array $input, SessionContext $context, SessionState $state, AgentConfig $config): ToolOutcome
    {
        $query = (string)($input['query'] ?? '');
        $policies = $this->backend->searchPolicies($context, $query);
        $payload = $this->serializer->policies($policies);
        $text = "Policies:\n" . $this->fence->fencePayload($payload, $config->maxFencedChars);
        return ToolOutcome::ok($text);
    }
}
