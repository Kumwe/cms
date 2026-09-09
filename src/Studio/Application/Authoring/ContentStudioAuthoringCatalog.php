<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Application\Authoring;

use Kumwe\App\Studio\Application\Composition\StudioCompositionContributionCatalog;
use Kumwe\App\Studio\Application\Release\StudioCoreCatalog;
use Kumwe\Producer\Canonical\CanonicalJson;
use LogicException;
use stdClass;

/**
 * The one block catalog a contextual Content authoring session, its deployment and its saves share.
 *
 * Studio's hosted runtime admits a block only when the resolved session locks it and the lock is
 * either compiled into the pinned browser module or admitted by the resolved authoring target from
 * the deployment's contribution bundle. The App therefore locks the union of the first-party
 * catalog the pinned release compiles in and its own Content-field blocks, ships those field blocks
 * (and its patterns) as the bundle, and declares them as the target's contribution dependencies.
 * Every member is derived deterministically from the same inputs, so the deployment document, the
 * `authoring/start` snapshot and each accepted save agree on the catalog and on its generation.
 *
 * @since  2.0.0
 */
final readonly class ContentStudioAuthoringCatalog
{
    /**
     * Compose the pinned first-party catalog with the App's live contribution projection.
     *
     * @param  StudioCompositionContributionCatalog  $contributions  Live App contribution projection.
     * @param  StudioCoreCatalog                     $core           Exact first-party coordinates.
     *
     * @since  2.0.0
     */
    public function __construct(
        private StudioCompositionContributionCatalog $contributions,
        private StudioCoreCatalog $core,
    ) {
    }

    /**
     * Every exact block lock a contextual session offers, in type order.
     *
     * @return  list<stdClass>  `{type, version, revision}` locks.
     *
     * @throws  LogicException  When an App block reuses a first-party type at another coordinate.
     *
     * @since   2.0.0
     */
    public function blockLocks(): array
    {
        $locks = [];
        foreach ($this->core->blockCoordinates() as $coordinate) {
            $locks[$coordinate->type] = (object) [
                'type' => $coordinate->type,
                'version' => $coordinate->version,
                'revision' => $coordinate->revision,
            ];
        }
        foreach ($this->projection()->blockLocks as $lock) {
            $type = $lock->type;
            if (!is_string($type)) {
                continue;
            }
            $existing = $locks[$type] ?? null;
            if ($existing === null) {
                $locks[$type] = $lock;
                continue;
            }
            if ($existing->version !== $lock->version || $existing->revision !== $lock->revision) {
                throw new LogicException(sprintf(
                    'The App block %s is declared at a coordinate the pinned Studio release does not compile in.',
                    $type,
                ));
            }
        }
        ksort($locks, SORT_STRING);

        return array_values($locks);
    }

    /**
     * The App-owned canonical documents the browser must admit for its target: field blocks and patterns.
     *
     * Documents whose coordinates the pinned module already compiles in are never shipped twice, and
     * executable-adjacent kinds (field adapters, inspectors, design vocabularies, migrations) stay on
     * the server: the contextual surface binds fields through Studio's first-party controls.
     *
     * @return  list<stdClass>  Canonical `block-definition` and `pattern` documents in identity order.
     *
     * @since   2.0.0
     */
    public function contributionPayloads(): array
    {
        $payloads = [];
        foreach ($this->projection()->documents as $document) {
            $kind = $document->kind ?? null;
            if ($kind === 'block-definition') {
                $type = $document->type ?? null;
                if (is_string($type) && !$this->core->hasBlock($type)) {
                    $payloads['block-definition' . "\0" . $type] = $document;
                }
                continue;
            }
            if ($kind === 'pattern') {
                $id = $document->id ?? null;
                if (is_string($id) && !$this->core->hasPattern($id)) {
                    $payloads['pattern' . "\0" . $id] = $document;
                }
            }
        }
        ksort($payloads, SORT_STRING);

        return array_values($payloads);
    }

    /**
     * The exact contribution dependencies the Content authoring target declares.
     *
     * @return  list<stdClass>  Schema-valid `contributionDependency` members, all required.
     *
     * @since   2.0.0
     */
    public function contributionDependencies(): array
    {
        $dependencies = [];
        foreach ($this->contributionPayloads() as $payload) {
            $kind = $payload->kind;
            $id = $kind === 'block-definition' ? $payload->type : $payload->id;
            $version = $payload->version ?? null;
            if (!is_string($kind) || !is_string($id) || !is_string($version)) {
                continue;
            }
            $dependencies[] = (object) [
                'kind' => $kind,
                'id' => $id,
                'versions' => $version,
                'required' => true,
            ];
        }

        return $dependencies;
    }

    /**
     * The immutable generation label binding the bundle and locks the session was opened with.
     *
     * @return  string  Stable label that changes whenever a lock or payload changes.
     *
     * @since   2.0.0
     */
    public function contributionGeneration(): string
    {
        return 'contributions-' . substr(hash('sha256', CanonicalJson::stringify((object) [
            'blocks' => $this->blockLocks(),
            'payloads' => $this->contributionPayloads(),
            'release' => $this->core->release,
        ])), 0, 24);
    }

    /**
     * The Content authoring target declaration bound to this catalog.
     *
     * @return  stdClass  Schema-valid `authoring-target` declaration.
     *
     * @since   2.0.0
     */
    public function declaration(): stdClass
    {
        return ContentStudioAuthoringDocuments::declaration($this->contributionDependencies());
    }

    /**
     * Project the App's live contributions for the contextual renderers without an actor filter.
     *
     * @return  \Kumwe\App\Studio\Application\Composition\StudioCompositionContributionProjection  Snapshot.
     *
     * @since   2.0.0
     */
    private function projection(): \Kumwe\App\Studio\Application\Composition\StudioCompositionContributionProjection
    {
        return $this->contributions->project([], ContentStudioAuthoringService::RENDERERS);
    }
}
