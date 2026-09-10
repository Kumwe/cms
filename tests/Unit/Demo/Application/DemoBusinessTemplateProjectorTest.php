<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Demo\Application;

use Kumwe\App\BusinessDefinition\Domain\EntityTypeDefinition;
use Kumwe\App\Demo\Application\DemoBusinessTemplateProjector;
use Kumwe\App\Demo\Application\VdmBusinessManifestProjector;
use Kumwe\App\Demo\Infrastructure\FilesystemDemoManifestCatalog;
use Kumwe\Context\Value\SiteContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Proves live published definitions project back into the released template namespace, order, and shape.
 *
 * The released VDM profile is used as the live system: every shipped draft is published to version one under
 * `site.default.vdm_`, exactly what a running default-site installation exposes, and the projector must turn
 * that back into a package another profile name can install.
 *
 * @since  2.0.0
 */
#[CoversClass(DemoBusinessTemplateProjector::class)]
#[UsesClass(FilesystemDemoManifestCatalog::class)]
#[UsesClass(VdmBusinessManifestProjector::class)]
final class DemoBusinessTemplateProjectorTest extends TestCase
{
    /**
     * Order over the full reference graph, so a definition referenced only by a field still comes first.
     *
     * `invoice_line` and `quotation_line` declare no relationships at all; each reaches `product` through a
     * `core.entity_reference` field alone. A relationship-only order placed them before `product` and the
     * installer failed publishing them. The live catalog order is reversed here so nothing is placed by
     * accident, and every definition's persisted dependency set is checked to precede it.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testOrderPlacesEveryReferencedDefinitionFirstIncludingFieldReferences(): void
    {
        $projector = new DemoBusinessTemplateProjector();
        $live = $this->liveDefinitions();
        $fixtures = $this->fixtures();

        $ordered = $projector->orderByDependency(array_reverse($live));

        self::assertCount(count($live), $ordered);
        $position = [];
        foreach ($ordered as $offset => $definition) {
            $position[$definition->handle] = $offset;
        }
        foreach ($ordered as $definition) {
            foreach ($definition->dependencyGraph()['entities'] as $target) {
                self::assertLessThan(
                    $position[$definition->handle],
                    $position[$target],
                    sprintf('%s must be installed before %s.', $target, $definition->handle),
                );
            }
        }
        self::assertLessThan($position['site.default.vdm_invoice_line'], $position['site.default.vdm_product']);
        self::assertLessThan($position['site.default.vdm_quotation_line'], $position['site.default.vdm_product']);

        $byHandle = [];
        foreach ($ordered as $definition) {
            $byHandle[$definition->handle] = $definition;
        }
        self::assertSame(
            ['definition.product'],
            $projector->dependencies($byHandle['site.default.vdm_invoice_line'], $ordered, $fixtures),
        );
        $invoice = $projector->dependencies($byHandle['site.default.vdm_invoice'], $ordered, $fixtures);
        sort($invoice);
        self::assertSame(
            ['definition.client_account', 'definition.engagement', 'definition.invoice_line', 'definition.quotation'],
            $invoice,
        );
        self::assertSame([], $projector->dependencies($byHandle['site.default.vdm_product'], $ordered, $fixtures));
    }

    /**
     * Refuse definitions that reference one another in a cycle, naming the members of the cycle.
     *
     * A definition targeting itself is not a cycle the installer cannot satisfy and stays exportable.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testOrderRefusesAReferenceCycleByName(): void
    {
        $projector = new DemoBusinessTemplateProjector();
        $alpha = $this->referencing('site.default.vdm_alpha', 'site.default.vdm_beta', '0000000000a1');
        $beta = $this->referencing('site.default.vdm_beta', 'site.default.vdm_alpha', '0000000000b2');
        $self = $this->referencing('site.default.vdm_gamma', 'site.default.vdm_gamma', '0000000000c3');
        $client = $this->liveDefinitions()[0];
        self::assertSame('site.default.vdm_client_account', $client->handle);

        $ordered = $projector->orderByDependency([$self, $client]);
        self::assertSame(['site.default.vdm_gamma', 'site.default.vdm_client_account'], [
            $ordered[0]->handle,
            $ordered[1]->handle,
        ]);
        $fixtures = ['site.default.vdm_gamma' => 'definition.gamma'];
        self::assertSame([], $projector->dependencies($self, $ordered, $fixtures));

        try {
            $projector->orderByDependency([$client, $alpha, $beta]);
            self::fail('A reference cycle was written into an installation order.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('reference one another in a cycle', $exception->getMessage());
            self::assertStringContainsString('site.default.vdm_alpha', $exception->getMessage());
            self::assertStringContainsString('site.default.vdm_beta', $exception->getMessage());
            self::assertStringNotContainsString('site.default.vdm_client_account', $exception->getMessage());
        }
    }

    /**
     * Re-namespace every handle and every reference to it, and emit the released-draft document shape.
     *
     * The assembled package is then pushed through the install-side projector, which is the contract the
     * export has to satisfy: it accepts only default-site documents in the profile's own namespace.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTemplateDocumentsMoveEveryReferenceIntoTheProfileNamespaceAsDrafts(): void
    {
        $projector = new DemoBusinessTemplateProjector();
        $ordered = $projector->orderByDependency($this->liveDefinitions());
        $fixtures = $this->fixtures();

        $handles = $projector->templateHandles('fork', $ordered, $fixtures);

        self::assertCount(12, $handles);
        self::assertSame('site.default.fork_client_account', $handles['site.default.vdm_client_account']);
        self::assertSame('site.default.fork_invoice_line', $handles['site.default.vdm_invoice_line']);

        $documents = [];
        foreach ($ordered as $definition) {
            $document = $projector->templateDocument($definition, $handles);
            self::assertSame($handles[$definition->handle], $document['handle']);
            self::assertSame('draft', $document['status']);
            self::assertSame(0, $document['definition_version']);
            self::assertSame('default', $document['site']);
            self::assertSame(['type' => 'site', 'identifier' => 'default'], $document['owner']);
            self::assertArrayHasKey('record_invariants', $document);
            self::assertSame($definition->id, $document['id']);
            $encoded = json_encode($document, JSON_THROW_ON_ERROR);
            self::assertStringNotContainsString('site.default.vdm_', $encoded);
            $republished = EntityTypeDefinition::fromArray($document)->published($definition->definitionVersion);
            self::assertSame(
                count($definition->fields()),
                count($republished->fields()),
                'The template document must carry every live field.',
            );
            $documents[$fixtures[$definition->handle]] = $document;
        }
        $invoiceLine = $documents['definition.invoice_line'];
        self::assertIsArray($invoiceLine['fields']);
        $product = null;
        foreach ($invoiceLine['fields'] as $field) {
            self::assertIsArray($field);
            if (($field['handle'] ?? null) === 'product') {
                $product = $field;
            }
        }
        self::assertIsArray($product);
        self::assertSame(['target' => 'site.default.fork_product'], $product['configuration']);
        $invoice = $documents['definition.invoice'];
        self::assertIsArray($invoice['relationships']);
        foreach ($invoice['relationships'] as $relationship) {
            self::assertIsArray($relationship);
            self::assertIsString($relationship['target']);
            self::assertStringStartsWith('site.default.fork_', $relationship['target']);
        }

        $records = $projector->templateRecords($this->recordsDocument(), $handles);
        self::assertSame('default', $records['site']);
        foreach (['records', 'relations', 'actions', 'archives'] as $member) {
            self::assertIsArray($records[$member]);
            self::assertCount(count($this->recordsDocument()[$member]), $records[$member]);
            foreach ($records[$member] as $declaration) {
                self::assertIsArray($declaration);
                self::assertIsString($declaration['definition']);
                self::assertStringStartsWith('site.default.fork_', $declaration['definition']);
            }
        }

        $aggregate = [
            'format' => 'kumwe.demo-business-profile/v1',
            'profile' => 'fork',
            'version' => 1,
            'site_template' => 'default',
            'definition_documents' => $documents,
            'records_document' => $records,
        ];
        $projected = (new VdmBusinessManifestProjector())->forSite(
            $aggregate,
            SiteContext::fromString('customer-east'),
        );
        $encoded = json_encode($projected, JSON_THROW_ON_ERROR);
        self::assertStringContainsString('site.customer-east.fork_invoice_line', $encoded);
        self::assertStringNotContainsString('site.default.fork_', $encoded);
    }

    /**
     * Derive portable template handles from fixture tails, collapsing underscores and separating collisions.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTemplateHandlesCollapseUnderscoresAndSeparateCollisions(): void
    {
        $projector = new DemoBusinessTemplateProjector();
        [$client, $catalog, $engagement] = $this->liveDefinitions();

        $handles = $projector->templateHandles('fork', [$client, $catalog, $engagement], [
            $client->handle => 'definition.client__account_',
            $catalog->handle => 'definition.client_account',
            $engagement->handle => 'definition.engagement',
        ]);

        self::assertSame([
            $client->handle => 'site.default.fork_client_account',
            $catalog->handle => 'site.default.fork_client_account_2',
            $engagement->handle => 'site.default.fork_engagement',
        ], $handles);
    }

    /**
     * Refuse a definition without a fixture key, an unportable handle, foreign ownership, or an unknown handle.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTemplateProjectionRefusesWhatTheReleasedContractCannotCarry(): void
    {
        $projector = new DemoBusinessTemplateProjector();
        [$client] = $this->liveDefinitions();

        try {
            $projector->templateHandles('fork', [$client], []);
            self::fail('A definition without a fixture key received a template handle.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('has no definition fixture key', $exception->getMessage());
        }

        try {
            $projector->templateHandles('fork', [$client], [$client->handle => 'definition.' . str_repeat('a', 200)]);
            self::fail('An over-long template handle was accepted.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('cannot form a portable template handle', $exception->getMessage());
        }

        try {
            $projector->templateDocument($client, []);
            self::fail('A definition without a template handle was projected.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('has no template handle', $exception->getMessage());
        }

        $document = $client->toArray();
        $document['owner'] = ['type' => 'core', 'identifier' => 'core'];
        $document['handle'] = 'core.client_account';
        $foreign = EntityTypeDefinition::fromArray($document);
        try {
            $projector->templateDocument($foreign, [$foreign->handle => 'site.default.fork_client_account']);
            self::fail('A definition the site does not own was projected as a site template.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('is not site owned', $exception->getMessage());
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('references a definition the export does not carry');

        $projector->templateRecords(
            ['records' => [['definition' => 'site.default.vdm_unknown', 'record_id' => 'x']]],
            [$client->handle => 'site.default.fork_client_account'],
        );
    }

    /**
     * Publish every shipped VDM draft to version one, the way a running default-site installation holds it.
     *
     * @return  list<EntityTypeDefinition>  Published definitions in the released installation order.
     *
     * @since   2.0.0
     */
    private function liveDefinitions(): array
    {
        $documents = $this->manifest()['definition_documents'] ?? null;
        self::assertIsArray($documents);
        $definitions = [];
        foreach ($documents as $document) {
            self::assertIsArray($document);
            $definitions[] = EntityTypeDefinition::fromArray($document)->published(1);
        }

        return $definitions;
    }

