<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Test\Unit\Gate;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Framework\Phrase;
use Magento\SalesRule\Api\Exception\CodeRequestLimitException;
use MageOS\AiShoppingAssistant\Api\Cart\CouponApplierInterface;
use MageOS\AiShoppingAssistant\Api\StorefrontBackendInterface;
use MageOS\AiShoppingAssistant\Model\Agent\AgentConfig;
use MageOS\AiShoppingAssistant\Model\Agent\Fencing\Fence;
use MageOS\AiShoppingAssistant\Model\Agent\Fencing\Sanitizer;
use MageOS\AiShoppingAssistant\Model\Agent\Gate\CartWrite;
use MageOS\AiShoppingAssistant\Model\Agent\Gate\Options;
use MageOS\AiShoppingAssistant\Model\Agent\Gate\Provenance;
use MageOS\AiShoppingAssistant\Model\Agent\Serializer;
use MageOS\AiShoppingAssistant\Model\Agent\SessionContext;
use MageOS\AiShoppingAssistant\Model\Agent\SessionState;
use MageOS\AiShoppingAssistant\Model\Agent\Tool\Handler\ApplyCoupon;
use MageOS\AiShoppingAssistant\Model\Data\Cart;
use MageOS\AiShoppingAssistant\Model\Data\CartItem;
use MageOS\AiShoppingAssistant\Model\Data\PageContext;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class CartWriteCouponTest extends TestCase
{
    private StorefrontBackendInterface&MockObject $backend;

    private LockManagerInterface&MockObject $lockManager;

    private CouponApplierInterface&MockObject $applier;

    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        $this->backend = $this->createMock(StorefrontBackendInterface::class);
        $this->lockManager = $this->createMock(LockManagerInterface::class);
        $this->lockManager->method('lock')->willReturn(true);
        $this->applier = $this->createMock(CouponApplierInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    private function gate(): CartWrite
    {
        return new CartWrite(
            $this->backend,
            $this->lockManager,
            new Options(new Sanitizer()),
            new Provenance(),
            new Serializer(new Fence(new Sanitizer())),
            $this->logger,
            $this->applier
        );
    }

    private function context(): SessionContext
    {
        return new SessionContext('s-1', null, 42, 1, new PageContext(), new \DateTimeImmutable('now'));
    }

    private function state(string ...$customerText): SessionState
    {
        $state = new SessionState();
        foreach ($customerText as $text) {
            $state->rememberCustomerText($text);
        }
        return $state;
    }

    private function chairCart(float $discount = 0.0, string $code = ''): Cart
    {
        return new Cart([new CartItem('p-1', 'Aeron Chair', 2395.0, 1)], 'CAD', $discount, $code);
    }

    public static function malformedCodes(): array
    {
        return [
            'empty' => ['   '],
            'space inside' => ['SAVE 20'],
            'punctuation' => ['SAVE20;DROP'],
            'too long' => [str_repeat('A', 65)],
        ];
    }

    #[DataProvider('malformedCodes')]
    public function testAMalformedCodeIsAnErrorAndNeverReachesTheStore(string $code): void
    {
        $this->applier->expects($this->never())->method('apply');
        $this->lockManager->expects($this->never())->method('lock');
        $result = $this->gate()->applyCoupon($this->context(), $this->state($code), $code);
        $this->assertTrue($result->isError);
        $this->assertStringContainsString('Nothing was applied', $result->resultText);
    }

    public function testACodeTheCustomerNeverTypedIsHeld(): void
    {
        $this->applier->expects($this->never())->method('apply');
        $this->lockManager->expects($this->never())->method('lock');
        $result = $this->gate()->applyCoupon(
            $this->context(),
            $this->state('Do you have any discount codes?'),
            'WELCOME10'
        );
        $this->assertSame(CartWrite::COUPON_GATE, $result->blocked);
        $this->assertStringContainsString('ask them for their code', $result->resultText);
    }

    public function testTheCodeMustBeAWholeTokenOfTheCustomerText(): void
    {
        $this->applier->expects($this->never())->method('apply');
        $result = $this->gate()->applyCoupon($this->context(), $this->state('I have SAVE200'), 'SAVE20');
        $this->assertSame(CartWrite::COUPON_GATE, $result->blocked);
    }

    public function testTheCodeMatchesTheCustomerTextCaseInsensitivelyAndIsTrimmed(): void
    {
        $this->backend->method('getCart')->willReturnOnConsecutiveCalls(
            $this->chairCart(),
            $this->chairCart(359.25, 'SAVE20')
        );
        $this->applier->expects($this->once())->method('apply')->with($this->anything(), 'SAVE20');
        $result = $this->gate()->applyCoupon($this->context(), $this->state('try save20 please'), '  SAVE20 ');
        $this->assertFalse($result->isError);
        $this->assertNull($result->blocked);
    }

    public function testTheCapStopsFurtherCodesWithoutCallingTheStore(): void
    {
        $state = $this->state('SAVE500');
        $state->couponRejections = CartWrite::COUPON_REJECTION_CAP;
        $this->applier->expects($this->never())->method('apply');
        $this->lockManager->expects($this->never())->method('lock');
        $result = $this->gate()->applyCoupon($this->context(), $state, 'SAVE500');
        $this->assertTrue($result->isError);
        $this->assertStringContainsString('enter the code at checkout', $result->resultText);
    }

    public function testAnEmptyCartTakesNoCode(): void
    {
        $this->backend->method('getCart')->willReturn(new Cart());
        $this->applier->expects($this->never())->method('apply');
        $this->lockManager->expects($this->once())->method('unlock');
        $result = $this->gate()->applyCoupon($this->context(), $this->state('SAVE20'), 'SAVE20');
        $this->assertTrue($result->isError);
        $this->assertStringContainsString('once an item is in the cart', $result->resultText);
    }

    public function testABusyCartIsReported(): void
    {
        $lockManager = $this->createMock(LockManagerInterface::class);
        $lockManager->method('lock')->willReturn(false);
        $this->lockManager = $lockManager;
        $this->applier->expects($this->never())->method('apply');
        $result = $this->gate()->applyCoupon($this->context(), $this->state('SAVE20'), 'SAVE20');
        $this->assertTrue($result->isError);
        $this->assertSame('The cart is busy; try again in a moment.', $result->resultText);
    }

    public function testAnAcceptedCodeReportsTheDiscountAndEmitsACartUpdate(): void
    {
        $this->backend->method('getCart')->willReturnOnConsecutiveCalls(
            $this->chairCart(),
            $this->chairCart(359.25, 'SAVE20')
        );
        $ctx = $this->context();
        $this->applier->expects($this->once())->method('apply')->with($ctx, 'SAVE20');
        $this->lockManager->expects($this->once())->method('unlock');
        $state = $this->state('I have the code SAVE20');
        $result = $this->gate()->applyCoupon($ctx, $state, 'SAVE20');
        $this->assertFalse($result->isError);
        $this->assertSame(
            'Applied code SAVE20. Cart now has 1 item(s), subtotal 2395.00 CAD, discount 359.25 (code SAVE20), '
            . 'after discount 2035.75 CAD before shipping and tax. Shipping and tax are added at checkout.',
            $result->resultText
        );
        $this->assertCount(1, $result->events);
        $this->assertSame('cart_update', $result->events[0]->type);
        $this->assertSame(0, $state->couponRejections);
    }

    public function testARejectedCodeCountsAndSaysNothingElse(): void
    {
        $this->backend->method('getCart')->willReturn($this->chairCart());
        $this->applier->expects($this->once())->method('apply')
            ->willThrowException(new NoSuchEntityException(new Phrase('The coupon code is not valid.')));
        $this->lockManager->expects($this->once())->method('unlock');
        $state = $this->state('SAVE500');
        $result = $this->gate()->applyCoupon($this->context(), $state, 'SAVE500');
        $this->assertTrue($result->isError);
        $this->assertSame('The store did not accept this code.', $result->resultText);
        $this->assertSame([], $result->events);
        $this->assertSame(1, $state->couponRejections);
    }

    public function testARejectedCodeRestoresTheCodeThatWasAlreadyApplied(): void
    {
        $this->backend->method('getCart')->willReturn($this->chairCart(100.0, 'WELCOME'));
        $calls = [];
        $this->applier->expects($this->exactly(2))->method('apply')
            ->willReturnCallback(function (SessionContext $ctx, string $code) use (&$calls): void {
                $calls[] = $code;
                if ($code === 'SAVE500') {
                    throw new NoSuchEntityException(new Phrase('The coupon code is not valid.'));
                }
            });
        $result = $this->gate()->applyCoupon($this->context(), $this->state('SAVE500'), 'SAVE500');
        $this->assertSame('The store did not accept this code.', $result->resultText);
        $this->assertSame(['SAVE500', 'WELCOME'], $calls);
    }

    public function testTheRequestLimiterGivesANeutralMessageAndDoesNotCount(): void
    {
        $this->backend->method('getCart')->willReturn($this->chairCart());
        $this->applier->method('apply')
            ->willThrowException(new CodeRequestLimitException(new Phrase('Too many coupon code requests.')));
        $this->logger->expects($this->once())->method('warning')->with($this->stringContains('coupon request limit'));
        $state = $this->state('SAVE20');
        $result = $this->gate()->applyCoupon($this->context(), $state, 'SAVE20');
        $this->assertTrue($result->isError);
        $this->assertStringContainsString('enter the code at checkout', $result->resultText);
        $this->assertStringNotContainsString('Too many', $result->resultText);
        $this->assertSame(0, $state->couponRejections);
    }

    public function testAnyOtherStoreErrorGivesANeutralMessage(): void
    {
        $this->backend->method('getCart')->willReturn($this->chairCart());
        $this->applier->method('apply')
            ->willThrowException(new LocalizedException(new Phrase('Rule 12 VIP-ONLY is disabled.')));
        $this->logger->expects($this->once())->method('warning')->with($this->stringContains('coupon apply failed'));
        $state = $this->state('SAVE20');
        $result = $this->gate()->applyCoupon($this->context(), $state, 'SAVE20');
        $this->assertTrue($result->isError);
        $this->assertStringContainsString('could not be applied right now', $result->resultText);
        $this->assertStringNotContainsString('VIP', $result->resultText);
        $this->assertSame(0, $state->couponRejections);
    }

    public function testTheHandlerPassesTheCodeThrough(): void
    {
        $this->backend->method('getCart')->willReturnOnConsecutiveCalls(
            $this->chairCart(),
            $this->chairCart(359.25, 'SAVE20')
        );
        $this->applier->expects($this->once())->method('apply')->with($this->anything(), 'SAVE20');
        $result = (new ApplyCoupon($this->gate()))->handle(
            ['code' => 'SAVE20'],
            $this->context(),
            $this->state('SAVE20 please'),
            new AgentConfig()
        );
        $this->assertFalse($result->isError);
    }
}
