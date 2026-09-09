<?php
declare(strict_types=1);

namespace MageOS\ClaudeConsumerAgent\Model\Backend\Provider;

use Magento\Catalog\Model\Product\Visibility;
use Magento\Framework\Api\Search\SearchCriteria;
use Magento\Framework\Api\Search\SearchCriteriaBuilder;
use Magento\Framework\Api\SortOrder;
use MageOS\ClaudeConsumerAgent\Api\Backend\SearchProviderInterface;
use MageOS\ClaudeConsumerAgent\Api\Data\SearchFiltersInterface;
use MageOS\ClaudeConsumerAgent\Model\Agent\SessionContext;

final class FulltextSearch implements SearchProviderInterface
{
    private const SORT_BEST_SELLERS = 'best_sellers';
    private const BEST_SELLERS_PAGE_SIZE_MULTIPLIER = 5;
    private const BEST_SELLERS_PAGE_SIZE_CAP = 60;

    public function __construct(
        private readonly \Magento\Framework\Api\Search\SearchCriteriaBuilderFactory $searchCriteriaBuilderFactory,
        private readonly \Magento\Framework\Api\FilterBuilder $filterBuilder,
        private readonly \Magento\Search\Api\SearchInterface $search,
        private readonly \Magento\Catalog\Api\CategoryListInterface $categoryList,
        private readonly \Magento\Framework\Api\SearchCriteriaBuilder $categorySearchCriteriaBuilder,
        private readonly \Magento\Store\Model\StoreManagerInterface $storeManager,
        private readonly \MageOS\ClaudeConsumerAgent\Model\Config\StoreConfig $storeConfig,
        private readonly \MageOS\ClaudeConsumerAgent\Api\Backend\BestsellerRankInterface $bestsellerRank
    ) {
    }

    public function search(SessionContext $ctx, string $query, ?SearchFiltersInterface $filters, int $limit): array
    {
        $categoryIds = $this->categoryFilterValues($filters, $ctx->storeId);
        $isBestSellers = $filters !== null && $filters->getSort() === self::SORT_BEST_SELLERS;
        $searchLimit = $isBestSellers
            ? min($limit * self::BEST_SELLERS_PAGE_SIZE_MULTIPLIER, self::BEST_SELLERS_PAGE_SIZE_CAP)
            : $limit;

        if ($query !== '') {
            $ids = $this->searchByTerm($ctx, $query, $filters, $categoryIds, $searchLimit);
        } elseif ($categoryIds !== []) {
            $ids = $this->listCategory($ctx, $filters, $categoryIds, $searchLimit);
        } else {
            return [];
        }

        if (!$isBestSellers) {
            return $ids;
        }

        return array_slice($this->bestsellerRank->rank($ids, $ctx->storeId), 0, $limit);
    }

    private function searchByTerm(
        SessionContext $ctx,
        string $query,
        ?SearchFiltersInterface $filters,
        array $categoryIds,
        int $limit
    ): array {
        $criteria = $this->searchCriteriaBuilderFactory->create();
        $criteria->addFilter($this->filterBuilder->setField('search_term')->setValue($query)->create());
        $criteria->addFilter(
            $this->filterBuilder->setField('visibility')
                ->setValue([Visibility::VISIBILITY_IN_SEARCH, Visibility::VISIBILITY_BOTH])
                ->create()
        );

        $this->addPriceFilters($criteria, $filters);

        if ($categoryIds !== []) {
            $criteria->addFilter(
                $this->filterBuilder->setField('category_ids')->setValue($categoryIds)->create()
            );
        }

        $criteria->addSortOrder('relevance', SortOrder::SORT_DESC);
        $criteria->setPageSize($limit);

        $searchCriteria = $criteria->create();
        $searchCriteria->setRequestName('quick_search_container');

        return $this->runSearch($ctx, $searchCriteria);
    }

    private function listCategory(
        SessionContext $ctx,
        ?SearchFiltersInterface $filters,
        array $categoryIds,
        int $limit
    ): array {
        $criteria = $this->searchCriteriaBuilderFactory->create();
        $criteria->addFilter(
            $this->filterBuilder->setField('category_ids')->setValue($categoryIds)->create()
        );
        $criteria->addFilter(
            $this->filterBuilder->setField('visibility')
                ->setValue([Visibility::VISIBILITY_IN_CATALOG, Visibility::VISIBILITY_BOTH])
                ->create()
        );

        $this->addPriceFilters($criteria, $filters);

        [$sortField, $sortDirection] = $this->listSortOrder($filters !== null ? $filters->getSort() : 'relevance');
        $criteria->addSortOrder($sortField, $sortDirection);
        $criteria->setPageSize($limit);

        $searchCriteria = $criteria->create();
        $searchCriteria->setRequestName('catalog_view_container');

        return $this->runSearch($ctx, $searchCriteria);
    }

    private function addPriceFilters(
        SearchCriteriaBuilder $criteria,
        ?SearchFiltersInterface $filters
    ): void {
        if ($filters !== null && $filters->getMinPrice() !== null) {
            $criteria->addFilter(
                $this->filterBuilder->setField('price.from')->setValue((string)$filters->getMinPrice())->create()
            );
        }
        if ($filters !== null && $filters->getMaxPrice() !== null) {
            $criteria->addFilter(
                $this->filterBuilder->setField('price.to')->setValue((string)$filters->getMaxPrice())->create()
            );
        }
    }

    private function listSortOrder(string $sort): array
    {
        return match ($sort) {
            'price_asc' => ['price', SortOrder::SORT_ASC],
            'price_desc' => ['price', SortOrder::SORT_DESC],
            default => ['position', SortOrder::SORT_ASC],
        };
    }

    private function runSearch(SessionContext $ctx, SearchCriteria $searchCriteria): array
    {
        $previousStoreId = (int)$this->storeManager->getStore()->getId();
        $this->storeManager->setCurrentStore($ctx->storeId);

        try {
            $result = $this->search->search($searchCriteria);
        } finally {
            $this->storeManager->setCurrentStore($previousStoreId);
        }

        $ids = [];
        foreach ($result->getItems() as $document) {
            $ids[] = (int)$document->getId();
        }
        return $ids;
    }

    private function categoryFilterValues(?SearchFiltersInterface $filters, int $storeId): array
    {
        if ($filters !== null && $filters->getCategoryId() !== null) {
            return [(string)$filters->getCategoryId()];
        }

        if ($filters !== null && $filters->getCategory() !== null) {
            $resolved = $this->resolveCategoryId($filters->getCategory(), $storeId);
            if ($resolved !== null) {
                return [(string)$resolved];
            }
        }

        $allowedCategories = $this->storeConfig->agent($storeId)->allowedCategories;
        return $allowedCategories !== [] ? array_map('strval', $allowedCategories) : [];
    }

    private function resolveCategoryId(string $name, int $storeId): ?int
    {
        $previousStoreId = (int)$this->storeManager->getStore()->getId();
        $this->storeManager->setCurrentStore($storeId);

        try {
            $criteria = $this->categorySearchCriteriaBuilder
                ->addFilter('name', $name, 'eq')
                ->setPageSize(1)
                ->create();
            $items = $this->categoryList->getList($criteria)->getItems();
            $first = reset($items);
            return $first !== false ? (int)$first->getId() : null;
        } finally {
            $this->storeManager->setCurrentStore($previousStoreId);
        }
    }
}
