<?php

declare(strict_types=1);

namespace Kumwe\App\Delivery\Console\Command;

use InvalidArgumentException;
use Kumwe\Context\Value\SiteContext;
use Kumwe\App\Delivery\Console\Command;
use Kumwe\App\Delivery\Console\Output;
use Kumwe\App\Infrastructure\Mcp\KumweMcpHandlers;
use Kumwe\App\Infrastructure\Mcp\KumweMcpServerFactory;
use Kumwe\App\Identity\Application\Authentication\AccessTokenVerifier;
use Mcp\Server\Transport\StdioTransport;
use Psr\Log\LoggerInterface;

/**
 * Console entry point that serves the Kumwe MCP tool surface over stdio as `kumwe mcp:serve`.
 *
 * An MCP client launches this as a child process and speaks the protocol down its pipes, so the
 * process is long-lived and every tool call in it is answered under one credential. That credential is
 * a token issued for the `kumwe-mcp` audience and the `mcp` purpose, bound to the site named by
 * `--site`, and it is what decides which tools the client may reach — running the command locally
 * grants nothing by itself. The token is bound rather than exchanged for a fixed context, so the
 * handlers re-verify it before each protected access and a revocation takes effect on the next call
 * instead of when the process next restarts.
 *
 * Unlike the other console commands this one reports startup failures with distinct exit statuses
 * rather than a blanket 1, because a supervisor has to tell a bad invocation from a badly protected
 * token file from a rejected credential.
 *
 * @since  2.0.0
 */
final readonly class McpServeCommand implements Command
{
    /**
     * Wire the command to the MCP server factory, its handlers, the token verifier and the log sink.
     *
     * @param  KumweMcpServerFactory  $servers   Builds the protocol server from the capability catalogue.
     * @param  KumweMcpHandlers       $handlers  Tool and resource implementations, bound to the credential here.
     * @param  AccessTokenVerifier    $tokens    Verifies the stdio token before the transport is opened.
     * @param  LoggerInterface        $logger    Sink the stdio transport reports protocol trouble to, since
     *         standard output carries protocol frames and cannot carry diagnostics.
     *
     * @since  2.0.0
     */
    public function __construct(
        private KumweMcpServerFactory $servers,
        private KumweMcpHandlers $handlers,
        private AccessTokenVerifier $tokens,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Name the operator types to start the MCP stdio server.
     *
     * @return  string  Always `mcp:serve`.
     *
     * @since   2.0.0
     */
    public function name(): string
    {
        return 'mcp:serve';
    }

    /**
     * Describe the command for the console's command listing.
     *
     * @return  string  One-line summary naming the transport and the capability protection.
     *
     * @since   2.0.0
     */
    public function description(): string
    {
        return 'core.console.mcp_serve.description';
    }

    /**
     * Validate the invocation and the credential, then serve MCP frames until the client disconnects.
     *
     * Exactly two options are accepted, `--site` and `--token-file`, and nothing else may be present:
     * a stray argument is a usage error rather than something to ignore, because this process is
     * launched by a client and a silently misread option would hand it the wrong site. The three
     * startup checks run in order and report distinct statuses — 64 for an unusable invocation or an
     * unreadable site, 65 for a token file that fails its location and permission checks, and 77 for a
     * token that is invalid, expired, revoked, or issued for another site or purpose. Only then is the
     * transport opened, after which the return value is whatever the protocol server reports when the
     * client goes away.
     *
     * @param   list<string>  $arguments  Exactly `--site=SITE` and `--token-file=PATH`, in any order.
     * @param   Output        $output     Sink for the startup failure messages; protocol traffic bypasses it.
     *
     * @return  int  The server's own status once the session ends, or 64, 65 or 77 when a startup check
     *          rejected the invocation, the token file or the token.
     *
     * @since   2.0.0
     */
    public function execute(array $arguments, Output $output): int
    {
        try {
            $options = CommandInput::options($arguments);
            if (
                count($arguments) !== 2
                || count($options) !== 2
                || array_diff(array_keys($options), ['site', 'token-file']) !== []
            ) {
                throw new InvalidArgumentException('The MCP stdio options are invalid.');
            }
            $site = SiteContext::fromString(CommandInput::required($options, 'site'));
            $file = CommandInput::required($options, 'token-file');
        } catch (InvalidArgumentException $exception) {
            $output->failure('core.console.mcp_serve.usage_mcp_serve_site_site_token', [
                'reason' => $exception->getMessage(),
            ]);

            return 64;
        }

        try {
            $token = CommandInput::secretFile($file);
        } catch (InvalidArgumentException) {
            $output->failure('core.console.mcp_serve.token_file_requirements');

            return 65;
        }

        $siteIdentifier = $site->identifier();
        if ($this->tokens->verify($token, 'kumwe-mcp', 'mcp', $siteIdentifier) === null) {
            $output->failure('core.console.mcp_serve.the_mcp_access_token_is_invalid');

            return 77;
        }

        return $this->servers->create($this->handlers->forCredential(
            $this->tokens,
            $token,
            $siteIdentifier,
        ))
            ->run(new StdioTransport(logger: $this->logger));
    }
}
