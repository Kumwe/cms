<?php

declare(strict_types=1);

namespace Kumwe\App\Application\Authorization;

use Kumwe\Context\Value\ExecutionContext;

/**
 * Names the PSR-7 request attribute the authenticating middleware stores the host execution context under.
 *
 * The context itself is the package value `Kumwe\Context\Value\ExecutionContext`, which owns no request
 * vocabulary, so the host keeps the one key every session and bearer middleware writes and every handler,
 * renderer, request adapter and idempotency fence reads. The package class name doubles as the key, exactly as
 * the retired App class did, so no two components can drift apart on the spelling, and the same key serves the
 * administrator, portal, API and MCP surfaces alike.
 *
 * @since  2.0.0
 */
final class ExecutionContextAttribute
{
    /**
     * PSR-7 request attribute holding the active `ExecutionContext`.
     *
     * @var    string
     * @since  2.0.0
     */
    public const string NAME = ExecutionContext::class;
}
