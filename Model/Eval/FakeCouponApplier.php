<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Model\Eval;

use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Phrase;
use MageOS\AiShoppingAssistant\Api\Cart\CouponApplierInterface;
use MageOS\AiShoppingAssistant\Model\Agent\SessionContext;

/**
 * Fixture mode has no cart rules, so the store turns down every code.
 */
class FakeCouponApplier implements CouponApplierInterface
{
    public function apply(SessionContext $ctx, string $code): void
    {
        throw new NoSuchEntityException(new Phrase('The coupon code is not valid.'));
    }
}
