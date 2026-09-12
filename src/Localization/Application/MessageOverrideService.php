<?php

declare(strict_types=1);

namespace Kumwe\App\Localization\Application;

use Kumwe\Localization\Application\MessageCatalogueRepository;
use Kumwe\Localization\Application\MessageFormattingFailed;
use Kumwe\Localization\Application\MessageOverrideRecord;
use Kumwe\Localization\Application\MessageOverrideStore;
use Kumwe\Localization\Application\MessagePatternValidator;
use Kumwe\Localization\Application\SupportedLocales;
use Kumwe\Localization\Application\Translator;
use DateTimeImmutable;
use InvalidArgumentException;
use Kumwe\App\Application\Authorization\AuthorizationGateway;
use Kumwe\App\Application\Authorization\AuthorizationResource;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\Transaction\Contract\TransactionManager;
use Kumwe\App\Audit\Application\AuditRecorder;
use Kumwe\App\Audit\Domain\AuditEvent;
use Kumwe\Extension\Spi\Identity\Domain\Capability;
use Kumwe\Localization\Domain\InvalidLocaleTag;
use Kumwe\Localization\Domain\LocaleTag;
use Kumwe\Localization\Domain\MessageCatalogueLayer;
use Kumwe\Localization\Domain\MessageIdentifier;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;

/**
 * Management face of the administered wording layers: the only supported way to change a message.
 *
 * An operator relabelling "Client" as "Patient" is performing an administrative act, not a fork, and
 * this is where that act is validated, authorized and recorded. Every delivery surface that changes
 * wording goes through here, so the rules live once: the identifier must satisfy the frozen grammar,
 * the locale must be one this installation carries, the pattern must be non-empty and bounded, App
 * messages must compile as ICU while the exact Studio shell namespace must satisfy its published
 * named-interpolation subset, and the actor must hold `localization.overrides.manage` on the scope.
 *
 * Two guards are worth naming because they are what stop terminology adaptation from becoming a
 * denial-of-service against the render path. An override may only be stored for an identifier some
 * file-shipped layer already declares, so the store cannot fill with wording nothing looks up. And a
 * scope carries a bounded number of overrides, because the whole map is read on the render path and
 * an unbounded map would make every page pay for one operator's bulk import.
 *
 * @since  2.0.0
 */
