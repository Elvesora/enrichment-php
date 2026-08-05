<?php

declare(strict_types=1);

namespace Elvesora\Enrichment\Contracts;

use Elvesora\Enrichment\EnrichmentResult;

interface EnrichmentClientInterface
{
    public function enrich(string $domain, ?string $idempotencyKey = null): EnrichmentResult;
}
