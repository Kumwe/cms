<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessRecord\Application;

use Kumwe\Context\Value\ExecutionContext;
use Kumwe\Context\Value\SiteContext;

/**
 * Serializes record mutations with definition lifecycle and physical-schema execution.
 *
 * A record operation resolves a definition and its installation, then reads or writes the physical
 * tables that installation describes — and between those two steps an installer could publish a new
 * version, disable the owner, or alter the tables underneath it. This port closes that window: the
 * caller takes a fence inside its own transaction, and the definition's installation state is held
 * still until that transaction ends. The returned generation is the state as observed under the lock,
 * which callers pass to `BusinessRecordMutationGeneration::assertMatches()` so that a definition
 * resolved outside the lock is proved to be the one the lock is actually holding.
 *
 * @since  2.0.0
 */
interface BusinessRecordMutationFence
{
    /**
     * Hold a definition's installation exclusively for the rest of the caller's transaction.
     *
     * This is the fence a mutation takes. It serializes writes to one definition against each other
     * and against the schema installer, so only an installation that is live and owned by an active
     * owner can be mutated through it.
     *
     * @param   ExecutionContext  $context               Actor and site the mutation runs as.
     * @param   string            $definitionIdentifier  Definition UUID or handle to fence.
     *
     * @return  BusinessRecordMutationGeneration  Installation identity, version, checksums, and status
     *          as observed under the exclusive lock.
     *
     * @throws  \Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordDefinitionUnavailable  When
     *          no definition matches the identifier on this site, or its owner is disabled.
     * @throws  \Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordSchemaUnavailable  When the
     *          definition has no installation that is live and owned by the definition's owner.
     * @throws  \Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordTemporarilyUnavailable  When
     *          no transaction is open to hold the lock, or the lock cannot be taken right now.
     *
     * @since   2.0.0
     */
    public function lock(
        ExecutionContext $context,
        string $definitionIdentifier,
    ): BusinessRecordMutationGeneration;

    /**
     * Hold a definition's installation against schema change while concurrent readers proceed.
     *
     * This is the fence a query takes: it keeps an installer from moving the schema mid-read without
     * serializing reads against each other. Passing `$historyOnly` also admits an installation that
     * has been withdrawn from live traffic, which is what lets an authorized history read describe
     * records whose type is no longer accepting mutations.
     *
     * @param   SiteContext  $site                  Site whose installation of the definition is fenced.
     * @param   string       $definitionIdentifier  Definition UUID or handle to fence.
     * @param   bool         $historyOnly           True to also accept a withdrawn installation and a
     *          disabled owner, for reads of preserved history.
     *
     * @return  BusinessRecordMutationGeneration  Installation identity, version, checksums, and status
     *          as observed under the shared lock.
     *
     * @throws  \Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordDefinitionUnavailable  When
     *          no definition matches the identifier on this site, or its owner is disabled and
     *          $historyOnly is false.
     * @throws  \Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordSchemaUnavailable  When the
     *          definition has no installation whose status this call admits.
     * @throws  \Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordTemporarilyUnavailable  When
     *          no transaction is open to hold the lock, the platform offers no shared row lock, or the
     *          lock cannot be taken right now.
     *
     * @since   2.0.0
     */
    public function shared(
        SiteContext $site,
        string $definitionIdentifier,
        bool $historyOnly = false,
    ): BusinessRecordMutationGeneration;
}
