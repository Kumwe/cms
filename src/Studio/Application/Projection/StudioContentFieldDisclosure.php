<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Application\Projection;

use Kumwe\App\Content\Application\ContentRecord;
use Kumwe\App\Content\Domain\ContentTypeDefinition;
use Kumwe\Context\Value\ExecutionContext;

/**
 * Application policy seam deciding whether one Content value may enter a Studio projection.
 *
 * Model and entry questions are separate so a future field policy can disclose a binding's shape
 * while withholding its current value. A refusal is omission, not a diagnostic naming the field;
 * this prevents the model port from becoming an existence oracle for protected data.
 *
 * @since  2.0.0
 */
interface StudioContentFieldDisclosure
{
    /**
     * Decide whether the projected model may describe one source field.
     *
     * `@title` and `@slug` name the two ContentEntry properties outside the definition's data schema;
     * every other value is a top-level Content field key.
     *
     * @param   ExecutionContext       $context      Authorized actor and site.
     * @param   ContentTypeDefinition  $definition   Content type whose shape is being projected.
     * @param   string                 $sourceField  Source field key or one of the two `@` names.
     *
     * @return  bool  True only when revealing that a field exists is permitted.
     *
     * @since   2.0.0
     */
    public function mayDescribe(
        ExecutionContext $context,
        ContentTypeDefinition $definition,
        string $sourceField,
    ): bool;

    /**
     * Decide whether the projected entry may disclose one current source value.
     *
     * @param   ExecutionContext  $context      Authorized actor and site.
     * @param   ContentRecord     $record       Entry whose row-level read has already been authorized.
     * @param   string            $sourceField  Source field key or one of the two `@` names.
     *
     * @return  bool  True only when the value may leave the application service.
     *
     * @since   2.0.0
     */
    public function mayDisclose(
        ExecutionContext $context,
        ContentRecord $record,
        string $sourceField,
    ): bool;
}
