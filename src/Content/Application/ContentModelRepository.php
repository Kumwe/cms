<?php

declare(strict_types=1);

namespace Kumwe\App\Content\Application;

use Kumwe\App\Content\Domain\ContentTypeDefinition;
use Kumwe\App\Workflow\Domain\WorkflowDefinition;
use Kumwe\Context\Value\SiteContext;

/**
 * Persistence contract for the versioned content model: content type and workflow definitions.
 *
 * Definitions are never edited in place. Each publication appends a new version and moves the head
 * pointer, so an entry written against version three keeps validating against version three even after
 * version four ships — which is the guarantee `ContentRecord` relies on when it pins its definition
 * versions. Every method is scoped by site, and lookups accept either the stable UUID or the operator's
 * handle so that seed data, console commands and the API can all name a definition the way that suits
 * them.
 *
 * @since  2.0.0
 */
interface ContentModelRepository
{
    /**
     * List the head version of every content type published for a site.
     *
     * @param   SiteContext  $site  Site whose model is being read.
     *
     * @return  list<ContentTypeDefinition>  Ordered by handle, so administrator pickers are stable.
     *
     * @since   2.0.0
     */
    public function contentTypes(SiteContext $site): array;

    /**
     * Load one content type definition, at its head or at a specific published version.
     *
     * @param   SiteContext  $site        Site the definition must belong to.
     * @param   string       $identifier  UUID or operator-facing handle of the content type.
     * @param   ?int         $version     Version to load, or null for the current head.
     *
     * @return  ?ContentTypeDefinition  Null when the site has no such content type at that version.
     *
     * @since   2.0.0
     */
    public function contentType(SiteContext $site, string $identifier, ?int $version = null): ?ContentTypeDefinition;

    /**
     * Register a brand new content type and its first version.
     *
     * @param   ContentTypeDefinition  $definition  Definition to store, at version one.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function insertContentType(ContentTypeDefinition $definition): void;

    /**
     * Append the next version of an existing content type and move its head pointer to it.
     *
     * The head move is conditional on the version the caller read, so two operators editing the same
     * content type cannot interleave publications and lose one of them.
     *
     * @param   ContentTypeDefinition  $definition       Definition carrying the already-incremented version.
     * @param   int                    $expectedVersion  Version the caller read before editing.
     *
     * @return  void
     *
     * @throws  \Kumwe\App\Content\Domain\VersionConflict  When the stored head has already moved on.
     *
     * @since   2.0.0
     */
    public function publishContentType(ContentTypeDefinition $definition, int $expectedVersion): void;

    /**
     * List the head version of every workflow published for a site.
     *
     * @param   SiteContext  $site  Site whose model is being read.
     *
     * @return  list<WorkflowDefinition>  Ordered by handle, so administrator pickers are stable.
     *
     * @since   2.0.0
     */
    public function workflows(SiteContext $site): array;

    /**
     * Load one workflow definition, at its head or at a specific published version.
     *
     * Content entries pin the workflow version they were authored against, so the version argument is
     * the normal path here rather than an edge case.
     *
     * @param   SiteContext  $site        Site the definition must belong to.
     * @param   string       $identifier  UUID or operator-facing handle of the workflow.
     * @param   ?int         $version     Version to load, or null for the current head.
     *
     * @return  ?WorkflowDefinition  Null when the site has no such workflow at that version.
     *
     * @since   2.0.0
     */
    public function workflow(SiteContext $site, string $identifier, ?int $version = null): ?WorkflowDefinition;

    /**
     * Register a brand new workflow and its first version.
     *
     * @param   WorkflowDefinition  $definition  Definition to store, at version one.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function insertWorkflow(WorkflowDefinition $definition): void;

    /**
     * Append the next version of an existing workflow and move its head pointer to it.
     *
     * @param   WorkflowDefinition  $definition       Definition carrying the already-incremented version.
     * @param   int                 $expectedVersion  Version the caller read before editing.
     *
     * @return  void
     *
     * @throws  \Kumwe\App\Content\Domain\VersionConflict  When the stored head has already moved on.
     *
     * @since   2.0.0
     */
    public function publishWorkflow(WorkflowDefinition $definition, int $expectedVersion): void;
}
