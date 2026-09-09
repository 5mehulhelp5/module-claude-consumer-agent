<?php
declare(strict_types=1);

namespace MageOS\ClaudeConsumerAgent\Api\Session;

use MageOS\ClaudeConsumerAgent\Model\Agent\SessionContext;
use MageOS\ClaudeConsumerAgent\Model\Agent\SessionState;
use MageOS\ClaudeConsumerAgent\Model\Session\Binding;

interface SessionRepositoryInterface
{
    public function bind(?string $sessionId, SessionContext $ctx, string $surface = 'overlay'): Binding;

    public function create(SessionContext $ctx, string $surface = 'overlay'): Binding;

    public function save(Binding $binding, SessionState $state): bool;

    public function delete(string $sessionId): void;
}
