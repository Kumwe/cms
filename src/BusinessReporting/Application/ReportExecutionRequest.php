<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessReporting\Application;

use InvalidArgumentException;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\Extension\Spi\BusinessRecord\Application\BusinessRecordQueryPurpose;

/**
 * Authenticated input for an interactive report or queued export execution.
 *
 * @since  2.0.0
 */
final readonly class ReportExecutionRequest
{
    /**
     * Caller-supplied report parameters pending definition validation.
     *
     * @var    array<string, mixed>
     * @since  2.0.0
     */
    public array $parameters;

    /**
     * Capture execution context, scope and bounded parameter object.
     *
     * @param   ExecutionContext            $context                 Authenticated actor.
     * @param   string                      $reportIdentifier        Namespaced report handle.
     * @param   array<string, mixed>        $parameters              Values keyed by declared parameter handle.
     * @param   ?string                     $organizationIdentifier  Organization record scope.
     * @param   BusinessRecordQueryPurpose  $purpose                 Report or export usage only.
     *
     * @throws  InvalidArgumentException  When purpose or parameter object is invalid.
     *
     * @since   2.0.0
     */
    public function __construct(
        public ExecutionContext $context,
        public string $reportIdentifier,
        array $parameters = [],
        public ?string $organizationIdentifier = null,
        public BusinessRecordQueryPurpose $purpose = BusinessRecordQueryPurpose::Report,
    ) {
        if ($purpose === BusinessRecordQueryPurpose::Browse || count($parameters) > 32) {
            throw new InvalidArgumentException('A report execution purpose or parameter count is invalid.');
        }
        foreach (array_keys($parameters) as $name) {
            if (!is_string($name)) {
                throw new InvalidArgumentException('Report execution parameters must form an object.');
            }
        }
        $this->parameters = $parameters;
    }
}
