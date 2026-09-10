<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Application\Projection;

use Kumwe\App\Content\Application\ContentRecord;
use Kumwe\App\Content\Domain\ContentTypeDefinition;
use Kumwe\Context\Value\ExecutionContext;

/**
 * Applies Content's current record-level disclosure contract to Studio fields.
 *
 * Kumwe Content presently has no narrower per-field read policy: `ContentModelService` authorizes the
 * model and `ContentService` authorizes the entry as a whole. This implementation therefore admits
 * every field only after those services have returned. Keeping that fact behind an explicit seam
 * means a future field policy replaces this class instead of being bypassed by the model port.
 *
 * @since  2.0.0
 */
final readonly class RecordAuthorizedStudioContentFieldDisclosure implements StudioContentFieldDisclosure
{
    /**
     * Admit a field after the Content model service authorized the definition.
     *
     * @param   ExecutionContext       $context      Authorized actor and site.
     * @param   ContentTypeDefinition  $definition   Authorized definition.
     * @param   string                 $sourceField  Source field key.
     *
     * @return  bool  Always true because Content currently authorizes model reads as a whole.
     *
     * @since   2.0.0
     */
    public function mayDescribe(
        ExecutionContext $context,
        ContentTypeDefinition $definition,
        string $sourceField,
    ): bool {
        return true;
    }

    /**
     * Admit a value after the Content service authorized the complete entry.
     *
     * @param   ExecutionContext  $context      Authorized actor and site.
     * @param   ContentRecord     $record       Authorized entry.
     * @param   string            $sourceField  Source field key.
     *
     * @return  bool  Always true because Content currently authorizes entry reads as a whole.
     *
     * @since   2.0.0
     */
    public function mayDisclose(
        ExecutionContext $context,
        ContentRecord $record,
        string $sourceField,
    ): bool {
        return true;
    }
}
