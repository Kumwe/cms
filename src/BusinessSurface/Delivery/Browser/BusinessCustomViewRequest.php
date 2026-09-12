<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessSurface\Delivery\Browser;

use InvalidArgumentException;
use Kumwe\Localization\Application\Translator;

/**
 * Maps one native custom-view GET request through policy-filtered metadata and its signed query schema.
 *
 * Record controls use the shared bounded query grammar but are restricted to fields declared by the view.
 * Contract parameters use `BusinessSchemaForm`, including nested objects and bounded arrays whose row count
 * can be changed with a native configure round trip. Opaque query JSON and arbitrary parameter keys never
 * enter the application facade.
 *
 * @since  2.0.0
 */
final readonly class BusinessCustomViewRequest
{
    /**
     * Capture one validated custom-view browser request.
     *
     * @param  BusinessBrowserQuery        $records     Bounded standard record query.
     * @param  array<string, mixed>        $parameters  Typed signed-contract parameters.
     * @param  list<array<string, mixed>>  $fields      Recursive safe semantic controls for Twig.
     * @param  bool                        $submitted   Whether execution was requested.
     * @param  bool                        $configured  Whether an array-row reconfiguration was requested.
     *
     * @since  2.0.0
     */
    private function __construct(
        public BusinessBrowserQuery $records,
        public array $parameters,
        public array $fields,
        public bool $submitted,
        public bool $configured,
    ) {
    }

    /**
     * Decode one custom-view query against policy-visible metadata and its active signed schema.
     *
     * @param   Translator                  $translator  Resolves the parameter form's display wording.
     * @param   array<string, mixed>        $query       Decoded browser query string.
     * @param   array<string, mixed>        $view        Policy-filtered custom view metadata.
     * @param   list<array<string, mixed>>  $fields      Policy-filtered definition fields.
     * @param   array<string, mixed>        $schema      Closed custom-view query schema.
     *
     * @return  self  Typed parameters, recursive controls, and bounded record query.
     *
     * @throws  InvalidArgumentException  When a control is malformed, undeclared, or outside its schema.
     *
     * @since   2.0.0
     */
    public static function fromQuery(
        Translator $translator,
        array $query,
        array $view,
        array $fields,
        array $schema,
    ): self {
        if (array_key_exists('query', $query)) {
            throw new InvalidArgumentException('A custom business browser view does not accept raw query JSON.');
        }
        self::assertRecordFields($query, $view, $fields);
        $submitted = self::flag($query, 'run');
        $configured = self::flag($query, 'configure');
        if ($submitted && $configured) {
            throw new InvalidArgumentException('A custom business view cannot run and configure rows together.');
        }
        $raw = self::object($query, 'parameters');
        if (!$submitted && !$configured && $raw !== []) {
            throw new InvalidArgumentException('Custom view parameters require run or configure.');
        }
        $form = BusinessSchemaForm::fromInput(
            $schema,
            $translator,
            'parameters',
            $raw,
            self::object($query, 'schema_counts'),
            self::object($query, 'schema_presence'),
            $submitted,
        );

        return new self(
            BusinessBrowserQuery::fromQuery($query),
            $form->value,
            $form->fields,
            $submitted,
            $configured,
        );
    }

    /**
     * Restrict graphical record controls to the policy-visible declaration.
     *
     * @param   array<string, mixed>        $query   Browser query string.
     * @param   array<string, mixed>        $view    View metadata carrying filters and sorts.
     * @param   list<array<string, mixed>>  $fields  Definition field metadata carrying search use.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When any graphical field falls outside the declared view.
     *
     * @since   2.0.0
     */
    private static function assertRecordFields(array $query, array $view, array $fields): void
    {
        $filters = self::stringList($view['filters'] ?? null);
        foreach (['filters', 'integer_filters', 'boolean_filters'] as $key) {
            $map = $query[$key] ?? [];
            if (!is_array($map) || ($map !== [] && array_is_list($map))) {
                throw new InvalidArgumentException('Custom business browser filters must be field maps.');
            }
            foreach (array_keys($map) as $field) {
                if (!is_string($field) || !in_array($field, $filters, true)) {
                    throw new InvalidArgumentException('A custom business browser filter is unavailable.');
                }
            }
        }
        $sort = $query['sort_field'] ?? null;
        if (
            $sort !== null && $sort !== '' && (!is_string($sort) || !in_array(
                $sort,
                self::stringList($view['sorts'] ?? null),
                true,
            ))
        ) {
            throw new InvalidArgumentException('A custom business browser sort is unavailable.');
        }
        $searchable = [];
        $projection = self::stringList($view['fields'] ?? null);
        foreach ($fields as $field) {
            $uses = $field['uses'] ?? null;
            if (
                is_string($field['handle'] ?? null)
                && in_array($field['handle'], $projection, true)
                && is_array($uses)
                && ($uses['search'] ?? false) === true
            ) {
                $searchable[] = $field['handle'];
            }
        }
        foreach (self::stringList($query['search_fields'] ?? []) as $field) {
            if (!in_array($field, $searchable, true)) {
                throw new InvalidArgumentException('A custom business browser search field is unavailable.');
            }
        }
    }

    /**
     * Read one optional nested object control.
     *
     * @param   array<string, mixed>  $query  Browser query string.
     * @param   string                $key    Object member name.
     *
     * @return  array<string, mixed>  Decoded object or an empty object when absent.
     *
     * @since   2.0.0
     */
    private static function object(array $query, string $key): array
    {
        $value = $query[$key] ?? [];
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new InvalidArgumentException('A custom business browser object control is malformed.');
        }
        $object = [];
        foreach ($value as $member => $item) {
            if (!is_string($member)) {
                throw new InvalidArgumentException('A custom business browser object control is malformed.');
            }
            $object[$member] = $item;
        }

        return $object;
    }

    /**
     * Read one native submit flag.
     *
     * @param   array<string, mixed>  $query  Browser query string.
     * @param   string                $key    Flag member name.
     *
     * @return  bool  True only for the exact value `1`.
     *
     * @since   2.0.0
     */
    private static function flag(array $query, string $key): bool
    {
        $value = $query[$key] ?? null;
        if ($value !== null && $value !== '1') {
            throw new InvalidArgumentException('A custom business browser submit control is invalid.');
        }
        return $value === '1';
    }

    /**
     * Validate one list whose members must be safe unique field handles.
     *
     * @param   mixed  $value  Candidate metadata or query list.
     *
     * @return  list<string>  Validated list.
     *
     * @throws  InvalidArgumentException  When the value is not a bounded unique handle list.
     *
     * @since   2.0.0
     */
    private static function stringList(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > 128) {
            throw new InvalidArgumentException('A custom business browser field list is invalid or unbounded.');
        }
        $items = [];
        foreach ($value as $item) {
            if (!is_string($item) || preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $item) !== 1) {
                throw new InvalidArgumentException('A custom business browser field list is invalid.');
            }
            $items[] = $item;
        }
        if (count($items) !== count(array_unique($items))) {
            throw new InvalidArgumentException('A custom business browser field list contains duplicates.');
        }
        return $items;
    }
}
