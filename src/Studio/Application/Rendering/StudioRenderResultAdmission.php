<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Application\Rendering;

use Kumwe\Producer\Render\RenderException;
use Kumwe\Producer\Render\RenderResult;
use Kumwe\Producer\Schema\StudioContractResources;
use stdClass;

/**
 * Admits only render results whose enhancements the pinned Studio enhancement runtime publishes.
 *
 * A published page defers the exact runtime Producer pins, so every enhancement family a render
 * result declares must be one that runtime implements; a family outside the manifest would be markup
 * the deployment could never upgrade and is refused instead of served half-working.
 *
 * @since  2.0.0
 */
final class StudioRenderResultAdmission
{
    /**
     * Enhancement families the pinned runtime publishes, decoded once per process.
     *
     * @var    list<string>|null
     * @since  2.0.0
     */
    private static ?array $families = null;

    /**
     * Refuse a result declaring an enhancement the pinned runtime does not implement.
     *
     * @param   RenderResult  $result  Producer render result.
     *
     * @return  void
     *
     * @throws  RenderException  When an enhancement family is outside the pinned manifest.
     *
     * @since   2.0.0
     */
    public static function assertSupported(RenderResult $result): void
    {
        $families = self::families();
        foreach ($result->enhancementNames() as $name) {
            if (!in_array($name, $families, true)) {
                throw new RenderException('The pinned Studio enhancement runtime does not implement ' . $name . '.');
            }
        }
    }

    /**
     * The closed enhancement family list of the pinned browser-asset manifest.
     *
     * @return  list<string>  Published family names.
     *
     * @throws  RenderException  When the pinned manifest publishes no enhancement runtime.
     *
     * @since   2.0.0
     */
    private static function families(): array
    {
        if (self::$families !== null) {
            return self::$families;
        }
        $manifest = json_decode(StudioContractResources::browserManifestBytes(), false, 16);
        $runtime = $manifest instanceof stdClass ? ($manifest->enhancementRuntime ?? null) : null;
        $declared = $runtime instanceof stdClass ? ($runtime->enhancements ?? null) : null;
        if (!is_array($declared) || $declared === []) {
            throw new RenderException('The pinned Studio manifest publishes no enhancement runtime.');
        }
        $families = [];
        foreach ($declared as $family) {
            if (is_string($family) && $family !== '') {
                $families[] = $family;
            }
        }

        return self::$families = $families;
    }

    /**
     * Not constructable: the admission is a function of the pinned manifest.
     *
     * @since  2.0.0
     */
    private function __construct()
    {
    }
}
