<?php
declare(strict_types=1);

namespace MageOS\ClaudeConsumerAgent\Model\Backend;

use Magento\Catalog\Api\Data\ProductCustomOptionInterface;
use Magento\Catalog\Api\Data\ProductCustomOptionValuesInterface;
use Magento\Catalog\Api\Data\ProductInterface as MagentoProductInterface;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Visibility;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Magento\Eav\Model\Entity\Attribute\AbstractAttribute;
use Magento\Framework\Api\SortOrder;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Item as QuoteItem;
use Magento\Sales\Model\Order as SalesOrder;
use Magento\Sales\Model\Order\Item as SalesOrderItem;
use MageOS\ClaudeConsumerAgent\Api\Data\CartInterface;
use MageOS\ClaudeConsumerAgent\Api\Data\OrderInterface as DataOrderInterface;
use MageOS\ClaudeConsumerAgent\Api\Data\ProductDetailsInterface;
use MageOS\ClaudeConsumerAgent\Api\Data\ProductInterface as DataProductInterface;
use MageOS\ClaudeConsumerAgent\Api\Data\SearchFiltersInterface;
use MageOS\ClaudeConsumerAgent\Api\Data\UserPreferencesInterface;
use MageOS\ClaudeConsumerAgent\Api\StorefrontBackendInterface;
use MageOS\ClaudeConsumerAgent\Model\Agent\Exception\NotOffered;
use MageOS\ClaudeConsumerAgent\Model\Agent\Exception\SignInRequired;
use MageOS\ClaudeConsumerAgent\Model\Agent\Exception\Unavailable;
use MageOS\ClaudeConsumerAgent\Model\Agent\SessionContext;
use MageOS\ClaudeConsumerAgent\Model\Data\Cart;
use MageOS\ClaudeConsumerAgent\Model\Data\CartItem;
use MageOS\ClaudeConsumerAgent\Model\Data\Order;
use MageOS\ClaudeConsumerAgent\Model\Data\OrderItem;
use MageOS\ClaudeConsumerAgent\Model\Data\Product;
use MageOS\ClaudeConsumerAgent\Model\Data\ProductDetails;
use MageOS\ClaudeConsumerAgent\Model\Data\UserPreferences;

final class MagentoStorefront implements StorefrontBackendInterface
{
    private const MAX_VARIANTS = 60;
    private const VARIANT_CAP_NOTE = 'More variants exist; ask about a size or colour to narrow the list.';

    public function __construct(
        private readonly \Magento\Catalog\Api\ProductRepositoryInterface $productRepository,
        private readonly \Magento\Framework\Api\SearchCriteriaBuilder $searchCriteriaBuilder,
        private readonly \Magento\Framework\Api\SortOrderBuilder $sortOrderBuilder,
        private readonly \Magento\Store\Model\StoreManagerInterface $storeManager,
        private readonly \Magento\Catalog\Helper\Image $imageHelper,
        private readonly \Magento\Catalog\Helper\Product\Configuration $productConfiguration,
        private readonly \MageOS\ClaudeConsumerAgent\Api\Backend\SearchProviderInterface $searchProvider,
        private readonly \MageOS\ClaudeConsumerAgent\Model\Backend\ProductMapper $productMapper,
        private readonly \MageOS\ClaudeConsumerAgent\Model\Backend\Salability $salability,
        private readonly \MageOS\ClaudeConsumerAgent\Api\Cart\BuyRequestBuilderInterface $buyRequestBuilder,
        private readonly \Magento\ConfigurableProduct\Model\ResourceModel\Product\Type\Configurable $configurableResource,
        private readonly \Magento\Quote\Api\CartRepositoryInterface $cartRepository,
        private readonly \Magento\Customer\Api\CustomerRepositoryInterface $customerRepository,
        private readonly \Magento\Customer\Api\GroupRepositoryInterface $groupRepository,
        private readonly \Magento\Customer\Api\AddressRepositoryInterface $addressRepository,
        private readonly \Magento\Sales\Api\OrderRepositoryInterface $orderRepository,
        private readonly \MageOS\ClaudeConsumerAgent\Api\Backend\OrderStatusMapperInterface $orderStatusMapper,
        private readonly \Magento\Shipping\Helper\Data $shippingHelper,
        private readonly \MageOS\ClaudeConsumerAgent\Api\Backend\PolicySourceInterface $policySource,
        private readonly \MageOS\ClaudeConsumerAgent\Api\Backend\FulfillmentProviderInterface $fulfillmentProvider,
        private readonly \MageOS\ClaudeConsumerAgent\Api\Backend\CategorySearchProviderInterface $categorySearchProvider
    ) {
    }

