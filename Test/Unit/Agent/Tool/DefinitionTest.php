<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Test\Unit\Agent\Tool;

use MageOS\AiShoppingAssistant\Model\Agent\Tool\Definition;
use PHPUnit\Framework\TestCase;

class DefinitionTest extends TestCase
{
    private function buildDefinition(bool $takesStatus, array $inputSchema = []): Definition
    {
        return new Definition(
            'search_products',
            'Search the catalog',
            $inputSchema,
            'read',
            null,
            ['product_id'],
            true,
            $takesStatus,
            10
        );
    }

    public function testGetters(): void
    {
        $definition = $this->buildDefinition(true, ['type' => 'object', 'properties' => ['query' => ['type' => 'string']]]);
        $this->assertSame('search_products', $definition->getName());
        $this->assertSame('Search the catalog', $definition->getDescription());
        $this->assertSame('read', $definition->getKind());
        $this->assertNull($definition->getHandler());
        $this->assertSame(['product_id'], $definition->getProductIdArguments());
        $this->assertTrue($definition->remembersProducts());
        $this->assertTrue($definition->takesStatus());
        $this->assertSame(10, $definition->getSortOrder());
    }

    public function testApiDefinitionShape(): void
    {
        $definition = $this->buildDefinition(false, ['type' => 'object', 'properties' => []]);
        $this->assertSame('search_products', $definition->getName());
        $this->assertSame('Search the catalog', $definition->getDescription());
    }

    public function testApiDefinitionPrependsStatusWhenTakesStatus(): void
    {
        $definition = $this->buildDefinition(
            true,
            ['type' => 'object', 'properties' => ['query' => ['type' => 'string']]]
        );
        $properties = $definition->getParameters()['properties'];
        $this->assertSame(['status', 'query'], array_keys($properties));
        $this->assertSame(
            [
                'type' => 'string',
                'maxLength' => 60,
                'description' => 'A few plain words the customer sees while this runs',
            ],
            $properties['status']
        );
    }

    public function testApiDefinitionOmitsStatusWhenNotTakesStatus(): void
    {
        $definition = $this->buildDefinition(
            false,
            ['type' => 'object', 'properties' => ['query' => ['type' => 'string']]]
        );
        $this->assertArrayNotHasKey('status', $definition->getParameters()['properties']);
    }

    public function testStatusIsNeverRequired(): void
    {
        $definition = $this->buildDefinition(
            true,
            ['type' => 'object', 'properties' => ['query' => ['type' => 'string']], 'required' => ['query']]
        );
        $this->assertSame(['query'], $definition->getParameters()['required']);
        $this->assertNotContains('status', $definition->getParameters()['required']);
    }

    public function testApiDefinitionNormalizesEmptyPropertiesToAnObjectForJsonEncoding(): void
    {
        $definition = $this->buildDefinition(false, ['type' => 'object', 'properties' => []]);
        $encoded = json_encode($definition->getParameters());
        $this->assertIsString($encoded);
        $this->assertStringContainsString('"properties":{}', $encoded);
    }

    public function testApiDefinitionMergesStatusIntoStdClassProperties(): void
    {
        $definition = $this->buildDefinition(true, ['type' => 'object', 'properties' => new \stdClass()]);
        $this->assertArrayHasKey('status', $definition->getParameters()['properties']);
    }

    public function testItIsAnAiBaseToolDefinitionWithTheStatusProperty(): void
    {
        $definition = $this->buildDefinition(
            true,
            ['type' => 'object', 'properties' => ['query' => ['type' => 'string']]]
        );

        $this->assertInstanceOf(\MageOS\AiBase\Api\Data\ToolDefinitionInterface::class, $definition);
        $this->assertArrayHasKey('status', $definition->getParameters()['properties']);
    }
}
