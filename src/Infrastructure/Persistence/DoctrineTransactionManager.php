<?php

declare(strict_types=1);

namespace Kumwe\App\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Kumwe\Transaction\Contract\TransactionManager;

/**
 * DBAL implementation of `TransactionManager`, making one physical transaction cover each logical nest.
 *
 * Only the outermost call enters `Connection::transactional()`. Nested calls execute inside that physical
 * transaction and push hook frames of their own. A nested failure marks the physical transaction for
 * rollback even when application code catches it, avoiding DBAL savepoint semantics that would otherwise
 * let the outer call commit. Holding the frames and rollback cause is why this adapter is not `readonly`.
 *
 * @since  2.0.0
 */
final class DoctrineTransactionManager implements TransactionManager
{
    /**
     * Completion callbacks awaiting their scope, one frame per `transactional()` call still open.
     *
     * The last entry is the innermost scope, which is where `afterCommit()` and `afterRollback()` file
     * what they are handed. An empty stack means no transaction is open, which is what makes
     * `afterCommit()` run inline and `afterRollback()` discard its operation.
     *
     * @var    list<array{commit: list<callable(): void>, rollback: list<callable(): void>}>
     * @since  2.0.0
     */
    private array $frames = [];

    /**
     * First nested failure that doomed the active nest, retained to prevent an otherwise successful commit.
     *
     * @var    \Throwable|null
     * @since  2.0.0
     */
    private ?\Throwable $rollbackCause = null;

    /**
     * Bind the manager to the connection whose transactions it drives.
     *
     * @param  Connection  $connection  DBAL connection this manager begins, commits and rolls back on.
     *
     * @since  2.0.0
     */
    public function __construct(private Connection $connection)
    {
    }

    /**
     * Run an operation inside a transaction, committing when it returns and rolling back when it throws.
     *
     * A nested call runs directly inside the outer physical transaction and pushes its own frame. A failure
     * dooms that physical transaction even when an enclosing operation catches it. If the outer operation
     * otherwise returns, that retained failure is rethrown to make DBAL roll back; if the outer operation
     * later throws a different exception, its own exception still reaches its caller unchanged. Rollback-hook
     * failures never replace either operation failure.
     *
     * @template T
     *
     * @param   callable(): T  $operation  Work to perform inside the transaction scope.
     *
     * @return  T  Whatever the operation returned, passed straight back.
     *
     * @throws  \LogicException  When the frame this call pushed is no longer on the stack, meaning
     *          something else popped it while the operation was running.
     * @throws  \Doctrine\DBAL\Exception  When the driver cannot begin, commit or roll back.
     *
     * @since   2.0.0
     */
    public function transactional(callable $operation): mixed
    {
        $outermost = $this->frames === [];
        if ($outermost) {
            $this->rollbackCause = null;
        }
        $this->frames[] = ['commit' => [], 'rollback' => []];

        try {
            $result = $outermost
                ? $this->connection->transactional(function (Connection $connection) use ($operation): mixed {
                    $result = $operation();
                    if ($this->rollbackCause !== null) {
                        throw $this->rollbackCause;
                    }

                    return $result;
                })
                : $operation();
        } catch (\Throwable $exception) {
            $this->rollbackCause ??= $exception;
            $frame = array_pop($this->frames);
            if (is_array($frame)) {
                $this->invokeRollback($frame['rollback']);
            }
            if ($outermost) {
                $this->rollbackCause = null;
            }
            throw $exception;
        }

        $frame = array_pop($this->frames);
        if (!is_array($frame)) {
            if ($outermost) {
                $this->rollbackCause = null;
            }
            throw new \LogicException('The transaction completion frame was lost.');
        }
        $parent = array_key_last($this->frames);
        if ($parent !== null) {
            array_push($this->frames[$parent]['commit'], ...$frame['commit']);
            array_push($this->frames[$parent]['rollback'], ...$frame['rollback']);
        } else {
            $this->rollbackCause = null;
            $this->invoke($frame['commit']);
        }

        return $result;
    }

    /**
     * Queue a side effect to run once the outermost transaction has committed.
     *
     * With no transaction open the operation runs inline, so a caller outside a transaction still gets
     * the effect rather than having it silently dropped. Inside a nest the hook rides up frame by frame
     * and fires only after the outermost scope commits, which is what keeps an unundoable effect from
     * running for work a later failure discards.
     *
     * @param   callable(): void  $operation  Side effect to perform once the work is durable.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function afterCommit(callable $operation): void
    {
        $frame = array_key_last($this->frames);
        if ($frame === null) {
            $operation();
            return;
        }
        $this->frames[$frame]['commit'][] = $operation;
    }

    /**
     * Queue a compensating action to run if the scope that registered it is discarded.
     *
     * Registering with no transaction open drops the operation, since nothing was staged that could
     * still need undoing. Within a nest a scope that succeeds hands its rollback hooks to the enclosing
     * frame, so they still fire if an outer scope fails afterwards.
     *
     * @param   callable(): void  $operation  Compensating action to perform when the scope is discarded.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function afterRollback(callable $operation): void
    {
        $frame = array_key_last($this->frames);
        if ($frame !== null) {
            $this->frames[$frame]['rollback'][] = $operation;
        }
    }

    /**
     * Run one frame's queued callbacks in the order they were registered.
     *
     * A callback that throws abandons the rest of the frame, so a hook that can fail should contain its
     * own failure rather than take the hooks queued behind it down with it.
     *
     * @param   list<callable(): void>  $operations  Callbacks collected by a single completion frame.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function invoke(array $operations): void
    {
        foreach ($operations as $operation) {
            $operation();
        }
    }

    /**
     * Run every rollback callback without allowing one to replace the operation's original failure.
     *
     * @param   list<callable(): void>  $operations  Compensations collected by the discarded frame.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function invokeRollback(array $operations): void
    {
        foreach ($operations as $operation) {
            try {
                $operation();
            } catch (\Throwable) {
                // The transaction failure is the caller-visible outcome; a compensation cannot replace it.
            }
        }
    }
}
