<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Model\Agent\Tool\Handler;

use MageOS\AiShoppingAssistant\Api\Data\OrderInterface;
use MageOS\AiShoppingAssistant\Api\Tool\HandlerInterface;
use MageOS\AiShoppingAssistant\Model\Agent\AgentConfig;
use MageOS\AiShoppingAssistant\Model\Agent\SessionContext;
use MageOS\AiShoppingAssistant\Model\Agent\SessionState;
use MageOS\AiShoppingAssistant\Model\Agent\ToolOutcome;

class GetOrderStatus implements HandlerInterface
{
    public const GUEST_GATE = 'guest_order';

    public const GUEST_FAILURE_CAP = 5;

    public const GUEST_NO_MATCH = 'No order matches that order number and email.';

    public function __construct(
        private readonly \MageOS\AiShoppingAssistant\Api\StorefrontBackendInterface $backend,
        private readonly \MageOS\AiShoppingAssistant\Model\Agent\Serializer $serializer,
        private readonly \MageOS\AiShoppingAssistant\Model\Agent\Fencing\Fence $fence,
        private readonly \MageOS\AiShoppingAssistant\Api\Backend\GuestOrderLookupInterface $guestOrders
    ) {
    }

    public function handle(array $input, SessionContext $context, SessionState $state, AgentConfig $config): ToolOutcome
    {
        if ($context->customerId === null) {
            return $this->guest($input, $context, $state, $config);
        }
        $orderId = (string)($input['order_id'] ?? '');
        $order = $this->backend->getOrder($context, $orderId);
        if ($order === null) {
            return ToolOutcome::error("No order with id {$orderId} for this customer.");
        }
        return $this->found($order, $context, $config);
    }

    private function guest(array $input, SessionContext $context, SessionState $state, AgentConfig $config): ToolOutcome
    {
        $orderId = trim((string)($input['order_id'] ?? ''));
        $email = trim((string)($input['email'] ?? ''));
        if ($email === '') {
            return ToolOutcome::held(
                self::GUEST_GATE,
                'Nothing was looked up: the customer is not signed in. Ask for the order number and the email '
                . 'address used at checkout, then call this again with both. An order placed while signed in to '
                . 'an account needs sign-in.'
            );
        }
        if (!$this->customerTyped($state, $email)) {
            return ToolOutcome::held(
                self::GUEST_GATE,
                'Nothing was looked up: the customer has not typed this email address in this conversation. Ask '
                . 'them for the email address they used at checkout; never guess or reuse one.'
            );
        }
        if ($state->guestOrderFailures >= self::GUEST_FAILURE_CAP) {
            return ToolOutcome::error(
                'Nothing was looked up: ' . self::GUEST_FAILURE_CAP . ' order lookups found no match in this '
                . 'conversation, so no more are tried here. Point the customer to the store\'s contact page or '
                . 'customer service.'
            );
        }
        $order = $this->guestOrders->find($context, $orderId, $email);
        if ($order === null) {
            $state->guestOrderFailures++;
            return ToolOutcome::error(
                self::GUEST_NO_MATCH . ' Ask the customer to check both. If they placed the order while signed '
                . 'in to an account, they need to sign in to see it.'
            );
        }
        return $this->found($order, $context, $config);
    }

    private function found(OrderInterface $order, SessionContext $context, AgentConfig $config): ToolOutcome
    {
        $payload = $this->serializer->order($order, $context->now);
        $text = "Order status:\n" . $this->fence->fencePayload($payload, $config->maxFencedChars);
        return ToolOutcome::ok($text, [], $this->orderItemsToProducts($order));
    }

    private function customerTyped(SessionState $state, string $email): bool
    {
        $pattern = '/(?<![A-Za-z0-9._%+-])' . preg_quote($email, '/') . '(?![A-Za-z0-9_%+-]|\.[A-Za-z0-9])/iu';
        foreach ($state->recentCustomerText as $text) {
            if (preg_match($pattern, (string)$text) === 1) {
                return true;
            }
        }
        return false;
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
