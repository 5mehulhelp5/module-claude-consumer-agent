<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Test\Unit\Eval;

use MageOS\AiBase\Api\Data\FinishReason;
use MageOS\AiBase\Api\Data\StreamChunkType;
use MageOS\AiBase\Model\Chat\ChatMessage;
use MageOS\AiBase\Model\Chat\ChatRequest;
use MageOS\AiBase\Api\Data\MessageRole;
use MageOS\AiBase\Exceptions\AiTransientException;
use MageOS\AiShoppingAssistant\Model\Eval\FixedClientResolver;
use MageOS\AiShoppingAssistant\Test\Unit\Support\AiBaseDoubles;
use PHPUnit\Framework\TestCase;

class ScriptedAiClientTest extends TestCase
{
    use AiBaseDoubles;

    public function testRoundsPlayInOrderAndReturnTheAssembledTurn(): void
    {
        $client = $this->scriptedClient()
            ->addToolRound([['id' => 'tu1', 'name' => 'a', 'input' => ['x' => 1]]], 'Checking.')
            ->addTextRound('Done.');

        $first = $client->streamChat($this->request(), ['tool_choice' => 'auto']);
        $types = array_map(static fn ($chunk) => $chunk->getType(), iterator_to_array($first, false));
        $turn = $first->getReturn();

        $this->assertSame([StreamChunkType::Text, StreamChunkType::ToolCall, StreamChunkType::Usage], $types);
        $this->assertSame('Checking.', $turn->getText());
        $this->assertSame('tu1', $turn->getToolCalls()[0]->getId());
        $this->assertSame(FinishReason::ToolCall, $turn->getFinishReason());
        $this->assertSame('Done.', $client->chat($this->request())->getText());
        $this->assertSame(['tool_choice' => 'auto'], $client->calls[0]['options']);
        $this->assertCount(2, $client->calls);
    }

    public function testAFailureRoundThrowsAfterItsText(): void
    {
        $client = $this->scriptedClient()->addFailure(new AiTransientException(__('overloaded')), 'Half');
        $stream = $client->streamChat($this->request());

        $this->assertSame('Half', $stream->current()->getText());
        $this->expectException(AiTransientException::class);
        $stream->next();
    }

    public function testAnExhaustedScriptFailsLoudly(): void
    {
        $this->expectException(\LogicException::class);

        iterator_to_array($this->scriptedClient()->streamChat($this->request()), false);
    }

    public function testTheFixedResolverReturnsItsClientForEveryStore(): void
    {
        $client = $this->scriptedClient();

        $this->assertSame($client, (new FixedClientResolver($client))->forStore(7));
    }

    private function request(): ChatRequest
    {
        return new ChatRequest([new ChatMessage(MessageRole::User, 'Hi')]);
    }
}