    public function searchProducts(
        SessionContext $ctx,
        string $query,
        ?SearchFiltersInterface $filters,
        int $limit
    ): array {
        $ids = $this->searchProvider->search($ctx, $query, $filters, $limit);
        if ($ids === []) {
            return [];
        }

        $products = $this->loadEnabledProducts($ids, $ctx->storeId, $limit);
        $byId = [];
        foreach ($products as $product) {
            $byId[(int)$product->getId()] = $product;
        }

        $records = [];
        foreach ($ids as $id) {
            if (isset($byId[$id])) {
                $records[] = $this->productMapper->toProduct($byId[$id], $ctx);
            }
        }

        return $this->applyFilters($records, $filters);
    }

    private function loadEnabledProducts(array $ids, int $storeId, int $limit): array
    {
        $previousStoreId = (int)$this->storeManager->getStore()->getId();
        $this->storeManager->setCurrentStore($storeId);

        try {
            $criteria = $this->searchCriteriaBuilder
                ->addFilter('entity_id', $ids, 'in')
                ->addFilter('status', Status::STATUS_ENABLED)
                ->setPageSize($limit)
                ->create();
            return $this->productRepository->getList($criteria)->getItems();
        } finally {
            $this->storeManager->setCurrentStore($previousStoreId);
        }
    }

    private function applyFilters(array $records, ?SearchFiltersInterface $filters): array
    {
        if ($filters === null) {
            return $records;
        }

        $minRating = $filters->getMinRating();
        if ($minRating !== null) {
            $records = array_values(array_filter(
                $records,
                static fn (DataProductInterface $product): bool =>
                    $product->getRating() === null || $product->getRating() >= $minRating
            ));
        }

        $sort = $filters->getSort();
        if ($sort === 'price_asc') {
            usort(
                $records,
                static fn (DataProductInterface $a, DataProductInterface $b): int => $a->getPrice() <=> $b->getPrice()
            );
        } elseif ($sort === 'price_desc') {
            usort(
                $records,
                static fn (DataProductInterface $a, DataProductInterface $b): int => $b->getPrice() <=> $a->getPrice()
            );
        } elseif ($sort === 'rating') {
            usort(
                $records,
                static fn (DataProductInterface $a, DataProductInterface $b): int =>
                    ($b->getRating() ?? 0.0) <=> ($a->getRating() ?? 0.0)
            );
        }

        return $records;
    }

    public function getProductDetails(SessionContext $ctx, string $productId): ?ProductDetailsInterface
    {
        try {
            $product = $this->productRepository->getById((int)$productId, false, $ctx->storeId);
        } catch (NoSuchEntityException $exception) {
            return null;
        }

        if ((int)$product->getStatus() !== Status::STATUS_ENABLED) {
            return null;
        }
        if ((int)$product->getVisibility() === Visibility::VISIBILITY_NOT_VISIBLE) {
            return null;
        }

        $family = $this->productMapper->toProduct($product, $ctx);
        $details = ProductDetails::fromProduct(
            $family,
            $this->cleanText((string)$product->getData('description')),
            $this->specs($product),
            []
        );

        if ($product->getTypeId() === Configurable::TYPE_CODE) {
            $details = $this->withVariants($details, $family, $product, $ctx);
        }

        return $details;
    }

    private function withVariants(
        ProductDetails $details,
        Product $family,
        MagentoProductInterface $product,
        SessionContext $ctx
    ): ProductDetails {
        $typeInstance = $product->getTypeInstance();
        if (!$typeInstance instanceof Configurable) {
            return $details;
        }

        $attributes = $typeInstance->getConfigurableAttributesAsArray($product);
        $variants = [];
        $anyInStock = false;
        $lowestPrice = null;

        foreach ($typeInstance->getUsedProducts($product) as $child) {
            $variantProduct = $this->productMapper->toProduct($child, $ctx);
            $variantData = $variantProduct->toArray();
            $variantData['option_values'] = $this->variantOptionValues($attributes, $child);
            $variantData['variant_of'] = $family->getProductId();
            $variantData['options'] = [];
            $variant = Product::fromArray($variantData);
            $variants[] = $variant;

            if ($variant->isInStock()) {
                $anyInStock = true;
                if ($lowestPrice === null || $variant->getPrice() < $lowestPrice) {
                    $lowestPrice = $variant->getPrice();
                }
            }
        }

        $capped = count($variants) > self::MAX_VARIANTS;
        if ($capped) {
            usort(
                $variants,
                static fn (DataProductInterface $a, DataProductInterface $b): int => $a->getPrice() <=> $b->getPrice()
            );
            $variants = array_slice($variants, 0, self::MAX_VARIANTS);
        }

        $details = $details->withVariants($variants)->withInStock($anyInStock);
        if ($lowestPrice !== null) {
            $details = $details->withPrice($lowestPrice);
        }
        if ($capped) {
            $details = $details->withNote(self::VARIANT_CAP_NOTE);
        }

        return $details;
    }

