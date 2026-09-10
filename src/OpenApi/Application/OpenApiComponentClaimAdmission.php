<?php

declare(strict_types=1);

namespace Kumwe\App\OpenApi\Application;

use InvalidArgumentException;
use Kumwe\App\BusinessDefinition\Application\BusinessDefinitionContractAdmission;
use Kumwe\App\BusinessDefinition\Domain\EntityTypeDefinition;
use Kumwe\App\BusinessDefinition\Domain\InvalidBusinessDefinition;
use Kumwe\Context\Value\SiteContext;

/**
 * Claims every deterministic per-definition OpenAPI component before definitions become active.
 *
 * @since  2.0.0
 */
final readonly class OpenApiComponentClaimAdmission implements BusinessDefinitionContractAdmission
{
    /**
     * Core schema names after compiler-owned generated output has been removed.
     *
     * @var    array<string, true>
     * @since  2.0.0
     */
    private array $coreComponents;

    /**
     * Reserve every non-generated component in the checked-in OpenAPI contract.
     *
     * @param   array<string, mixed>  $coreContract  Checked-in OpenAPI 3.1 document or generated golden.
     *
     * @throws  InvalidArgumentException  When the core schema registry or generated marker is malformed.
     *
     * @since   2.0.0
     */
    public function __construct(array $coreContract)
    {
        if (($coreContract['openapi'] ?? null) !== '3.1.0') {
            throw new InvalidArgumentException('The contract admission document must use OpenAPI 3.1.0.');
        }
        $components = $coreContract['components'] ?? null;
        if (!is_array($components) || ($components !== [] && array_is_list($components))) {
            throw new InvalidArgumentException('The contract admission component registry is invalid.');
        }
        $schemas = $components['schemas'] ?? null;
        $generated = $coreContract['x-kumwe-generated-components'] ?? [];
        if (
            !is_array($schemas) || ($schemas !== [] && array_is_list($schemas))
            || !is_array($generated) || !array_is_list($generated) || count($generated) > 1024
        ) {
            throw new InvalidArgumentException('The contract admission component registry is invalid.');
        }
        foreach ($generated as $component) {
            if (!is_string($component) || !$this->safe($component)) {
                throw new InvalidArgumentException('The contract admission generated marker is invalid.');
            }
            unset($schemas[$component]);
        }
        $components = [];
        foreach (array_keys($schemas) as $component) {
            if (!is_string($component) || !$this->safe($component)) {
                throw new InvalidArgumentException('The core OpenAPI component registry contains an unsafe name.');
            }
            $components[$component] = true;
        }
        $this->coreComponents = $components;
    }

    /**
     * Claim every generated component family in one complete site definition set.
     *
     * @param   SiteContext                 $site         Site whose namespace is being admitted.
     * @param   list<EntityTypeDefinition>  $definitions  Active or post-publication definitions.
     *
     * @return  void
     *
     * @throws  InvalidBusinessDefinition  When the set is malformed, unbounded, cross-site, unsafe, or colliding.
     *
     * @since   2.0.0
     */
    public function admit(SiteContext $site, array $definitions): void
    {
        if (!array_is_list($definitions) || count($definitions) > OpenApiContractLimits::MAX_DEFINITIONS) {
            throw new InvalidBusinessDefinition('The OpenAPI definition contract set is invalid or unbounded.');
        }
        $claims = $this->coreComponents;
        foreach ($definitions as $definition) {
            if (!$definition instanceof EntityTypeDefinition || $definition->siteIdentifier !== $site->identifier()) {
                throw new InvalidBusinessDefinition('The OpenAPI definition contract set crosses a site boundary.');
            }
            $this->claimDefinition($claims, $definition);
        }
    }

    /**
     * Claim one entity and every custom view/action contract component derived from it.
     *
     * @param   array<string, true>   $claims      Names already owned by core or another definition.
     * @param   EntityTypeDefinition  $definition  Definition whose deterministic family is being reserved.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function claimDefinition(array &$claims, EntityTypeDefinition $definition): void
    {
        $prefix = 'Business_' . str_replace(['.', '-'], '_', $definition->handle) . '_';
        foreach (['Record', 'Create', 'Update'] as $suffix) {
            $this->claim($claims, $prefix . $suffix);
        }
        foreach ($definition->views() as $view) {
            if ($view->handler !== null) {
                $this->claim($claims, $prefix . 'View_' . $view->handle . '_Query');
                $this->claim($claims, $prefix . 'View_' . $view->handle . '_Result');
            }
        }
        foreach ($definition->actions() as $action) {
            if ($action->handler !== null) {
                $this->claim($claims, $prefix . 'Action_' . $action->handle . '_Command');
                $this->claim($claims, $prefix . 'Action_' . $action->handle . '_Result');
            }
        }
    }

    /**
     * Reserve one safe generated component exactly once.
     *
     * @param   array<string, true>  $claims  Mutable component registry.
     * @param   string               $name    Candidate component name.
     *
     * @return  void
     *
     * @throws  InvalidBusinessDefinition  When the name is unsafe or already claimed.
     *
     * @since   2.0.0
     */
    private function claim(array &$claims, string $name): void
    {
        if (!$this->safe($name) || isset($claims[$name])) {
            throw new InvalidBusinessDefinition('A generated OpenAPI component collides or is unsafe.');
        }
        $claims[$name] = true;
    }

    /**
     * Check the closed OpenAPI component-name grammar shared by core markers and generated claims.
     *
     * @param   string  $name  Candidate schema component name.
     *
     * @return  bool  True for a portable bounded component name.
     *
     * @since   2.0.0
     */
    private function safe(string $name): bool
    {
        return preg_match('/^[A-Za-z][A-Za-z0-9_]{0,190}$/D', $name) === 1;
    }
}
