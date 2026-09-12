<?php

declare(strict_types=1);

namespace Kumwe\App\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Kumwe\Transaction\Contract\TransactionState;

/**
 * DBAL implementation of `TransactionState`, reading the connection's own transaction nesting.
 *
 * The answer comes from the connection rather than from `DoctrineTransactionManager`'s frame stack so that
 * a transaction begun by any route — the manager, a repository, a migration, or driver code — is reported
 * alike: what matters to a caller is whether the connection is inside a transaction, not who opened it.
 *
 * @since  2.0.0
 */
final readonly class DoctrineTransactionState implements TransactionState
{
    /**
     * Bind the view to the connection whose transaction state it reports.
     *
     * @param  Connection  $connection  DBAL connection the application's transactions run on.
     *
     * @since  2.0.0
     */
    public function __construct(private Connection $connection)
    {
    }

    /**
     * Report whether the connection currently holds an open transaction at any nesting level.
     *
     * @return  bool  True when DBAL reports a transaction nesting level above zero.
     *
     * @since   2.0.0
     */
    public function isActive(): bool
    {
        return $this->connection->isTransactionActive();
    }
}
