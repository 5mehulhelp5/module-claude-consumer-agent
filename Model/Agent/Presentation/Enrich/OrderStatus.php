<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Model\Agent\Presentation\Enrich;

use MageOS\AiShoppingAssistant\Api\Data\OrderInterface;
use MageOS\AiShoppingAssistant\Model\Agent\Exception\PresentationRefused;
use MageOS\AiShoppingAssistant\Model\Agent\Presentation\EnrichmentContext;

class OrderStatus
{
    public function __construct(
        private readonly \MageOS\AiShoppingAssistant\Model\Agent\Serializer $serializer,
        private readonly \MageOS\AiShoppingAssistant\Api\Backend\GuestOrderLookupInterface $guestOrders
    ) {
    }

    public function __invoke(array $input, EnrichmentContext $ctx): array
    {
        $orderId = (string)($input['order_id'] ?? '');
        $order = $this->order($orderId, $ctx);
        if ($order === null) {
            throw new PresentationRefused("No order with id {$orderId}. Look it up first.");
        }
        $payload = [
            'order_id' => $orderId,
            'summary' => (string)($input['summary'] ?? ''),
        ];
        if (isset($input['next_step'])) {
            $payload['next_step'] = $input['next_step'];
        }
        $payload['order'] = $this->serializer->order($order, $ctx->context->now);
        return $payload;
    }

    private function order(string $orderId, EnrichmentContext $ctx): ?OrderInterface
    {
        if ($ctx->context->customerId !== null) {
            return $ctx->backend->getOrder($ctx->context, $orderId);
        }
        $email = $ctx->state->guestOrders[$orderId] ?? '';
        if ($email === '') {
            return null;
        }
        return $this->guestOrders->find($ctx->context, $orderId, $email);
    }
}
