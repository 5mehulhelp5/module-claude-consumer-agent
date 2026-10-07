<?php
declare(strict_types=1);

namespace MageOS\ClaudeConsumerAgent\Test\Unit\Limits;

use MageOS\ClaudeConsumerAgent\Model\Limits\BusyEvent;
use PHPUnit\Framework\TestCase;

class BusyEventTest extends TestCase
{
    public function testARateLimitNamesTheWaitInMinutes(): void
    {
        $event = (new BusyEvent())->limited(270);

        $this->assertSame('rate_limit', $event->data['kind']);
        $this->assertSame(270, $event->data['retry_after']);
        $this->assertStringContainsString('wait about 5 minutes', $event->data['message']);
    }

    public function testAWaitUnderAMinuteAsksForAMinute(): void
    {
        $this->assertStringContainsString('wait a minute', (new BusyEvent())->limited(40)->data['message']);
    }

    public function testAFullServerKeepsTheShortBusyMessage(): void
    {
        $event = (new BusyEvent())->event(5);

        $this->assertArrayNotHasKey('kind', $event->data);
        $this->assertStringContainsString('busy', $event->data['message']);
    }
}