    /**
     * Read the released fixture key of every VDM definition, keyed by its live handle.
     *
     * @return  array<string, string>  Fixture key by definition handle.
     *
     * @since   2.0.0
     */
    private function fixtures(): array
    {
        $order = $this->manifest()['installation_order'] ?? null;
        self::assertIsArray($order);
        $fixtures = [];
        foreach ($order as $entry) {
            self::assertIsArray($entry);
            self::assertIsString($entry['handle']);
            self::assertIsString($entry['fixture_key']);
            $fixtures[$entry['handle']] = $entry['fixture_key'];
        }

        return $fixtures;
    }

    /**
     * Read the released VDM records document.
     *
     * @return  array<string, mixed>  Decoded `kumwe.demo-business-records/v1` document.
     *
     * @since   2.0.0
     */
    private function recordsDocument(): array
    {
        $records = $this->manifest()['records_document'] ?? null;
        self::assertIsArray($records);

        /** @var array<string, mixed> $records */
        return $records;
    }

    /**
     * Load the released VDM aggregate manifest from the repository.
     *
     * @return  array<string, mixed>  Validated aggregate manifest.
     *
     * @since   2.0.0
     */
    private function manifest(): array
    {
        return (new FilesystemDemoManifestCatalog(dirname(__DIR__, 4)))->vdmBusiness()['manifest'];
    }