    private function variantOptionValues(array $attributes, MagentoProductInterface $child): array
    {
        $values = [];
        foreach ($attributes as $attribute) {
            $code = (string)($attribute['attribute_code'] ?? '');
            $label = (string)($attribute['label'] ?? '');
            if ($code === '' || $label === '') {
                continue;
            }
            $valueLabel = $this->optionLabel($attribute, $child->getData($code));
            if ($valueLabel !== null) {
                $values[$label] = $valueLabel;
            }
        }
        return $values;
    }

    private function optionLabel(array $attribute, mixed $value): ?string
    {
        foreach ((array)($attribute['options'] ?? []) as $option) {
            if ((string)($option['value'] ?? '') === (string)$value) {
                return isset($option['label']) ? (string)$option['label'] : null;
            }
        }
        return null;
    }

    private function specs(MagentoProductInterface $product): array
    {
        $excluded = ['description', 'short_description', 'manufacturer', 'sku', 'price', 'status', 'name'];
        $specs = [];

        foreach ($product->getAttributes() as $attribute) {
            if (!$attribute instanceof AbstractAttribute
                || !$attribute->getIsVisibleOnFront()
                || in_array($attribute->getAttributeCode(), $excluded, true)
            ) {
                continue;
            }

            $value = $product->getAttributeText($attribute->getAttributeCode());
            if (is_array($value)) {
                $value = implode(', ', $value);
            }
            if ($value === false || $value === null || $value === '') {
                $value = $product->getData($attribute->getAttributeCode());
            }
            if ($value === null || $value === '' || is_array($value)) {
                continue;
            }

            $specs[$attribute->getStoreLabel()] = (string)$value;
        }

        return $specs;
    }

    private function cleanText(string $text): ?string
    {
        $stripped = strip_tags($text);
        $collapsed = preg_replace('/\s+/', ' ', $stripped) ?? '';
        $trimmed = trim($collapsed);
        return $trimmed !== '' ? $trimmed : null;
    }

    public function searchCategories(SessionContext $ctx, string $keywords, int $limit): array
    {
        return $this->categorySearchProvider->search($ctx, $keywords, $limit);
    }

    public function getCart(SessionContext $ctx): CartInterface
    {
        $quote = $this->cartRepository->get($ctx->quoteId);
        $items = [];

        foreach ($quote->getAllVisibleItems() as $item) {
            $isConfigurable = $item->getProductType() === Configurable::TYPE_CODE;
            $purchasable = $isConfigurable ? $this->childProduct($item) : $item->getProduct();
            if ($purchasable === null) {
                continue;
            }

            $items[] = new CartItem(
                (string)$purchasable->getId(),
                (string)$item->getName(),
                $this->itemPrice($item),
                (int)$item->getQty(),
                $this->imageHelper->init($purchasable, 'product_thumbnail_image')->getUrl(),
                $this->itemOptionValues($item, $isConfigurable, $purchasable),
                $isConfigurable ? (string)$item->getProduct()->getId() : null,
                (int)$item->getId()
            );
        }

        $currency = $this->storeManager->getStore($ctx->storeId)->getCurrentCurrencyCode();
        return new Cart($items, $currency);
    }

    private function itemPrice(QuoteItem $item): float
    {
        $price = (float)$item->getPriceInclTax();
        return $price > 0.0 ? $price : (float)$item->getPrice();
    }

    private function itemOptionValues(QuoteItem $item, bool $isConfigurable, MagentoProductInterface $purchasable): array
    {
        $values = [];
        if ($isConfigurable) {
            $typeInstance = $item->getProduct()->getTypeInstance();
            if ($typeInstance instanceof Configurable) {
                $attributes = $typeInstance->getConfigurableAttributesAsArray($item->getProduct());
                $values = $this->variantOptionValues($attributes, $purchasable);
            }
        }

        foreach ($this->productConfiguration->getOptions($item) as $option) {
            $label = (string)($option['label'] ?? '');
            if ($label === '') {
                continue;
            }
            $value = $option['value'] ?? '';
            if (is_array($value)) {
                $value = implode(', ', $value);
            }
            $values[$label] = (string)$value;
        }

        return $values;
    }

