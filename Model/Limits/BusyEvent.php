<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Model\Limits;

use MageOS\AiShoppingAssistant\Model\Agent\Event;

class BusyEvent
{
    public function event(int $retryAfter): Event
    {
        return Event::error(
            'The assistant is busy right now. Please try again in a few seconds.',
            $retryAfter
        );
    }

    public function limited(int $retryAfter): Event
    {
        $minutes = (int)ceil($retryAfter / 60);
        $message = $minutes <= 1
            ? 'You are sending messages quickly. Please wait a minute, then try again.'
            : sprintf('You are sending messages quickly. Please wait about %d minutes, then try again.', $minutes);

        return Event::error($message, $retryAfter, 'rate_limit');
    }

    public function sessionCap(): Event
    {
        return Event::error(
            'This conversation has reached its length limit. Start a new conversation to keep going.',
            null,
            'session_cap'
        );
    }
}
