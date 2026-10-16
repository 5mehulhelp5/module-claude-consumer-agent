<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Test\Unit\Backend;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\Api\SearchCriteria;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Sales\Api\Data\OrderSearchResultInterface;
use Magento\Sales\Api\Data\ShipmentSearchResultInterface;
use Magento\Sales\Api\Data\ShipmentTrackSearchResultInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Api\ShipmentRepositoryInterface;
use Magento\Sales\Api\ShipmentTrackRepositoryInterface;
use Magento\Sales\Model\Order as SalesOrder;
use Magento\Shipping\Helper\Data as ShippingHelper;
use Magento\Store\Model\StoreManagerInterface;
use MageOS\AiShoppingAssistant\Api\Backend\OrderStatusMapperInterface;
use MageOS\AiShoppingAssistant\Model\Agent\SessionContext;
use MageOS\AiShoppingAssistant\Model\Backend\GuestOrderLookup;
use MageOS\AiShoppingAssistant\Model\Backend\OrderRecords;
use MageOS\AiShoppingAssistant\Model\Data\PageContext;
use PHPUnit\Framework\TestCase;

class GuestOrderLookupTest extends TestCase
{
    private array $filters = [];

    private function context(): SessionContext
    {
        return new SessionContext('sess-1', null, 1, 3, PageContext::fromArray([]), new \DateTimeImmutable('2026-10-16T12:00:00+00:00'));
    }

    private function searchCriteriaBuilder(): SearchCriteriaBuilder
    {
        $builder = $this->createMock(SearchCriteriaBuilder::class);
        $builder->method('addFilter')->willReturnCallback(
            function (string $field, mixed $value) use ($builder): SearchCriteriaBuilder {
                $this->filters[$field] = $value;
                return $builder;
            }
        );
        $builder->method('setPageSize')->willReturnSelf();
        $builder->method('create')->willReturn($this->createMock(SearchCriteria::class));
        return $builder;
    }

    private function salesOrder(string $email): SalesOrder
    {
        $order = $this->createMock(SalesOrder::class);
        $order->method('getId')->willReturn(7);
        $order->method('getIncrementId')->willReturn('1000077284');
        $order->method('getCustomerEmail')->willReturn($email);
        $order->method('getCreatedAt')->willReturn('2026-10-13 12:00:00');
        $order->method('getGrandTotal')->willReturn(158.0);
        $order->method('getOrderCurrencyCode')->willReturn('USD');
        $order->method('getAllVisibleItems')->willReturn([]);
        return $order;
    }

    private function lookup(array $orders): GuestOrderLookup
    {
        $results = $this->createMock(OrderSearchResultInterface::class);
        $results->method('getItems')->willReturn($orders);
        $orderRepository = $this->createMock(OrderRepositoryInterface::class);
        $orderRepository->method('getList')->willReturn($results);

        $trackResults = $this->createMock(ShipmentTrackSearchResultInterface::class);
        $trackResults->method('getItems')->willReturn([]);
        $tracks = $this->createMock(ShipmentTrackRepositoryInterface::class);
        $tracks->method('getList')->willReturn($trackResults);
        $shipmentResults = $this->createMock(ShipmentSearchResultInterface::class);
        $shipmentResults->method('getItems')->willReturn([]);
        $shipments = $this->createMock(ShipmentRepositoryInterface::class);
        $shipments->method('getList')->willReturn($shipmentResults);
        $statusMapper = $this->createMock(OrderStatusMapperInterface::class);
        $statusMapper->method('map')->willReturn('processing');

        $builder = $this->searchCriteriaBuilder();
        $records = new OrderRecords(
            $this->createMock(ProductRepositoryInterface::class),
            $builder,
            $this->createMock(StoreManagerInterface::class),
            $statusMapper,
            $this->createMock(ShippingHelper::class),
            $tracks,
            $shipments
        );
        return new GuestOrderLookup($orderRepository, $builder, $records);
    }

    public function testAGuestOrderOfTheStoreIsFoundByNumberAndEmailIgnoringCaseAndSpaces(): void
    {
        $order = $this->lookup([7 => $this->salesOrder('Jane.Doe@Example.com ')])
            ->find($this->context(), ' 1000077284 ', ' jane.doe@example.COM');

        $this->assertNotNull($order);
        $this->assertSame('1000077284', $order->getOrderId());
        $this->assertSame('processing', $order->getStatus());
        $this->assertSame('1000077284', $this->filters['increment_id']);
        $this->assertSame(3, $this->filters['store_id']);
        $this->assertSame(1, $this->filters['customer_is_guest']);
    }

    public function testAnotherEmailFindsNothing(): void
    {
        $order = $this->lookup([7 => $this->salesOrder('jane.doe@example.com')])
            ->find($this->context(), '1000077284', 'john@example.com');

        $this->assertNull($order);
    }

    public function testNoGuestOrderWithThatNumberFindsNothing(): void
    {
        $this->assertNull($this->lookup([])->find($this->context(), '1000077284', 'jane.doe@example.com'));
    }

    public function testAnEmptyEmailNeverQueries(): void
    {
        $this->assertNull($this->lookup([7 => $this->salesOrder('')])->find($this->context(), '1000077284', ' '));
        $this->assertSame([], $this->filters);
    }
}