    private function childProduct(QuoteItem $item): ?MagentoProductInterface
    {
        $option = $item->getOptionByCode('simple_product');
        return $option !== null ? $option->getProduct() : null;
    }

    public function addToCart(SessionContext $ctx, string $productId, int $quantity, array $options = []): CartInterface
    {
        $quote = $this->cartRepository->get($ctx->quoteId);

        try {
            $product = $this->productRepository->getById((int)$productId, false, $ctx->storeId);
        } catch (NoSuchEntityException $exception) {
            throw new Unavailable($productId . ' is out of stock');
        }

        if (!$this->salability->isSalable($product, $ctx)) {
            $siblings = $this->inStockSiblingIds($product, $ctx);
            $message = $productId . ' is out of stock';
            if ($siblings !== []) {
                $message .= '; in stock: ' . implode(', ', $siblings);
            }
            throw new Unavailable($message);
        }

        $resolvedOptions = $this->resolveCustomOptions($product, $options);

        $parentIds = $this->configurableResource->getParentIdsByChild($product->getId());
        $parentId = $parentIds[0] ?? null;

        if ($parentId !== null) {
            $parent = $this->productRepository->getById((int)$parentId, false, $ctx->storeId);
            $result = $this->addConfigurableToQuote($quote, $parent, $product, $quantity, $resolvedOptions);
        } else {
            $request = $this->buyRequestBuilder->build($product, $quantity, ['options' => $resolvedOptions]);
            $result = $this->addProductToQuote($quote, $product, $request);
        }

        if (is_string($result)) {
            throw new NotOffered($this->truncate($result));
        }

        $quote->getBillingAddress();
        $quote->getShippingAddress()->setCollectShippingRates(true);
        $quote->collectTotals();
        $this->cartRepository->save($quote);

        return $this->getCart($ctx);
    }

    private function resolveCustomOptions(MagentoProductInterface $product, array $options): array
    {
        if ($options === []) {
            return [];
        }

        $productName = (string)$product->getName();
        $available = (array)$product->getOptions();
        $resolved = [];

        foreach ($options as $title => $selection) {
            $title = (string)$title;
            $option = $this->findCustomOptionByTitle($available, $title);
            if ($option === null) {
                throw new NotOffered($productName . ' has no option ' . $title);
            }
            $resolved[(int)$option->getOptionId()] = $this->resolveCustomOptionValue(
                $option,
                (string)$selection,
                $title,
                $productName
            );
        }

        return $resolved;
    }

    private function resolveCustomOptionValue(
        ProductCustomOptionInterface $option,
        string $selection,
        string $title,
        string $productName
    ): string {
        $type = (string)$option->getType();

        if (in_array($type, [
            ProductCustomOptionInterface::OPTION_TYPE_FIELD,
            ProductCustomOptionInterface::OPTION_TYPE_AREA,
        ], true)) {
            return $selection;
        }

        if (in_array($type, [
            ProductCustomOptionInterface::OPTION_TYPE_CHECKBOX,
            ProductCustomOptionInterface::OPTION_TYPE_MULTIPLE,
        ], true)) {
            $ids = [];
            foreach (array_map('trim', explode(',', $selection)) as $part) {
                $ids[] = $this->resolveCustomOptionValueId($option, $part, $title, $productName);
            }
            return implode(',', $ids);
        }

        return $this->resolveCustomOptionValueId($option, $selection, $title, $productName);
    }

    private function resolveCustomOptionValueId(
        ProductCustomOptionInterface $option,
        string $valueTitle,
        string $title,
        string $productName
    ): string {
        $value = $this->findCustomOptionValueByTitle($option, $valueTitle);
        if ($value === null) {
            throw new NotOffered($productName . ' has no value ' . $valueTitle . ' for ' . $title);
        }
        return (string)$value->getOptionTypeId();
    }

    private function findCustomOptionByTitle(array $options, string $title): ?ProductCustomOptionInterface
    {
        foreach ($options as $option) {
            if ($option instanceof ProductCustomOptionInterface && strcasecmp((string)$option->getTitle(), $title) === 0) {
                return $option;
            }
        }
        return null;
    }

