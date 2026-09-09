<?php
declare(strict_types=1);

namespace MageOS\ClaudeConsumerAgent\Model\Agent\Tool\Handler;

use MageOS\ClaudeConsumerAgent\Api\Tool\HandlerInterface;
use MageOS\ClaudeConsumerAgent\Model\Agent\AgentConfig;
use MageOS\ClaudeConsumerAgent\Model\Agent\SessionContext;
use MageOS\ClaudeConsumerAgent\Model\Agent\SessionState;
use MageOS\ClaudeConsumerAgent\Model\Agent\ToolOutcome;
use MageOS\ClaudeConsumerAgent\Model\Data\Product;

final class GetProductDetails implements HandlerInterface
{
    public function __construct(
        private readonly \MageOS\ClaudeConsumerAgent\Api\StorefrontBackendInterface $backend,
        private readonly \MageOS\ClaudeConsumerAgent\Model\Agent\Serializer $serializer,
        private readonly \MageOS\ClaudeConsumerAgent\Model\Agent\Fencing\Fence $fence
    ) {
    }

    public function handle(array $input, SessionContext $context, SessionState $state, AgentConfig $config): ToolOutcome
    {
        $productId = (string)($input['product_id'] ?? '');
        $details = $this->backend->getProductDetails($context, $productId);
        if ($details === null) {
            return ToolOutcome::error("No product with product_id {$productId}. Search for it by name instead.");
        }
        $payload = $this->serializer->productDetails($details);
        $products = [Product::fromArray($details->toArray())->toArray()];
        foreach ($details->getVariants() as $variant) {
            $products[] = $variant->toArray();
        }
        $text = "Product details:\n" . $this->fence->fencePayload($payload, $config->maxFencedChars);
        return ToolOutcome::ok($text, [], $products);
    }
}
