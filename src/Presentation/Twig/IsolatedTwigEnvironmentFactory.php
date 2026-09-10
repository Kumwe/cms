<?php

declare(strict_types=1);

namespace Kumwe\App\Presentation\Twig;

use Kumwe\App\Extension\Runtime\ActiveExtensionSet;
use Kumwe\Context\Value\SiteContext;
use Kumwe\App\Localization\Presentation\TranslationTwigExtension;
use Kumwe\App\Extension\Domain\ThemeSurface;
use Twig\Environment;
use Twig\Loader\ChainLoader;
use Twig\Loader\FilesystemLoader;
use Twig\Loader\LoaderInterface;

/**
 * Builds one Twig environment per rendering surface, each with its own loader chain and cache.
 *
 * Themes and extension views arrive from operator-installed packages, so a single shared environment
 * would let the front end resolve back-office templates and let any installed package shadow core
 * views. This factory composes a separate chain per surface instead: fixed namespaces (`@core-site`,
 * `@core-admin`, `@site-theme`, `@admin-theme`), extension views under an injective per-identifier
 * namespace, an administrator theme admitted only through its override contract, and a recovery chain
 * that sees neither themes nor extensions. It is the only place those chains are assembled, and the
 * container shares each resulting environment once under its own class name.
 *
 * @since  2.0.0
 */
