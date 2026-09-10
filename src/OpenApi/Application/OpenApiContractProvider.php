<?php

declare(strict_types=1);

namespace Kumwe\App\OpenApi\Application;

use Kumwe\Context\Value\ExecutionContext;

/**
 * Supplies the exact verified OpenAPI contract for an authenticated request context.
 *
 * @since  2.0.0
 */
interface OpenApiContractProvider
{
    /**
     * Resolve a checksum-verified contract for the current authorization and runtime generation.
     *
     * @param   ExecutionContext  $context  Authenticated API actor and exact site/membership.
     *
     * @return  CompiledOpenApiContract  Canonical current-generation contract.
     *
     * @throws  OpenApiContractUnavailable  When current metadata cannot be safely assembled or verified.
     *
     * @since   2.0.0
     */
    public function contract(ExecutionContext $context): CompiledOpenApiContract;
}
