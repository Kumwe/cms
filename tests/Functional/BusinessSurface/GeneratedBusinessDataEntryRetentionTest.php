<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Functional\BusinessSurface;

use Kumwe\App\Kernel\Container;
use Kumwe\Context\Value\AuthenticatedSurface;
use Kumwe\Context\Value\AuthenticationStrength;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\Context\Value\SiteContext;
use Kumwe\App\BusinessDefinition\Domain\PortalOperation;
use Kumwe\App\BusinessRecord\Application\BusinessRecordService;
use Kumwe\App\BusinessRecord\Application\Command\CreateRecordCommand;
use Kumwe\App\BusinessRecord\Application\Command\RelateRecordsCommand;
use Kumwe\App\BusinessRecord\Application\Command\UpdateRecordCommand;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordVersionConflict;
use Kumwe\App\BusinessRecord\Application\Query\ReadRecordQuery;
use Kumwe\App\BusinessSurface\Application\BusinessSurface;
use Kumwe\App\BusinessSurface\Delivery\Browser\BusinessBrowserResult;
use Kumwe\App\BusinessSurface\Delivery\Browser\GeneratedBusinessBrowserController;
use Kumwe\App\Identity\Application\Authentication\AuthenticatedPrincipal;
use Kumwe\App\Shared\Infrastructure\Configuration\Environment;
use Kumwe\App\Tests\Support\NeutralBusinessFixture;
use Kumwe\App\Tests\Support\TestKernelFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

#[CoversClass(GeneratedBusinessBrowserController::class)]
#[CoversClass(BusinessRecordService::class)]
/**
 * Proves a refused generated-business save never costs the operator what they typed.
 *
 * Both surfaces are exercised because both mount the same shared controller and both are reachable
 * by a person filling in a long form: an administrator editing a document, and a portal user editing
 * their own record. The two recoverable failures — a value the definition rejects, and a save that
 * lost the optimistic-concurrency race — are asserted to return the submitted values rather than an
 * error page, and the conflict is additionally asserted to leave the newer record untouched.
 *
 * @since  2.0.0
 */
final class GeneratedBusinessDataEntryRetentionTest extends TestCase
{
    /**
     * The one booted kernel every test in this class shares.
     *
     * Each boot migrates and re-materializes the extension runtime, and this class installs several
     * definitions, so booting per test moves the runtime generation under the next boot. One kernel per
     * process keeps that machinery out of what is being proved here.
     *
     * @var     ?Container
     *
     * @since   2.0.0
     */
    private static ?Container $kernel = null;

    /**
     * Release the shared kernel so a later suite in the same process boots its own.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public static function tearDownAfterClass(): void
    {
        self::$kernel = null;
    }

    /**
     * Boot the real kernel once per process and hand every test the same container.
     *
     * @return  Container  Fully composed application container.
     *
     * @since   2.0.0
     */
    private function kernel(): Container
    {
        return self::$kernel ??= TestKernelFactory::create(Environment::fromGlobals());
    }