final readonly class IsolatedTwigEnvironmentFactory
{
    /**
     * Capture the state every surface's environment is composed from.
     *
     * @param  ActiveExtensionSet         $active            Runtime set supplying activated theme paths and
     *         extension views.
     * @param  string                     $coreTemplateRoot  Directory holding the built-in `site` and
     *         `administrator` trees.
     * @param  string                     $cacheRoot         Directory below which each surface caches compiled
     *         templates.
     * @param  bool                       $production        True in production, where compiled templates are
     *         cached on disk.
     * @param  ?TranslationTwigExtension  $translation       Extension publishing `t`, `locale_tag` and
     *         `text_direction`, added to every environment this factory builds; null leaves an environment
     *         without them, which only a caller assembling templates outside a request should do.
     *
     * @since  2.0.0
     */
    public function __construct(
        private ActiveExtensionSet $active,
        private string $coreTemplateRoot,
        private string $cacheRoot,
        private bool $production,
        private ?TranslationTwigExtension $translation = null,
    ) {
    }

    /**
     * Build the front-end environment for one site.
     *
     * The theme activated for this site context is searched ahead of the built-in templates, so two
     * sites in the same installation render from their own theme assignments.
     *
     * @param   ?SiteContext  $site  Site whose theme assignment applies, or null to use the default site.
     *
     * @return  SiteTwigEnvironment  Environment carrying site namespaces only, cached under `site`.
     *
     * @since   2.0.0
     */
    public function site(?SiteContext $site = null): SiteTwigEnvironment
    {
        $environment = new SiteTwigEnvironment(
            $this->surfaceLoader(
                ThemeSurface::Site,
                $this->coreTemplateRoot . '/site',
                $site ?? SiteContext::default(),
            ),
            $this->options($this->cacheRoot . '/site'),
        );
        $this->publishTranslation($environment);

        return $environment;
    }

    /**
     * Build the back-office environment with the activated theme held to its override contract.
     *
     * @return  AdministratorTwigEnvironment  Environment whose theme may override `layout.twig` and nothing else.
     *
     * @since   2.0.0
     */
    public function administrator(): AdministratorTwigEnvironment
    {
        $environment = new AdministratorTwigEnvironment(
            $this->surfaceLoader(ThemeSurface::Administrator, $this->coreTemplateRoot . '/administrator'),
            $this->options($this->cacheRoot . '/administrator'),
        );
        $this->publishTranslation($environment);

        return $environment;
    }

    /**
     * Build the emergency back-office environment from the built-in administrator templates alone.
     *
     * Neither a theme path nor an extension view path is added, deliberately: this environment must
     * still render when an activated administrator theme fails to compile, so nothing operator
     * installed may take part in resolving its templates.
     *
     * @return  RecoveryAdministratorTwigEnvironment  Environment exposing core templates and `@core-admin` only.
     *
     * @since   2.0.0
     */
    public function recoveryAdministrator(): RecoveryAdministratorTwigEnvironment
    {
        $loader = new FilesystemLoader();
        $loader->addPath($this->coreTemplateRoot . '/administrator');
        $loader->addPath($this->coreTemplateRoot . '/administrator', 'core-admin');
        $loader->addPath($this->coreTemplateRoot . '/interface-standard', 'kis');

        $environment = new RecoveryAdministratorTwigEnvironment(
            $loader,
            $this->options($this->cacheRoot . '/recovery-administrator'),
        );
        $this->publishTranslation($environment);

        return $environment;
    }

    /**
     * Add the translation functions to one environment, when the factory was given them.
     *
     * Every surface receives the same extension instance, so one shared registration decides what
     * `t` resolves against and a template cannot reach a translator another surface configured.
     *
     * @param   Environment  $environment  Environment being composed.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function publishTranslation(Environment $environment): void
    {
        if ($this->translation instanceof TranslationTwigExtension) {
            $environment->addExtension($this->translation);
        }
    }

    /**
     * Compose the loader chain for one surface.
     *
     * Unnamespaced names resolve theme first, then core, then extension views, which is what makes a
     * theme an override rather than a replacement. The same paths are registered again under stable
     * namespaces so a template can address either explicitly. The administrator surface is delegated
     * to `administratorLoader()`, because there the theme is admitted through a contract instead.
     *
     * @param   ThemeSurface  $surface   Surface whose theme assignment and extension views are resolved.
     * @param   string        $corePath  Directory holding this surface's built-in templates.
     * @param   ?SiteContext  $site      Site whose theme applies on the site surface; ignored on the other.
     *
     * @return  LoaderInterface  Loader resolving names in override order for this surface alone.
     *
     * @since   2.0.0
     */
    private function surfaceLoader(ThemeSurface $surface, string $corePath, ?SiteContext $site = null): LoaderInterface
    {
        $themePath = $surface === ThemeSurface::Site
            ? $this->active->siteThemePath(($site ?? SiteContext::default())->identifier())
            : $this->active->themePath($surface);

        if ($surface === ThemeSurface::Administrator) {
            return $this->administratorLoader($corePath, $themePath);
        }

        $loader = new FilesystemLoader();

        if ($themePath !== null) {
            $loader->addPath($themePath);
            $loader->addPath($themePath, 'site-theme');
        }

        $loader->addPath($corePath);
        $loader->addPath($corePath, 'core-site');
        $loader->addPath($this->coreTemplateRoot . '/interface-standard', 'kis');

        foreach ($this->active->extensionViewPaths($surface) as $identifier => $path) {
            $loader->addPath($path, self::extensionNamespace($identifier));
        }

        return $loader;
    }

    /**
     * Compose the back-office loader chain, admitting the theme only for its contracted templates.
     *
     * The core loader carries the built-in templates and the extension view namespaces, and is
     * returned unwrapped when no administrator theme is active. With a theme active, a
     * `ContractRestrictedLoader` limited to `layout.twig` is chained ahead of it, so the theme
     * restyles the shell while login views and controller-specific pages still come from core.
     *
     * @param   string   $corePath   Directory holding the built-in administrator templates.
     * @param   ?string  $themePath  Root of the activated administrator theme, or null when none is active.
     *
     * @return  LoaderInterface  The core loader alone, or a chain preferring the theme layout over core.
     *
     * @since   2.0.0
     */
    private function administratorLoader(string $corePath, ?string $themePath): LoaderInterface
    {
        $core = new FilesystemLoader();
        $core->addPath($corePath);
        $core->addPath($corePath, 'core-admin');
        $core->addPath($this->coreTemplateRoot . '/interface-standard', 'kis');
        foreach ($this->active->extensionViewPaths(ThemeSurface::Administrator) as $identifier => $path) {
            $core->addPath($path, self::extensionNamespace($identifier));
        }
        if ($themePath === null) {
            return $core;
        }

        $theme = new FilesystemLoader();
        $theme->addPath($themePath);
        $theme->addPath($themePath, 'admin-theme');

        return new ChainLoader([
            new ContractRestrictedLoader($theme, ['layout.twig', '@admin-theme/layout.twig']),
            $core,
        ]);
    }

    /**
     * Derive the Twig namespace an extension's views are registered under.
     *
     * Extension identifiers contain characters a Twig namespace cannot hold, and sanitising them
     * previously let distinct identifiers collide onto one namespace. Hex encoding the identifier is
     * injective, so `acme/tools` resolves as `@extension-61636d652f746f6f6c73` and no package can
     * reach another's views. Callers building a reference must prepend the `@` themselves.
     *
     * @param   string  $identifier  Extension identifier in `vendor/name` form.
     *
     * @return  string  Namespace name without the leading `@`.
     *
     * @since   2.0.0
     */
    public static function extensionNamespace(string $identifier): string
    {
        return 'extension-' . bin2hex($identifier);
    }

    /**
     * Assemble the environment options every surface is constructed with.
     *
     * Output is HTML autoescaped and `strict_variables` is on, so an undefined template variable
     * fails loudly instead of rendering blank. Compilation caching is enabled only in production,
     * which keeps template edits visible during development without a cache clear.
     *
     * @param   string  $cache  Directory this surface writes compiled templates to when caching is on.
     *
     * @return  array{autoescape: string, cache: string|false, strict_variables: true}  Twig constructor options.
     *
     * @since   2.0.0
     */
    private function options(string $cache): array
    {
        return [
            'autoescape' => 'html',
            'cache' => $this->production ? $cache : false,
            'strict_variables' => true,
        ];
    }
}