    private function findCustomOptionValueByTitle(
        ProductCustomOptionInterface $option,
        string $title
    ): ?ProductCustomOptionValuesInterface {
        foreach ((array)$option->getValues() as $value) {
            if ($value instanceof ProductCustomOptionValuesInterface && strcasecmp((string)$value->getTitle(), $title) === 0) {
                return $value;
            }
        }
        return null;
    }

    private function addConfigurableToQuote(
        Quote $quote,
        MagentoProductInterface $parent,
        MagentoProductInterface $child,
        int $quantity,
        array $resolvedOptions
    ) {
        $selections = [];
        $typeInstance = $parent->getTypeInstance();
        if ($typeInstance instanceof Configurable) {
            foreach ($typeInstance->getConfigurableAttributesAsArray($parent) as $attribute) {
                $attributeId = (string)($attribute['attribute_id'] ?? '');
                $code = (string)($attribute['attribute_code'] ?? '');
                if ($attributeId === '' || $code === '') {
                    continue;
                }
                $selections[$attributeId] = $child->getData($code);
            }
        }

        $request = $this->buyRequestBuilder->build(
            $parent,
            $quantity,
            ['super_attribute' => $selections, 'options' => $resolvedOptions]
        );

        return $this->addProductToQuote($quote, $parent, $request);
    }

    private function addProductToQuote(Quote $quote, MagentoProductInterface $product, DataObject $request)
    {
        try {
            return $quote->addProduct($product, $request);
        } catch (LocalizedException $exception) {
            throw new NotOffered($this->truncate($exception->getMessage()));
        }
    }

    private function inStockSiblingIds(MagentoProductInterface $product, SessionContext $ctx): array
    {
        $parentIds = $this->configurableResource->getParentIdsByChild($product->getId());
        $parentId = $parentIds[0] ?? null;
        if ($parentId === null) {
            return [];
        }

        try {
            $parent = $this->productRepository->getById((int)$parentId, false, $ctx->storeId);
        } catch (NoSuchEntityException $exception) {
            return [];
        }

        $typeInstance = $parent->getTypeInstance();
        if (!$typeInstance instanceof Configurable) {
            return [];
        }

        $siblings = [];
        foreach ($typeInstance->getUsedProducts($parent) as $child) {
            if ((int)$child->getId() === (int)$product->getId()) {
                continue;
            }
            if ($this->salability->isSalable($child, $ctx)) {
                $siblings[] = (string)$child->getId();
            }
        }
        return $siblings;
    }

    private function truncate(string $message): string
    {
        return mb_substr(trim($message), 0, 200);
    }

    public function updateCartItem(SessionContext $ctx, string $productId, int $quantity): CartInterface
    {
        $quote = $this->cartRepository->get($ctx->quoteId);
        $item = $this->findVisibleItem($quote, $productId);
        if ($item === null) {
            return $this->getCart($ctx);
        }

        $item->setQty($quantity);
        $quote->getBillingAddress();
        $quote->getShippingAddress()->setCollectShippingRates(true);
        $quote->collectTotals();
        $this->cartRepository->save($quote);

        return $this->getCart($ctx);
    }

    public function removeFromCart(SessionContext $ctx, string $productId): CartInterface
    {
        $quote = $this->cartRepository->get($ctx->quoteId);
        $item = $this->findVisibleItem($quote, $productId);
        if ($item !== null) {
            $quote->removeItem((int)$item->getId());
            $quote->getBillingAddress();
            $quote->getShippingAddress()->setCollectShippingRates(true);
            $quote->collectTotals();
            $this->cartRepository->save($quote);
        }

        return $this->getCart($ctx);
    }

    private function findVisibleItem(Quote $quote, string $productId): ?QuoteItem
    {
        foreach ($quote->getAllVisibleItems() as $item) {
            $isConfigurable = $item->getProductType() === Configurable::TYPE_CODE;
            $purchasable = $isConfigurable ? $this->childProduct($item) : $item->getProduct();
            if ($purchasable !== null && (string)$purchasable->getId() === $productId) {
                return $item;
            }
        }
        return null;
    }

    public function getPreferences(SessionContext $ctx): UserPreferencesInterface
    {
        if ($ctx->customerId === null) {
            return new UserPreferences('guest:' . $ctx->quoteId);
        }

        try {
            $customer = $this->customerRepository->getById($ctx->customerId);
        } catch (NoSuchEntityException $exception) {
            return new UserPreferences('guest:' . $ctx->quoteId);
        }

        $loyaltyTier = null;
        try {
            $loyaltyTier = $this->groupRepository->getById((int)$customer->getGroupId())->getCode();
        } catch (NoSuchEntityException $exception) {
            $loyaltyTier = null;
        }

        return new UserPreferences(
            (string)$customer->getId(),
            $customer->getFirstname(),
            $loyaltyTier,
            $this->customerLocation($customer->getDefaultShipping())
        );
    }

