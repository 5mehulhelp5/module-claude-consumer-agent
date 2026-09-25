<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Test\Unit\Support;

use MageOS\AiBase\Api\ChatRequestBuilderInterfaceFactory;
use MageOS\AiBase\Api\Data\ChatMessageInterfaceFactory;
use MageOS\AiBase\Api\Data\ChatRequestInterfaceFactory;
use MageOS\AiBase\Api\Data\ChatResponseInterfaceFactory;
use MageOS\AiBase\Api\Data\StreamChunkInterfaceFactory;
use MageOS\AiBase\Api\Data\TokenUsageInterfaceFactory;
use MageOS\AiBase\Api\Data\ToolCallInterfaceFactory;
use MageOS\AiBase\Api\Data\ToolDefinitionInterfaceFactory;
use MageOS\AiBase\Model\Chat\ChatMessage;
use MageOS\AiBase\Model\Chat\ChatRequest;
use MageOS\AiBase\Model\Chat\ChatRequestBuilder;
use MageOS\AiBase\Model\Chat\ChatResponse;
use MageOS\AiBase\Model\Chat\StreamChunk;
use MageOS\AiBase\Model\Chat\TokenUsage;
use MageOS\AiBase\Model\Chat\ToolCall;
use MageOS\AiBase\Model\Chat\ToolDefinition;
use MageOS\AiShoppingAssistant\Model\Eval\ScriptedAiClient;

trait AiBaseDoubles
{
    private function chatRequestBuilderFactory(): ChatRequestBuilderInterfaceFactory
    {
        $requestFactory = $this->createMock(ChatRequestInterfaceFactory::class);
        $requestFactory->method('create')->willReturnCallback(
            static fn (array $data = []): ChatRequest => new ChatRequest(...$data)
        );
        $messageFactory = $this->createMock(ChatMessageInterfaceFactory::class);
        $messageFactory->method('create')->willReturnCallback(
            static fn (array $data = []): ChatMessage => new ChatMessage(...$data)
        );
        $toolFactory = $this->createMock(ToolDefinitionInterfaceFactory::class);
        $toolFactory->method('create')->willReturnCallback(
            static fn (array $data = []): ToolDefinition => new ToolDefinition(...$data)
        );
        $builderFactory = $this->createMock(ChatRequestBuilderInterfaceFactory::class);
        $builderFactory->method('create')->willReturnCallback(
            static fn (): ChatRequestBuilder => new ChatRequestBuilder($requestFactory, $messageFactory, $toolFactory)
        );
        return $builderFactory;
    }

    private function chatResponseFactory(): ChatResponseInterfaceFactory
    {
        $factory = $this->createMock(ChatResponseInterfaceFactory::class);
        $factory->method('create')->willReturnCallback(
            static fn (array $data = []): ChatResponse => new ChatResponse(...$data)
        );
        return $factory;
    }

    private function toolCallFactory(): ToolCallInterfaceFactory
    {
        $factory = $this->createMock(ToolCallInterfaceFactory::class);
        $factory->method('create')->willReturnCallback(
            static fn (array $data = []): ToolCall => new ToolCall(...$data)
        );
        return $factory;
    }

    private function streamChunkFactory(): StreamChunkInterfaceFactory
    {
        $factory = $this->createMock(StreamChunkInterfaceFactory::class);
        $factory->method('create')->willReturnCallback(
            static fn (array $data = []): StreamChunk => new StreamChunk(...$data)
        );
        return $factory;
    }

    private function tokenUsageFactory(): TokenUsageInterfaceFactory
    {
        $factory = $this->createMock(TokenUsageInterfaceFactory::class);
        $factory->method('create')->willReturnCallback(
            static fn (array $data = []): TokenUsage => new TokenUsage(...$data)
        );
        return $factory;
    }

    private function scriptedClient(): ScriptedAiClient
    {
        return new ScriptedAiClient(
            $this->streamChunkFactory(),
            $this->chatResponseFactory(),
            $this->toolCallFactory(),
            $this->tokenUsageFactory()
        );
    }
}
