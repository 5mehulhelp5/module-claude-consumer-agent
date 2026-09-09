<?php
declare(strict_types=1);

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Customer\Api\AccountManagementInterface;
use Magento\Customer\Api\AddressRepositoryInterface;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\AddressInterfaceFactory;
use Magento\Customer\Api\Data\CustomerInterfaceFactory;
use Magento\Customer\Api\Data\RegionInterfaceFactory;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Api\ShipmentTrackRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Address as OrderAddress;
use Magento\Sales\Model\Order\Item as OrderItem;
use Magento\Sales\Model\Order\Payment;
use Magento\Sales\Model\Order\Shipment\Track;
use Magento\Sales\Model\Order\ShipmentFactory;
use Magento\Store\Model\StoreManagerInterface;
use Magento\TestFramework\Helper\Bootstrap;

require __DIR__ . '/product_simple.php';

$objectManager = Bootstrap::getObjectManager();
$storeId = (int)$objectManager->get(StoreManagerInterface::class)->getStore()->getId();

$regionFactory = $objectManager->get(RegionInterfaceFactory::class);
$region = $regionFactory->create();
$region->setRegion('California')->setRegionCode('CA')->setRegionId(12);

$addressFactory = $objectManager->get(AddressInterfaceFactory::class);
$address = $addressFactory->create();
$address->setFirstname('Ada')
    ->setLastname('Example')
    ->setCountryId('US')
    ->setRegion($region)
    ->setRegionId(12)
    ->setCity('Los Angeles')
    ->setPostcode('90001')
    ->setStreet(['123 Test Street'])
    ->setTelephone('5555550100')
    ->setIsDefaultBilling(true)
    ->setIsDefaultShipping(true);

$customerFactory = $objectManager->get(CustomerInterfaceFactory::class);
$customer = $customerFactory->create();
$customer->setEmail('aiagent-customer@example.test')
    ->setFirstname('Ada')
    ->setLastname('Example')
    ->setStoreId($storeId)
    ->setWebsiteId(1)
    ->setAddresses([$address]);

$accountManagement = $objectManager->get(AccountManagementInterface::class);
$customer = $accountManagement->createAccount($customer, 'AiAgentTest123!');

$customerRepository = $objectManager->get(CustomerRepositoryInterface::class);
$addressRepository = $objectManager->get(AddressRepositoryInterface::class);
$savedAddresses = $customerRepository->getById($customer->getId())->getAddresses();
$defaultAddress = $savedAddresses[0];
$defaultAddress->setIsDefaultShipping(true)->setIsDefaultBilling(true);
$addressRepository->save($defaultAddress);

$productRepository = $objectManager->get(ProductRepositoryInterface::class);
$product = $productRepository->get('aiagent-simple');

$addressData = [
    'firstname' => 'Ada',
    'lastname' => 'Example',
    'street' => '123 Test Street',
    'city' => 'Los Angeles',
    'region' => 'California',
    'region_id' => 12,
    'postcode' => '90001',
    'country_id' => 'US',
    'telephone' => '5555550100',
];

$orderRepository = $objectManager->get(OrderRepositoryInterface::class);

$buildOrder = static function (
    string $incrementId,
    string $state
) use (
    $objectManager,
    $addressData,
    $product,
    $customer,
    $storeId,
    $orderRepository
): Order {
    $billingAddress = $objectManager->create(OrderAddress::class, ['data' => $addressData]);
    $billingAddress->setAddressType('billing');
    $shippingAddress = clone $billingAddress;
    $shippingAddress->setId(null)->setAddressType('shipping');

    $payment = $objectManager->create(Payment::class);
    $payment->setMethod('checkmo');

    $orderItem = $objectManager->create(OrderItem::class);
    $orderItem->setProductId($product->getId())
        ->setQtyOrdered(1)
        ->setBasePrice($product->getPrice())
        ->setPrice($product->getPrice())
        ->setRowTotal($product->getPrice())
        ->setProductType('simple')
        ->setName($product->getName())
        ->setSku($product->getSku());

    $order = $objectManager->create(Order::class);
    $order->setIncrementId($incrementId)
        ->setState($state)
        ->setStatus($order->getConfig()->getStateDefaultStatus($state))
        ->setSubtotal($product->getPrice())
        ->setGrandTotal($product->getPrice())
        ->setBaseSubtotal($product->getPrice())
        ->setBaseGrandTotal($product->getPrice())
        ->setOrderCurrencyCode('USD')
        ->setBaseCurrencyCode('USD')
        ->setCustomerIsGuest(false)
        ->setCustomerId($customer->getId())
        ->setCustomerEmail($customer->getEmail())
        ->setBillingAddress($billingAddress)
        ->setShippingAddress($shippingAddress)
        ->setStoreId($storeId)
        ->addItem($orderItem)
        ->setPayment($payment);

    return $orderRepository->save($order);
};

$buildOrder('900000001', Order::STATE_PROCESSING);
$shippedOrder = $buildOrder('900000002', Order::STATE_PROCESSING);

$items = [];
foreach ($shippedOrder->getItems() as $orderItem) {
    $items[$orderItem->getId()] = $orderItem->getQtyOrdered();
}
$shipment = $objectManager->get(ShipmentFactory::class)->create($shippedOrder, $items);
$shipment->save();

$track = $objectManager->create(Track::class);
$track->setOrderId($shippedOrder->getId());
$track->setParentId($shipment->getId());
$track->setTitle('AI Agent Test Carrier');
$track->setCarrierCode('custom');
$track->setTrackNumber('AIAGENT-TRACK-1');

$shipmentTrackRepository = $objectManager->get(ShipmentTrackRepositoryInterface::class);
$shipmentTrackRepository->save($track);
