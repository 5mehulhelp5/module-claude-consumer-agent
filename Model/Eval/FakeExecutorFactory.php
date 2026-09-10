<?php
declare(strict_types=1);

namespace MageOS\ClaudeConsumerAgent\Model\Eval;

use MageOS\ClaudeConsumerAgent\Model\Agent\Executor;
use MageOS\ClaudeConsumerAgent\Model\Agent\ExecutorFactory;

/**
 * Executor's own dependencies that are not part of the per-call data (registry,
 * presentation, validator, provenance, sanitizer, logger) would otherwise resolve
 * through the shared object manager, which always hands back the real storefront
 * backend. Overriding create() here keeps the whole tool-execution path, not just the
 * two calls Orchestrator makes directly, wired to whichever backend the eval runner
 * built this factory with.
 */
final class FakeExecutorFactory extends ExecutorFactory
{
    public function __construct(
        private readonly \MageOS\ClaudeConsumerAgent\Model\Agent\Tool\Registry $registry,
        private readonly \MageOS\ClaudeConsumerAgent\Model\Agent\Presentation\Runner $presentation,
        private readonly \MageOS\ClaudeConsumerAgent\Model\Agent\Schema\Validator $validator,
        private readonly \MageOS\ClaudeConsumerAgent\Model\Agent\Gate\Provenance $provenance,
        private readonly \MageOS\ClaudeConsumerAgent\Model\Agent\Fencing\Sanitizer $sanitizer,
        private readonly \Psr\Log\LoggerInterface $logger
    ) {
    }

    public function create(array $data = [])
    {
        return new Executor(
            $this->registry,
            $this->presentation,
            $this->validator,
            $this->provenance,
            $this->sanitizer,
            $this->logger,
            $data['context'],
            $data['state'],
            $data['config']
        );
    }
}
