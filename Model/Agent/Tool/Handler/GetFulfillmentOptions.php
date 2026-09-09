<?php
declare(strict_types=1);

namespace MageOS\ClaudeConsumerAgent\Model\Agent\Tool\Handler;

use MageOS\ClaudeConsumerAgent\Api\Tool\HandlerInterface;
use MageOS\ClaudeConsumerAgent\Model\Agent\AgentConfig;
use MageOS\ClaudeConsumerAgent\Model\Agent\SessionContext;
use MageOS\ClaudeConsumerAgent\Model\Agent\SessionState;
use MageOS\ClaudeConsumerAgent\Model\Agent\ToolOutcome;

final class GetFulfillmentOptions implements HandlerInterface
{
    private const MAX_PRODUCT_IDS = 20;

    public function __construct(
        private readonly \MageOS\ClaudeConsumerAgent\Api\StorefrontBackendInterface $backend,
        private readonly \MageOS\ClaudeConsumerAgent\Model\Agent\Serializer $serializer,
        private readonly \MageOS\ClaudeConsumerAgent\Model\Agent\Fencing\Fence $fence
    ) {
    }

    public function handle(array $input, SessionContext $context, SessionState $state, AgentConfig $config): ToolOutcome
    {
        $rawIds = is_array($input['product_ids'] ?? null) ? $input['product_ids'] : [];
        $productIds = array_slice(array_map('strval', $rawIds), 0, self::MAX_PRODUCT_IDS);
        $options = $this->backend->getFulfillmentOptions($context, $productIds);
        $payload = $this->serializer->fulfillment($options);
        $text = "Fulfillment options:\n" . $this->fence->fencePayload($payload, $config->maxFencedChars);
        return ToolOutcome::ok($text);
    }
}
