<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Api\Cart;

use MageOS\AiShoppingAssistant\Model\Agent\SessionContext;

/**
 * Applies a discount code to the session's cart. Throws NoSuchEntityException when the
 * store does not accept the code and LocalizedException when it cannot be applied.
 */
interface CouponApplierInterface
{
    public function apply(SessionContext $ctx, string $code): void;
}
