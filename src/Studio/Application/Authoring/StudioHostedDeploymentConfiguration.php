<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Application\Authoring;

use InvalidArgumentException;

/**
 * The canonical, already-proven browser deployment of one contextual Content Studio mount.
 *
 * Producer has validated the `studio-deployment` document against the pinned schema, bound it to the
 * exact release and rendered the inert discovery pair; App keeps the result opaque to its templates
 * beyond the few delivery facts a page needs: the inert JSON to place in the configuration block, where
 * the pinned module loads from and with which integrity, which origin the response policy must admit,
 * and where the editor returns to. Nothing here is re-serialized or re-interpreted by the template
 * layer; the page renders the target element itself so it can carry its own classes and data.
 *
 * @since  2.0.0
 */
final readonly class StudioHostedDeploymentConfiguration implements StudioContextualAuthoringConfiguration
{
    /**
     * Hold one emitted mount.
     *
     * @param   string   $mountId            `id` of the target element the page renders.
     * @param   string   $configurationId    `id` of the inert configuration block the page renders.
     * @param   string   $configurationJson  Producer-proven, HTML-inert canonical JSON of the deployment document,
     *          placed verbatim inside the `application/json` block the page renders.
     * @param   string   $moduleUrl          Absolute or site-absolute URL of the pinned browser module.
     * @param   string   $moduleIntegrity    Subresource-integrity value of that module.
     * @param   ?string  $scriptOrigin       Exact origin the response's `script-src` must additionally admit, or
     *          null when the module is same-origin.
     * @param   string   $returnPath         Site-absolute administrator path the editor returns to.
     *
     * @throws  InvalidArgumentException  When an identifier, the document or the return path is not usable.
     *
     * @since   2.0.0
     */
    public function __construct(
        public string $mountId,
        public string $configurationId,
        public string $configurationJson,
        public string $moduleUrl,
        public string $moduleIntegrity,
        public ?string $scriptOrigin,
        public string $returnPath,
    ) {
        if (
            preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,199}$/D', $mountId) !== 1
            || preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,199}$/D', $configurationId) !== 1
            || $mountId === $configurationId
            || !str_starts_with($configurationJson, '{')
            || str_contains($configurationJson, '<')
            || !str_starts_with($returnPath, '/administrator/')
        ) {
            throw new InvalidArgumentException('A hosted Studio deployment must carry usable identifiers and JSON.');
        }
    }
}
