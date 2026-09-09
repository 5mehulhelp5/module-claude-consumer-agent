<?php
declare(strict_types=1);

namespace MageOS\ClaudeConsumerAgent\Test\Unit\Presentation;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use MageOS\ClaudeConsumerAgent\Api\Data\PageContextInterface;
use MageOS\ClaudeConsumerAgent\Api\StorefrontBackendInterface;
use MageOS\ClaudeConsumerAgent\Model\Agent\Presentation\Enrich\Checkout;
use MageOS\ClaudeConsumerAgent\Model\Agent\Presentation\Enrich\Comparison;
use MageOS\ClaudeConsumerAgent\Model\Agent\Presentation\Enrich\OrderStatus;
use MageOS\ClaudeConsumerAgent\Model\Agent\Presentation\Enrich\Products;
use MageOS\ClaudeConsumerAgent\Model\Agent\Presentation\Enrich\Suggestions;
use MageOS\ClaudeConsumerAgent\Model\Agent\Presentation\Registry;
use MageOS\ClaudeConsumerAgent\Model\Agent\Presentation\Runner;
use MageOS\ClaudeConsumerAgent\Model\Agent\SessionContext;
use MageOS\ClaudeConsumerAgent\Model\Agent\SessionState;
use MageOS\ClaudeConsumerAgent\Model\Config\StoreConfig;
use MageOS\ClaudeConsumerAgent\Model\Data\Product;
use MageOS\ClaudeConsumerAgent\Model\Data\ProductDetails;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class RunnerTest extends TestCase
{
    private const SERIALIZER_CLASS = \MageOS\ClaudeConsumerAgent\Model\Agent\Serializer::class;
    private const FENCE_CLASS = \MageOS\ClaudeConsumerAgent\Model\Agent\Fencing\Fence::class;
    private const SANITIZER_CLASS = \MageOS\ClaudeConsumerAgent\Model\Agent\Fencing\Sanitizer::class;
    private const VALIDATOR_CLASS = \MageOS\ClaudeConsumerAgent\Model\Agent\Schema\Validator::class;

    protected function setUp(): void
    {
        foreach ([self::SERIALIZER_CLASS, self::FENCE_CLASS, self::SANITIZER_CLASS, self::VALIDATOR_CLASS] as $class) {
            if (!class_exists($class)) {
                $this->markTestSkipped($class . ' is not present on disk yet (task T3 has not landed).');
            }
        }
    }

    private function buildRunner(StorefrontBackendInterface $backend, ?LoggerInterface $logger = null): Runner
    {
        $sanitizerClass = self::SANITIZER_CLASS;
        $fenceClass = self::FENCE_CLASS;
        $serializerClass = self::SERIALIZER_CLASS;
        $validatorClass = self::VALIDATOR_CLASS;
        $sanitizer = new $sanitizerClass();
        $fence = new $fenceClass($sanitizer);
        $serializer = new $serializerClass($fence);
        $registry = new Registry(
            new Products($logger ?? $this->createMock(LoggerInterface::class)),
            new Comparison(),
            new OrderStatus($serializer),
            new Checkout($serializer),
            new Suggestions($sanitizer)
        );
        $validator = new $validatorClass();
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturn(null);
        $scopeConfig->method('isSetFlag')->willReturn(false);
        $store = $this->createMock(StoreInterface::class);
        $store->method('getName')->willReturn('Test Store');
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);
        $storeConfig = new StoreConfig($scopeConfig, $storeManager);
        return new Runner($registry, $validator, $backend, $storeConfig);
    }

    private function context(): SessionContext
    {
        $page = $this->createMock(PageContextInterface::class);
        return new SessionContext('sess-1', null, 1, 1, $page, new \DateTimeImmutable('now'));
    }

    private function stateWithSeenProducts(array $records): SessionState
    {
        $state = new SessionState();
        $state->rememberProducts($records);
        return $state;
    }

    private function familySeenProduct(): array
    {
        return [
            'product_id' => 'p-500',
            'title' => 'Trail Tent',
            'price' => 199.0,
            'currency' => 'USD',
            'options' => ['Size' => ['Small', 'Medium', 'Large']],
        ];
    }

    public function testDroppedIdsAreReportedInNotes(): void
    {
        $backend = $this->createMock(StorefrontBackendInterface::class);
        $runner = $this->buildRunner($backend);
        $state = $this->stateWithSeenProducts([
            ['product_id' => 'p-100', 'title' => 'Tent', 'price' => 149.0, 'currency' => 'USD'],
        ]);

        $outcome = $runner->run(
            'present_products',
            ['picks' => [['product_id' => 'p-100'], ['product_id' => 'ghost-1']]],
            $this->context(),
            $state
        );

        $this->assertFalse($outcome->isError);
        $this->assertStringContainsString(
            'Skipped unknown product_ids not seen in this session: ghost-1.',
            $outcome->resultText
        );
        $this->assertCount(1, $outcome->events);
        $this->assertSame('products', $outcome->events[0]->data['component']);
        $this->assertCount(1, $outcome->events[0]->data['payload']['items']);
        $this->assertArrayHasKey('reason', $outcome->events[0]->data['payload']['items'][0]);
        $this->assertNull($outcome->events[0]->data['payload']['items'][0]['reason']);
    }

    public function testProductItemCarriesTheGivenReason(): void
    {
        $backend = $this->createMock(StorefrontBackendInterface::class);
        $runner = $this->buildRunner($backend);
        $state = $this->stateWithSeenProducts([
            ['product_id' => 'p-100', 'title' => 'Tent', 'price' => 149.0, 'currency' => 'USD'],
        ]);

        $outcome = $runner->run(
            'present_products',
            ['picks' => [['product_id' => 'p-100', 'reason' => 'Fits a family of four']]],
            $this->context(),
            $state
        );

        $this->assertFalse($outcome->isError);
        $this->assertSame(
            'Fits a family of four',
            $outcome->events[0]->data['payload']['items'][0]['reason']
        );
    }

    public function testProductItemCarriesOptionValuesAndVariantOfPassthrough(): void
    {
        $backend = $this->createMock(StorefrontBackendInterface::class);
        $runner = $this->buildRunner($backend);
        $state = $this->stateWithSeenProducts([
            [
                'product_id' => 'p-100-m',
                'title' => 'Tent',
                'price' => 159.0,
                'currency' => 'USD',
                'option_values' => ['Size' => 'Medium'],
                'variant_of' => 'p-100',
            ],
        ]);

        $outcome = $runner->run(
            'present_products',
            ['picks' => [['product_id' => 'p-100-m', 'reason' => 'Medium fits the site']]],
            $this->context(),
            $state
        );

        $this->assertFalse($outcome->isError);
        $item = $outcome->events[0]->data['payload']['items'][0];
        $this->assertSame(['Size' => 'Medium'], $item['option_values']);
        $this->assertSame('p-100', $item['variant_of']);
        $this->assertSame(['Size' => 'Medium'], $item['product']['option_values']);
        $this->assertSame('p-100', $item['product']['variant_of']);
    }

    public function testFamilyPickExpandsIntoVariantItemsWithOptionValuesInReason(): void
    {
        $variantSmall = new Product(
            productId: 'p-500-s',
            title: 'Trail Tent - Small',
            price: 179.0,
            currency: 'USD',
            optionValues: ['Size' => 'Small'],
            variantOf: 'p-500'
        );
        $variantLarge = new Product(
            productId: 'p-500-l',
            title: 'Trail Tent - Large',
            price: 219.0,
            currency: 'USD',
            optionValues: ['Size' => 'Large'],
            variantOf: 'p-500'
        );
        $details = ProductDetails::fromProduct(
            Product::fromArray($this->familySeenProduct()),
            null,
            [],
            [$variantSmall, $variantLarge]
        );
        $backend = $this->createMock(StorefrontBackendInterface::class);
        $backend->method('getProductDetails')->willReturn($details);
        $runner = $this->buildRunner($backend);
        $state = $this->stateWithSeenProducts([$this->familySeenProduct()]);

        $outcome = $runner->run(
            'present_products',
            ['picks' => [['product_id' => 'p-500', 'reason' => 'Fits a family of four']]],
            $this->context(),
            $state
        );

        $this->assertFalse($outcome->isError);
        $items = $outcome->events[0]->data['payload']['items'];
        $this->assertCount(2, $items);
        $this->assertSame('p-500-s', $items[0]['product']['product_id']);
        $this->assertSame('Fits a family of four', $items[0]['reason']);
        $this->assertSame('Trail Tent', $items[0]['product']['title']);
        $this->assertSame(['Size' => 'Small'], $items[0]['option_values']);
        $this->assertSame('p-500-l', $items[1]['product']['product_id']);
        $this->assertSame('Fits a family of four', $items[1]['reason']);
        $this->assertStringContainsString('Expanded Trail Tent into 2 variants.', $outcome->resultText);
    }

    public function testFamilyExpansionRemembersVariantsInState(): void
    {
        $variant = new Product(
            productId: 'p-500-m',
            title: 'Trail Tent - Medium',
            price: 199.0,
            currency: 'USD',
            optionValues: ['Size' => 'Medium'],
            variantOf: 'p-500'
        );
        $details = ProductDetails::fromProduct(
            Product::fromArray($this->familySeenProduct()),
            null,
            [],
            [$variant]
        );
        $backend = $this->createMock(StorefrontBackendInterface::class);
        $backend->method('getProductDetails')->willReturn($details);
        $runner = $this->buildRunner($backend);
        $state = $this->stateWithSeenProducts([$this->familySeenProduct()]);

        $runner->run(
            'present_products',
            ['picks' => [['product_id' => 'p-500']]],
            $this->context(),
            $state
        );

        $this->assertTrue($state->hasSeen('p-500-m'));
        $this->assertSame('Medium', $state->seen('p-500-m')['option_values']['Size']);
    }

    public function testFamilyExpansionCapsCardAtTwelveItems(): void
    {
        $variants = [];
        for ($i = 1; $i <= 15; $i++) {
            $variants[] = new Product(
                productId: "p-500-v{$i}",
                title: "Trail Tent - Variant {$i}",
                price: 199.0,
                currency: 'USD',
                optionValues: ['Size' => "Size {$i}"],
                variantOf: 'p-500'
            );
        }
        $details = ProductDetails::fromProduct(
            Product::fromArray($this->familySeenProduct()),
            null,
            [],
            $variants
        );
        $backend = $this->createMock(StorefrontBackendInterface::class);
        $backend->method('getProductDetails')->willReturn($details);
        $runner = $this->buildRunner($backend);
        $state = $this->stateWithSeenProducts([$this->familySeenProduct()]);

        $outcome = $runner->run(
            'present_products',
            ['picks' => [['product_id' => 'p-500']]],
            $this->context(),
            $state
        );

        $items = $outcome->events[0]->data['payload']['items'];
        $this->assertCount(12, $items);
        $this->assertSame('p-500-v12', $items[11]['product']['product_id']);
    }

    public function testFamilyExpansionListsInStockVariantsBeforeOutOfStock(): void
    {
        $variantIn = new Product(
            productId: 'p-500-in',
            title: 'Trail Tent - In Stock',
            price: 199.0,
            currency: 'USD',
            inStock: true,
            optionValues: ['Size' => 'Small'],
            variantOf: 'p-500'
        );
        $variantOut = new Product(
            productId: 'p-500-out',
            title: 'Trail Tent - Out Of Stock',
            price: 199.0,
            currency: 'USD',
            inStock: false,
            optionValues: ['Size' => 'Medium'],
            variantOf: 'p-500'
        );
        $variantInTwo = new Product(
            productId: 'p-500-in2',
            title: 'Trail Tent - In Stock Two',
            price: 199.0,
            currency: 'USD',
            inStock: true,
            optionValues: ['Size' => 'Large'],
            variantOf: 'p-500'
        );
        $details = ProductDetails::fromProduct(
            Product::fromArray($this->familySeenProduct()),
            null,
            [],
            [$variantOut, $variantIn, $variantInTwo]
        );
        $backend = $this->createMock(StorefrontBackendInterface::class);
        $backend->method('getProductDetails')->willReturn($details);
        $runner = $this->buildRunner($backend);
        $state = $this->stateWithSeenProducts([$this->familySeenProduct()]);

        $outcome = $runner->run(
            'present_products',
            ['picks' => [['product_id' => 'p-500']]],
            $this->context(),
            $state
        );

        $items = $outcome->events[0]->data['payload']['items'];
        $this->assertSame(
            ['p-500-in', 'p-500-in2', 'p-500-out'],
            array_column(array_column($items, 'product'), 'product_id')
        );
    }

    public function testFamilyExpansionKeepsFamilyItemWhenBackendThrows(): void
    {
        $backend = $this->createMock(StorefrontBackendInterface::class);
        $backend->method('getProductDetails')->willThrowException(new \RuntimeException('backend unavailable'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning');
        $runner = $this->buildRunner($backend, $logger);
        $state = $this->stateWithSeenProducts([$this->familySeenProduct()]);

        $outcome = $runner->run(
            'present_products',
            ['picks' => [['product_id' => 'p-500', 'reason' => 'Fits a family of four']]],
            $this->context(),
            $state
        );

        $this->assertFalse($outcome->isError);
        $items = $outcome->events[0]->data['payload']['items'];
        $this->assertCount(1, $items);
        $this->assertSame('p-500', $items[0]['product']['product_id']);
        $this->assertSame('Fits a family of four', $items[0]['reason']);
    }

    public function testRefusedEmptyProductsComponentCarriesProvenanceGate(): void
    {
        $backend = $this->createMock(StorefrontBackendInterface::class);
        $runner = $this->buildRunner($backend);

        $outcome = $runner->run(
            'present_products',
            ['picks' => [['product_id' => 'ghost-1'], ['product_id' => 'ghost-2']]],
            $this->context(),
            new SessionState()
        );

        $this->assertFalse($outcome->isError);
        $this->assertSame('provenance', $outcome->blocked);
        $this->assertSame([], $outcome->events);
    }

    public function testComparisonPriceDelta(): void
    {
        $backend = $this->createMock(StorefrontBackendInterface::class);
        $runner = $this->buildRunner($backend);
        $state = $this->stateWithSeenProducts([
            ['product_id' => 'p-100', 'title' => 'Tent', 'price' => 149.0, 'currency' => 'USD'],
            ['product_id' => 'p-200', 'title' => 'Stove', 'price' => 89.0, 'currency' => 'USD'],
        ]);

        $outcome = $runner->run(
            'present_comparison',
            ['entries' => [['product_id' => 'p-100'], ['product_id' => 'p-200']]],
            $this->context(),
            $state
        );

        $this->assertFalse($outcome->isError);
        $payload = $outcome->events[0]->data['payload'];
        $this->assertSame([
            'amount' => 60.0,
            'low_product_id' => 'p-200',
            'low_price' => 89.0,
            'high_product_id' => 'p-100',
            'high_price' => 149.0,
        ], $payload['price_delta']);
        $this->assertSame([], $payload['entries'][0]['pros']);
        $this->assertSame([], $payload['entries'][0]['cons']);
    }

    public function testComparisonEntryCarriesTheGivenProsAndCons(): void
    {
        $backend = $this->createMock(StorefrontBackendInterface::class);
        $runner = $this->buildRunner($backend);
        $state = $this->stateWithSeenProducts([
            ['product_id' => 'p-100', 'title' => 'Tent', 'price' => 149.0, 'currency' => 'USD'],
            ['product_id' => 'p-200', 'title' => 'Stove', 'price' => 89.0, 'currency' => 'USD'],
        ]);

        $outcome = $runner->run(
            'present_comparison',
            [
                'entries' => [
                    ['product_id' => 'p-100', 'pros' => ['Roomier'], 'cons' => ['Heavier']],
                    ['product_id' => 'p-200'],
                ],
            ],
            $this->context(),
            $state
        );

        $this->assertFalse($outcome->isError);
        $payload = $outcome->events[0]->data['payload'];
        $this->assertSame(['Roomier'], $payload['entries'][0]['pros']);
        $this->assertSame(['Heavier'], $payload['entries'][0]['cons']);
        $this->assertSame([], $payload['entries'][1]['pros']);
        $this->assertSame([], $payload['entries'][1]['cons']);
    }

    public function testChipSanitizingThroughARealSanitizer(): void
    {
        $backend = $this->createMock(StorefrontBackendInterface::class);
        $runner = $this->buildRunner($backend);

        $outcome = $runner->run(
            'present_suggestions',
            ['suggestions' => ["Add\u{200b} to\x07cart", "\u{200b}\u{feff}", 'Compare the top two']],
            $this->context(),
            new SessionState()
        );

        $this->assertFalse($outcome->isError);
        $this->assertSame(
            ['Add to cart', 'Compare the top two'],
            $outcome->events[0]->data['payload']['suggestions']
        );
    }

    public function testUnknownKeysAreDroppedNotRejected(): void
    {
        $backend = $this->createMock(StorefrontBackendInterface::class);
        $runner = $this->buildRunner($backend);

        $outcome = $runner->run(
            'present_suggestions',
            ['suggestions' => ['Compare the top two'], 'status' => 'Building your suggestions'],
            $this->context(),
            new SessionState()
        );

        $this->assertFalse($outcome->isError);
        $this->assertArrayNotHasKey('status', $outcome->events[0]->data['payload']);
    }

    public function testStreamIdPassthroughAndFallbackToComponentName(): void
    {
        $backend = $this->createMock(StorefrontBackendInterface::class);
        $runner = $this->buildRunner($backend);

        $withStreamId = $runner->run(
            'present_suggestions',
            ['suggestions' => ['Compare the top two']],
            $this->context(),
            new SessionState(),
            'tu_123'
        );
        $this->assertSame('tu_123', $withStreamId->events[0]->data['stream_id']);

        $withoutStreamId = $runner->run(
            'present_suggestions',
            ['suggestions' => ['Compare the top two']],
            $this->context(),
            new SessionState()
        );
        $this->assertSame('suggestions', $withoutStreamId->events[0]->data['stream_id']);
    }
}
