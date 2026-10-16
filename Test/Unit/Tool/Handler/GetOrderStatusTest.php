<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Test\Unit\Tool\Handler;

use MageOS\AiShoppingAssistant\Api\Backend\GuestOrderLookupInterface;
use MageOS\AiShoppingAssistant\Api\StorefrontBackendInterface;
use MageOS\AiShoppingAssistant\Model\Agent\AgentConfig;
use MageOS\AiShoppingAssistant\Model\Agent\Fencing\Fence;
use MageOS\AiShoppingAssistant\Model\Agent\Fencing\Sanitizer;
use MageOS\AiShoppingAssistant\Model\Agent\Serializer;
use MageOS\AiShoppingAssistant\Model\Agent\SessionContext;
use MageOS\AiShoppingAssistant\Model\Agent\SessionState;
use MageOS\AiShoppingAssistant\Model\Agent\Tool\Handler\GetOrderStatus;
use MageOS\AiShoppingAssistant\Model\Data\Order;
use MageOS\AiShoppingAssistant\Model\Data\PageContext;
use MageOS\AiShoppingAssistant\Model\Eval\FakeBackend;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class GetOrderStatusTest extends TestCase
{
    private const NOW = '2026-10-16T12:00:00+00:00';

    private function context(?int $customerId = null): SessionContext
    {
        return new SessionContext('sess-1', $customerId, 1, 1, PageContext::fromArray([]), new \DateTimeImmutable(self::NOW));
    }

    private function handler(
        StorefrontBackendInterface $backend,
        GuestOrderLookupInterface $guestOrders
    ): GetOrderStatus {
        $fence = new Fence(new Sanitizer());
        return new GetOrderStatus($backend, new Serializer($fence), $fence, $guestOrders);
    }

    private function order(string $orderId = '1000077284'): Order
    {
        return Order::fromArray([
            'order_id' => $orderId,
            'status' => 'shipped',
            'placed_at' => '2026-10-13T12:00:00+00:00',
            'total' => 158.0,
            'currency' => 'USD',
            'items' => [['product_id' => 'p-100', 'title' => 'Tent', 'quantity' => 1, 'price' => 149.0]],
        ]);
    }

    private function state(string ...$texts): SessionState
    {
        $state = new SessionState();
        foreach ($texts as $text) {
            $state->rememberCustomerText($text);
        }
        return $state;
    }

    private function noBackend(): StorefrontBackendInterface&MockObject
    {
        $backend = $this->createMock(StorefrontBackendInterface::class);
        $backend->expects($this->never())->method('getOrder');
        return $backend;
    }

    private function guestBackend(): FakeBackend
    {
        return new FakeBackend([], [], [
            [
                'order_id' => '1000077284',
                'status' => 'shipped',
                'placed_at' => '2026-10-13T12:00:00+00:00',
                'total' => 158.0,
                'currency' => 'USD',
                'items' => [['product_id' => 'p-100', 'title' => 'Tent', 'quantity' => 1, 'price' => 149.0]],
                'customer_is_guest' => true,
                'customer_email' => 'Jane.Doe@example.com',
            ],
            [
                'order_id' => '1000077636',
                'status' => 'processing',
                'placed_at' => '2026-10-14T12:00:00+00:00',
                'total' => 99.0,
                'currency' => 'USD',
                'items' => [],
                'customer_is_guest' => false,
                'customer_email' => 'member@example.com',
            ],
        ]);
    }

    public function testASignedInCustomerIsLookedUpThroughTheBackendAsBefore(): void
    {
        $backend = $this->createMock(StorefrontBackendInterface::class);
        $backend->expects($this->once())->method('getOrder')->with($this->anything(), '1000077284')->willReturn($this->order());
        $guestOrders = $this->createMock(GuestOrderLookupInterface::class);
        $guestOrders->expects($this->never())->method('find');

        $outcome = $this->handler($backend, $guestOrders)->handle(
            ['order_id' => '1000077284', 'email' => 'jane.doe@example.com'],
            $this->context(42),
            new SessionState(),
            new AgentConfig()
        );

        $this->assertFalse($outcome->isError);
        $this->assertStringStartsWith("Order status:\n", $outcome->resultText);
    }

    public function testASignedInCustomerWithAnUnknownOrderGetsTheExistingError(): void
    {
        $backend = $this->createMock(StorefrontBackendInterface::class);
        $backend->method('getOrder')->willReturn(null);

        $outcome = $this->handler($backend, $this->createMock(GuestOrderLookupInterface::class))->handle(
            ['order_id' => '42'],
            $this->context(42),
            new SessionState(),
            new AgentConfig()
        );

        $this->assertTrue($outcome->isError);
        $this->assertSame('No order with id 42 for this customer.', $outcome->resultText);
    }

    public function testAGuestOrderMatchesItsNumberAndTheEmailTheCustomerTyped(): void
    {
        $fake = $this->guestBackend();
        $state = $this->state('my order is 1000077284 and my email is JANE.doe@example.com.');

        $outcome = $this->handler($this->noBackend(), $fake)->handle(
            ['order_id' => '1000077284', 'email' => ' jane.doe@example.com '],
            $this->context(),
            $state,
            new AgentConfig()
        );

        $this->assertFalse($outcome->isError);
        $this->assertNull($outcome->blocked);
        $this->assertStringStartsWith("Order status:\n", $outcome->resultText);
        $this->assertStringContainsString('1000077284', $outcome->resultText);
        $this->assertStringContainsString('"placed_hours_ago":72', $outcome->resultText);
        $this->assertStringContainsString('"placed_days_ago":3', $outcome->resultText);
        $this->assertSame('p-100', $outcome->products[0]['product_id']);
        $this->assertSame(0, $state->guestOrderFailures);
    }

    public function testAWrongEmailGetsTheUniformFailureAndCounts(): void
    {
        $state = $this->state('1000077284, other@example.com');

        $outcome = $this->handler($this->noBackend(), $this->guestBackend())->handle(
            ['order_id' => '1000077284', 'email' => 'other@example.com'],
            $this->context(),
            $state,
            new AgentConfig()
        );

        $this->assertTrue($outcome->isError);
        $this->assertStringStartsWith(GetOrderStatus::GUEST_NO_MATCH, $outcome->resultText);
        $this->assertSame(1, $state->guestOrderFailures);
    }

    public function testARegisteredCustomersOrderIsNeverReturnedToAGuest(): void
    {
        $state = $this->state('order 1000077636 email member@example.com');

        $outcome = $this->handler($this->noBackend(), $this->guestBackend())->handle(
            ['order_id' => '1000077636', 'email' => 'member@example.com'],
            $this->context(),
            $state,
            new AgentConfig()
        );

        $this->assertTrue($outcome->isError);
        $this->assertStringNotContainsString('processing', $outcome->resultText);
        $this->assertStringContainsString('sign in', $outcome->resultText);
        $this->assertSame([], $outcome->products);
    }

    public function testTheFailureTextIsTheSameWhetherOrNotTheNumberExists(): void
    {
        $handler = $this->handler($this->noBackend(), $this->guestBackend());
        $texts = [];
        foreach (['1000077284', '1000077636', '9999999999'] as $orderId) {
            $state = $this->state($orderId . ' wrong@example.com');
            $texts[] = $handler->handle(
                ['order_id' => $orderId, 'email' => 'wrong@example.com'],
                $this->context(),
                $state,
                new AgentConfig()
            )->resultText;
        }

        $this->assertCount(1, array_unique($texts));
        $this->assertStringNotContainsString('1000077', $texts[0]);
    }

    public function testAGuestWithoutAnEmailIsAskedForTheNumberAndTheCheckoutEmail(): void
    {
        $guestOrders = $this->createMock(GuestOrderLookupInterface::class);
        $guestOrders->expects($this->never())->method('find');
        $state = $this->state('where is order 1000077284?');

        $outcome = $this->handler($this->noBackend(), $guestOrders)->handle(
            ['order_id' => '1000077284'],
            $this->context(),
            $state,
            new AgentConfig()
        );

        $this->assertFalse($outcome->isError);
        $this->assertSame(GetOrderStatus::GUEST_GATE, $outcome->blocked);
        $this->assertStringContainsString('order number and the email address used at checkout', $outcome->resultText);
        $this->assertSame(0, $state->guestOrderFailures);
    }

    public function testAnEmailTheCustomerDidNotTypeIsHeld(): void
    {
        $guestOrders = $this->createMock(GuestOrderLookupInterface::class);
        $guestOrders->expects($this->never())->method('find');
        $state = $this->state('order 1000077284, my email is jane.doe@example.co');

        $outcome = $this->handler($this->noBackend(), $guestOrders)->handle(
            ['order_id' => '1000077284', 'email' => 'jane.doe@example.com'],
            $this->context(),
            $state,
            new AgentConfig()
        );

        $this->assertSame(GetOrderStatus::GUEST_GATE, $outcome->blocked);
        $this->assertStringContainsString('has not typed this email address', $outcome->resultText);
        $this->assertSame(0, $state->guestOrderFailures);
    }

    public function testLookupsStopAfterTheCapAndPointToTheStoreContact(): void
    {
        $guestOrders = $this->createMock(GuestOrderLookupInterface::class);
        $guestOrders->expects($this->exactly(GetOrderStatus::GUEST_FAILURE_CAP))->method('find')->willReturn(null);
        $handler = $this->handler($this->noBackend(), $guestOrders);
        $state = $this->state('jane.doe@example.com');

        for ($attempt = 1; $attempt <= GetOrderStatus::GUEST_FAILURE_CAP; $attempt++) {
            $handler->handle(
                ['order_id' => (string)(1000000000 + $attempt), 'email' => 'jane.doe@example.com'],
                $this->context(),
                $state,
                new AgentConfig()
            );
        }
        $outcome = $handler->handle(
            ['order_id' => '1000077284', 'email' => 'jane.doe@example.com'],
            $this->context(),
            $state,
            new AgentConfig()
        );

        $this->assertSame(GetOrderStatus::GUEST_FAILURE_CAP, $state->guestOrderFailures);
        $this->assertTrue($outcome->isError);
        $this->assertStringContainsString('no more are tried', $outcome->resultText);
        $this->assertStringContainsString('contact', $outcome->resultText);
    }

    public function testTheCapSurvivesASessionSave(): void
    {
        $guestOrders = $this->createMock(GuestOrderLookupInterface::class);
        $guestOrders->expects($this->never())->method('find');
        $saved = $this->state('jane.doe@example.com');
        $saved->guestOrderFailures = GetOrderStatus::GUEST_FAILURE_CAP;

        $outcome = $this->handler($this->noBackend(), $guestOrders)->handle(
            ['order_id' => '1000077284', 'email' => 'jane.doe@example.com'],
            $this->context(),
            SessionState::fromJson($saved->toJson()),
            new AgentConfig()
        );

        $this->assertTrue($outcome->isError);
        $this->assertStringContainsString('no more are tried', $outcome->resultText);
    }
}
