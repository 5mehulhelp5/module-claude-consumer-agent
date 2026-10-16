<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Model\Backend;

use Magento\Sales\Model\Order as SalesOrder;
use Magento\Sales\Model\Order\Item as SalesOrderItem;
use MageOS\AiShoppingAssistant\Model\Agent\SessionContext;
use MageOS\AiShoppingAssistant\Model\Data\Order;
use MageOS\AiShoppingAssistant\Model\Data\OrderItem;

class OrderRecords
{
    public function __construct(
        private readonly \Magento\Catalog\Api\ProductRepositoryInterface $productRepository,
        private readonly \Magento\Framework\Api\SearchCriteriaBuilder $searchCriteriaBuilder,
        private readonly \Magento\Store\Model\StoreManagerInterface $storeManager,
        private readonly \MageOS\AiShoppingAssistant\Api\Backend\OrderStatusMapperInterface $orderStatusMapper,
        private readonly \Magento\Shipping\Helper\Data $shippingHelper,
        private readonly \Magento\Sales\Api\ShipmentTrackRepositoryInterface $shipmentTrackRepository,
        private readonly \Magento\Sales\Api\ShipmentRepositoryInterface $shipmentRepository
    ) {
    }

    /**
     * @param SalesOrder[] $salesOrders
     * @return Order[]
     */
    public function fromSalesOrders(array $salesOrders, SessionContext $ctx): array
    {
        $simpleProductIds = $this->simpleProductIdsBySku($salesOrders, $ctx->storeId);
        $trackedOrderIds = $this->trackedOrderIds($salesOrders);

        $orders = [];
        foreach ($salesOrders as $order) {
            $hasTracking = $trackedOrderIds[(int)$order->getId()] ?? false;
            $orders[] = $this->toOrder($order, $ctx, $simpleProductIds, $hasTracking);
        }
        return $orders;
    }

    private function simpleProductIdsBySku(array $orders, int $storeId): array
    {
        $skus = [];
        foreach ($orders as $order) {
            foreach ($order->getAllVisibleItems() as $orderItem) {
                $options = $orderItem->getProductOptions();
                $options = is_array($options) ? $options : [];
                $sku = isset($options['simple_sku']) ? (string)$options['simple_sku'] : null;
                if ($sku !== null && $sku !== '') {
                    $skus[$sku] = true;
                }
            }
        }
        if ($skus === []) {
            return [];
        }

        $previousStoreId = (int)$this->storeManager->getStore()->getId();
        $this->storeManager->setCurrentStore($storeId);

        try {
            $criteria = $this->searchCriteriaBuilder
                ->addFilter('sku', array_keys($skus), 'in')
                ->create();
            $products = $this->productRepository->getList($criteria)->getItems();
        } finally {
            $this->storeManager->setCurrentStore($previousStoreId);
        }

        $ids = [];
        foreach ($products as $product) {
            $ids[$product->getSku()] = (string)$product->getId();
        }
        return $ids;
    }

    private function trackedOrderIds(array $orders): array
    {
        $orderIds = [];
        foreach ($orders as $order) {
            $orderIds[] = (int)$order->getId();
        }
        if ($orderIds === []) {
            return [];
        }

        $tracked = [];

        $trackCriteria = $this->searchCriteriaBuilder->addFilter('order_id', $orderIds, 'in')->create();
        foreach ($this->shipmentTrackRepository->getList($trackCriteria)->getItems() as $track) {
            $tracked[(int)$track->getOrderId()] = true;
        }

        $shipmentCriteria = $this->searchCriteriaBuilder->addFilter('order_id', $orderIds, 'in')->create();
        foreach ($this->shipmentRepository->getList($shipmentCriteria)->getItems() as $shipment) {
            $tracked[(int)$shipment->getOrderId()] = true;
        }

        return $tracked;
    }

    private function toOrder(SalesOrder $order, SessionContext $ctx, array $simpleProductIds, bool $hasTracking): Order
    {
        $items = [];
        foreach ($order->getAllVisibleItems() as $orderItem) {
            $items[] = $this->toOrderItem($orderItem, $simpleProductIds);
        }

        $trackingUrl = $hasTracking ? $this->shippingHelper->getTrackingPopupUrlBySalesModel($order) : null;

        return new Order(
            (string)$order->getIncrementId(),
            $this->orderStatusMapper->map($order, $hasTracking),
            new \DateTimeImmutable((string)$order->getCreatedAt()),
            (float)$order->getGrandTotal(),
            (string)$order->getOrderCurrencyCode(),
            $items,
            null,
            $trackingUrl !== null && $trackingUrl !== '' ? $trackingUrl : null
        );
    }

    private function toOrderItem(SalesOrderItem $orderItem, array $simpleProductIds): OrderItem
    {
        $options = $orderItem->getProductOptions();
        $options = is_array($options) ? $options : [];
        $simpleSku = isset($options['simple_sku']) ? (string)$options['simple_sku'] : null;

        return new OrderItem(
            $this->orderItemProductId($orderItem, $simpleSku, $simpleProductIds),
            (string)$orderItem->getName(),
            (int)$orderItem->getQtyOrdered(),
            (float)$orderItem->getPrice(),
            $this->orderItemOptionValues($options),
            $simpleSku !== null && $simpleSku !== '' ? (string)$orderItem->getProductId() : null
        );
    }

    private function orderItemProductId(SalesOrderItem $orderItem, ?string $simpleSku, array $simpleProductIds): string
    {
        if ($simpleSku !== null && $simpleSku !== '' && isset($simpleProductIds[$simpleSku])) {
            return $simpleProductIds[$simpleSku];
        }
        return (string)$orderItem->getProductId();
    }

    private function orderItemOptionValues(array $options): array
    {
        $values = [];
        foreach ((array)($options['attributes_info'] ?? []) as $entry) {
            $label = (string)($entry['label'] ?? '');
            if ($label !== '') {
                $values[$label] = (string)($entry['value'] ?? '');
            }
        }
        foreach ((array)($options['options'] ?? []) as $entry) {
            $label = (string)($entry['label'] ?? '');
            if ($label !== '') {
                $values[$label] = (string)($entry['value'] ?? '');
            }
        }
        return $values;
    }
}
