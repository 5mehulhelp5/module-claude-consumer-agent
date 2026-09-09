<?php
declare(strict_types=1);

namespace MageOS\ClaudeConsumerAgent\Api\Tool;

use MageOS\ClaudeConsumerAgent\Model\Agent\AgentConfig;

interface ToolProviderInterface
{
    /**
     * @return \MageOS\ClaudeConsumerAgent\Model\Agent\Tool\Definition[]
     */
    public function getTools(AgentConfig $config): array;
}
