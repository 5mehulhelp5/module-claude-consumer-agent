<?php
declare(strict_types=1);

namespace MageOS\ClaudeConsumerAgent\Model\Agent\Tool\Handler;

use MageOS\ClaudeConsumerAgent\Api\Tool\HandlerInterface;
use MageOS\ClaudeConsumerAgent\Model\Agent\AgentConfig;
use MageOS\ClaudeConsumerAgent\Model\Agent\SessionContext;
use MageOS\ClaudeConsumerAgent\Model\Agent\SessionState;
use MageOS\ClaudeConsumerAgent\Model\Agent\ToolOutcome;

final class AddToCart implements HandlerInterface
{
    public function __construct(
        private readonly \MageOS\ClaudeConsumerAgent\Model\Agent\Gate\CartWrite $cartWrite
    ) {
    }

    public function handle(array $input, SessionContext $context, SessionState $state, AgentConfig $config): ToolOutcome
    {
        return $this->cartWrite->add(
            $context,
            $state,
            $config,
            (string)($input['product_id'] ?? ''),
            (int)($input['quantity'] ?? 1),
            is_array($input['options'] ?? null) ? $input['options'] : []
        );
    }
}