    private function customerLocation(?string $defaultShippingId): ?string
    {
        if ($defaultShippingId === null) {
            return null;
        }

        try {
            $address = $this->addressRepository->getById((int)$defaultShippingId);
        } catch (NoSuchEntityException $exception) {
            return null;
        }

        $city = $address->getCity();
        $region = $address->getRegion();
        $regionText = $region !== null ? $region->getRegion() : null;

        if ($city !== null && $regionText !== null) {
            return $city . ', ' . $regionText;
        }
        return $city;
    }

    public function getOrders(SessionContext $ctx, int $limit): array
    {
        if ($ctx->customerId === null) {
            throw new SignInRequired();
        }

        $criteria = $this->searchCriteriaBuilder
            ->addFilter('customer_id', $ctx->customerId)
            ->addFilter('store_id', $ctx->storeId)
            ->addSortOrder($this->sortOrderBuilder->setField('created_at')->setDirection(SortOrder::SORT_DESC)->create())
            ->setPageSize($limit)
            ->create();

        $orders = [];
        foreach ($this->orderRepository->getList($criteria)->getItems() as $order) {
            $orders[] = $this->toOrder($order, $ctx);
        }
        return $orders;
    }

    public function getOrder(SessionContext $ctx, string $orderId): ?DataOrderInterface
    {
        if ($ctx->customerId === null) {
            throw new SignInRequired();
        }

        $criteria = $this->searchCriteriaBuilder
            ->addFilter('increment_id', $orderId)
            ->addFilter('customer_id', $ctx->customerId)
            ->setPageSize(1)
            ->create();

        $items = $this->orderRepository->getList($criteria)->getItems();
        $first = reset($items);
        return $first !== false ? $this->toOrder($first, $ctx) : null;
    }

    private function toOrder(SalesOrder $order, SessionContext $ctx): Order
    {
        $items = [];
        foreach ($order->getAllVisibleItems() as $orderItem) {
            $items[] = $this->toOrderItem($orderItem, $ctx);
        }

        $tracks = $order->getTracksCollection();
        $trackingUrl = null;
        foreach ($tracks as $track) {
            $trackingUrl = $this->shippingHelper->getTrackingPopupUrlBySalesModel($track);
            break;
        }

        return new Order(
            (string)$order->getIncrementId(),
            $this->orderStatusMapper->map($order),
            new \DateTimeImmutable((string)$order->getCreatedAt()),
            (float)$order->getGrandTotal(),
            (string)$order->getOrderCurrencyCode(),
            $items,
            null,
            $trackingUrl !== null && $trackingUrl !== '' ? $trackingUrl : null
        );
    }

    private function toOrderItem(SalesOrderItem $orderItem, SessionContext $ctx): OrderItem
    {
        $options = $orderItem->getProductOptions();
        $options = is_array($options) ? $options : [];
        $simpleSku = isset($options['simple_sku']) ? (string)$options['simple_sku'] : null;

        return new OrderItem(
            $this->orderItemProductId($orderItem, $simpleSku, $ctx),
            (string)$orderItem->getName(),
            (int)$orderItem->getQtyOrdered(),
            (float)$orderItem->getPrice(),
            $this->orderItemOptionValues($options),
            $simpleSku !== null && $simpleSku !== '' ? (string)$orderItem->getProductId() : null
        );
    }

    private function orderItemProductId(SalesOrderItem $orderItem, ?string $simpleSku, SessionContext $ctx): string
    {
        if ($simpleSku !== null && $simpleSku !== '') {
            try {
                return (string)$this->productRepository->get($simpleSku, false, $ctx->storeId)->getId();
            } catch (NoSuchEntityException $exception) {
                return (string)$orderItem->getProductId();
            }
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

    public function searchPolicies(SessionContext $ctx, string $query): array
    {
        return $this->policySource->search($ctx, $query);
    }

    public function getFulfillmentOptions(SessionContext $ctx, array $productIds): array
    {
        return $this->fulfillmentProvider->options($ctx, array_slice($productIds, 0, 20));
    }

    public function checkoutHandoff(SessionContext $ctx, CartInterface $cart): array
    {
        return [];
    }
}
