<?php

declare(strict_types=1);

namespace Kumwe\App\Http\Security;

use Kumwe\Producer\Deployment\DeploymentException;
use Kumwe\Producer\Deployment\StudioContentSecurityPolicy;

/**
 * Response header policy that every Kumwe response is hardened with.
 *
 * The policy sits in a plain builder rather than inside the middleware so the exact header set is
 * unit-testable and identical on every delivery path. `SecurityHeadersMiddleware` builds one per
 * response and copies the result onto it. HSTS is constructor-gated because pinning a browser to HTTPS
 * from a development host, or from a plain HTTP request, locks visitors out of a site that cannot
 * serve it; the caller decides, this class only spells the policy out.
 *
 * `upgrade-insecure-requests` is gated for the same reason and answers the same question, which is why
 * both are decided by the caller rather than here. On an HTTPS origin the directive is real hardening:
 * a stylesheet or image a template still names over `http://` is fetched over TLS instead of being
 * blocked or, worse, loaded in the clear. On an origin that is not served over TLS at all it hardens
 * nothing — the document itself already arrived in the clear — while instructing the browser to fetch
 * every subresource, and to send every form submission, to an `https://` port that is not listening.
 * Chromium and Firefox mask that by exempting loopback; WebKit does not, so an HTTP-only deployment
 * loses its stylesheets, its scripts and its sign-in form in Safari and in no other browser.
 *
 * @since  2.0.0
 */
final readonly class SecurityHeaders
{
    /**
     * Fix the transport policy for the header sets this instance builds.
     *
     * The two flags are separate because they answer different questions. HSTS asks whether this
     * deployment may pin a browser to TLS for a year, which only a production site should ever do;
     * the upgrade directive asks only whether this response travelled over TLS, which a staging or
     * development site served over HTTPS answers yes to just as truthfully.
     *
     * @param  bool  $enableHsts               Whether to emit `Strict-Transport-Security`; enable only on
     *         production HTTPS.
     * @param  bool  $upgradeInsecureRequests  Whether the response is served over TLS, and so whether the
     *         browser may be told to upgrade the subresources it fetches.
     *
     * @since  2.0.0
     */
    public function __construct(private bool $enableHsts, private bool $upgradeInsecureRequests = false)
    {
    }

    /**
     * Build the header names and values to copy onto an outgoing response.
     *
     * Passing a nonce is what makes an inline script executable under this policy; without one
     * `script-src` admits same-origin script files only, which is the safe default for pages that
     * carry no inline script at all.
     *
     * Style is split across three directives rather than left as one permissive `style-src`, because the
     * two things `'unsafe-inline'` used to admit are not equally dangerous. A `<style>` element is what a
     * CSS exfiltration attack needs — attribute selectors paired with `url()` requests, or an `@import`
     * to an attacker origin — and nothing Kumwe renders is one, so `style-src-elem` is `'self'` and an
     * injected style block simply does not apply. What remains admitted is the `style` attribute, through
     * `style-src-attr`, which a handful of shipped templates use to carry validated per-site theme
     * colours and bounded layout values into CSS custom properties; an attribute cannot express a
     * selector, so the residual is UI redress rather than exfiltration. Removing it needs those values
     * served as same-origin stylesheets, which `docs/qualification/gap-matrix.md` records as still open.
     *
     * A script origin widens `script-src` alone, and only by an exact `scheme://host[:port]`: it is how a
     * page that mounts the pinned Studio browser module from the configured npm CDN admits that one
     * origin while every other directive, including `connect-src`, stays same-origin. The origin is
     * proven by Producer's exact-origin grammar, so a wildcard, a path or a plain-HTTP host is refused.
     *
     * @param   ?string       $scriptNonce    Nonce that admits matching inline scripts, or null to allow none.
     * @param   list<string>  $scriptOrigins  Exact origins that additionally serve script files.
     *
     * @return  array<string, string>  Header name to value; `Strict-Transport-Security` and the
     *          `upgrade-insecure-requests` directive only when enabled.
     *
     * @throws  DeploymentException  When a script origin is not an exact admissible origin.
     *
     * @since   2.0.0
     */
    public function values(?string $scriptNonce = null, array $scriptOrigins = []): array
    {
        return $this->build($scriptNonce, false, $scriptOrigins);
    }

    /**
     * Build the exact same-origin preview document policy.
     *
     * The preview route is the sole framed administrator document. Its policy adds only a same-origin
     * frame source/ancestor relationship and removes the general site's residual inline-style attribute
     * allowance; script remains self-only and neither eval nor an inline script/style source is admitted.
     *
     * @return  array<string, string>  Hardened headers with SAMEORIGIN framing and no referrer leakage.
     *
     * @since   2.0.0
     */
    public function previewValues(): array
    {
        return $this->build(null, true);
    }

    /**
     * Assemble the ordinary or exact preview policy without duplicating shared directives.
     *
     * @param   string|null   $scriptNonce    Ordinary response nonce, or null for no inline script.
     * @param   bool          $preview        Whether to apply the dedicated framed-document delta.
     * @param   list<string>  $scriptOrigins  Exact origins that additionally serve script files.
     *
     * @return  array<string, string>  Complete response header policy.
     *
     * @throws  DeploymentException  When a script origin is not an exact admissible origin.
     *
     * @since   2.0.0
     */
    private function build(?string $scriptNonce, bool $preview, array $scriptOrigins = []): array
    {
        $scriptSource = $scriptNonce === null ? "'self'" : sprintf("'self' 'nonce-%s'", $scriptNonce);
        foreach (array_values(array_unique($scriptOrigins)) as $origin) {
            StudioContentSecurityPolicy::assertOrigin($origin);
            $scriptSource .= ' ' . $origin;
        }
        $directives = [
                "default-src 'self'",
                "base-uri 'self'",
                "connect-src 'self'",
                "font-src 'self' data:",
                "form-action 'self'",
                $preview ? "frame-ancestors 'self'" : "frame-ancestors 'none'",
                "img-src 'self' data: blob:",
                "object-src 'none'",
                sprintf('script-src %s', $scriptSource),
                "style-src 'self'",
                $preview ? "style-src-attr 'none'" : "style-src-attr 'unsafe-inline'",
                "style-src-elem 'self'",
        ];
        if ($preview) {
            array_splice($directives, 6, 0, ["frame-src 'self'"]);
        }
        $headers = [
            'Content-Security-Policy' => implode('; ', $directives),
            'Cross-Origin-Opener-Policy' => 'same-origin',
            'Permissions-Policy' => 'camera=(), geolocation=(), microphone=(), payment=(), usb=()',
            'Referrer-Policy' => $preview ? 'no-referrer' : 'strict-origin-when-cross-origin',
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => $preview ? 'SAMEORIGIN' : 'DENY',
        ];

        if ($this->upgradeInsecureRequests) {
            $headers['Content-Security-Policy'] .= '; upgrade-insecure-requests';
        }
        if ($this->enableHsts) {
            $headers['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains';
        }

        return $headers;
    }
}
