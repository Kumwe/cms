<?php

declare(strict_types=1);

namespace Kumwe\App\Localization\Presentation;

use DateTimeInterface;
use Kumwe\Localization\Application\ActiveLocale;
use Kumwe\Localization\Application\Translator;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Publishes the translation contract onto a Twig environment as `t`, `locale_tag` and `text_direction`.
 *
 * Until this existed no rendering environment carried a localizable helper of any kind, so every
 * template wrote its text inline. `t` is the only way a template is now allowed to produce
 * user-facing words: it takes the stable message identifier and the values the message names, and
 * returns the formatted string, autoescaped like every other expression. `locale_tag` and
 * `text_direction` exist so a layout can emit `lang` and `dir` from the locale that was actually
 * negotiated rather than from a hardcoded attribute.
 *
 * The extension holds no locale of its own. It reads the one the unit of work in flight resolved,
 * which is what lets one shared environment render two requests in two languages.
 *
 * @since  2.0.0
 */
final class TranslationTwigExtension extends AbstractExtension
{
    /**
     * Bind the extension to the translator and the locale of the unit of work.
     *
     * @param  Translator    $translator  Port every message is resolved and formatted through.
     * @param  ActiveLocale  $active      Holder of the locale the unit of work negotiated.
     *
     * @since  2.0.0
     */
    public function __construct(
        private readonly Translator $translator,
        private readonly ActiveLocale $active,
    ) {
    }

    /**
     * Declare the three functions a template may reach translation through.
     *
     * @return  list<TwigFunction>  The `t`, `t_html`, `locale_tag` and `text_direction` functions.
     *
     * @since   2.0.0
     */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('t', $this->translate(...)),
            new TwigFunction('t_html', $this->translateHtml(...), ['is_safe' => ['html']]),
            new TwigFunction('locale_tag', $this->localeTag(...)),
            new TwigFunction('text_direction', $this->textDirection(...)),
        ];
    }

    /**
     * Resolve and format one message for the locale in flight.
     *
     * @param   string                                                  $identifier  Stable message identifier.
     * @param   array<string, string|int|float|bool|DateTimeInterface>  $parameters  Values the message names.
     *
     * @return  string  The formatted message, or the identifier when no catalogue carries it.
     *
     * @throws  \Kumwe\Localization\Domain\InvalidMessageIdentifier  When the template used source
     *          text, or an otherwise malformed identifier, as the lookup key.
     *
     * @since   2.0.0
     */
    public function translate(string $identifier, array $parameters = []): string
    {
        return $this->translator->translate($identifier, $parameters);
    }

    /**
     * Resolve a message whose source text legitimately contains inline markup.
     *
     * A sentence that wraps one of its own words in `<code>` or `<strong>` cannot be split around
     * the element without handing a translator two fragments and no sentence, which is the single
     * most common way a translated interface becomes ungrammatical. The element therefore stays
     * inside the message, and this function is what makes that safe: every supplied value is
     * escaped before it reaches the formatter, so only the markup a catalogue itself carries is
     * treated as markup and a substituted value never can be.
     *
     * Use `t` everywhere else. This exists for messages that genuinely contain an element, not as a
     * way to avoid escaping.
     *
     * @param   string                                                  $identifier  Stable message identifier.
     * @param   array<string, string|int|float|bool|DateTimeInterface>  $parameters  Values the message names.
     *
     * @return  string  The formatted message, safe to emit as HTML.
     *
     * @throws  \Kumwe\Localization\Domain\InvalidMessageIdentifier  When the template used source
     *          text, or an otherwise malformed identifier, as the lookup key.
     *
     * @since   2.0.0
     */
    public function translateHtml(string $identifier, array $parameters = []): string
    {
        $escaped = [];
        foreach ($parameters as $name => $value) {
            $escaped[$name] = is_string($value)
                ? htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                : $value;
        }

        return $this->translator->translate($identifier, $escaped);
    }

    /**
     * The canonical tag of the locale in flight, for a `lang` attribute.
     *
     * @return  string  Language tag such as `en-GB` or `ar`.
     *
     * @since   2.0.0
     */
    public function localeTag(): string
    {
        return $this->active->locale()->toString();
    }

    /**
     * The writing direction of the locale in flight, for a `dir` attribute.
     *
     * @return  string  Either `ltr` or `rtl`.
     *
     * @since   2.0.0
     */
    public function textDirection(): string
    {
        return $this->active->locale()->direction()->value;
    }
}
