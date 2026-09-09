<?php
declare(strict_types=1);

namespace MageOS\ClaudeConsumerAgent\Model\Agent\Tool\Handler;

use MageOS\ClaudeConsumerAgent\Api\Tool\HandlerInterface;
use MageOS\ClaudeConsumerAgent\Model\Agent\AgentConfig;
use MageOS\ClaudeConsumerAgent\Model\Agent\SessionContext;
use MageOS\ClaudeConsumerAgent\Model\Agent\SessionState;
use MageOS\ClaudeConsumerAgent\Model\Agent\ToolOutcome;

final class LoadSkill implements HandlerInterface
{
    public function __construct(
        private readonly \MageOS\ClaudeConsumerAgent\Model\Agent\Skill\Registry $registry
    ) {
    }

    public function handle(array $input, SessionContext $context, SessionState $state, AgentConfig $config): ToolOutcome
    {
        $skillName = (string)($input['skill_name'] ?? '');
        $body = $this->registry->body($skillName);
        if ($body === null) {
            return ToolOutcome::error(
                "No skill named {$skillName}. Available: " . implode(', ', $this->registry->names()) . '.'
            );
        }
        return ToolOutcome::ok($body);
    }
}