    /**
     * Prove both browser surfaces retain typed values through validation failure and version conflict.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testBothBrowserSurfacesRetainSubmittedValuesOnEveryRecoverableFailure(): void
    {
        $container = $this->kernel();
        $installer = TestKernelFactory::administratorContext($container);
        $principal = $installer->principal();
        self::assertInstanceOf(AuthenticatedPrincipal::class, $principal);
        $suffix = strtolower(substr(str_replace('-', '', Uuid::uuid7()->toString()), -10));
        $document = NeutralBusinessFixture::document('retention' . $suffix, Uuid::uuid7()->toString());
        $document['portal_exposure'] = true;
        $document['portal_operations'] = array_map(
            static fn (PortalOperation $operation): string => $operation->value,
            PortalOperation::cases(),
        );
        $definition = NeutralBusinessFixture::install($container, $installer, $document)->handle;
        $records = $this->service($container, BusinessRecordService::class);
        $browser = $this->service($container, GeneratedBusinessBrowserController::class);

        foreach (
            [
                [BusinessSurface::Administrator, '/administrator/business'],
                [BusinessSurface::Portal, '/portal/business'],
            ] as [$surface, $basePath]
        ) {
            $record = Uuid::uuid7()->toString();
            $records->create(new CreateRecordCommand(
                $installer,
                $definition,
                NeutralBusinessFixture::recordValues('Retention ' . $surface->value . ' ' . $suffix),
                NeutralBusinessFixture::idempotencyKey('retention-' . $surface->value . '-' . $suffix),
                recordId: $record,
            ));
            $context = $this->context($principal, $surface, $suffix);
            $typed = 'Retention typed ' . $surface->value . ' ' . $suffix;

            $rejected = $browser->dispatch(
                $context,
                $surface,
                $basePath,
                'POST',
                $definition,
                $record,
                [],
                [
                    'operation' => 'update',
                    'operation_id' => 'retention-invalid-' . $surface->value . '-' . $suffix,
                    'expected_version' => '1',
                    'values' => ['name' => 'x', 'evolution_code' => $typed],
                ],
            );

            self::assertSame('business-form', $rejected->template);
            self::assertSame(422, $rejected->status);
            self::assertSame('x', $this->inputValue($rejected, 'name'));
            self::assertSame($typed, $this->inputValue($rejected, 'evolution_code'));
            self::assertArrayNotHasKey('version_conflict', $rejected->data);

            // A second writer moves the record on while the form above is still open.
            $records->update(new UpdateRecordCommand(
                $installer,
                $definition,
                $record,
                1,
                ['evolution_code' => 'MOVED-BY-ANOTHER-WRITER'],
                NeutralBusinessFixture::idempotencyKey('retention-race-' . $surface->value . '-' . $suffix),
            ));

            $conflicted = $browser->dispatch(
                $context,
                $surface,
                $basePath,
                'POST',
                $definition,
                $record,
                [],
                [
                    'operation' => 'update',
                    'operation_id' => 'retention-conflict-' . $surface->value . '-' . $suffix,
                    'expected_version' => '1',
                    'values' => ['name' => 'Retention kept ' . $surface->value . ' ' . $suffix,
                        'evolution_code' => $typed],
                ],
            );

            self::assertSame('business-form', $conflicted->template);
            self::assertSame(409, $conflicted->status);
            $conflict = $conflicted->data['version_conflict'] ?? null;
            self::assertIsArray($conflict);
            self::assertSame(1, $conflict['expected_version'] ?? null);
            self::assertIsString($conflict['summary'] ?? null);
            self::assertStringContainsString('nothing you submitted was written', $conflict['summary']);
            self::assertSame(
                'Retention kept ' . $surface->value . ' ' . $suffix,
                $this->inputValue($conflicted, 'name'),
            );
            self::assertSame($typed, $this->inputValue($conflicted, 'evolution_code'));

            // The newer record survived the refused save, and the form now quotes its version.
            $current = $conflicted->data['record'] ?? null;
            self::assertIsArray($current);
            self::assertSame(2, $current['version'] ?? null);
            $stored = $records->read(new ReadRecordQuery($installer, $definition, $record));
            self::assertSame('MOVED-BY-ANOTHER-WRITER', $stored->values['evolution_code'] ?? null);
        }
    }

    /**
     * Prove a hundred-line owned document loses neither a typed value nor a line on either failure.
     *
     * A long document is where the defect actually hurt, so the size is part of the assertion rather
     * than a detail: the owner's own fields come back as submitted, and every one of the hundred lines
     * hanging off it is still attached afterwards, because neither refused save wrote anything.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAHundredLineDocumentLosesNoLineOnEitherFailurePath(): void
    {
        $container = $this->kernel();
        $installer = TestKernelFactory::administratorContext($container);
        $principal = $installer->principal();
        self::assertInstanceOf(AuthenticatedPrincipal::class, $principal);
        $suffix = strtolower(substr(str_replace('-', '', Uuid::uuid7()->toString()), -10));
        $target = NeutralBusinessFixture::install(
            $container,
            $installer,
            NeutralBusinessFixture::relationTargetDocument($suffix, Uuid::uuid7()->toString()),
        );
        $line = NeutralBusinessFixture::install(
            $container,
            $installer,
            NeutralBusinessFixture::ownedLineDocument($suffix, Uuid::uuid7()->toString()),
        );
        $document = NeutralBusinessFixture::relationshipOwnerDocument(
            $suffix,
            Uuid::uuid7()->toString(),
            $target->handle,
            $line->handle,
        );
        // A rule the operator can break by typing, so the validation path is reachable from the form.
        $document['fields'][1]['validators'] = [['rule' => 'min_length', 'value' => 5]];
        $owner = NeutralBusinessFixture::install($container, $installer, $document)->handle;
        $records = $this->service($container, BusinessRecordService::class);
        $browser = $this->service($container, GeneratedBusinessBrowserController::class);
        $context = $this->context($principal, BusinessSurface::Administrator, $suffix);
        $record = Uuid::uuid7()->toString();
        $records->create(new CreateRecordCommand(
            $installer,
            $owner,
            ['title' => 'Hundred line document ' . $suffix],
            NeutralBusinessFixture::idempotencyKey('hundred-owner-' . $suffix),
            recordId: $record,
        ));
        $version = 1;
        for ($index = 0; $index < 100; $index++) {
            $version = $records->relate(new RelateRecordsCommand(
                $installer,
                $owner,
                $record,
                $version,
                'lines',
                Uuid::uuid7()->toString(),
                NeutralBusinessFixture::idempotencyKey('hundred-relate-' . $index . '-' . $suffix),
                $index,
                targetValues: ['description' => 'Line ' . $index, 'units' => '1.000'],
            ))->version;
        }
        self::assertSame(101, $version);
        self::assertSame(100, $this->lineCount($records, $installer, $owner, $record));

        $rejected = $browser->dispatch(
            $context,
            BusinessSurface::Administrator,
            '/administrator/business',
            'POST',
            $owner,
            $record,
            [],
            [
                'operation' => 'update',
                'operation_id' => 'hundred-invalid-' . $suffix,
                'expected_version' => (string) $version,
                'values' => ['title' => 'tiny'],
            ],
        );

        self::assertSame(422, $rejected->status);
        self::assertSame('tiny', $this->inputValue($rejected, 'title'));
        self::assertSame(100, $this->lineCount($records, $installer, $owner, $record));

        $records->update(new UpdateRecordCommand(
            $installer,
            $owner,
            $record,
            $version,
            ['title' => 'Moved by another writer ' . $suffix],
            NeutralBusinessFixture::idempotencyKey('hundred-race-' . $suffix),
        ));

        $conflicted = $browser->dispatch(
            $context,
            BusinessSurface::Administrator,
            '/administrator/business',
            'POST',
            $owner,
            $record,
            [],
            [
                'operation' => 'update',
                'operation_id' => 'hundred-conflict-' . $suffix,
                'expected_version' => (string) $version,
                'values' => ['title' => 'Rewritten by the operator ' . $suffix],
            ],
        );

        self::assertSame(409, $conflicted->status);
        self::assertSame(
            'Rewritten by the operator ' . $suffix,
            $this->inputValue($conflicted, 'title'),
        );
        self::assertSame(100, $this->lineCount($records, $installer, $owner, $record));
    }

    /**
     * Count the owned lines still attached to one document.
     *
     * @param   BusinessRecordService  $records     Canonical record service.
     * @param   ExecutionContext       $context     Installer context with full read access.
     * @param   string                 $definition  Owner definition handle.
     * @param   string                 $record      Owner record identity.
     *
     * @return  int  Number of lines the `lines` relationship currently resolves to.
     *
     * @since   2.0.0
     */
    private function lineCount(
        BusinessRecordService $records,
        ExecutionContext $context,
        string $definition,
        string $record,
    ): int {
        $view = $records->read(new ReadRecordQuery(
            $context,
            $definition,
            $record,
            includes: ['lines'],
        ));

        return count($view->includes['lines'] ?? []);
    }

