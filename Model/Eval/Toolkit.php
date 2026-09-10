<?php
declare(strict_types=1);

namespace MageOS\ClaudeConsumerAgent\Model\Eval;

use MageOS\ClaudeConsumerAgent\Model\Agent\Fencing\Fence;
use MageOS\ClaudeConsumerAgent\Model\Agent\Fencing\Sanitizer;
use MageOS\ClaudeConsumerAgent\Model\Agent\Gate\Options;
use MageOS\ClaudeConsumerAgent\Model\Agent\Gate\Provenance;
use MageOS\ClaudeConsumerAgent\Model\Agent\Grounding\SkuCandidates;
use MageOS\ClaudeConsumerAgent\Model\Agent\Lexicon;
use MageOS\ClaudeConsumerAgent\Model\Agent\Presentation\Registry as PresentationRegistry;
use MageOS\ClaudeConsumerAgent\Model\Agent\Prompt\Assembly;
use MageOS\ClaudeConsumerAgent\Model\Agent\Prompt\DynamicContext;
use MageOS\ClaudeConsumerAgent\Model\Agent\Prompt\StaticSystem;
use MageOS\ClaudeConsumerAgent\Model\Agent\Schema\Validator;
use MageOS\ClaudeConsumerAgent\Model\Agent\Serializer;
use MageOS\ClaudeConsumerAgent\Model\Agent\Skill\Registry as SkillRegistry;
use MageOS\ClaudeConsumerAgent\Model\Agent\Turn\StreamedRoundFactory;
use MageOS\ClaudeConsumerAgent\Model\Config\StoreConfig;

class Toolkit
{
    public function __construct(
        private readonly \MageOS\ClaudeConsumerAgent\Model\Config\StoreConfig $storeConfig,
        private readonly \MageOS\ClaudeConsumerAgent\Model\Agent\Prompt\StaticSystem $staticSystem,
        private readonly \MageOS\ClaudeConsumerAgent\Model\Agent\Prompt\DynamicContext $dynamicContext,
        private readonly \MageOS\ClaudeConsumerAgent\Model\Agent\Prompt\Assembly $assembly,
        private readonly \MageOS\ClaudeConsumerAgent\Model\Agent\Lexicon $lexicon,
        private readonly \MageOS\ClaudeConsumerAgent\Model\Agent\Grounding\SkuCandidates $skuCandidates,
        private readonly \MageOS\ClaudeConsumerAgent\Model\Agent\Turn\StreamedRoundFactory $streamedRoundFactory,
        private readonly \MageOS\ClaudeConsumerAgent\Model\Agent\Schema\Validator $validator,
        private readonly \MageOS\ClaudeConsumerAgent\Model\Agent\Gate\Provenance $provenance,
        private readonly \MageOS\ClaudeConsumerAgent\Model\Agent\Gate\Options $options,
        private readonly \MageOS\ClaudeConsumerAgent\Model\Agent\Fencing\Sanitizer $sanitizer,
        private readonly \MageOS\ClaudeConsumerAgent\Model\Agent\Fencing\Fence $fence,
        private readonly \MageOS\ClaudeConsumerAgent\Model\Agent\Serializer $serializer,
        private readonly \MageOS\ClaudeConsumerAgent\Model\Agent\Skill\Registry $skillRegistry,
        private readonly \MageOS\ClaudeConsumerAgent\Model\Agent\Presentation\Registry $presentationRegistry
    ) {
    }

    public function storeConfig(): StoreConfig
    {
        return $this->storeConfig;
    }

    public function staticSystem(): StaticSystem
    {
        return $this->staticSystem;
    }

    public function dynamicContext(): DynamicContext
    {
        return $this->dynamicContext;
    }

    public function assembly(): Assembly
    {
        return $this->assembly;
    }

    public function lexicon(): Lexicon
    {
        return $this->lexicon;
    }

    public function skuCandidates(): SkuCandidates
    {
        return $this->skuCandidates;
    }

    public function streamedRoundFactory(): StreamedRoundFactory
    {
        return $this->streamedRoundFactory;
    }

    public function validator(): Validator
    {
        return $this->validator;
    }

    public function provenance(): Provenance
    {
        return $this->provenance;
    }

    public function options(): Options
    {
        return $this->options;
    }

    public function sanitizer(): Sanitizer
    {
        return $this->sanitizer;
    }

    public function fence(): Fence
    {
        return $this->fence;
    }

    public function serializer(): Serializer
    {
        return $this->serializer;
    }

    public function skillRegistry(): SkillRegistry
    {
        return $this->skillRegistry;
    }

    public function presentationRegistry(): PresentationRegistry
    {
        return $this->presentationRegistry;
    }
}
