<?php
declare(strict_types=1);

namespace MageOS\ClaudeConsumerAgent\Model\Agent\Presentation\Enrich;

use MageOS\ClaudeConsumerAgent\Model\Agent\Exception\PresentationRefused;
use MageOS\ClaudeConsumerAgent\Model\Agent\Presentation\EnrichmentContext;

final class Checkout
{
    public function __construct(
        private readonly \MageOS\ClaudeConsumerAgent\Model\Agent\Serializer $serializer
    ) {
    }

    public function __invoke(array $input, EnrichmentContext $ctx): array
    {
        $cart = $ctx->backend->getCart($ctx->context);
        if ($cart->isEmpty()) {
            throw new PresentationRefused('The cart is empty, nothing to check out.');
        }
        $payload = [];
        if (isset($input['note'])) {
            $payload['note'] = $input['note'];
        }
        if (isset($input['fulfillment_method'])) {
            $payload['fulfillment_method'] = $input['fulfillment_method'];
        }
        $payload['cart'] = $this->serializer->cart($cart);
        $payload['checkout_url'] = '/checkout';
        return $payload;
    }
}
