<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Model\Backend;

use Magento\Framework\Exception\CouldNotSaveException;
use Magento\SalesRule\Api\Exception\CodeRequestLimitException;
use MageOS\AiShoppingAssistant\Api\Cart\CouponApplierInterface;
use MageOS\AiShoppingAssistant\Model\Agent\SessionContext;

class CouponApplier implements CouponApplierInterface
{
    public function __construct(
        private readonly \Magento\Quote\Api\CouponManagementInterface $couponManagement
    ) {
    }

    public function apply(SessionContext $ctx, string $code): void
    {
        try {
            $this->couponManagement->set($ctx->quoteId, $code);
        } catch (CouldNotSaveException $exception) {
            $previous = $exception->getPrevious();
            if ($previous instanceof CodeRequestLimitException) {
                throw $previous;
            }
            throw $exception;
        }
    }
}
