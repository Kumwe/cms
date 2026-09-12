<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessSurface\Delivery\Browser;

use InvalidArgumentException;
use Kumwe\Localization\Application\Translator;

/**
 * Converts contract-validated custom view data into a bounded markup-free browser projection.
 *
 * The generic templates render only this closed object/list/scalar tree. They never serialize arbitrary
 * data as JSON, select a contributor template, or accept prebuilt markup. Custom result contracts already
 * cap depth, nodes, strings and collection width; this presenter independently verifies those assumptions
 * and supplies readable labels and exact scalar display text for core-owned Twig macros.
 *
 * @since  2.0.0
 */
final readonly class BusinessCustomViewPresenter
{
    /**
     * Bind the presenter to the translator its scalar display wording resolves through.
     *
     * @param  Translator  $translator  Resolves the null and boolean display text for the locale in flight.
     *
     * @since  2.0.0
     */
    public function __construct(private Translator $translator)
    {
    }

    /**
     * Present one bounded custom result object.
     *
     * @param   array<string, mixed>  $data  Contract-validated custom view result.
     *
     * @return  array<string, mixed>  Closed recursive semantic projection.
     *
     * @throws  InvalidArgumentException  When the result contradicts the custom payload boundary.
     *
     * @since   2.0.0
     */
    public function present(array $data): array
    {
        if ($data !== [] && array_is_list($data)) {
            throw new InvalidArgumentException('A custom business browser result must be an object.');
        }
        $nodes = 0;
        return $this->node($data, 0, $nodes);
    }

    /**
     * Present one exact JSON value recursively.
     *
     * @param   mixed  $value  Current result value.
     * @param   int    $depth  Current nesting level.
     * @param   int    $nodes  Shared node count.
     *
     * @return  array<string, mixed>  Scalar, object or list presentation node.
     *
     * @throws  InvalidArgumentException  When a value is unsafe or exceeds the presentation budget.
     *
     * @since   2.0.0
     */
    private function node(mixed $value, int $depth, int &$nodes): array
    {
        ++$nodes;
        if ($depth > 8 || $nodes > 4096) {
            throw new InvalidArgumentException('A custom business browser result exceeds its presentation budget.');
        }
        if ($value === null || is_bool($value) || is_int($value) || is_string($value)) {
            if (is_string($value) && strlen($value) > 65_535) {
                throw new InvalidArgumentException('A custom business browser result contains oversized text.');
            }
            return [
                'kind' => 'scalar',
                'value' => match ($value) {
                    null => $this->translator->translate('core.business.form.not_set'),
                    true => $this->translator->translate('core.business.form.yes'),
                    false => $this->translator->translate('core.business.form.no'),
                    default => (string) $value,
                },
                'empty' => $value === null || $value === '',
            ];
        }
        if (!is_array($value)) {
            throw new InvalidArgumentException('A custom business browser result contains an unsupported value.');
        }
        if (array_is_list($value)) {
            if (count($value) > 200) {
                throw new InvalidArgumentException('A custom business browser result contains an oversized list.');
            }
            $items = [];
            foreach ($value as $index => $item) {
                $items[] = ['number' => $index + 1, 'value' => $this->node($item, $depth + 1, $nodes)];
            }
            return ['kind' => 'list', 'items' => $items];
        }
        if (count($value) > 128) {
            throw new InvalidArgumentException('A custom business browser result contains an oversized object.');
        }
        $entries = [];
        foreach ($value as $key => $item) {
            if (!is_string($key) || preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $key) !== 1) {
                throw new InvalidArgumentException('A custom business browser result contains an unsafe property.');
            }
            $entries[] = [
                'handle' => $key,
                'label' => ucfirst(str_replace('_', ' ', $key)),
                'value' => $this->node($item, $depth + 1, $nodes),
            ];
        }
        return ['kind' => 'object', 'entries' => $entries];
    }
}
