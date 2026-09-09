<?php
declare(strict_types=1);

namespace MageOS\ClaudeConsumerAgent\Api\Backend;

interface CatalogMapProviderInterface
{
    /**
     * Returns a list of nodes: ['id' => int, 'name' => string, 'children' => [...]],
     * children sorted by category position, only active categories.
     *
     * @return array<int, array{id: int, name: string, children: array}>
     */
    public function map(int $storeId, array $rootIds, int $depth): array;
}
