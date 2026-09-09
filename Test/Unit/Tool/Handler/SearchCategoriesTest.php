<?php
declare(strict_types=1);

namespace MageOS\ClaudeConsumerAgent\Test\Unit\Tool\Handler;

use MageOS\ClaudeConsumerAgent\Api\Data\PageContextInterface;
use MageOS\ClaudeConsumerAgent\Api\StorefrontBackendInterface;
use MageOS\ClaudeConsumerAgent\Model\Agent\AgentConfig;
use MageOS\ClaudeConsumerAgent\Model\Agent\Fencing\Fence;
use MageOS\ClaudeConsumerAgent\Model\Agent\Fencing\Sanitizer;
use MageOS\ClaudeConsumerAgent\Model\Agent\Serializer;
use MageOS\ClaudeConsumerAgent\Model\Agent\SessionContext;
use MageOS\ClaudeConsumerAgent\Model\Agent\SessionState;
use MageOS\ClaudeConsumerAgent\Model\Agent\Tool\Handler\SearchCategories;
use MageOS\ClaudeConsumerAgent\Model\Data\CategoryMatch;
use PHPUnit\Framework\TestCase;

final class SearchCategoriesTest extends TestCase
{
    private function serializer(): Serializer
    {
        return new Serializer(new Fence(new Sanitizer()));
    }

    private function context(): SessionContext
    {
        $page = $this->createMock(PageContextInterface::class);
        return new SessionContext('sess-1', null, 1, 1, $page, new \DateTimeImmutable('now'));
    }

    public function testMatchesAreFencedAndTheKeywordsAndLimitArePassedThrough(): void
    {
        $match = new CategoryMatch(1235, 'Lounge Chairs', ['Seating', 'Lounge Chairs'], 12, 3);
        $backend = $this->createMock(StorefrontBackendInterface::class);
        $backend->expects($this->once())
            ->method('searchCategories')
            ->with($this->anything(), 'lounge chairs', 8)
            ->willReturn([$match]);

        $handler = new SearchCategories($backend, $this->serializer());
        $outcome = $handler->handle(
            ['keywords' => 'lounge chairs'],
            $this->context(),
            new SessionState(),
            new AgentConfig()
        );

        $this->assertFalse($outcome->isError);
        $this->assertStringContainsString('Category search returned 1 match(es)', $outcome->resultText);
        $this->assertStringContainsString('1235', $outcome->resultText);
    }

    public function testEmptyResultUsesTheNoMatchHeader(): void
    {
        $backend = $this->createMock(StorefrontBackendInterface::class);
        $backend->method('searchCategories')->willReturn([]);

        $handler = new SearchCategories($backend, $this->serializer());
        $outcome = $handler->handle(
            ['keywords' => 'board game'],
            $this->context(),
            new SessionState(),
            new AgentConfig()
        );

        $this->assertFalse($outcome->isError);
        $this->assertStringStartsWith('No category name matches these keywords.', $outcome->resultText);
    }

    public function testLimitDefaultsToEightAndIsClampedToTwenty(): void
    {
        $backend = $this->createMock(StorefrontBackendInterface::class);
        $backend->expects($this->once())
            ->method('searchCategories')
            ->with($this->anything(), 'rugs', 8)
            ->willReturn([]);

        $handler = new SearchCategories($backend, $this->serializer());
        $handler->handle(
            ['keywords' => 'rugs'],
            $this->context(),
            new SessionState(),
            new AgentConfig()
        );
    }

    public function testLimitAboveTwentyIsClamped(): void
    {
        $backend = $this->createMock(StorefrontBackendInterface::class);
        $backend->expects($this->once())
            ->method('searchCategories')
            ->with($this->anything(), 'rugs', 20)
            ->willReturn([]);

        $handler = new SearchCategories($backend, $this->serializer());
        $handler->handle(
            ['keywords' => 'rugs', 'limit' => 50],
            $this->context(),
            new SessionState(),
            new AgentConfig()
        );
    }
}
