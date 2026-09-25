<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Model\Agent\Turn;

use Generator;
use MageOS\AiBase\Api\Data\ChatResponseInterface;
use MageOS\AiBase\Api\Data\FinishReason;
use MageOS\AiBase\Api\Data\StreamChunkInterface;
use MageOS\AiBase\Api\Data\StreamChunkType;
use MageOS\AiBase\Api\Data\TokenUsageInterface;
use MageOS\AiShoppingAssistant\Model\Agent\Turn\Exception\ApiStreamError;
use MageOS\AiShoppingAssistant\Model\Agent\Turn\Item\TextDelta;
use MageOS\AiShoppingAssistant\Model\Agent\Turn\Item\ToolUseClosed;
use MageOS\AiShoppingAssistant\Model\Agent\Turn\Item\UnreadableToolInput;
use MageOS\AiShoppingAssistant\Model\Client\RawEvent;

final class StreamedRound
{
    private array $blocks = [];

    private array $usage = [
        'input_tokens' => 0,
        'output_tokens' => 0,
        'cache_read_input_tokens' => 0,
        'cache_creation_input_tokens' => 0,
    ];

    private ?string $stopReason = null;

    private ?string $messageId = null;

    private bool $consumed = false;

    private string $text = '';

    private array $toolUses = [];

    private ?TokenUsageInterface $chunkUsage = null;

    private ?ChatResponseInterface $response = null;

    public function feed(RawEvent $raw): array
    {
        return match ($raw->type) {
            'message_start' => $this->onMessageStart($raw->data),
            'content_block_start' => $this->onBlockStart($raw->data),
            'content_block_delta' => $this->onBlockDelta($raw->data),
            'content_block_stop' => $this->onBlockStop($raw->data),
            'message_delta' => $this->onMessageDelta($raw->data),
            'error' => $this->onError($raw->data),
            default => [],
        };
    }

    public function consume(Generator $stream, ?callable $onWaiting = null): Generator
    {
        $this->consumed = true;
        foreach ($stream as $chunk) {
            if ($onWaiting !== null) {
                $onWaiting();
            }
            if (!$chunk instanceof StreamChunkInterface) {
                continue;
            }
            $this->chunkUsage = $chunk->getUsage() ?? $this->chunkUsage;
            if ($chunk->getType() === StreamChunkType::Text && $chunk->getText() !== '') {
                $this->text .= $chunk->getText();
                yield new TextDelta($chunk->getText());
                continue;
            }
            $call = $chunk->getType() === StreamChunkType::ToolCall ? $chunk->getToolCall() : null;
            if ($call === null) {
                continue;
            }
            $this->toolUses[] = ['id' => $call->getId(), 'name' => $call->getName(), 'input' => $call->getArguments()];
            yield new ToolUseClosed($call->getId(), $call->getName(), $call->getArguments());
        }
        $response = $stream->getReturn();
        $this->response = $response instanceof ChatResponseInterface ? $response : null;
    }

    public function assistantMessage(): ?array
    {
        if ($this->consumed) {
            return $this->consumedAssistantMessage();
        }
        $content = [];
        foreach ($this->blocks as $block) {
            if ($block['type'] === 'thinking' && ($block['signature'] ?? '') !== '') {
                $content[] = [
                    'type' => 'thinking',
                    'thinking' => $block['thinking'] ?? '',
                    'signature' => $block['signature'],
                ];
                continue;
            }
            if ($block['type'] === 'text' && ($block['text'] ?? '') !== '') {
                $content[] = ['type' => 'text', 'text' => $block['text']];
                continue;
            }
            if ($block['type'] === 'tool_use') {
                $content[] = [
                    'type' => 'tool_use',
                    'id' => $block['id'],
                    'name' => $block['name'],
                    'input' => $block['input'] ?? [],
                ];
            }
        }
        return $content !== [] ? ['role' => 'assistant', 'content' => $content] : null;
    }

    public function toolUses(): array
    {
        if ($this->consumed) {
            return $this->toolUses;
        }
        $uses = [];
        foreach ($this->blocks as $block) {
            if ($block['type'] === 'tool_use') {
                $uses[] = [
                    'id' => $block['id'],
                    'name' => $block['name'],
                    'input' => $block['input'] ?? [],
                ];
            }
        }
        return $uses;
    }

    public function usage(): array
    {
        if (!$this->consumed) {
            return $this->usage;
        }
        $usage = $this->tokenUsage();
        $read = $usage?->getCacheReadTokens() ?? 0;
        $write = $usage?->getCacheWriteTokens() ?? 0;
        return [
            'input_tokens' => max(0, $this->promptTokens() - $read - $write),
            'output_tokens' => $usage?->getCompletionTokens() ?? 0,
            'cache_read_input_tokens' => $read,
            'cache_creation_input_tokens' => $write,
        ];
    }

    public function promptTokens(): int
    {
        return $this->tokenUsage()?->getPromptTokens() ?? 0;
    }

