<?php

declare(strict_types=1);

namespace Kumwe\App\OpenApi\Application;

use InvalidArgumentException;
use Kumwe\App\BusinessSurface\Application\BusinessSurface;
use Kumwe\App\BusinessSurface\Application\BusinessSurfaceCatalog;
use Kumwe\App\BusinessSurface\Application\BusinessSurfaceOperation;
use Kumwe\Context\Value\ExecutionContext;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Builds and caches the access-aware OpenAPI contract for one authenticated API context.
 *
 * @since  2.0.0
 */
final readonly class OpenApiContractService implements OpenApiContractProvider
{
    /**
     * Configure the compiler with the immutable core contract and shared business catalog.
     *
     * @param  array<string, mixed>     $core      Checked-in core OpenAPI contract decoded at composition.
     * @param  BusinessSurfaceCatalog   $catalog   Shared policy-filtered metadata source.
     * @param  OpenApiContractCompiler  $compiler  Deterministic assembler and validator.
     * @param  OpenApiContractCache     $cache     Disposable verified generation cache.
     * @param  LoggerInterface          $logger    Records disposable cache repair failures without contract data.
     *
     * @since  2.0.0
     */
    public function __construct(
        private array $core,
        private BusinessSurfaceCatalog $catalog,
        private OpenApiContractCompiler $compiler,
        private OpenApiContractCache $cache,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Return the verified contract for one actor, compiling it only on a cache miss.
     *
     * @param   ExecutionContext  $context  Authenticated API actor and exact site/membership.
     *
     * @return  CompiledOpenApiContract  Canonical contract bound to current trusted metadata.
     *
     * @throws  OpenApiContractUnavailable  When current metadata cannot be safely assembled or verified.
     *
     * @since   2.0.0
     */
    public function contract(ExecutionContext $context): CompiledOpenApiContract
    {
        try {
            return $this->current($context);
        } catch (OpenApiContractUnavailable $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new OpenApiContractUnavailable($exception);
        }
    }

    /**
     * Assemble or load only the exact current caller-specific generation.
     *
     * Component collisions are admitted before site publication and extension activation. Compiler
     * validation remains here as a defense against restored legacy data or an internal invariant failure;
     * it never falls back to an older generation. A corrupt or unavailable disposable cache is treated as
     * a miss, while failure to republish a verified current compile is logged and does not change its safety.
     *
     * @param   ExecutionContext  $context  Authenticated API actor and exact site/membership.
     *
     * @return  CompiledOpenApiContract  Verified exact-generation contract.
     *
     * @since   2.0.0
     */
    private function current(ExecutionContext $context): CompiledOpenApiContract
    {
        /** @var array<string, array<string, mixed>> $merged */
        $merged = [];
        foreach (
            [
            BusinessSurfaceOperation::Browse,
            BusinessSurfaceOperation::Read,
            BusinessSurfaceOperation::Create,
            BusinessSurfaceOperation::Update,
            BusinessSurfaceOperation::Action,
            BusinessSurfaceOperation::History,
            BusinessSurfaceOperation::Relation,
            BusinessSurfaceOperation::Report,
            BusinessSurfaceOperation::Export,
            ] as $operation
        ) {
            foreach ($this->catalog->definitions($context, BusinessSurface::Api, $operation) as $definition) {
                $handle = $this->metadataHandle($definition, 'definition');
                $merged[$handle] = isset($merged[$handle])
                    ? $this->merge($merged[$handle], $definition)
                    : $definition;
            }
        }
        ksort($merged, SORT_STRING);
        $definitions = array_values($merged);
        $generation = hash('sha256', implode(':', [
            $this->catalog->generation($definitions),
            $context->authorizationFingerprint(),
            hash('sha256', json_encode($definitions, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)),
        ]));
        try {
            $cached = $this->cache->get($generation);
        } catch (Throwable $exception) {
            $this->cacheFailure('read', $generation, $exception);
            $cached = null;
        }
        if ($cached !== null) {
            return $cached;
        }
        $compiled = $this->compiler->compile($this->core, $definitions, $generation);
        try {
            $this->cache->put($compiled);
        } catch (Throwable $exception) {
            $this->cacheFailure('write', $generation, $exception);
        }

        return $compiled;
    }

    /**
     * Record a cache repair failure without serializing contract bytes or exception messages.
     *
     * @param   string     $operation   Cache read or write operation.
     * @param   string     $generation  Exact non-secret generation digest.
     * @param   Throwable  $exception   Internal failure, represented only by class.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function cacheFailure(string $operation, string $generation, Throwable $exception): void
    {
        $this->logger->warning('The disposable OpenAPI contract cache could not be repaired.', [
            'operation' => $operation,
            'generation' => $generation,
            'exception' => $exception::class,
        ]);
    }

    /**
     * Merge metadata produced under two operation-specific policy plans.
     *
     * @param   array<string, mixed>  $left   Existing entity metadata.
     * @param   array<string, mixed>  $right  Same entity under another operation.
     *
     * @return  array<string, mixed>  Union with field-use flags ORed and named sets deduplicated.
     *
     * @since   2.0.0
     */
    private function merge(array $left, array $right): array
    {
        if (
            $this->metadataHandle($left, 'definition')
            !== $this->metadataHandle($right, 'definition')
        ) {
            throw new InvalidArgumentException('OpenAPI metadata from different definitions cannot be merged.');
        }
        /** @var array<string, array<string, mixed>> $fields */
        $fields = [];
        $fieldItems = [
            ...$this->metadataItems($left, 'fields', 256),
            ...$this->metadataItems($right, 'fields', 256),
        ];
        foreach ($fieldItems as $field) {
            $handle = $this->metadataHandle($field, 'field');
            $uses = $this->booleanFlags($field, 'uses');
            if (isset($fields[$handle])) {
                $mergedUses = $this->booleanFlags($fields[$handle], 'uses');
                foreach ($uses as $use => $allowed) {
                    $mergedUses[$use] = ($mergedUses[$use] ?? false) || $allowed;
                }
                $fields[$handle]['uses'] = $mergedUses;
                continue;
            }
            $field['uses'] = $uses;
            $fields[$handle] = $field;
        }
        ksort($fields, SORT_STRING);
        $left['fields'] = array_values($fields);
        foreach (['views', 'actions', 'relationships'] as $collection) {
            /** @var array<string, array<string, mixed>> $items */
            $items = [];
            $collectionItems = [
                ...$this->metadataItems($left, $collection, 128),
                ...$this->metadataItems($right, $collection, 128),
            ];
            foreach ($collectionItems as $item) {
                $items[$this->metadataHandle($item, $collection)] = $item;
            }
            ksort($items, SORT_STRING);
            $left[$collection] = array_values($items);
        }
        $left['operation'] = 'multi';

        return $left;
    }

    /**
     * Read a required bounded handle from one catalog metadata object.
     *
     * @param   array<string, mixed>  $item     Definition, field, view, action, or relationship metadata.
     * @param   string                $context  Stable object label for validation failures.
     *
     * @return  string  Valid non-empty metadata handle.
     *
     * @throws  InvalidArgumentException  When the handle is absent or unsafe for keyed merging.
     *
     * @since   2.0.0
     */
    private function metadataHandle(array $item, string $context): string
    {
        $handle = $item['handle'] ?? null;
        if (!is_string($handle) || $handle === '' || strlen($handle) > 191) {
            throw new InvalidArgumentException('OpenAPI ' . $context . ' metadata has an invalid handle.');
        }

        return $handle;
    }

    /**
     * Read a bounded list of string-keyed catalog metadata objects.
     *
     * @param   array<string, mixed>  $document  Definition metadata carrying the list.
     * @param   string                $member    Required collection member.
     * @param   int                   $maximum   Maximum accepted item count.
     *
     * @return  list<array<string, mixed>>  Validated metadata objects in catalog order.
     *
     * @throws  InvalidArgumentException  When the collection is absent, malformed, or unbounded.
     *
     * @since   2.0.0
     */
    private function metadataItems(array $document, string $member, int $maximum): array
    {
        $items = $document[$member] ?? null;
        if (!is_array($items) || !array_is_list($items) || count($items) > $maximum) {
            throw new InvalidArgumentException('OpenAPI definition metadata has an invalid ' . $member . ' list.');
        }
        $validated = [];
        foreach ($items as $item) {
            $validated[] = $this->objectArray(
                $item,
                'OpenAPI definition metadata contains an invalid ' . $member . ' item.',
            );
        }

        return $validated;
    }

    /**
     * Read a bounded boolean flag map from one field metadata object.
     *
     * @param   array<string, mixed>  $field   Field metadata carrying the flag map.
     * @param   string                $member  Required flag-map member.
     *
     * @return  array<string, bool>  Validated use flags.
     *
     * @throws  InvalidArgumentException  When a flag name or value is malformed or unbounded.
     *
     * @since   2.0.0
     */
    private function booleanFlags(array $field, string $member): array
    {
        $flags = $this->objectArray(
            $field[$member] ?? null,
            'OpenAPI field metadata has an invalid use map.',
        );
        if (count($flags) > 32) {
            throw new InvalidArgumentException('OpenAPI field metadata has an invalid use map.');
        }
        $validated = [];
        foreach ($flags as $use => $allowed) {
            if ($use === '' || strlen($use) > 63 || !is_bool($allowed)) {
                throw new InvalidArgumentException('OpenAPI field metadata has an invalid use map.');
            }
            $validated[$use] = $allowed;
        }

        return $validated;
    }

    /**
     * Narrow a catalog value to the JSON-object representation required for deterministic keyed merging.
     *
     * @param   mixed   $value    Candidate catalog value.
     * @param   string  $message  Stable validation failure detail.
     *
     * @return  array<string, mixed>  Validated string-keyed object.
     *
     * @throws  InvalidArgumentException  When the value is not a string-keyed object.
     *
     * @since   2.0.0
     */
    private function objectArray(mixed $value, string $message): array
    {
        if (!is_array($value)) {
            throw new InvalidArgumentException($message);
        }
        foreach (array_keys($value) as $key) {
            if (!is_string($key)) {
                throw new InvalidArgumentException($message);
            }
        }
        /** @var array<string, mixed> $value */

        return $value;
    }
}