    /**
     * Build a published definition whose only reference is an entity-reference field to one target.
     *
     * The shipped invoice-line document is the template: its `product` field is the reference, so the
     * definition declares no relationship at all and reaches its target through the field alone.
     *
     * @param   string  $handle  Handle of the definition to build.
     * @param   string  $target  Handle the `product` field references.
     * @param   string  $tail    Twelve hexadecimal characters that make the definition identity unique.
     *
     * @return  EntityTypeDefinition  Published version one of the built definition.
     *
     * @since   2.0.0
     */
    private function referencing(string $handle, string $target, string $tail): EntityTypeDefinition
    {
        $documents = $this->manifest()['definition_documents'] ?? null;
        self::assertIsArray($documents);
        $document = $documents['definition.invoice_line'] ?? null;
        self::assertIsArray($document);
        $document['id'] = '019c7000-0000-7000-8000-' . $tail;
        $document['handle'] = $handle;
        self::assertIsArray($document['fields']);
        foreach ($document['fields'] as $offset => $field) {
            self::assertIsArray($field);
            if (($field['handle'] ?? null) === 'product') {
                $field['configuration'] = ['target' => $target];
                $document['fields'][$offset] = $field;
            }
        }

        return EntityTypeDefinition::fromArray($document)->published(1);
    }
}
