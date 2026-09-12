<?php

declare(strict_types=1);

namespace Kumwe\App\Kernel;

use Closure;
use Laminas\ServiceManager\ServiceManager;
use Psr\Container\ContainerInterface;

/**
 * Application container: the composition root's registration vocabulary over a Laminas ServiceManager.
 *
 * `ContainerFactory` registers every service through `share()` and `alias()`, so this class keeps those
 * two verbs while resolution runs on the ServiceManager it composes — the ServiceManager itself is
 * `@final` in Laminas 4, which is why this is a wrapper rather than a subclass. The wrapper passes
 * itself as the creation context, so every factory closure receives this container. `share()` sends a
 * closure to a lazily invoked factory and any other value to an already-built service instance; both
 * resolve as singletons because the ServiceManager shares by default. Overwrite protection is the
 * container-wide stance rather than a per-key flag: `allowOverride` stays false, so re-registering a
 * materialized entry throws, which is how the ServiceManager expresses the protected registrations the
 * composition root has always demanded.
 *
 * @phpstan-import-type FactoryCallable from ServiceManager
 *
 * @since  2.0.0
 */
final class Container implements ContainerInterface
{
    /**
     * Laminas ServiceManager resolution runs on, created with this wrapper as its creation context.
     *
     * @var    ServiceManager
     * @since  2.0.0
     */
    private readonly ServiceManager $services;

    /**
     * Create an empty container whose factories will receive this instance.
     *
     * @since  2.0.0
     */
    public function __construct()
    {
        $this->services = new ServiceManager([], $this);
    }

    /**
     * Apply an explicitly selected package provider's service definitions to the host container.
     *
     * @param   array{factories: array<class-string, class-string<object&FactoryCallable>>,
     *          aliases: array<class-string, class-string>,
     *          shared: array<class-string, bool>}  $dependencies  Canonical factory, alias and lifetime bindings.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function configure(array $dependencies): void
    {
        $this->services->configure($dependencies);
    }

    /**
     * Register a shared service under an identifier.
     *
     * @param   string  $id         Identifier the service is resolved by.
     * @param   mixed   $value      Factory closure invoked with this container, or the ready instance to
     *          serve as-is.
     * @param   bool    $protected  Accepted for the registration vocabulary; every entry is protected
     *          alike because overrides stay disabled container-wide.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function share(string $id, mixed $value, bool $protected = false): void
    {
        if ($value instanceof Closure) {
            $this->services->setFactory($id, $value);

            return;
        }

        $this->services->setService($id, $value);
    }

    /**
     * Register an alias resolving to another registered identifier.
     *
     * @param   string  $alias   Identifier the alias is resolved by.
     * @param   string  $target  Registered identifier the alias resolves to.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function alias(string $alias, string $target): void
    {
        $this->services->setAlias($alias, $target);
    }

    /**
     * Resolve a registered service.
     *
     * @param   string  $id  Identifier of the service to resolve.
     *
     * @return  mixed  The shared service instance.
     *
     * @since   2.0.0
     */
    public function get(string $id): mixed
    {
        return $this->services->get($id);
    }

    /**
     * Tell whether an identifier is registered, directly or through an alias.
     *
     * @param   string  $id  Identifier to look up.
     *
     * @return  bool  True when the identifier resolves.
     *
     * @since   2.0.0
     */
    public function has(string $id): bool
    {
        return $this->services->has($id);
    }
}