    /**
     * Prove an operation carrying nothing typed still fails closed instead of pretending to save.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAConfirmationOperationWithNothingToRetainStillFailsClosed(): void
    {
        $container = $this->kernel();
        $installer = TestKernelFactory::administratorContext($container);
        $principal = $installer->principal();
        self::assertInstanceOf(AuthenticatedPrincipal::class, $principal);
        $suffix = strtolower(substr(str_replace('-', '', Uuid::uuid7()->toString()), -10));
        $definition = NeutralBusinessFixture::install(
            $container,
            $installer,
            NeutralBusinessFixture::document('closed' . $suffix, Uuid::uuid7()->toString()),
        )->handle;
        $records = $this->service($container, BusinessRecordService::class);
        $browser = $this->service($container, GeneratedBusinessBrowserController::class);
        $record = Uuid::uuid7()->toString();
        $records->create(new CreateRecordCommand(
            $installer,
            $definition,
            NeutralBusinessFixture::recordValues('Closed ' . $suffix),
            NeutralBusinessFixture::idempotencyKey('closed-create-' . $suffix),
            recordId: $record,
        ));

        $this->expectException(BusinessRecordVersionConflict::class);
        $browser->dispatch(
            $this->context($principal, BusinessSurface::Administrator, $suffix),
            BusinessSurface::Administrator,
            '/administrator/business',
            'POST',
            $definition,
            $record,
            [],
            [
                'operation' => 'archive',
                'operation_id' => 'closed-archive-' . $suffix,
                'expected_version' => '9',
                'confirmed' => '1',
            ],
        );
    }

    /**
     * Read one presented field's retained input value out of a rendered form model.
     *
     * @param   BusinessBrowserResult  $result  Result whose `fields` list is being inspected.
     * @param   string                 $handle  Field handle to read.
     *
     * @return  mixed  The `input_value` the form would render into that field's control.
     *
     * @since   2.0.0
     */
    private function inputValue(BusinessBrowserResult $result, string $handle): mixed
    {
        $fields = $result->data['fields'] ?? null;
        self::assertIsArray($fields);
        foreach ($fields as $field) {
            if (is_array($field) && ($field['handle'] ?? null) === $handle) {
                return $field['input_value'] ?? null;
            }
        }
        self::fail(sprintf('The re-rendered form carries no %s field.', $handle));
    }

