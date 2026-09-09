<?php
declare(strict_types=1);

namespace MageOS\ClaudeConsumerAgent\Model\Agent\Tool\Handler;

use MageOS\ClaudeConsumerAgent\Api\Data\OrderInterface;
use MageOS\ClaudeConsumerAgent\Api\Tool\HandlerInterface;
use MageOS\ClaudeConsumerAgent\Model\Agent\AgentConfig;
use MageOS\ClaudeConsumerAgent\Model\Agent\SessionContext;
use MageOS\ClaudeConsumerAgent\Model\Agent\SessionState;
use MageOS\ClaudeConsumerAgent\Model\Agent\ToolOutcome;

final class GetOrderStatus implements HandlerInterface
{
    public function __construct(
        private readonly \MageOS\ClaudeConsumerAgent\Api\StorefrontBackendInterface $backend,
        private readonly \MageOS\ClaudeConsumerAgent\Model\Agent\Serializer $serializer,
        private readonly \MageOS\ClaudeConsumerAgent\Model\Agent\Fencing\Fence $fence
    ) {
    }

    public function handle(array $input, SessionContext $context, SessionState $state, AgentConfig $config): ToolOutcome
    {
        $orderId = (string)($input['order_id'] ?? '');
        $order = $this->backend->getOrder($context, $orderId);
        if ($order === null) {
            return ToolOutcome::error("No order with id {$orderId} for this customer.");
        }
        $payload = $this->serializer->order($order);
        $text = "Order status:\n" . $this->fence->fencePayload($payload, $config->maxFencedChars);
        return ToolOutcome::ok($text, [], $this->orderItemsToProducts($order));
    }

    private function orderItemsToProducts(OrderInterface $order): array
    {
        $products = [];
        foreach ($order->getItems() as $item) {
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
        return $products;
    }
}
