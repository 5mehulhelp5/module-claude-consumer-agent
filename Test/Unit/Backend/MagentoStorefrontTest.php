<?php
declare(strict_types=1);

namespace MageOS\ClaudeConsumerAgent\Test\Unit\Backend;

use Magento\Catalog\Api\Data\ProductCustomOptionInterface;
use Magento\Catalog\Api\Data\ProductCustomOptionValuesInterface;
use Magento\Catalog\Api\Data\ProductSearchResultsInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Helper\Image as ImageHelper;
use Magento\Catalog\Helper\Product\Configuration as ProductConfigurationHelper;
use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\Product as MagentoProduct;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ResourceModel\Category\Collection as CategoryCollection;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Magento\ConfigurableProduct\Model\ResourceModel\Product\Type\Configurable as ConfigurableResource;
use Magento\Customer\Api\AddressRepositoryInterface;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\GroupRepositoryInterface;
use Magento\Framework\Api\SearchCriteria;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SortOrder;
use Magento\Framework\Api\SortOrderBuilder;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\DataObject;
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\Pricing\Amount\AmountInterface;
use Magento\Framework\Pricing\Price\PriceInterface;
use Magento\Framework\Pricing\PriceInfoInterface;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use Magento\InventoryApi\Api\Data\StockInterface;
use Magento\InventorySalesApi\Api\IsProductSalableInterface;
use Magento\InventorySalesApi\Api\StockResolverInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address as QuoteAddress;
use Magento\Quote\Model\Quote\Item as QuoteItem;
use Magento\Quote\Model\Quote\Item\Option as QuoteItemOption;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Shipping\Helper\Data as ShippingHelper;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Store\Model\Website;
use MageOS\ClaudeConsumerAgent\Api\Backend\CategorySearchProviderInterface;
use MageOS\ClaudeConsumerAgent\Api\Backend\FulfillmentProviderInterface;
use MageOS\ClaudeConsumerAgent\Api\Backend\OrderStatusMapperInterface;
use MageOS\ClaudeConsumerAgent\Api\Backend\PolicySourceInterface;
use MageOS\ClaudeConsumerAgent\Api\Backend\SearchProviderInterface;
use MageOS\ClaudeConsumerAgent\Model\Agent\Exception\NotOffered;
use MageOS\ClaudeConsumerAgent\Model\Agent\Exception\SignInRequired;
use MageOS\ClaudeConsumerAgent\Model\Agent\Exception\Unavailable;
use MageOS\ClaudeConsumerAgent\Model\Agent\SessionContext;
use MageOS\ClaudeConsumerAgent\Model\Backend\BuyRequestBuilder;
use MageOS\ClaudeConsumerAgent\Model\Backend\MagentoStorefront;
use MageOS\ClaudeConsumerAgent\Model\Backend\Provider\AllowedCategories;
use MageOS\ClaudeConsumerAgent\Model\Backend\Provider\CoreOptions;
use MageOS\ClaudeConsumerAgent\Model\Backend\ProductMapper;
use MageOS\ClaudeConsumerAgent\Model\Backend\Salability;
use MageOS\ClaudeConsumerAgent\Model\Config\StoreConfig;
use MageOS\ClaudeConsumerAgent\Model\Data\PageContext;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class MagentoStorefrontTest extends TestCase
{
    private function context(?int $customerId = null): SessionContext
    {
        return new SessionContext('session-1', $customerId, 1, 1, new PageContext(), new \DateTimeImmutable('now'));
    }

    private function store(): Store&MockObject
    {
        $website = $this->createMock(Website::class);
        $website->method('getCode')->willReturn('base');

        $store = $this->createMock(Store::class);
        $store->method('getId')->willReturn(1);
        $store->method('getCurrentCurrencyCode')->willReturn('USD');
        $store->method('getWebsite')->willReturn($website);
        $store->method('getWebsiteId')->willReturn(1);
        return $store;
    }

    private function storeManager(): StoreManagerInterface&MockObject
    {
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($this->store());
        return $storeManager;
    }

    private function imageHelper(): ImageHelper&MockObject
    {
        $imageHelper = $this->createMock(ImageHelper::class);
        $imageHelper->method('init')->willReturnSelf();
        $imageHelper->method('getUrl')->willReturn('https://example.test/img.jpg');
        return $imageHelper;
    }

    private function priceInfo(float $value): PriceInfoInterface&MockObject
    {
        $amount = $this->createMock(AmountInterface::class);
        $amount->method('getValue')->willReturn($value);
        $price = $this->createMock(PriceInterface::class);
        $price->method('getAmount')->willReturn($amount);
        $priceInfo = $this->createMock(PriceInfoInterface::class);
        $priceInfo->method('getPrice')->willReturn($price);
        return $priceInfo;
    }

    private function magentoProduct(int $id, string $name, float $price): MagentoProduct&MockObject
    {
        $product = $this->createMock(MagentoProduct::class);
        $product->method('getId')->willReturn($id);
        $product->method('getName')->willReturn($name);
        $product->method('getTypeId')->willReturn('simple');
        $product->method('getPriceInfo')->willReturn($this->priceInfo($price));
        $product->method('getProductUrl')->willReturn(null);
        $product->method('getOptions')->willReturn([]);
        $product->method('getAttributeText')->willReturn(false);
        return $product;
    }

    private function salability(bool $result): Salability
    {
        return $this->salabilityBySku([], $result);
    }

    private function salabilityBySku(array $bySku, bool $default = true): Salability
    {
        $isProductSalable = $this->createMock(IsProductSalableInterface::class);
        $isProductSalable->method('execute')->willReturnCallback(
            static fn (string $sku, int $stockId): bool => $bySku[$sku] ?? $default
        );

        $stock = $this->createMock(StockInterface::class);
        $stock->method('getStockId')->willReturn(1);
        $stockResolver = $this->createMock(StockResolverInterface::class);
        $stockResolver->method('execute')->willReturn($stock);

        $objectManager = $this->createMock(ObjectManagerInterface::class);
        $objectManager->method('get')->willReturnMap([
            [StockResolverInterface::class, $stockResolver],
            [IsProductSalableInterface::class, $isProductSalable],
        ]);

        $stockRegistry = $this->createMock(StockRegistryInterface::class);

        return new Salability($objectManager, $stockRegistry, $this->storeManager());
    }

    private function productMapper(): ProductMapper
    {
        return new ProductMapper($this->storeManager(), $this->imageHelper(), $this->salability(true), new CoreOptions());
    }

    private function searchCriteriaBuilder(): SearchCriteriaBuilder&MockObject
    {
        $builder = $this->createMock(SearchCriteriaBuilder::class);
        $builder->method('addFilter')->willReturnSelf();
        $builder->method('addSortOrder')->willReturnSelf();
        $builder->method('setPageSize')->willReturnSelf();
        $builder->method('create')->willReturn($this->createMock(SearchCriteria::class));
        return $builder;
    }

    private function sortOrderBuilder(): SortOrderBuilder&MockObject
    {
        $builder = $this->createMock(SortOrderBuilder::class);
        $builder->method('setField')->willReturnSelf();
        $builder->method('setDirection')->willReturnSelf();
        $builder->method('create')->willReturn($this->createMock(SortOrder::class));
        return $builder;
    }

    private function storeConfig(array $allowedCategories): StoreConfig
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(
            static fn (string $path): ?string => $path === 'aiagent/content/allowed_categories'
                ? implode(',', $allowedCategories)
                : null
        );
        $scopeConfig->method('isSetFlag')->willReturn(false);

        return new StoreConfig($scopeConfig, $this->storeManager());
    }

    private function category(int $id, string $path): Category&MockObject
    {
        $category = $this->createMock(Category::class);
        $category->method('getId')->willReturn($id);
        $category->method('getPath')->willReturn($path);
        return $category;
    }

    private function allowedCategories(
        array $allowedCategories = [],
        array $categories = [],
        ?CategoryCollectionFactory $collectionFactory = null
    ): AllowedCategories {
        if ($collectionFactory === null) {
            $collection = $this->createMock(CategoryCollection::class);
            $collection->method('setStoreId')->willReturnSelf();
            $collection->method('addAttributeToSelect')->willReturnSelf();
            $collection->method('addIdFilter')->willReturnSelf();
            $collection->method('getIterator')->willReturn(new \ArrayIterator($categories));

            $collectionFactory = $this->createMock(CategoryCollectionFactory::class);
            $collectionFactory->method('create')->willReturn($collection);
        }

        return (new ObjectManager($this))->getObject(AllowedCategories::class, [
            'collectionFactory' => $collectionFactory,
            'storeConfig' => $this->storeConfig($allowedCategories),
        ]);
    }

    private function detailedProduct(int $id, array $categoryIds): MagentoProduct&MockObject
    {
        $product = $this->magentoProduct($id, 'Product ' . $id, 10.0);
        $product->method('getStatus')->willReturn(Status::STATUS_ENABLED);
        $product->method('getVisibility')->willReturn(Visibility::VISIBILITY_BOTH);
        $product->method('getAttributes')->willReturn([]);
        $product->method('getCategoryIds')->willReturn($categoryIds);
        return $product;
    }

    private function defaultDependencies(): array
    {
        return [
            'productRepository' => $this->createMock(ProductRepositoryInterface::class),
            'searchCriteriaBuilder' => $this->searchCriteriaBuilder(),
            'sortOrderBuilder' => $this->sortOrderBuilder(),
            'storeManager' => $this->storeManager(),
            'imageHelper' => $this->imageHelper(),
            'productConfiguration' => $this->createMock(ProductConfigurationHelper::class),
            'searchProvider' => $this->createMock(SearchProviderInterface::class),
            'productMapper' => $this->productMapper(),
            'salability' => $this->salability(true),
            'buyRequestBuilder' => new BuyRequestBuilder(),
            'configurableResource' => $this->createMock(ConfigurableResource::class),
            'cartRepository' => $this->createMock(CartRepositoryInterface::class),
            'customerRepository' => $this->createMock(CustomerRepositoryInterface::class),
            'groupRepository' => $this->createMock(GroupRepositoryInterface::class),
            'addressRepository' => $this->createMock(AddressRepositoryInterface::class),
            'orderRepository' => $this->createMock(OrderRepositoryInterface::class),
            'orderStatusMapper' => $this->createMock(OrderStatusMapperInterface::class),
            'shippingHelper' => $this->createMock(ShippingHelper::class),
            'policySource' => $this->createMock(PolicySourceInterface::class),
            'fulfillmentProvider' => $this->createMock(FulfillmentProviderInterface::class),
            'categorySearchProvider' => $this->createMock(CategorySearchProviderInterface::class),
            'allowedCategories' => $this->allowedCategories(),
        ];
    }

    private function buildStorefront(array $overrides = []): MagentoStorefront
    {
        $deps = array_merge($this->defaultDependencies(), $overrides);
        return new MagentoStorefront(...$deps);
    }

    public function testSearchProductsOrdersByRankAndDropsMissingIds(): void
    {
        $searchProvider = $this->createMock(SearchProviderInterface::class);
        $searchProvider->method('search')->willReturn([10, 20, 30]);

        $product10 = $this->magentoProduct(10, 'Product Ten', 10.0);
        $product30 = $this->magentoProduct(30, 'Product Thirty', 30.0);

        $results = $this->createMock(ProductSearchResultsInterface::class);
        $results->method('getItems')->willReturn([$product30, $product10]);
        $productRepository = $this->createMock(ProductRepositoryInterface::class);
        $productRepository->method('getList')->willReturn($results);

        $storefront = $this->buildStorefront([
            'productRepository' => $productRepository,
            'searchProvider' => $searchProvider,
        ]);

        $records = $storefront->searchProducts($this->context(), 'widget', null, 10);

        $this->assertCount(2, $records);
        $this->assertSame('10', $records[0]->getProductId());
        $this->assertSame('30', $records[1]->getProductId());
    }

    public function testSearchProductsReturnsEmptyWhenSearchProviderFindsNothing(): void
    {
        $searchProvider = $this->createMock(SearchProviderInterface::class);
        $searchProvider->method('search')->willReturn([]);

        $storefront = $this->buildStorefront(['searchProvider' => $searchProvider]);

        $this->assertSame([], $storefront->searchProducts($this->context(), 'widget', null, 10));
    }

    public function testGetCartMapsConfigurableItemToChildWithVariantOf(): void
    {
        $child = $this->magentoProduct(501, 'Red Shirt - M', 25.0);
        $child->method('getData')->willReturnCallback(
            static fn (string $key = '') => $key === 'color' ? '10' : null
        );

        $parentTypeInstance = $this->createMock(Configurable::class);
        $parentTypeInstance->method('getConfigurableAttributesAsArray')->willReturn([
            [
                'attribute_code' => 'color',
                'label' => 'Color',
                'options' => [['value' => '10', 'label' => 'Red']],
            ],
        ]);
        $parent = $this->magentoProduct(500, 'Shirt', 25.0);
        $parent->method('getTypeInstance')->willReturn($parentTypeInstance);

        $option = $this->createMock(QuoteItemOption::class);
        $option->method('getProduct')->willReturn($child);

        $item = $this->getMockBuilder(QuoteItem::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getProductType', 'getOptionByCode', 'getProduct', 'getName', 'getQty', 'getId', 'getPrice'])
            ->addMethods(['getPriceInclTax'])
            ->getMock();
        $item->method('getProductType')->willReturn(Configurable::TYPE_CODE);
        $item->method('getOptionByCode')->with('simple_product')->willReturn($option);
        $item->method('getProduct')->willReturn($parent);
        $item->method('getName')->willReturn('Red Shirt - M');
        $item->method('getQty')->willReturn(2.0);
        $item->method('getId')->willReturn(77);
        $item->method('getPriceInclTax')->willReturn(25.0);
        $item->method('getPrice')->willReturn(25.0);

        $quote = $this->createMock(Quote::class);
        $quote->method('getAllVisibleItems')->willReturn([$item]);

        $cartRepository = $this->createMock(CartRepositoryInterface::class);
        $cartRepository->method('get')->willReturn($quote);

        $productConfiguration = $this->createMock(ProductConfigurationHelper::class);
        $productConfiguration->method('getOptions')->willReturn([]);

        $storefront = $this->buildStorefront([
            'cartRepository' => $cartRepository,
            'productConfiguration' => $productConfiguration,
        ]);

        $cart = $storefront->getCart($this->context());

        $this->assertCount(1, $cart->getItems());
        $cartItem = $cart->getItems()[0];
        $this->assertSame('501', $cartItem->getProductId());
        $this->assertSame('500', $cartItem->getVariantOf());
        $this->assertSame(['Color' => 'Red'], $cartItem->getOptionValues());
        $this->assertSame(2, $cartItem->getQuantity());
        $this->assertSame(77, $cartItem->getItemId());
    }

    public function testAddToCartRaisesUnavailableWithSiblingIds(): void
    {
        $child = $this->magentoProduct(201, 'Blue Shirt - S', 20.0);
        $child->method('getSku')->willReturn('SHIRT-BLUE-S');

        $sibling1 = $this->magentoProduct(202, 'Blue Shirt - M', 20.0);
        $sibling1->method('getSku')->willReturn('SHIRT-BLUE-M');
        $sibling2 = $this->magentoProduct(203, 'Blue Shirt - L', 20.0);
        $sibling2->method('getSku')->willReturn('SHIRT-BLUE-L');

        $parentTypeInstance = $this->createMock(Configurable::class);
        $parentTypeInstance->method('getUsedProducts')->willReturn([$child, $sibling1, $sibling2]);
        $parent = $this->magentoProduct(200, 'Blue Shirt', 20.0);
        $parent->method('getTypeInstance')->willReturn($parentTypeInstance);

        $productRepository = $this->createMock(ProductRepositoryInterface::class);
        $productRepository->method('getById')->willReturnCallback(
            static fn (int $id) => match ($id) {
                201 => $child,
                200 => $parent,
                default => null,
            }
        );

        $configurableResource = $this->createMock(ConfigurableResource::class);
        $configurableResource->method('getParentIdsByChild')->willReturn(['200']);

        $cartRepository = $this->createMock(CartRepositoryInterface::class);
        $cartRepository->method('get')->willReturn($this->createMock(Quote::class));

        $storefront = $this->buildStorefront([
            'productRepository' => $productRepository,
            'configurableResource' => $configurableResource,
            'cartRepository' => $cartRepository,
            'salability' => $this->salabilityBySku([
                'SHIRT-BLUE-S' => false,
                'SHIRT-BLUE-M' => true,
                'SHIRT-BLUE-L' => true,
            ]),
        ]);

        $this->expectException(Unavailable::class);
        $this->expectExceptionMessage('201 is out of stock; in stock: 202, 203');

        $storefront->addToCart($this->context(), '201', 1);
    }

    public function testAddToCartUsesTheLoadedMagentoProductForTheBuyRequest(): void
    {
        $product = $this->magentoProduct(301, 'Solo Item', 12.0);
        $product->method('getSku')->willReturn('SOLO-1');

        $productRepository = $this->createMock(ProductRepositoryInterface::class);
        $productRepository->method('getById')->willReturn($product);

        $configurableResource = $this->createMock(ConfigurableResource::class);
        $configurableResource->method('getParentIdsByChild')->willReturn([]);

        $shippingAddress = $this->getMockBuilder(QuoteAddress::class)
            ->disableOriginalConstructor()
            ->addMethods(['setCollectShippingRates'])
            ->getMock();
        $shippingAddress->expects($this->once())->method('setCollectShippingRates')->with(true);

        $quote = $this->createMock(Quote::class);
        $quote->expects($this->once())->method('addProduct')->with(
            $product,
            $this->callback(static fn (DataObject $request): bool => $request->getData('product') === 301
                && $request->getData('qty') === 1)
        )->willReturn(true);
        $quote->method('getAllVisibleItems')->willReturn([]);
        $quote->expects($this->once())->method('getBillingAddress')->willReturn($this->createMock(QuoteAddress::class));
        $quote->expects($this->once())->method('getShippingAddress')->willReturn($shippingAddress);

        $cartRepository = $this->createMock(CartRepositoryInterface::class);
        $cartRepository->method('get')->willReturn($quote);

        $storefront = $this->buildStorefront([
            'productRepository' => $productRepository,
            'configurableResource' => $configurableResource,
            'cartRepository' => $cartRepository,
            'salability' => $this->salabilityBySku(['SOLO-1' => true]),
        ]);

        $storefront->addToCart($this->context(), '301', 1);
    }

    private function sizeCustomOption(): ProductCustomOptionInterface&MockObject
    {
        $small = $this->createMock(ProductCustomOptionValuesInterface::class);
        $small->method('getTitle')->willReturn('1 CUP');
        $small->method('getOptionTypeId')->willReturn(11);

        $large = $this->createMock(ProductCustomOptionValuesInterface::class);
        $large->method('getTitle')->willReturn('3 CUP');
        $large->method('getOptionTypeId')->willReturn(12);

        $option = $this->createMock(ProductCustomOptionInterface::class);
        $option->method('getOptionId')->willReturn(7);
        $option->method('getTitle')->willReturn('Size');
        $option->method('getType')->willReturn('drop_down');
        $option->method('getValues')->willReturn([$small, $large]);

        return $option;
    }

    private function productWithCustomOption(
        int $id,
        string $name,
        float $price,
        string $sku,
        ProductCustomOptionInterface $option
    ): MagentoProduct&MockObject {
        $product = $this->createMock(MagentoProduct::class);
        $product->method('getId')->willReturn($id);
        $product->method('getName')->willReturn($name);
        $product->method('getTypeId')->willReturn('simple');
        $product->method('getPriceInfo')->willReturn($this->priceInfo($price));
        $product->method('getProductUrl')->willReturn(null);
        $product->method('getSku')->willReturn($sku);
        $product->method('getAttributeText')->willReturn(false);
        $product->method('getOptions')->willReturn([$option]);
        return $product;
    }

    private function quoteAcceptingAdd(MagentoProduct $product, callable $requestAssertion): Quote&MockObject
    {
        $shippingAddress = $this->getMockBuilder(QuoteAddress::class)
            ->disableOriginalConstructor()
            ->addMethods(['setCollectShippingRates'])
            ->getMock();

        $quote = $this->createMock(Quote::class);
        $quote->expects($this->once())->method('addProduct')->with(
            $product,
            $this->callback($requestAssertion)
        )->willReturn(true);
        $quote->method('getAllVisibleItems')->willReturn([]);
        $quote->method('getBillingAddress')->willReturn($this->createMock(QuoteAddress::class));
        $quote->method('getShippingAddress')->willReturn($shippingAddress);
        return $quote;
    }

    public function testAddToCartResolvesCustomOptionTitleToValueIdCaseInsensitively(): void
    {
        $product = $this->productWithCustomOption(301, 'Alessi 9090', 175.0, 'ALESSI-9090', $this->sizeCustomOption());

        $productRepository = $this->createMock(ProductRepositoryInterface::class);
        $productRepository->method('getById')->willReturn($product);

        $configurableResource = $this->createMock(ConfigurableResource::class);
        $configurableResource->method('getParentIdsByChild')->willReturn([]);

        $quote = $this->quoteAcceptingAdd(
            $product,
            static fn (DataObject $request): bool => $request->getData('options') === [7 => '12']
        );

        $cartRepository = $this->createMock(CartRepositoryInterface::class);
        $cartRepository->method('get')->willReturn($quote);

        $storefront = $this->buildStorefront([
            'productRepository' => $productRepository,
            'configurableResource' => $configurableResource,
            'cartRepository' => $cartRepository,
            'salability' => $this->salabilityBySku(['ALESSI-9090' => true]),
        ]);

        $storefront->addToCart($this->context(), '301', 1, ['size' => '3 cup']);
    }

    public function testAddToCartThrowsNotOfferedForAnOptionTitleTheProductDoesNotOffer(): void
    {
        $product = $this->productWithCustomOption(301, 'Alessi 9090', 175.0, 'ALESSI-9090', $this->sizeCustomOption());

        $productRepository = $this->createMock(ProductRepositoryInterface::class);
        $productRepository->method('getById')->willReturn($product);

        $configurableResource = $this->createMock(ConfigurableResource::class);
        $configurableResource->method('getParentIdsByChild')->willReturn([]);

        $cartRepository = $this->createMock(CartRepositoryInterface::class);
        $cartRepository->method('get')->willReturn($this->createMock(Quote::class));

        $storefront = $this->buildStorefront([
            'productRepository' => $productRepository,
            'configurableResource' => $configurableResource,
            'cartRepository' => $cartRepository,
            'salability' => $this->salabilityBySku(['ALESSI-9090' => true]),
        ]);

        $this->expectException(NotOffered::class);
        $this->expectExceptionMessage('Alessi 9090 has no option Colour');

        $storefront->addToCart($this->context(), '301', 1, ['Colour' => 'Chrome']);
    }

    public function testAddToCartThrowsNotOfferedForAValueTheOptionDoesNotOffer(): void
    {
        $product = $this->productWithCustomOption(301, 'Alessi 9090', 175.0, 'ALESSI-9090', $this->sizeCustomOption());

        $productRepository = $this->createMock(ProductRepositoryInterface::class);
        $productRepository->method('getById')->willReturn($product);

        $configurableResource = $this->createMock(ConfigurableResource::class);
        $configurableResource->method('getParentIdsByChild')->willReturn([]);

        $cartRepository = $this->createMock(CartRepositoryInterface::class);
        $cartRepository->method('get')->willReturn($this->createMock(Quote::class));

        $storefront = $this->buildStorefront([
            'productRepository' => $productRepository,
            'configurableResource' => $configurableResource,
            'cartRepository' => $cartRepository,
            'salability' => $this->salabilityBySku(['ALESSI-9090' => true]),
        ]);

        $this->expectException(NotOffered::class);
        $this->expectExceptionMessage('Alessi 9090 has no value 5 CUP for Size');

        $storefront->addToCart($this->context(), '301', 1, ['Size' => '5 CUP']);
    }

    public function testAddToCartPassesRawTextForAFieldTypeCustomOption(): void
    {
        $fieldOption = $this->createMock(ProductCustomOptionInterface::class);
        $fieldOption->method('getOptionId')->willReturn(4);
        $fieldOption->method('getTitle')->willReturn('Engraving Text');
        $fieldOption->method('getType')->willReturn('field');
        $fieldOption->method('getValues')->willReturn([]);

        $product = $this->productWithCustomOption(302, 'Engraved Mug', 25.0, 'MUG-1', $fieldOption);

        $productRepository = $this->createMock(ProductRepositoryInterface::class);
        $productRepository->method('getById')->willReturn($product);

        $configurableResource = $this->createMock(ConfigurableResource::class);
        $configurableResource->method('getParentIdsByChild')->willReturn([]);

        $quote = $this->quoteAcceptingAdd(
            $product,
            static fn (DataObject $request): bool => $request->getData('options') === [4 => 'World\'s Best Dad']
        );

        $cartRepository = $this->createMock(CartRepositoryInterface::class);
        $cartRepository->method('get')->willReturn($quote);

        $storefront = $this->buildStorefront([
            'productRepository' => $productRepository,
            'configurableResource' => $configurableResource,
            'cartRepository' => $cartRepository,
            'salability' => $this->salabilityBySku(['MUG-1' => true]),
        ]);

        $storefront->addToCart($this->context(), '302', 1, ['Engraving Text' => 'World\'s Best Dad']);
    }

    public function testGetProductDetailsSetsNoteWhenVariantsAreCapped(): void
    {
        $children = [];
        for ($i = 1; $i <= 61; $i++) {
            $children[] = $this->magentoProduct(1000 + $i, 'Variant ' . $i, (float)$i);
        }

        $typeInstance = $this->createMock(Configurable::class);
        $typeInstance->method('getConfigurableAttributesAsArray')->willReturn([]);
        $typeInstance->method('getUsedProducts')->willReturn($children);

        $parent = $this->createMock(MagentoProduct::class);
        $parent->method('getId')->willReturn(900);
        $parent->method('getName')->willReturn('Configurable Parent');
        $parent->method('getTypeId')->willReturn(Configurable::TYPE_CODE);
        $parent->method('getPriceInfo')->willReturn($this->priceInfo(1.0));
        $parent->method('getProductUrl')->willReturn(null);
        $parent->method('getOptions')->willReturn([]);
        $parent->method('getAttributeText')->willReturn(false);
        $parent->method('getTypeInstance')->willReturn($typeInstance);
        $parent->method('getStatus')->willReturn(Status::STATUS_ENABLED);
        $parent->method('getVisibility')->willReturn(Visibility::VISIBILITY_BOTH);
        $parent->method('getAttributes')->willReturn([]);

        $productRepository = $this->createMock(ProductRepositoryInterface::class);
        $productRepository->method('getById')->willReturn($parent);

        $storefront = $this->buildStorefront(['productRepository' => $productRepository]);

        $details = $storefront->getProductDetails($this->context(), '900');

        $this->assertNotNull($details);
        $this->assertCount(60, $details->getVariants());
        $this->assertSame(
            'More variants exist; ask about a size or colour to narrow the list.',
            $details->getNote()
        );
    }

    public function testGetProductDetailsReturnsNullForAProductOutsideTheAllowlist(): void
    {
        $productRepository = $this->createMock(ProductRepositoryInterface::class);
        $productRepository->method('getById')->willReturn($this->detailedProduct(700, [7]));

        $storefront = $this->buildStorefront([
            'productRepository' => $productRepository,
            'allowedCategories' => $this->allowedCategories([10], [$this->category(7, '1/2/5/7')]),
        ]);

        $this->assertNull($storefront->getProductDetails($this->context(), '700'));
    }

    public function testGetProductDetailsReturnsAProductUnderAnAllowedCategory(): void
    {
        $productRepository = $this->createMock(ProductRepositoryInterface::class);
        $productRepository->method('getById')->willReturn($this->detailedProduct(701, [55]));

        $storefront = $this->buildStorefront([
            'productRepository' => $productRepository,
            'allowedCategories' => $this->allowedCategories([10], [$this->category(55, '1/2/10/55')]),
        ]);

        $details = $storefront->getProductDetails($this->context(), '701');

        $this->assertNotNull($details);
        $this->assertSame('701', $details->getProductId());
    }

    public function testGetProductDetailsIgnoresTheAllowlistWhenItIsEmpty(): void
    {
        $productRepository = $this->createMock(ProductRepositoryInterface::class);
        $productRepository->method('getById')->willReturn($this->detailedProduct(702, [7]));

        $collectionFactory = $this->createMock(CategoryCollectionFactory::class);
        $collectionFactory->expects($this->never())->method('create');

        $storefront = $this->buildStorefront([
            'productRepository' => $productRepository,
            'allowedCategories' => $this->allowedCategories([], [], $collectionFactory),
        ]);

        $this->assertNotNull($storefront->getProductDetails($this->context(), '702'));
    }

    public function testGetOrdersThrowsSignInRequiredForGuest(): void
    {
        $storefront = $this->buildStorefront();

        $this->expectException(SignInRequired::class);

        $storefront->getOrders($this->context(null), 10);
    }

    public function testGetOrderThrowsSignInRequiredForGuest(): void
    {
        $storefront = $this->buildStorefront();

        $this->expectException(SignInRequired::class);

        $storefront->getOrder($this->context(null), '100000001');
    }
}
