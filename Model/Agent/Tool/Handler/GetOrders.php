<?php
declare(strict_types=1);

namespace MageOS\ClaudeConsumerAgent\Model\Agent\Tool\Handler;

use MageOS\ClaudeConsumerAgent\Api\Data\OrderInterface;
use MageOS\ClaudeConsumerAgent\Api\Data\OrderItemInterface;
use MageOS\ClaudeConsumerAgent\Api\Tool\HandlerInterface;
use MageOS\ClaudeConsumerAgent\Model\Agent\AgentConfig;
use MageOS\ClaudeConsumerAgent\Model\Agent\SessionContext;
use MageOS\ClaudeConsumerAgent\Model\Agent\SessionState;
use MageOS\ClaudeConsumerAgent\Model\Agent\ToolOutcome;

final class GetOrders implements HandlerInterface
{
    private const DEFAULT_LIMIT = 5;
    private const MAX_LIMIT = 20;

    public function __construct(
        private readonly \MageOS\ClaudeConsumerAgent\Api\StorefrontBackendInterface $backend,
        private readonly \MageOS\ClaudeConsumerAgent\Model\Agent\Serializer $serializer,
        private readonly \MageOS\ClaudeConsumerAgent\Model\Agent\Fencing\Fence $fence
    ) {
    }

    public function handle(array $input, SessionContext $context, SessionState $state, AgentConfig $config): ToolOutcome
    {
        $limit = max(1, min((int)($input['limit'] ?? self::DEFAULT_LIMIT), self::MAX_LIMIT));
        $orders = $this->backend->getOrders($context, $limit);
        $payload = $this->serializer->orders($orders);
        $text = "Order history:\n" . $this->fence->fencePayload($payload, $config->maxFencedChars);
        return ToolOutcome::ok($text, [], $this->orderItemsToProducts($orders));
    }

    private function orderItemsToProducts(array $orders): array
    {
        $products = [];
        foreach ($orders as $order) {
            if (!$order instanceof OrderInterface) {
                continue;
            }
            foreach ($order->getItems() as $item) {
                if (!$item instanceof OrderItemInterface) {
                    continue;
                }
                $products[] = [
                    'product_id' => $item->getProductId(),
                    'title' => $item->getTitle(),
                    'price' => $item->getPrice(),
                    'currency' => $order->getCurrency(),
                    'option_values' => $item->getOptionValues(),
                    'variant_of' => $item->getVariantOf(),
                    'in_stock' => true,
                ];
            }
        }
        return $products;
    }
}
