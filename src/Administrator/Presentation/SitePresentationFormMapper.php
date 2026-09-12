<?php

declare(strict_types=1);

namespace Kumwe\App\Administrator\Presentation;

use InvalidArgumentException;
use Kumwe\Localization\Application\Translator;
use Kumwe\App\Presentation\Application\SitePresentation;

/**
 * Maps the graphical site-settings controls to the shared presentation contract.
 *
 * The settings screen posts branding and theme choices as one flat form, with every colour scheme
 * spread across indexed fields such as `scheme_0_handle`. `AdministratorSettingsHandler` hands that form
 * here on save and stores what comes back, so this is the only place the screen's field naming is
 * understood. What it returns has already been through `SitePresentation`, which means the handler
 * cannot persist a palette or a style choice that has not passed the rules keeping those values safe to
 * interpolate into a page.
 *
 * @since  2.0.0
 */
final readonly class SitePresentationFormMapper
{
    /**
     * Bind the mapper to the translator its refusal wording resolves through.
     *
     * @param  Translator  $translator  Resolves the refusal an operator reads on the settings screen.
     *
     * @since  2.0.0
     */
    public function __construct(private Translator $translator)
    {
    }
    /**
     * Colour roles read out of each scheme's fields, matching the roles a presentation scheme must define.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    private const array COLOR_KEYS = [
        'navy',
        'ink',
        'muted',
        'canvas',
        'surface',
        'border',
        'accent',
        'accent_strong',
        'accent_soft',
        'on_accent',
    ];

    /**
     * Fold the flat settings form into the validated presentation document the site stores.
     *
     * Schemes are discovered from whichever `scheme_<n>_handle` fields the form carries and are ordered
     * by that index, so removing a row simply leaves a gap in the numbering and the rows that remain
     * keep the order the operator saw. Every field but the logo is required: a blank logo means the site
     * shows none, whereas a blank handle or colour is a malformed submission rather than a choice.
     *
     * @param   array<string, mixed>  $form  Flat settings form as posted by the administrator screen.
     *
     * @return  array<string, mixed>  The presentation document in the shape site settings persists.
     *
     * @throws  InvalidArgumentException  When a required field is missing or blank, or the assembled
     *          document breaks one of the presentation safety rules.
     *
     * @since   2.0.0
     */
    public function map(array $form): array
    {
        $indices = [];
        foreach (array_keys($form) as $key) {
            if (preg_match('/^scheme_([0-9]+)_handle$/D', $key, $matches) === 1) {
                $indices[] = (int) $matches[1];
            }
        }
        $indices = array_values(array_unique($indices));
        sort($indices, SORT_NUMERIC);

        $schemes = [];
        foreach ($indices as $index) {
            $colors = [];
            foreach (self::COLOR_KEYS as $key) {
                $colors[$key] = $this->value($form, sprintf('scheme_%d_%s', $index, $key));
            }
            $schemes[] = [
                'handle' => $this->value($form, sprintf('scheme_%d_handle', $index)),
                'name' => $this->value($form, sprintf('scheme_%d_name', $index)),
                'color_mode' => $this->value($form, sprintf('scheme_%d_color_mode', $index)),
                'colors' => $colors,
            ];
        }

        return SitePresentation::from([
            'logo' => $this->value($form, 'presentation_logo', false),
            'footer_text' => $this->value($form, 'presentation_footer_text'),
            'primary_menu' => $this->value($form, 'presentation_primary_menu'),
            'active_scheme' => $this->value($form, 'presentation_active_scheme'),
            'button_style' => $this->value($form, 'presentation_button_style'),
            'button_shape' => $this->value($form, 'presentation_button_shape'),
            'header_style' => $this->value($form, 'presentation_header_style'),
            'schemes' => $schemes,
        ])->toArray();
    }

    /**
     * Read one form field as a trimmed string, refusing anything the contract cannot carry.
     *
     * A non-string value is refused whether or not the field is required, so an array posted where a
     * scalar was expected fails with the field named instead of reaching `SitePresentation` as something
     * it has no rule for.
     *
     * @param   array<string, mixed>  $form      Flat settings form as posted by the administrator screen.
     * @param   string                $key       Name of the field to read.
     * @param   bool                  $required  Whether a blank value is refused; false admits the empty
     *          string, which is what the optional logo field needs.
     *
     * @return  string  The value with surrounding whitespace removed.
     *
     * @throws  InvalidArgumentException  When the field is absent, is not a string, or is required and
     *          trims to empty.
     *
     * @since   2.0.0
     */
    private function value(array $form, string $key, bool $required = true): string
    {
        $value = $form[$key] ?? null;
        if (!is_string($value) || ($required && trim($value) === '')) {
            throw new InvalidArgumentException($this->translator->translate(
                'core.administrator.settings.presentation_field_required',
                ['field' => $key],
            ));
        }

        return trim($value);
    }
}
