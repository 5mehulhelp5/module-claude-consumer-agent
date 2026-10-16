<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Model\Backend;

use Magento\Sales\Model\Order as SalesOrder;
use MageOS\AiShoppingAssistant\Api\Backend\GuestOrderLookupInterface;
use MageOS\AiShoppingAssistant\Api\Data\OrderInterface;
use MageOS\AiShoppingAssistant\Model\Agent\SessionContext;

class GuestOrderLookup implements GuestOrderLookupInterface
{
    public function __construct(
        private readonly \Magento\Sales\Api\OrderRepositoryInterface $orderRepository,
        private readonly \Magento\Framework\Api\SearchCriteriaBuilder $searchCriteriaBuilder,
        private readonly \MageOS\AiShoppingAssistant\Model\Backend\OrderRecords $orderRecords
    ) {
    }

    public function find(SessionContext $ctx, string $orderId, string $email): ?OrderInterface
    {
        $orderId = trim($orderId);
        $email = mb_strtolower(trim($email));
        if ($orderId === '' || $email === '') {
            return null;
        }

        $criteria = $this->searchCriteriaBuilder
            ->addFilter('increment_id', $orderId)
            ->addFilter('store_id', $ctx->storeId)
            ->addFilter('customer_is_guest', 1)
            ->setPageSize(1)
            ->create();

        $items = $this->orderRepository->getList($criteria)->getItems();
        $first = reset($items);
        if (!$first instanceof SalesOrder || mb_strtolower(trim((string)$first->getCustomerEmail())) !== $email) {
            return null;
        }

        return $this->orderRecords->fromSalesOrders([$first], $ctx)[0];
    }
}
