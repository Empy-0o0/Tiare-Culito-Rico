<?php

namespace App\Services\Ai\Llm;

final class LlmResult
{
    /** @param array<string, mixed> $data */
    public function __construct(
        public readonly array $data,
        public readonly string $model,
        public readonly ?int $inputTokens,
        public readonly ?int $outputTokens,
    ) {}
}
