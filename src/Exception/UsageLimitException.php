<?php

declare(strict_types=1);

namespace Elvesora\Enrichment\Exception;

final class UsageLimitException extends EnrichmentException
{
    public function limit(): ?int
    {
        return $this->credits->limit ?? $this->integerResponseField('limit');
    }

    public function remaining(): ?int
    {
        return $this->credits->remaining ?? $this->integerResponseField('remaining');
    }

    private function integerResponseField(string $field): ?int
    {
        $value = $this->responseBody[$field] ?? null;

        return is_int($value) ? $value : null;
    }
}
