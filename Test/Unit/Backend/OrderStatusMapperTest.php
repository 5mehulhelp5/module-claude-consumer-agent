<?php
declare(strict_types=1);

namespace MageOS\ClaudeConsumerAgent\Test\Unit\Backend;

use Magento\Sales\Model\Order;
use Magento\Sales\Model\ResourceModel\Order\Shipment\Collection as ShipmentCollection;
use Magento\Sales\Model\ResourceModel\Order\Shipment\Track\Collection as TrackCollection;
use MageOS\ClaudeConsumerAgent\Api\Data\OrderInterface;
use MageOS\ClaudeConsumerAgent\Model\Backend\Provider\OrderStatusMapper;
use PHPUnit\Framework\TestCase;

final class OrderStatusMapperTest extends TestCase
{
    private function mapper(): OrderStatusMapper
    {
        return new OrderStatusMapper();
    }

    private function order(string $state, string $status): Order&\PHPUnit\Framework\MockObject\MockObject
    {
        $order = $this->createMock(Order::class);
        $order->method('getState')->willReturn($state);
        $order->method('getStatus')->willReturn($status);
        return $order;
    }

    private function collection(int $size): object
    {
        $collection = $this->createMock(TrackCollection::class);
        $collection->method('getSize')->willReturn($size);
        return $collection;
    }

    public function testCancelledState(): void
    {
        $order = $this->order(Order::STATE_CANCELED, 'canceled');

        $this->assertSame(OrderInterface::STATUS_CANCELLED, $this->mapper()->map($order));
    }

    public function testClosedStateMapsToRefunded(): void
    {
        $order = $this->order(Order::STATE_CLOSED, 'closed');

        $this->assertSame(OrderInterface::STATUS_REFUNDED, $this->mapper()->map($order));
    }

    public function testStatusContainingReturnMapsToReturnInitiated(): void
    {
        $order = $this->order(Order::STATE_PROCESSING, 'return_requested');

        $this->assertSame(OrderInterface::STATUS_RETURN_INITIATED, $this->mapper()->map($order));
    }

    public function testCompleteStateMapsToDelivered(): void
    {
        $order = $this->order(Order::STATE_COMPLETE, 'complete');

        $this->assertSame(OrderInterface::STATUS_DELIVERED, $this->mapper()->map($order));
    }

    public function testTracksPresentMapsToShipped(): void
    {
        $order = $this->order(Order::STATE_PROCESSING, 'processing');
        $order->method('getTracksCollection')->willReturn($this->collection(1));

        $this->assertSame(OrderInterface::STATUS_SHIPPED, $this->mapper()->map($order));
    }

    public function testShippedStatusMapsToShipped(): void
    {
        $order = $this->order(Order::STATE_PROCESSING, 'shipped');
        $order->method('getTracksCollection')->willReturn($this->collection(0));

        $this->assertSame(OrderInterface::STATUS_SHIPPED, $this->mapper()->map($order));
    }

    public function testShipmentsPresentMapsToShipped(): void
    {
        $order = $this->order(Order::STATE_PROCESSING, 'processing');
        $order->method('getTracksCollection')->willReturn($this->collection(0));
        $shipments = $this->createMock(ShipmentCollection::class);
        $shipments->method('getSize')->willReturn(1);
        $order->method('getShipmentsCollection')->willReturn($shipments);

        $this->assertSame(OrderInterface::STATUS_SHIPPED, $this->mapper()->map($order));
    }

    public function testHoldedStateMapsToDelayed(): void
    {
        $order = $this->order(Order::STATE_HOLDED, 'holded');
        $order->method('getTracksCollection')->willReturn($this->collection(0));
        $shipments = $this->createMock(ShipmentCollection::class);
        $shipments->method('getSize')->willReturn(0);
        $order->method('getShipmentsCollection')->willReturn($shipments);

        $this->assertSame(OrderInterface::STATUS_DELAYED, $this->mapper()->map($order));
    }

    public function testDefaultsToProcessing(): void
    {
        $order = $this->order(Order::STATE_NEW, 'pending');
        $order->method('getTracksCollection')->willReturn($this->collection(0));
        $shipments = $this->createMock(ShipmentCollection::class);
        $shipments->method('getSize')->willReturn(0);
        $order->method('getShipmentsCollection')->willReturn($shipments);

        $this->assertSame(OrderInterface::STATUS_PROCESSING, $this->mapper()->map($order));
    }
}
