<?php
declare(strict_types=1);

namespace MageOS\ClaudeConsumerAgent\Api\Tool;

use MageOS\ClaudeConsumerAgent\Model\Agent\AgentConfig;
use MageOS\ClaudeConsumerAgent\Model\Agent\SessionContext;
use MageOS\ClaudeConsumerAgent\Model\Agent\SessionState;
use MageOS\ClaudeConsumerAgent\Model\Agent\ToolOutcome;

interface HandlerInterface
{
    public function handle(array $input, SessionContext $context, SessionState $state, AgentConfig $config): ToolOutcome;
}
