<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Delivery\Console\Command;

use Kumwe\App\Tests\Support\TranslatesConsoleOutput;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\Context\Value\SiteContext;
use Kumwe\App\BusinessSecurity\Application\MembershipDirectory;
use Kumwe\App\Delivery\Console\Command\ConsoleAuthorizer;
use Kumwe\App\Delivery\Console\Command\CreateAccessTokenCommand;
use Kumwe\App\Delivery\Console\Output;
use Kumwe\App\Identity\Application\Administration\AdministratorIdentityGateway;
use Kumwe\App\Identity\Application\Authentication\AccessTokenVerifier;
use Kumwe\App\Tests\Support\AuthorizationContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CreateAccessTokenCommand::class)]
/**
 * Verifies that token creation binds organization authority only through live server membership.
 *
 * @since  2.0.0
 */
final class CreateAccessTokenCommandTest extends TestCase
{
    /**
     * Owner-protected password files awaiting cleanup.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    private array $files = [];

    /**
     * Remove every protected password file created by a test.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    /**
     * Prove password issuance embeds only the membership returned by the trusted directory.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testPasswordIssuanceBindsOnlyAServerResolvedOrganizationAndWorkspace(): void
    {
        $passwordFile = $this->secretFile('correct horse battery staple');
        $principal = AuthorizationContext::principal(['users.manage']);
        $membership = AuthorizationContext::membership('acme', 'north');
        $identities = $this->createMock(AdministratorIdentityGateway::class);
        $identities->expects(self::once())->method('authenticate')->with(
            'owner@example.com',
            'correct horse battery staple',
            'cli-token-create',
        )->willReturn($principal);
        $identities->expects(self::once())->method('issueAccessToken')->willReturnCallback(
            static function (ExecutionContext $context): array {
                self::assertSame('acme', $context->organization()?->identifier());
                self::assertSame('north', $context->workspace()?->identifier());
                self::assertSame(
                    '018f22e2-7c8b-7ab0-8f3a-88e8026bb302',
                    $context->membership()?->membershipId(),
                );

                return [
                    'token' => str_repeat('a', 64),
                    'token_id' => '018f22e2-7c8b-7ab0-8f3a-88e8026bb303',
                ];
            },
        );
        $memberships = $this->createMock(MembershipDirectory::class);
        $memberships->expects(self::once())->method('resolve')->with(
            AuthorizationContext::SUBJECT,
            self::callback(static fn (SiteContext $site): bool => $site->identifier() === 'default'),
            'acme',
            'north',
        )->willReturn($membership);
        $output = new AccessTokenCommandOutput();

        $status = $this->command($identities, $memberships)->execute([
            '--site=default',
            '--email=owner@example.com',
            '--name=organization-reporting',
            '--capabilities=business.record.report',
            '--organization=acme',
            '--workspace=north',
            '--password-file=' . $passwordFile,
        ], $output);

        self::assertSame(0, $status);
        self::assertSame(str_repeat('a', 64), $output->lines[1] ?? null);
        self::assertSame([], $output->errors);
    }

    /**
     * Reject a workspace selector before authentication when no parent organization was named.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testWorkspaceSelectionRequiresAnOrganization(): void
    {
        $identities = $this->createMock(AdministratorIdentityGateway::class);
        $identities->expects(self::never())->method('authenticate');
        $identities->expects(self::never())->method('issueAccessToken');
        $memberships = $this->createMock(MembershipDirectory::class);
        $memberships->expects(self::never())->method('resolve');
        $output = new AccessTokenCommandOutput();

        $status = $this->command($identities, $memberships)->execute([
            '--email=owner@example.com',
            '--name=workspace-reporting',
            '--capabilities=business.record.report',
            '--workspace=north',
            '--password-file=/not/read',
        ], $output);

        self::assertSame(1, $status);
        self::assertSame(['The --workspace option requires --organization.'], $output->errors);
    }

    /**
     * Refuse an organization identifier that resolves to no active membership for the subject.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testPasswordIssuanceRefusesAnUnavailableMembership(): void
    {
        $passwordFile = $this->secretFile('correct horse battery staple');
        $identities = $this->createMock(AdministratorIdentityGateway::class);
        $identities->method('authenticate')->willReturn(AuthorizationContext::principal(['users.manage']));
        $identities->expects(self::never())->method('issueAccessToken');
        $memberships = $this->createMock(MembershipDirectory::class);
        $memberships->expects(self::once())->method('resolve')->willReturn(null);
        $output = new AccessTokenCommandOutput();

        $status = $this->command($identities, $memberships)->execute([
            '--email=owner@example.com',
            '--name=organization-reporting',
            '--capabilities=business.record.report',
            '--organization=unknown',
            '--password-file=' . $passwordFile,
        ], $output);

        self::assertSame(1, $status);
        self::assertSame(['The requested live organization membership is unavailable.'], $output->errors);
    }

    /**
     * Prevent untrusted CLI flags from replacing the membership already embedded in a bearer token.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTokenAuthorizedIssuanceRejectsOrganizationSelectionOverrides(): void
    {
        $identities = $this->createMock(AdministratorIdentityGateway::class);
        $identities->expects(self::never())->method('authenticate');
        $identities->expects(self::never())->method('issueAccessToken');
        $memberships = $this->createMock(MembershipDirectory::class);
        $memberships->expects(self::never())->method('resolve');
        $output = new AccessTokenCommandOutput();

        $status = $this->command($identities, $memberships)->execute([
            '--site=default',
            '--token-file=/not/read',
            '--email=owner@example.com',
            '--name=organization-reporting',
            '--capabilities=business.record.report',
            '--organization=acme',
        ], $output);

        self::assertSame(1, $status);
        self::assertSame(
            ['Organization and workspace selection is derived from the verified token.'],
            $output->errors,
        );
    }

    /**
     * Build the command around focused identity and membership doubles.
     *
     * @param   AdministratorIdentityGateway  $identities   Identity double that authenticates and issues.
     * @param   MembershipDirectory           $memberships  Membership double that resolves exact selections.
     *
     * @return  CreateAccessTokenCommand  Command under test with an inert token verifier.
     *
     * @since   2.0.0
     */
    private function command(
        AdministratorIdentityGateway $identities,
        MembershipDirectory $memberships,
    ): CreateAccessTokenCommand {
        return new CreateAccessTokenCommand(
            $identities,
            new ConsoleAuthorizer($this->createMock(AccessTokenVerifier::class)),
            $memberships,
        );
    }

