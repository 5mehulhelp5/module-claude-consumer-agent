<?php
declare(strict_types=1);

namespace MageOS\ClaudeConsumerAgent\Model\Limits;

use MageOS\ClaudeConsumerAgent\Model\Agent\Event;

final class BusyEvent
{
    public function event(int $retryAfter): Event
    {
        return Event::error(
            'The assistant is busy right now. Please try again in a few seconds.',
            $retryAfter
        );
    }
}
