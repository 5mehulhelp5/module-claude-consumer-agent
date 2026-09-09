<?php
declare(strict_types=1);

namespace MageOS\ClaudeConsumerAgent\Test\Unit\Controller\Request;

use Magento\Framework\App\Request\Http;
use MageOS\ClaudeConsumerAgent\Controller\Request\BodyReader;
use MageOS\ClaudeConsumerAgent\Model\Agent\AgentConfig;
use PHPUnit\Framework\TestCase;

final class BodyReaderTest extends TestCase
{
    private function request(string $content): Http
    {
        $request = $this->createMock(Http::class);
        $request->method('getContent')->willReturn($content);
        return $request;
    }

    public function testBadJsonThrowsInvalidArgumentException(): void
    {
        $reader = new BodyReader();
        $this->expectException(\InvalidArgumentException::class);
        $reader->read($this->request('not json'), new AgentConfig());
    }

    public function testNonObjectJsonThrowsInvalidArgumentException(): void
    {
        $reader = new BodyReader();
        $this->expectException(\InvalidArgumentException::class);
        $reader->read($this->request('"just a string"'), new AgentConfig());
    }

    public function testEmptyMessageThrowsInvalidArgumentException(): void
    {
        $reader = new BodyReader();
        $this->expectException(\InvalidArgumentException::class);
        $reader->read($this->request((string)json_encode(['message' => '   '])), new AgentConfig());
    }

    public function testTooLongMessageThrowsInvalidArgumentException(): void
    {
        $reader = new BodyReader();
        $config = new AgentConfig(maxMessageLength: 5);
        $this->expectException(\InvalidArgumentException::class);
        $reader->read($this->request((string)json_encode(['message' => 'this is too long'])), $config);
    }

    public function testSessionIdIsAcceptedWhen64HexCharacters(): void
    {
        $reader = new BodyReader();
        $valid = str_repeat('a', 64);
        $result = $reader->read(
            $this->request((string)json_encode(['message' => 'hi', 'session' => $valid])),
            new AgentConfig()
        );
        $this->assertSame($valid, $result->sessionId);
    }

    public function testSessionIdIsNullWhenNot64HexCharacters(): void
    {
        $reader = new BodyReader();
        $result = $reader->read(
            $this->request((string)json_encode(['message' => 'hi', 'session' => 'not-a-session-id'])),
            new AgentConfig()
        );
        $this->assertNull($result->sessionId);
    }

    public function testStreamDefaultsToTrueWhenAbsent(): void
    {
        $reader = new BodyReader();
        $result = $reader->read($this->request((string)json_encode(['message' => 'hi'])), new AgentConfig());
        $this->assertTrue($result->wantsStream);
    }

    public function testStreamIsFalseWhenExplicitlyZero(): void
    {
        $reader = new BodyReader();
        $result = $reader->read(
            $this->request((string)json_encode(['message' => 'hi', 'stream' => 0])),
            new AgentConfig()
        );
        $this->assertFalse($result->wantsStream);
    }

    public function testReadStartAllowsEmptyMessageAndReturnsPage(): void
    {
        $reader = new BodyReader();
        $result = $reader->readStart($this->request((string)json_encode(['page' => ['page_type' => 'home']])));
        $this->assertSame('', $result->message);
        $this->assertSame(['page_type' => 'home'], $result->page);
    }

    public function testReadStartRejectsBadJson(): void
    {
        $reader = new BodyReader();
        $this->expectException(\InvalidArgumentException::class);
        $reader->readStart($this->request('not json'));
    }
}
