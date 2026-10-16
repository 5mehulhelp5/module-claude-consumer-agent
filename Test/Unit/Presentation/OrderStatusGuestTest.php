<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Test\Unit\Presentation;

use MageOS\AiShoppingAssistant\Api\Backend\GuestOrderLookupInterface;
use MageOS\AiShoppingAssistant\Api\Data\PageContextInterface;
use MageOS\AiShoppingAssistant\Api\StorefrontBackendInterface;
use MageOS\AiShoppingAssistant\Model\Agent\AgentConfig;
use MageOS\AiShoppingAssistant\Model\Agent\Exception\PresentationRefused;
use MageOS\AiShoppingAssistant\Model\Agent\Fencing\Fence;
use MageOS\AiShoppingAssistant\Model\Agent\Fencing\Sanitizer;
use MageOS\AiShoppingAssistant\Model\Agent\Presentation\Enrich\OrderStatus;
use MageOS\AiShoppingAssistant\Model\Agent\Presentation\EnrichmentContext;
use MageOS\AiShoppingAssistant\Model\Agent\Serializer;
use MageOS\AiShoppingAssistant\Model\Agent\SessionContext;
use MageOS\AiShoppingAssistant\Model\Agent\SessionState;
use MageOS\AiShoppingAssistant\Model\Data\Order;
use PHPUnit\Framework\TestCase;

class OrderStatusGuestTest extends TestCase
{
    public function testAGuestOrderLookedUpThisConversationGetsItsCard(): void
    {
        $order = Order::fromArray([
            'order_id' => '1000068812',
            'status' => 'processing',
            'placed_at' => '2026-10-08T10:00:00+00:00',
            'total' => 543.2,
            'currency' => 'CAD',
            'items' => [],
        ]);
        $guestOrders = $this->createMock(GuestOrderLookupInterface::class);
        $guestOrders->expects($this->once())->method('find')
            ->with($this->anything(), '1000068812', 'guest@example.com')
            ->willReturn($order);
        $backend = $this->createMock(StorefrontBackendInterface::class);
        $backend->expects($this->never())->method('getOrder');
        $state = new SessionState();
        $state->rememberGuestOrder('1000068812', 'guest@example.com');

        $payload = $this->enricher($guestOrders)(['order_id' => '1000068812', 'summary' => 'Processing.'], $this->ctx($backend, $state));

        $this->assertSame('1000068812', $payload['order']['order_id']);
        $this->assertSame(8, $payload['order']['placed_days_ago']);
    }

    public function testAGuestOrderNotLookedUpIsRefused(): void
    {
        $guestOrders = $this->createMock(GuestOrderLookupInterface::class);
        $guestOrders->expects($this->never())->method('find');

        $this->expectException(PresentationRefused::class);
        $this->enricher($guestOrders)(
            ['order_id' => '1000068812'],
            $this->ctx($this->createMock(StorefrontBackendInterface::class), new SessionState())
        );
    }

    public function testRememberedGuestOrdersSurviveTheJsonRoundTripAndStayCapped(): void
    {
        $state = new SessionState();
        for ($i = 1; $i <= SessionState::GUEST_ORDERS_CAP + 2; $i++) {
            $state->rememberGuestOrder('order-' . $i, 'guest@example.com');
        }

        $restored = SessionState::fromJson($state->toJson());

        $this->assertCount(SessionState::GUEST_ORDERS_CAP, $restored->guestOrders);
        $this->assertSame('guest@example.com', $restored->guestOrders['order-' . (SessionState::GUEST_ORDERS_CAP + 2)]);
        $this->assertSame([], SessionState::fromJson('{"turn_counter":1}')->guestOrders);
    }

    private function enricher(GuestOrderLookupInterface $guestOrders): OrderStatus
    {
        return new OrderStatus(new Serializer(new Fence(new Sanitizer())), $guestOrders);
    }

    private function ctx(StorefrontBackendInterface $backend, SessionState $state): EnrichmentContext
    {
        $context = new SessionContext(
            'sess-1',
            null,
            1,
            1,
            $this->createMock(PageContextInterface::class),
            new \DateTimeImmutable('2026-10-16T10:00:00+00:00')
        );

        return new EnrichmentContext($backend, new AgentConfig(), $context, $state);
    }
}