    public function stopReason(): ?string
    {
        if (!$this->consumed) {
            return $this->stopReason;
        }
        if ($this->response === null) {
            return null;
        }
        return match ($this->response->getFinishReason()) {
            FinishReason::Length => 'max_tokens',
            FinishReason::ContentFilter => 'refusal',
            FinishReason::ToolCall => 'tool_use',
            FinishReason::Stop, FinishReason::StopSequence => 'end_turn',
            default => ($this->toolUses !== [] || $this->response->hasToolCalls()) ? 'tool_use' : 'end_turn',
        };
    }

    public function messageId(): ?string
    {
        return $this->messageId;
    }

    private function consumedAssistantMessage(): ?array
    {
        $content = [];
        foreach ($this->response?->getReasoning() ?? [] as $reasoning) {
            $signature = (string)$reasoning->getSignature();
            if ($signature === '') {
                continue;
            }
            $content[] = ['type' => 'thinking', 'thinking' => $reasoning->getText(), 'signature' => $signature];
        }
        if ($this->text !== '') {
            $content[] = ['type' => 'text', 'text' => $this->text];
        }
        foreach ($this->toolUses as $toolUse) {
            $content[] = ['type' => 'tool_use'] + $toolUse;
        }
        return $content !== [] ? ['role' => 'assistant', 'content' => $content] : null;
    }

    private function tokenUsage(): ?TokenUsageInterface
    {
        return $this->response?->getUsage() ?? $this->chunkUsage;
    }

    private function onMessageStart(array $data): array
    {
        $message = $data['message'] ?? [];
        if (isset($message['id'])) {
            $this->messageId = (string)$message['id'];
        }
        $this->takeUsage(is_array($message['usage'] ?? null) ? $message['usage'] : []);
        return [];
    }

    private function onBlockStart(array $data): array
    {
        $index = $data['index'] ?? null;
        if (!is_int($index)) {
            return [];
        }
        $block = is_array($data['content_block'] ?? null) ? $data['content_block'] : [];
        $type = (string)($block['type'] ?? '');
        $entry = ['type' => $type, 'closed' => false];
        if ($type === 'text') {
            $entry['text'] = '';
        } elseif ($type === 'thinking') {
            $entry['thinking'] = '';
            $entry['signature'] = '';
        } elseif ($type === 'tool_use') {
            $entry['id'] = (string)($block['id'] ?? '');
            $entry['name'] = (string)($block['name'] ?? '');
            $entry['inputBuffer'] = '';
        }
        $this->blocks[$index] = $entry;
        return [];
    }

    private function onBlockDelta(array $data): array
    {
        $index = $data['index'] ?? null;
        if (!is_int($index) || !isset($this->blocks[$index])) {
            return [];
        }
        $delta = is_array($data['delta'] ?? null) ? $data['delta'] : [];
        $deltaType = (string)($delta['type'] ?? '');
        if ($deltaType === 'text_delta') {
            $text = (string)($delta['text'] ?? '');
            $this->blocks[$index]['text'] = ($this->blocks[$index]['text'] ?? '') . $text;
            return $text !== '' ? [new TextDelta($text)] : [];
        }
        if ($deltaType === 'input_json_delta') {
            $this->blocks[$index]['inputBuffer'] = ($this->blocks[$index]['inputBuffer'] ?? '')
                . (string)($delta['partial_json'] ?? '');
            return [];
        }
        if ($deltaType === 'thinking_delta') {
            $this->blocks[$index]['thinking'] = ($this->blocks[$index]['thinking'] ?? '')
                . (string)($delta['thinking'] ?? '');
            return [];
        }
        if ($deltaType === 'signature_delta') {
            $this->blocks[$index]['signature'] = (string)($delta['signature'] ?? '');
            return [];
        }
        return [];
    }

    private function onBlockStop(array $data): array
    {
        $index = $data['index'] ?? null;
        if (!is_int($index) || !isset($this->blocks[$index])) {
            return [];
        }
        $this->blocks[$index]['closed'] = true;
        if ($this->blocks[$index]['type'] !== 'tool_use') {
            return [];
        }
        $buffer = $this->blocks[$index]['inputBuffer'] ?? '';
        $parsed = $buffer === '' ? [] : json_decode($buffer, true);
        $id = $this->blocks[$index]['id'];
        $name = $this->blocks[$index]['name'];
        if (is_array($parsed)) {
            $this->blocks[$index]['input'] = $parsed;
            return [new ToolUseClosed($id, $name, $parsed)];
        }
        $this->blocks[$index]['input'] = [];
        return [new UnreadableToolInput($id, $name)];
    }

    private function onMessageDelta(array $data): array
    {
        $delta = is_array($data['delta'] ?? null) ? $data['delta'] : [];
        if (isset($delta['stop_reason']) && $delta['stop_reason'] !== null) {
            $this->stopReason = (string)$delta['stop_reason'];
        }
        $this->takeUsage(is_array($data['usage'] ?? null) ? $data['usage'] : []);
        return [];
    }

    private function onError(array $data): array
    {
        $error = is_array($data['error'] ?? null) ? $data['error'] : [];
        throw new ApiStreamError(
            (string)($error['type'] ?? 'error'),
            (string)($error['message'] ?? 'stream error')
        );
    }

    private function takeUsage(array $usage): void
    {
        foreach (array_keys($this->usage) as $key) {
            if (isset($usage[$key]) && is_int($usage[$key])) {
                $this->usage[$key] = $usage[$key];
            }
        }
    }
}