final readonly class MessageOverrideService
{
    /**
     * How many overrides one layer, scope and locale may carry.
     *
     * The whole map is fetched on the render path, so this is a bound on work done per request rather
     * than a product limit: relabelling a vertical's vocabulary is tens of messages, not thousands.
     *
     * @var    int
     * @since  2.0.0
     */
    public const int MAXIMUM_PER_SCOPE = 500;

    /**
     * Longest ICU pattern an override may carry, in bytes.
     *
     * @var    int
     * @since  2.0.0
     */
    public const int MAXIMUM_PATTERN_BYTES = 4000;

    /**
     * Capability an actor must hold to read or change administered wording.
     *
     * @var    string
     * @since  2.0.0
     */
    public const string CAPABILITY = 'localization.overrides.manage';

    /**
     * Catalogue namespace whose patterns are consumed by Studio's deterministic named interpolator.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string STUDIO_SHELL_NAMESPACE = 'core.studio.shell.';

    /**
     * Studio protocol's closed local-name grammar for one interpolation parameter.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string STUDIO_PARAMETER_GRAMMAR = '/^[a-z][a-z0-9]*(?:[._-][a-z0-9]+)*$/D';

    /**
     * Wire the service to its store, the catalogues it validates against, and its bookkeeping.
     *
     * @param  MessageOverrideStore        $store          Store the overrides are listed from and written to.
     * @param  MessageCatalogueRepository  $catalogues     File-shipped layers an identifier must be declared
     *         by before it may be overridden.
     * @param  SupportedLocales            $supported      Registry deciding which locales may be written.
     * @param  AuthorizationGateway        $authorization  Policy deciding who may change wording.
     * @param  TransactionManager          $transactions   Atomic boundary shared by state and audit writes.
     * @param  MessagePatternValidator     $patterns       ICU syntax validator run for ordinary App wording.
     * @param  Translator                  $translator     Resolves the refusal wording an operator reads.
     * @param  AuditRecorder               $audit          Sink every wording change is recorded to.
     * @param  ClockInterface              $clock          Source of the instant stored and audited.
     *
     * @since  2.0.0
     */
    public function __construct(
        private MessageOverrideStore $store,
        private MessageCatalogueRepository $catalogues,
        private SupportedLocales $supported,
        private AuthorizationGateway $authorization,
        private TransactionManager $transactions,
        private MessagePatternValidator $patterns,
        private Translator $translator,
        private AuditRecorder $audit,
        private ClockInterface $clock,
    ) {
    }

    /**
     * List the wording this actor's scope has changed.
     *
     * @param   ExecutionContext       $context  Actor and site the listing runs as.
     * @param   MessageCatalogueLayer  $layer    Administered layer to list, `Site` or `Organization`.
     * @param   ?string                $locale   Restrict to one locale tag, or null for every locale.
     *
     * @return  list<MessageOverrideRecord>  Stored overrides in a stable order.
     *
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the actor may not manage wording.
     * @throws  InvalidArgumentException  When the layer is not administered, or the locale is not carried.
     *
     * @since   2.0.0
     */
    public function overrides(
        ExecutionContext $context,
        MessageCatalogueLayer $layer,
        ?string $locale = null,
    ): array {
        $this->authorize($context);

        return $this->store->overrides(
            $this->administered($layer),
            $context->site()->identifier(),
            $this->organization($context, $layer),
            $locale === null ? null : $this->locale($locale),
        );
    }

    /**
     * Change one word without a file edit and without a deployment.
     *
     * @param   ExecutionContext       $context     Actor and site the write runs as.
     * @param   MessageCatalogueLayer  $layer       Administered layer the override is stored in.
     * @param   string                 $locale      Tag of the locale the new wording applies to.
     * @param   string                 $identifier  Message identifier whose wording is being replaced.
     * @param   string                 $pattern     Replacement ICU pattern.
     *
     * @return  MessageOverrideRecord  The stored override, carrying the instant it was written.
     *
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the actor may not manage wording.
     * @throws  \Kumwe\Localization\Domain\InvalidMessageIdentifier  When the identifier breaks the grammar.
     * @throws  InvalidArgumentException  When the layer is not administered, the locale is not carried, the
     *          pattern is blank or too long, no file-shipped layer declares the identifier, or the scope
     *          already holds the maximum number of overrides.
     * @throws  MessageFormattingFailed  When ICU or Studio's named interpolation grammar refuses the pattern.
     *
     * @since   2.0.0
     */
    public function override(
        ExecutionContext $context,
        MessageCatalogueLayer $layer,
        string $locale,
        string $identifier,
        string $pattern,
    ): MessageOverrideRecord {
        $this->authorize($context);
        $administered = $this->administered($layer);
        $tag = $this->locale($locale);
        $validated = MessageIdentifier::fromString($identifier)->value;
        $wording = $this->pattern($pattern);
        $shipped = $this->declaredPattern($validated, $tag);
        if (str_starts_with($validated, self::STUDIO_SHELL_NAMESPACE)) {
            $this->validateStudioPattern($validated, $wording, $shipped, $tag);
        } else {
            $this->patterns->validate($wording, $tag);
        }

        $site = $context->site()->identifier();
        $organization = $this->organization($context, $administered);
        $now = $this->clock->now();
        $override = new MessageOverrideRecord(
            $administered,
            $site,
            $organization,
            $tag->toString(),
            $validated,
            $wording,
            $now,
        );

        return $this->transactions->transactional(function () use (
            $administered,
            $site,
            $organization,
            $tag,
            $validated,
            $override,
            $context,
            $now,
        ): MessageOverrideRecord {
            $this->store->lockSite($site);
            $stored = $this->store->overrides($administered, $site, $organization, $tag);
            $known = [];
            foreach ($stored as $record) {
                $known[$record->identifier] = true;
            }
            if (!isset($known[$validated]) && count($known) >= self::MAXIMUM_PER_SCOPE) {
                throw new InvalidArgumentException($this->translator->translate(
                    'core.administrator.wording.error_scope_quota',
                    ['maximum' => self::MAXIMUM_PER_SCOPE],
                ));
            }

            $this->store->put($override);
            $this->record($context, 'localization.override.write', $override, $now);

            return $override;
        });
    }

    /**
     * Withdraw one override so the layer below it is read again.
     *
     * @param   ExecutionContext       $context     Actor and site the write runs as.
     * @param   MessageCatalogueLayer  $layer       Administered layer the override sits in.
     * @param   string                 $locale      Tag of the locale the override applies to.
     * @param   string                 $identifier  Message identifier to stop overriding.
     *
     * @return  bool  True when an override was withdrawn, false when the scope carried none.
     *
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the actor may not manage wording.
     * @throws  \Kumwe\Localization\Domain\InvalidMessageIdentifier  When the identifier breaks the grammar.
     * @throws  InvalidArgumentException  When the layer is not administered or the locale is not carried.
     *
     * @since   2.0.0
     */
    public function withdraw(
        ExecutionContext $context,
        MessageCatalogueLayer $layer,
        string $locale,
        string $identifier,
    ): bool {
        $this->authorize($context);
        $administered = $this->administered($layer);
        $tag = $this->locale($locale);
        $validated = MessageIdentifier::fromString($identifier)->value;
        $site = $context->site()->identifier();
        $organization = $this->organization($context, $administered);
        $now = $this->clock->now();

        return $this->transactions->transactional(function () use (
            $administered,
            $site,
            $organization,
            $tag,
            $validated,
            $context,
            $now,
        ): bool {
            $this->store->lockSite($site);
            $removed = $this->store->remove($administered, $site, $organization, $tag, $validated);
            if ($removed) {
                $this->record($context, 'localization.override.withdraw', new MessageOverrideRecord(
                    $administered,
                    $site,
                    $organization,
                    $tag->toString(),
                    $validated,
                    '',
                    $now,
                ), $now);
            }

            return $removed;
        });
    }

    /**
     * List the identifiers a scope may override, with the wording the file-shipped layers carry.
     *
     * An operator cannot relabel a word they cannot find, and searching a catalogue of hundreds of
     * identifiers by eye is not finding. This answers the search an administration screen runs: the
     * core and extension layers merged as the chain would merge them, filtered by a substring of
     * either the identifier or its wording, and bounded so a blank search cannot render everything.
     *
     * @param   ExecutionContext  $context  Actor and site the search runs as.
     * @param   string            $locale   Tag of the locale to search at.
     * @param   string            $term     Case-insensitive substring of an identifier or its wording;
     *          an empty term returns the first page in identifier order.
     * @param   int               $limit    Greatest number of matches to return, capped at 200.
     *
     * @return  list<array{identifier: string, pattern: string, layer: string}>  Matches in identifier
     *          order, each naming the file-shipped layer the wording came from.
     *
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the actor may not manage wording.
     * @throws  InvalidArgumentException  When the locale is not one this installation carries.
     *
     * @since   2.0.0
     */
    public function searchCatalogue(
        ExecutionContext $context,
        string $locale,
        string $term = '',
        int $limit = 50,
    ): array {
        $this->authorize($context);
        $tag = $this->locale($locale);
        $needle = mb_strtolower(trim($term));
        $bounded = max(1, min($limit, 200));

        $messages = [];
        foreach ([MessageCatalogueLayer::Extension, MessageCatalogueLayer::Core] as $layer) {
            foreach ($this->catalogues->catalogue($layer, $tag)->messages as $identifier => $pattern) {
                $messages[$identifier] ??= [
                    'identifier' => $identifier,
                    'pattern' => $pattern,
                    'layer' => $layer->value,
                ];
            }
        }
        ksort($messages, SORT_STRING);

        $matches = [];
        foreach ($messages as $message) {
            if (
                $needle !== ''
                && !str_contains(mb_strtolower($message['identifier']), $needle)
                && !str_contains(mb_strtolower($message['pattern']), $needle)
            ) {
                continue;
            }
            $matches[] = $message;
            if (count($matches) === $bounded) {
                break;
            }
        }

        return $matches;
    }

    /**
     * Refuse a layer that ships in files rather than being administered.
     *
     * @param   MessageCatalogueLayer  $layer  Layer the caller named.
     *
     * @return  MessageCatalogueLayer  The same layer, proved administered.
     *
     * @throws  InvalidArgumentException  When the layer is `Core` or `Extension`.
     *
     * @since   2.0.0
     */
    private function administered(MessageCatalogueLayer $layer): MessageCatalogueLayer
    {
        return match ($layer) {
            MessageCatalogueLayer::Site, MessageCatalogueLayer::Organization => $layer,
            default => throw new InvalidArgumentException(
                $this->translator->translate('core.administrator.wording.error_layer_not_administered'),
            ),
        };
    }

    /**
     * Resolve the organization an organization-scoped write applies to.
     *
     * @param   ExecutionContext       $context  Actor and site the write runs as.
     * @param   MessageCatalogueLayer  $layer    Administered layer being written.
     *
     * @return  ?string  The organization identifier, or null for a site-level write.
     *
     * @throws  InvalidArgumentException  When an organization-level write runs outside an organization.
     *
     * @since   2.0.0
     */
    private function organization(ExecutionContext $context, MessageCatalogueLayer $layer): ?string
    {
        if ($layer !== MessageCatalogueLayer::Organization) {
            return null;
        }

        return $context->organization()?->identifier()
            ?? throw new InvalidArgumentException(
                $this->translator->translate('core.administrator.wording.error_outside_organization'),
            );
    }

    /**
     * Accept only a locale this installation carries, so no override is stranded unreadable.
     *
     * @param   string  $locale  Locale tag as the caller spelled it.
     *
     * @return  LocaleTag  The canonical tag.
     *
     * @throws  InvalidArgumentException  When the tag is malformed or is not a carried locale.
     *
     * @since   2.0.0
     */
    private function locale(string $locale): LocaleTag
    {
        try {
            $tag = LocaleTag::fromString($locale);
        } catch (InvalidLocaleTag $malformed) {
            throw new InvalidArgumentException(
                $this->translator->translate('core.administrator.wording.error_locale_not_carried'),
                0,
                $malformed,
            );
        }
        if (!$this->supported->carries($tag)) {
            throw new InvalidArgumentException(
                $this->translator->translate('core.administrator.wording.error_locale_not_carried'),
            );
        }

        return $tag;
    }

    /**
     * Bound the replacement wording, rejecting a blank one and an unreasonably long one.
     *
     * @param   string  $pattern  Replacement ICU pattern as submitted.
     *
     * @return  string  The trimmed pattern.
     *
     * @throws  InvalidArgumentException  When the pattern is blank or exceeds the stored ceiling.
     *
     * @since   2.0.0
     */
    private function pattern(string $pattern): string
    {
        $trimmed = trim($pattern);
        if ($trimmed === '') {
            throw new InvalidArgumentException(
                $this->translator->translate('core.administrator.wording.error_empty_replacement'),
            );
        }
        if (strlen($trimmed) > self::MAXIMUM_PATTERN_BYTES) {
            throw new InvalidArgumentException($this->translator->translate(
                'core.administrator.wording.error_replacement_too_long',
                ['maximum' => self::MAXIMUM_PATTERN_BYTES],
            ));
        }
        $this->assertSafeMarkup($trimmed);

        return $trimmed;
    }

    /**
     * Permit only balanced, attribute-free inline elements in administered wording.
     *
     * `t_html` treats catalogue markup as trusted because its substitution values are escaped first.
     * File-shipped catalogues are release artifacts, but an administered override is operator input and
     * must not turn terminology management into script execution. The small allowlist covers the inline
     * elements the shipped messages use while excluding attributes, URLs, active content and malformed
     * nesting. Markup in an ICU branch is conservatively refused: balancing the raw source cannot prove
     * that every independently rendered branch is balanced. Plain messages may carry the same safe elements;
     * ordinary `t` rendering escapes them.
     *
     * @param   string  $pattern  Bounded nonblank wording to inspect.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When markup is not balanced or is outside the safe allowlist.
     *
     * @since   2.0.0
     */
    private function assertSafeMarkup(string $pattern): void
    {
        $stack = [];
        $containsMarkup = false;
        $plain = preg_replace_callback('/<[^<>]*>/', function (array $match) use (
            &$stack,
            &$containsMarkup,
        ): string {
            $containsMarkup = true;
            $tag = $match[0];
            if (preg_match('/^<(code|em|span|strong)>$/iD', $tag, $opened) === 1) {
                $stack[] = strtolower($opened[1]);

                return '';
            }
            if (preg_match('/^<\/(code|em|span|strong)>$/iD', $tag, $closed) === 1) {
                $expected = array_pop($stack);
                if ($expected !== strtolower($closed[1])) {
                    throw new InvalidArgumentException(
                        $this->translator->translate('core.administrator.wording.error_unbalanced_markup'),
                    );
                }

                return '';
            }

            throw new InvalidArgumentException(
                $this->translator->translate('core.administrator.wording.error_disallowed_markup'),
            );
        }, $pattern);
        if (!is_string($plain) || str_contains($plain, '<') || $stack !== []) {
            throw new InvalidArgumentException(
                $this->translator->translate('core.administrator.wording.error_unbalanced_markup'),
            );
        }
        if (
            $containsMarkup
            && preg_match('/\{[^{}]+,\s*(?:choice|plural|select|selectordinal)\s*,/i', $plain) === 1
        ) {
            throw new InvalidArgumentException(
                $this->translator->translate('core.administrator.wording.error_markup_in_branching_pattern'),
            );
        }
    }

    /**
     * Resolve the file-shipped pattern an administered override is allowed to replace.
     *
     * The check is deliberately made against the source locale as well as the target one: a translator
     * has not yet filed wording for every locale, and refusing an override because the target locale
     * is still untranslated would block the very case the chain exists for.
     *
     * @param   string     $identifier  Validated message identifier.
     * @param   LocaleTag  $locale      Locale the override is being stored at.
     *
     * @return  string  First declared pattern in target/source locale and extension/core precedence order.
     *
     * @throws  InvalidArgumentException  When neither the target locale nor the source locale declares it.
     *
     * @since   2.0.0
     */
    private function declaredPattern(string $identifier, LocaleTag $locale): string
    {
        foreach ([$locale, $this->supported->source()] as $candidate) {
            foreach ([MessageCatalogueLayer::Extension, MessageCatalogueLayer::Core] as $layer) {
                $pattern = $this->catalogues->catalogue($layer, $candidate)->pattern($identifier);
                if ($pattern !== null) {
                    return $pattern;
                }
            }
        }

        throw new InvalidArgumentException(
            $this->translator->translate('core.administrator.wording.error_unknown_identifier'),
        );
    }

    /**
     * Validate the exact named-interpolation subset consumed by the Studio shell.
     *
     * Studio deliberately does not pass its authoring catalogue through App's ICU formatter: its
     * published parameter grammar permits names such as `value-type`, and the shell substitutes only
     * the parameters declared by the canonical entry. An administered override must therefore keep
     * the shipped parameter set exactly and may contain only `{local-name}` expressions. This narrow
     * branch does not weaken ICU validation for any neighbouring App namespace.
     *
     * @param   string     $identifier  Exact Studio shell identifier being overridden.
     * @param   string     $pattern     Bounded replacement pattern supplied by the operator.
     * @param   string     $shipped     File-shipped pattern declaring the canonical parameter set.
     * @param   LocaleTag  $locale      Locale whose administered layer receives the pattern.
     *
     * @return  void
     *
     * @throws  MessageFormattingFailed  When the replacement is outside Studio's interpolation grammar
     *          or changes the canonical parameter set.
     *
     * @since   2.0.0
     */
    private function validateStudioPattern(
        string $identifier,
        string $pattern,
        string $shipped,
        LocaleTag $locale,
    ): void {
        $expected = $this->studioParameters($identifier, $shipped, $locale);
        $actual = $this->studioParameters($identifier, $pattern, $locale);
        if ($actual !== $expected) {
            throw MessageFormattingFailed::pattern(
                $locale->toString(),
                'the Studio shell parameter set differs from the shipped catalogue entry.',
                $identifier,
            );
        }
    }

    /**
     * Extract and validate one Studio named-interpolation parameter set.
     *
     * @param   string     $identifier  Message identifier named in a formatting refusal.
     * @param   string     $pattern     Studio shell text to inspect.
     * @param   LocaleTag  $locale      Locale named in a formatting refusal.
     *
     * @return  list<string>  Unique parameter names in ascending byte order.
     *
     * @throws  MessageFormattingFailed  When braces do not contain one safe Studio local name.
     *
     * @since   2.0.0
     */
    private function studioParameters(string $identifier, string $pattern, LocaleTag $locale): array
    {
        $parameters = [];
        $plain = preg_replace_callback('/\{([^{}]*)\}/', static function (array $match) use (&$parameters): string {
            $name = $match[1];
            if (
                strlen($name) > 100
                || preg_match(self::STUDIO_PARAMETER_GRAMMAR, $name) !== 1
                || in_array($name, ['__proto__', 'prototype', 'constructor'], true)
            ) {
                return $match[0];
            }
            $parameters[$name] = true;

            return '';
        }, $pattern);
        if (
            !is_string($plain)
            || str_contains($plain, '{')
            || str_contains($plain, '}')
            || str_contains($pattern, '<')
            || str_contains($pattern, '>')
        ) {
            throw MessageFormattingFailed::pattern(
                $locale->toString(),
                'the Studio shell accepts only attribute-free text and {local-name} parameters.',
                $identifier,
            );
        }
        ksort($parameters, SORT_STRING);

        return array_keys($parameters);
    }

    /**
     * Prove the actor may administer wording for the site they are working in.
     *
     * @param   ExecutionContext  $context  Actor and site the operation runs as.
     *
     * @return  void
     *
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the capability is absent.
     *
     * @since   2.0.0
     */
    private function authorize(ExecutionContext $context): void
    {
        $this->authorization->assertAllowed(
            $context,
            Capability::fromString(self::CAPABILITY),
            AuthorizationResource::collection('message_override'),
        );
    }

    /**
     * Record one wording change in the audit trail.
     *
     * The pattern itself is recorded, because wording is operator-authored text rather than a secret,
     * and an auditor asking what a screen said last month needs the answer rather than a hash of it.
     *
     * @param   ExecutionContext       $context   Actor and site the change ran as.
     * @param   string                 $action    Stable audit action name.
     * @param   MessageOverrideRecord  $override  The override written or withdrawn.
     * @param   DateTimeImmutable      $at        Instant recorded against the event.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function record(
        ExecutionContext $context,
        string $action,
        MessageOverrideRecord $override,
        DateTimeImmutable $at,
    ): void {
        $this->audit->record(new AuditEvent(
            Uuid::uuid7()->toString(),
            $at,
            $context->actorId(),
            $action,
            'message_override',
            $override->layer->value . ':' . $override->locale . ':' . $override->identifier,
            'success',
            [
                'layer' => $override->layer->value,
                'locale' => $override->locale,
                'identifier' => $override->identifier,
                'organization' => $override->organization,
            ],
        ));
    }
}