    /**
     * Build the execution context the named browser surface authenticates its operator with.
     *
     * @param   AuthenticatedPrincipal  $principal  Shared administrator principal.
     * @param   BusinessSurface         $surface    Administrator or portal boundary.
     * @param   string                  $suffix     Run-unique correlation suffix.
     *
     * @return  ExecutionContext  Password-strength context bound to that exact surface.
     *
     * @since   2.0.0
     */
    private function context(
        AuthenticatedPrincipal $principal,
        BusinessSurface $surface,
        string $suffix,
    ): ExecutionContext {
        return $principal->context(
            SiteContext::default(),
            AuthenticationStrength::Password,
            'retention-' . $surface->value . '-' . $suffix,
            surface: $surface === BusinessSurface::Portal
                ? AuthenticatedSurface::Portal
                : AuthenticatedSurface::Administrator,
        );
    }

    /**
     * Resolve one container service, failing the test rather than the type checker.
     *
     * @template  T of object
     *
     * @param   Container         $container  Real application container.
     * @param   class-string<T>   $class      Service identifier to resolve.
     *
     * @return  T  The resolved service.
     *
     * @since   2.0.0
     */
    private function service(Container $container, string $class): object
    {
        $service = $container->get($class);
        self::assertInstanceOf($class, $service);

        return $service;
    }
}
