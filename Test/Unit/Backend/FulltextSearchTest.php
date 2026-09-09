<?php
declare(strict_types=1);

namespace MageOS\ClaudeConsumerAgent\Test\Unit\Backend;

use Magento\Catalog\Api\CategoryListInterface;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Framework\Api\Filter;
use Magento\Framework\Api\FilterBuilder;
use Magento\Framework\Api\Search\DocumentInterface;
use Magento\Framework\Api\Search\SearchCriteria;
use Magento\Framework\Api\Search\SearchCriteriaBuilder;
use Magento\Framework\Api\Search\SearchCriteriaBuilderFactory;
use Magento\Framework\Api\Search\SearchResultInterface;
use Magento\Framework\Api\SearchCriteriaBuilder as PlainSearchCriteriaBuilder;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Search\Api\SearchInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use MageOS\ClaudeConsumerAgent\Api\Backend\BestsellerRankInterface;
use MageOS\ClaudeConsumerAgent\Model\Agent\SessionContext;
use MageOS\ClaudeConsumerAgent\Model\Backend\Provider\FulltextSearch;
use MageOS\ClaudeConsumerAgent\Model\Config\StoreConfig;
use MageOS\ClaudeConsumerAgent\Model\Data\PageContext;
use MageOS\ClaudeConsumerAgent\Model\Data\SearchFilters;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class FulltextSearchTest extends TestCase
{
    private array $filterCalls = [];
    private array $sortOrderCalls = [];
    private array $pageSizeCalls = [];

    private function context(int $storeId = 2): SessionContext
    {
        return new SessionContext('session-1', null, 1, $storeId, new PageContext(), new \DateTimeImmutable('now'));
    }

    private function storeConfig(): StoreConfig
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturn(null);
        $scopeConfig->method('isSetFlag')->willReturn(false);

        $store = $this->createMock(StoreInterface::class);
        $store->method('getName')->willReturn('Test Store');
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        return new StoreConfig($scopeConfig, $storeManager);
    }

    private function filterBuilder(): FilterBuilder&MockObject
    {
        $builder = $this->createMock(FilterBuilder::class);
        $current = ['field' => null, 'value' => null];
        $builder->method('setField')->willReturnCallback(function (string $field) use ($builder, &$current): FilterBuilder {
            $current['field'] = $field;
            return $builder;
        });
        $builder->method('setValue')->willReturnCallback(function ($value) use ($builder, &$current): FilterBuilder {
            $current['value'] = $value;
            return $builder;
        });
        $builder->method('create')->willReturnCallback(function () use (&$current): Filter {
            $this->filterCalls[] = $current;
            return (new Filter())->setField($current['field'])->setValue($current['value']);
        });
        return $builder;
    }

    private function searchCriteriaBuilder(): SearchCriteriaBuilder&MockObject
    {
        $builder = $this->createMock(SearchCriteriaBuilder::class);
        $builder->method('addFilter')->willReturnSelf();
        $builder->method('setPageSize')->willReturnCallback(
            function (int $pageSize) use ($builder): SearchCriteriaBuilder {
                $this->pageSizeCalls[] = $pageSize;
                return $builder;
            }
        );
        $builder->method('addSortOrder')->willReturnCallback(
            function (string $field, string $direction) use ($builder): SearchCriteriaBuilder {
                $this->sortOrderCalls[] = ['field' => $field, 'direction' => $direction];
                return $builder;
            }
        );
        $builder->method('create')->willReturnCallback(static fn (): SearchCriteria => new SearchCriteria());
        return $builder;
    }

    private function searchCriteriaBuilderFactory(
        SearchCriteriaBuilder $builder
    ): SearchCriteriaBuilderFactory&MockObject {
        $factory = $this->createMock(SearchCriteriaBuilderFactory::class);
        $factory->method('create')->willReturn($builder);
        return $factory;
    }

    private function categorySearchCriteriaBuilder(): PlainSearchCriteriaBuilder&MockObject
    {
        return $this->createMock(PlainSearchCriteriaBuilder::class);
    }

    private function searchResult(array $ids): SearchResultInterface&MockObject
    {
        $documents = array_map(
            function (int $id): DocumentInterface&MockObject {
                $document = $this->createMock(DocumentInterface::class);
                $document->method('getId')->willReturn($id);
                return $document;
            },
            $ids
        );
        $result = $this->createMock(SearchResultInterface::class);
        $result->method('getItems')->willReturn($documents);
        return $result;
    }

    private function defaultStoreManager(): StoreManagerInterface&MockObject
    {
        $store = $this->createMock(StoreInterface::class);
        $store->method('getId')->willReturn(1);
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);
        return $storeManager;
    }

    private function passthroughBestsellerRank(): BestsellerRankInterface&MockObject
    {
        $bestsellerRank = $this->createMock(BestsellerRankInterface::class);
        $bestsellerRank->method('rank')->willReturnArgument(0);
        return $bestsellerRank;
    }

    private function build(array $overrides = []): array
    {
        $filterBuilder = $overrides['filterBuilder'] ?? $this->filterBuilder();
        $searchCriteriaBuilder = $overrides['searchCriteriaBuilder'] ?? $this->searchCriteriaBuilder();
        $searchCriteriaBuilderFactory = $overrides['searchCriteriaBuilderFactory']
            ?? $this->searchCriteriaBuilderFactory($searchCriteriaBuilder);
        $search = $overrides['search'] ?? $this->createMock(SearchInterface::class);
        $storeManager = $overrides['storeManager'] ?? $this->defaultStoreManager();
        $bestsellerRank = $overrides['bestsellerRank'] ?? $this->passthroughBestsellerRank();

        $provider = new FulltextSearch(
            $searchCriteriaBuilderFactory,
            $filterBuilder,
            $search,
            $this->createMock(CategoryListInterface::class),
            $this->categorySearchCriteriaBuilder(),
            $storeManager,
            $this->storeConfig(),
            $bestsellerRank
        );

        return [$provider, $search, $storeManager, $bestsellerRank];
    }

    public function testSetsQuickSearchContainerRequestNameOnTheCriteriaObject(): void
    {
        $search = $this->createMock(SearchInterface::class);
        $search->expects($this->once())
            ->method('search')
            ->with($this->callback(
                static fn (SearchCriteria $criteria): bool => $criteria->getRequestName() === 'quick_search_container'
            ))
            ->willReturn($this->searchResult([]));

        [$provider] = $this->build(['search' => $search]);

        $provider->search($this->context(), 'widget', null, 10);
    }

    public function testAddsSearchTermAndVisibilityFilters(): void
    {
        $search = $this->createMock(SearchInterface::class);
        $search->method('search')->willReturn($this->searchResult([]));

        [$provider] = $this->build(['search' => $search]);

        $provider->search($this->context(), 'widget', null, 10);

        $this->assertContains(['field' => 'search_term', 'value' => 'widget'], $this->filterCalls);
        $this->assertContains(
            ['field' => 'visibility', 'value' => [Visibility::VISIBILITY_IN_SEARCH, Visibility::VISIBILITY_BOTH]],
            $this->filterCalls
        );
    }

    public function testAddsPriceFiltersWhenGiven(): void
    {
        $search = $this->createMock(SearchInterface::class);
        $search->method('search')->willReturn($this->searchResult([]));

        [$provider] = $this->build(['search' => $search]);

        $filters = SearchFilters::fromArray(['min_price' => 10.0, 'max_price' => 50.0]);
        $provider->search($this->context(), 'widget', $filters, 10);

        $this->assertContains(['field' => 'price.from', 'value' => '10'], $this->filterCalls);
        $this->assertContains(['field' => 'price.to', 'value' => '50'], $this->filterCalls);
    }

    public function testOmitsPriceFiltersWhenNotGiven(): void
    {
        $search = $this->createMock(SearchInterface::class);
        $search->method('search')->willReturn($this->searchResult([]));

        [$provider] = $this->build(['search' => $search]);

        $provider->search($this->context(), 'widget', null, 10);

        $fields = array_column($this->filterCalls, 'field');
        $this->assertNotContains('price.from', $fields);
        $this->assertNotContains('price.to', $fields);
    }

    public function testSwapsToTheContextStoreAndRestoresThePreviousStoreAfterSearch(): void
    {
        $previousStore = $this->createMock(StoreInterface::class);
        $previousStore->method('getId')->willReturn(1);

        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($previousStore);

        $setCurrentStoreCalls = [];
        $storeManager->method('setCurrentStore')->willReturnCallback(
            function (int $storeId) use (&$setCurrentStoreCalls): void {
                $setCurrentStoreCalls[] = $storeId;
            }
        );

        $search = $this->createMock(SearchInterface::class);
        $search->method('search')->willReturn($this->searchResult([]));

        [$provider] = $this->build(['search' => $search, 'storeManager' => $storeManager]);

        $provider->search($this->context(5), 'widget', null, 10);

        $this->assertSame([5, 1], $setCurrentStoreCalls);
    }

    public function testRestoresThePreviousStoreEvenWhenSearchThrows(): void
    {
        $previousStore = $this->createMock(StoreInterface::class);
        $previousStore->method('getId')->willReturn(1);

        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($previousStore);

        $setCurrentStoreCalls = [];
        $storeManager->method('setCurrentStore')->willReturnCallback(
            function (int $storeId) use (&$setCurrentStoreCalls): void {
                $setCurrentStoreCalls[] = $storeId;
            }
        );

        $search = $this->createMock(SearchInterface::class);
        $search->method('search')->willThrowException(new \RuntimeException('search backend unavailable'));

        [$provider] = $this->build(['search' => $search, 'storeManager' => $storeManager]);

        try {
            $provider->search($this->context(5), 'widget', null, 10);
            $this->fail('Expected exception was not thrown.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('search backend unavailable', $exception->getMessage());
        }

        $this->assertSame([5, 1], $setCurrentStoreCalls);
    }

    public function testReturnsDocumentIdsFromTheSearchResult(): void
    {
        $search = $this->createMock(SearchInterface::class);
        $search->method('search')->willReturn($this->searchResult([10, 20]));

        [$provider] = $this->build(['search' => $search]);

        $ids = $provider->search($this->context(), 'widget', null, 10);

        $this->assertSame([10, 20], $ids);
    }

    public function testAddsARelevanceDescendingSortOrder(): void
    {
        $search = $this->createMock(SearchInterface::class);
        $search->method('search')->willReturn($this->searchResult([]));

        [$provider] = $this->build(['search' => $search]);

        $provider->search($this->context(), 'widget', null, 10);

        $this->assertSame([['field' => 'relevance', 'direction' => 'DESC']], $this->sortOrderCalls);
    }

    public function testEmptyQueryWithCategoryIdUsesCatalogViewContainerRequestName(): void
    {
        $search = $this->createMock(SearchInterface::class);
        $search->expects($this->once())
            ->method('search')
            ->with($this->callback(
                static fn (SearchCriteria $criteria): bool => $criteria->getRequestName() === 'catalog_view_container'
            ))
            ->willReturn($this->searchResult([]));

        [$provider] = $this->build(['search' => $search]);

        $filters = SearchFilters::fromArray(['category_id' => 175]);
        $provider->search($this->context(), '', $filters, 10);
    }

    public function testEmptyQueryWithCategoryIdSortsByPositionAscendingByDefault(): void
    {
        $search = $this->createMock(SearchInterface::class);
        $search->method('search')->willReturn($this->searchResult([]));

        [$provider] = $this->build(['search' => $search]);

        $filters = SearchFilters::fromArray(['category_id' => 175]);
        $provider->search($this->context(), '', $filters, 10);

        $this->assertSame([['field' => 'position', 'direction' => 'ASC']], $this->sortOrderCalls);
    }

    public function testEmptyQueryWithCategoryIdAndPriceAscSortMapsToThePriceField(): void
    {
        $search = $this->createMock(SearchInterface::class);
        $search->method('search')->willReturn($this->searchResult([]));

        [$provider] = $this->build(['search' => $search]);

        $filters = SearchFilters::fromArray(['category_id' => 175, 'sort' => 'price_asc']);
        $provider->search($this->context(), '', $filters, 10);

        $this->assertSame([['field' => 'price', 'direction' => 'ASC']], $this->sortOrderCalls);
    }

    public function testEmptyQueryWithCategoryIdAndPriceDescSortMapsToThePriceField(): void
    {
        $search = $this->createMock(SearchInterface::class);
        $search->method('search')->willReturn($this->searchResult([]));

        [$provider] = $this->build(['search' => $search]);

        $filters = SearchFilters::fromArray(['category_id' => 175, 'sort' => 'price_desc']);
        $provider->search($this->context(), '', $filters, 10);

        $this->assertSame([['field' => 'price', 'direction' => 'DESC']], $this->sortOrderCalls);
    }

    public function testEmptyQueryWithCategoryIdDoesNotAddASearchTermFilter(): void
    {
        $search = $this->createMock(SearchInterface::class);
        $search->method('search')->willReturn($this->searchResult([]));

        [$provider] = $this->build(['search' => $search]);

        $filters = SearchFilters::fromArray(['category_id' => 175]);
        $provider->search($this->context(), '', $filters, 10);

        $fields = array_column($this->filterCalls, 'field');
        $this->assertNotContains('search_term', $fields);
        $this->assertContains(['field' => 'category_ids', 'value' => ['175']], $this->filterCalls);
    }

    public function testEmptyQueryWithNoCategoryReturnsEmptyWithoutCallingSearch(): void
    {
        $search = $this->createMock(SearchInterface::class);
        $search->expects($this->never())->method('search');

        [$provider] = $this->build(['search' => $search]);

        $ids = $provider->search($this->context(), '', null, 10);

        $this->assertSame([], $ids);
    }

    public function testCategoryIdFilterTakesPrecedenceOverCategoryName(): void
    {
        $search = $this->createMock(SearchInterface::class);
        $search->method('search')->willReturn($this->searchResult([]));

        $categoryList = $this->createMock(CategoryListInterface::class);
        $categoryList->expects($this->never())->method('getList');

        $provider = new FulltextSearch(
            $this->searchCriteriaBuilderFactory($this->searchCriteriaBuilder()),
            $this->filterBuilder(),
            $search,
            $categoryList,
            $this->categorySearchCriteriaBuilder(),
            $this->defaultStoreManager(),
            $this->storeConfig(),
            $this->passthroughBestsellerRank()
        );

        $filters = SearchFilters::fromArray(['category_id' => 175, 'category' => 'Seating']);
        $provider->search($this->context(), 'widget', $filters, 10);

        $this->assertContains(['field' => 'category_ids', 'value' => ['175']], $this->filterCalls);
    }

    public function testEachSearchCallGetsAFreshSearchCriteriaBuilder(): void
    {
        $firstBuilder = $this->searchCriteriaBuilder();
        $secondBuilder = $this->searchCriteriaBuilder();
        $factory = $this->createMock(SearchCriteriaBuilderFactory::class);
        $factory->expects($this->exactly(2))
            ->method('create')
            ->willReturnOnConsecutiveCalls($firstBuilder, $secondBuilder);

        $search = $this->createMock(SearchInterface::class);
        $search->method('search')->willReturn($this->searchResult([]));

        [$provider] = $this->build(['search' => $search, 'searchCriteriaBuilderFactory' => $factory]);

        $provider->search($this->context(), 'first', null, 10);
        $provider->search($this->context(), 'second', null, 10);

        $this->assertNotSame($firstBuilder, $secondBuilder);
    }

    public function testBestSellersSortWidensThePageSizeToFiveTimesTheLimit(): void
    {
        $search = $this->createMock(SearchInterface::class);
        $search->method('search')->willReturn($this->searchResult([]));

        [$provider] = $this->build(['search' => $search]);

        $filters = SearchFilters::fromArray(['sort' => 'best_sellers']);
        $provider->search($this->context(), 'widget', $filters, 10);

        $this->assertSame([50], $this->pageSizeCalls);
    }

    public function testBestSellersSortCapsThePageSizeAtSixty(): void
    {
        $search = $this->createMock(SearchInterface::class);
        $search->method('search')->willReturn($this->searchResult([]));

        [$provider] = $this->build(['search' => $search]);

        $filters = SearchFilters::fromArray(['category_id' => 175, 'sort' => 'best_sellers']);
        $provider->search($this->context(), '', $filters, 20);

        $this->assertSame([60], $this->pageSizeCalls);
    }

    public function testBestSellersSortRanksResultsAndSlicesToTheRequestedLimit(): void
    {
        $search = $this->createMock(SearchInterface::class);
        $search->method('search')->willReturn($this->searchResult([10, 20, 30, 40, 50]));

        $bestsellerRank = $this->createMock(BestsellerRankInterface::class);
        $bestsellerRank->expects($this->once())
            ->method('rank')
            ->with([10, 20, 30, 40, 50], 2)
            ->willReturn([50, 40, 30, 20, 10]);

        [$provider] = $this->build(['search' => $search, 'bestsellerRank' => $bestsellerRank]);

        $filters = SearchFilters::fromArray(['sort' => 'best_sellers']);
        $ids = $provider->search($this->context(), 'widget', $filters, 2);

        $this->assertSame([50, 40], $ids);
    }

    public function testNonBestSellersSortNeverCallsTheBestsellerRank(): void
    {
        $search = $this->createMock(SearchInterface::class);
        $search->method('search')->willReturn($this->searchResult([10, 20]));

        $bestsellerRank = $this->createMock(BestsellerRankInterface::class);
        $bestsellerRank->expects($this->never())->method('rank');

        [$provider] = $this->build(['search' => $search, 'bestsellerRank' => $bestsellerRank]);

        $provider->search($this->context(), 'widget', null, 10);
    }
}