    /**
     * Persist one owner-only command secret for the duration of a test.
     *
     * @param   string  $secret  Password value the command must read from the protected file.
     *
     * @return  string  Absolute path to the new mode-0600 file.
     *
     * @since   2.0.0
     */
    private function secretFile(string $secret): string
    {
        $file = tempnam(sys_get_temp_dir(), 'kumwe-token-password-');
        if (!is_string($file) || file_put_contents($file, $secret) === false || !chmod($file, 0600)) {
            self::fail('A protected test password file could not be created.');
        }
        $this->files[] = $file;

        return $file;
    }
}

/**
 * Captures token-command output without writing a bearer secret to the test process streams.
 *
 * @since  2.0.0
 */
final class AccessTokenCommandOutput implements Output
{
    use TranslatesConsoleOutput;

    /**
     * Normal command lines in emission order.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    public array $lines = [];

    /**
     * Error lines in emission order.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    public array $errors = [];

    /**
     * Capture one successful output line.
     *
     * @param   string  $message  Command line to retain for assertions.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function line(string $message): void
    {
        $this->lines[] = $message;
    }

    /**
     * Capture one sanitized command failure.
     *
     * @param   string  $message  Error line to retain for assertions.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function error(string $message): void
    {
        $this->errors[] = $message;
    }
}
