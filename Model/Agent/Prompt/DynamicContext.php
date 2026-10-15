<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Model\Agent\Prompt;

use MageOS\AiShoppingAssistant\Api\Data\CartInterface;
use MageOS\AiShoppingAssistant\Api\Data\PageContextInterface;
use MageOS\AiShoppingAssistant\Api\Data\UserPreferencesInterface;

class DynamicContext
{
    public const PREFIX = '# Session context';

    private const MAX_CHARS = 6000;

    public function __construct(
        private readonly \MageOS\AiShoppingAssistant\Model\Agent\Fencing\Fence $fence
    ) {
    }

    public function build(
        ?UserPreferencesInterface $prefs,
        ?CartInterface $cart,
        PageContextInterface $page,
        string $hourIso
    ): string {
        $payload = [];
        if ($prefs !== null) {
            $payload['customer'] = [
                'name' => $prefs->getDisplayName(),
                'loyalty_tier' => $prefs->getLoyaltyTier(),
                'location' => $prefs->getDefaultLocation(),
                'preferences' => $prefs->getPreferences(),
            ];
        }
        $payload['saved_memory'] = 'none';
        if ($cart !== null) {
            $payload['cart'] = [
                'item_count' => $cart->getItemCount(),
                'subtotal' => $cart->getSubtotal(),
            ] + $this->cartDiscount($cart) + [
                'items' => $this->cartItems($cart),
            ];
        }
        $payload['current_page'] = $page->toArray();
        $payload['local_time'] = $hourIso;

        return self::PREFIX . "\n\n" . $this->fence->fencePayload($payload, self::MAX_CHARS);
    }

    private function cartDiscount(CartInterface $cart): array
    {
        if ($cart->getDiscount() <= 0.0 && $cart->getCouponCode() === '') {
            return [];
        }
        return [
            'discount' => $cart->getDiscount(),
            'subtotal_after_discount' => $cart->getSubtotalAfterDiscount(),
            'coupon_code' => $cart->getCouponCode(),
            'shipping_and_tax' => 'not included, added at checkout',
        ];
    }

    private function cartItems(CartInterface $cart): array
    {
        $items = [];
        foreach ($cart->getItems() as $item) {
            $row = [
                'product_id' => $item->getProductId(),
                'title' => $item->getTitle(),
                'quantity' => $item->getQuantity(),
            ];
            if ($item->getOptionValues() !== []) {
                $row['option_values'] = $item->getOptionValues();
            }
            $items[] = $row;
        }
        return $items;
    }
}
