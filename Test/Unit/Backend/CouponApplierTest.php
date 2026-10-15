<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Test\Unit\Backend;

use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Phrase;
use Magento\Quote\Api\CouponManagementInterface;
use Magento\SalesRule\Api\Exception\CodeRequestLimitException;
use MageOS\AiShoppingAssistant\Model\Agent\SessionContext;
use MageOS\AiShoppingAssistant\Model\Backend\CouponApplier;
use MageOS\AiShoppingAssistant\Model\Data\PageContext;
use PHPUnit\Framework\TestCase;

class CouponApplierTest extends TestCase
{
    private function context(): SessionContext
    {
        return new SessionContext('s-1', null, 42, 1, new PageContext(), new \DateTimeImmutable('now'));
    }

    public function testItSetsTheCodeOnTheSessionQuote(): void
    {
        $management = $this->createMock(CouponManagementInterface::class);
        $management->expects($this->once())->method('set')->with(42, 'SAVE20')->willReturn(true);
        (new CouponApplier($management))->apply($this->context(), 'SAVE20');
    }

    public function testTheRequestLimiterIsUnwrappedFromTheSaveError(): void
    {
        $limit = new CodeRequestLimitException(new Phrase('Too many coupon code requests.'));
        $management = $this->createMock(CouponManagementInterface::class);
        $management->method('set')->willThrowException(new CouldNotSaveException(new Phrase('x'), $limit));
        $this->expectException(CodeRequestLimitException::class);
        (new CouponApplier($management))->apply($this->context(), 'SAVE20');
    }

    public function testAnyOtherSaveErrorIsRethrown(): void
    {
        $management = $this->createMock(CouponManagementInterface::class);
        $management->method('set')->willThrowException(new CouldNotSaveException(new Phrase('x')));
        $this->expectException(CouldNotSaveException::class);
        (new CouponApplier($management))->apply($this->context(), 'SAVE20');
    }
}
