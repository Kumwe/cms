<?php

/**
 * Repeat the integration suite in ordinary and reverse class order against one used database.
 *
 * The suite was not idempotent: running it again against the database the first run left behind exposed
 * state a previous class installed. That was `V2-QA-004`. The recorded reproductions are now removed, the
 * baseline is empty, and this check enforces the repaired property on every change.
 *
 * A reproduction that simply fails is useless — it blocks every pull request and reports the same thing
 * every time — and a reproduction that is allowed to fail is not a gate. Any future exception would have to
 * be recorded in `docs/quality/idempotency-baseline.json` with the pass that observed it, an owner, an expiry
 * and a removal condition. This tool fails on anything outside that pass-aware record, on a stale entry and
 * on an entry that outlives its expiry. Gate A requires the record to remain empty.
 *
 * Which passes are enforced is declared in the baseline rather than here, so changing the sequence is a
 * recorded decision. The ordinary gate runs `repeat` and then `reverse`; the latter uses a generated PHPUnit
 * configuration that reverses class files while leaving declaration order inside each class alone. Together
 * with the complete suite run that precedes this tool in CI, they prove three consecutive runs against one
 * database. Integration fixture shutdown withdraws each process's run-unique definitions and installations
 * before the next pass starts, retaining only a bounded explicit set of shared replay fixtures and diagnostic
 * history.
 *
 * Usage:
 *   php tools/verify-suite-idempotency.php --engine=mariadb|mysql|pgsql|postgresql
 *   php tools/verify-suite-idempotency.php --engine=ID --pass=repeat|reverse
 *   php tools/verify-suite-idempotency.php --engine=ID --expected-tests=COUNT \
 *       --junit=repeat:PATH --status=repeat:STATUS
 *
 * The second form judges results that were collected elsewhere, which is how the architecture suite proves
 * this check fails in the right direction without needing a database.
 *
 * @since  2.0.0
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$baselinePath = $root . '/docs/quality/idempotency-baseline.json';
$engine = null;
$today = date('Y-m-d');
$supplied = [];
$suppliedStatuses = [];
$expectedTests = null;
$startup = [];
$only = null;

foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--engine=')) {
        $engine = substr($argument, strlen('--engine='));
        continue;
    }
    if (str_starts_with($argument, '--baseline=')) {
        $baselinePath = substr($argument, strlen('--baseline='));
        continue;
    }
    if (str_starts_with($argument, '--today=')) {
        $today = substr($argument, strlen('--today='));
        continue;
    }
    if (str_starts_with($argument, '--pass=')) {
        $only = substr($argument, strlen('--pass='));
        continue;
    }
    if (str_starts_with($argument, '--expected-tests=')) {
        $candidate = substr($argument, strlen('--expected-tests='));
        $expectedTests = ctype_digit($candidate) ? (int) $candidate : null;
        if ($expectedTests === null || $expectedTests < 1) {
            $startup[] = '--expected-tests must be a positive integer.';
        }
        continue;
    }
    if ($argument === '--emit-reverse-configuration') {
        fwrite(STDOUT, reversedClassOrderConfiguration($root) . "\n");
        exit(0);
    }
    if (str_starts_with($argument, '--junit=')) {
        $value = substr($argument, strlen('--junit='));
        $separator = strpos($value, ':');
        if ($separator === false) {
            $startup[] = sprintf('--junit expects PASS:PATH, and %s has no pass name.', $value);
            continue;
        }
        $pass = substr($value, 0, $separator);
        $path = substr($value, $separator + 1);
        if ($pass === '' || $path === '') {
            $startup[] = sprintf('--junit expects non-empty PASS:PATH evidence, not %s.', $value);
            continue;
        }
        if (array_key_exists($pass, $supplied)) {
            $startup[] = sprintf('JUnit evidence for pass %s was supplied more than once.', $pass);
            continue;
        }
        $supplied[$pass] = $path;
        continue;
    }
    if (str_starts_with($argument, '--status=')) {
        $value = substr($argument, strlen('--status='));
        $separator = strpos($value, ':');
        if ($separator === false) {
            $startup[] = sprintf('--status expects PASS:STATUS, and %s has no pass name.', $value);
            continue;
        }
        $pass = substr($value, 0, $separator);
        $status = substr($value, $separator + 1);
        if ($pass === '' || !ctype_digit($status)) {
            $startup[] = sprintf('--status expects PASS:STATUS with a non-negative integer, not %s.', $value);
            continue;
        }
        if (array_key_exists($pass, $suppliedStatuses)) {
            $startup[] = sprintf('Runner status for pass %s was supplied more than once.', $pass);
            continue;
        }
        $suppliedStatuses[$pass] = (int) $status;
        continue;
    }

    $startup[] = sprintf('Unknown argument %s.', $argument);
}

if ($engine === null || $engine === '') {
    $startup[] = 'This check needs --engine=ID naming the engine the suite just ran against.';
}
if (($supplied !== [] || $suppliedStatuses !== []) && ($expectedTests === null || $expectedTests < 1)) {
    $startup[] = 'Supplied JUnit evidence needs --expected-tests=COUNT from the suite collection.';
}
if ($supplied === [] && $suppliedStatuses !== []) {
    $startup[] = 'Supplied runner statuses need matching --junit=PASS:PATH evidence.';
}
if ($startup !== []) {
    reportIdempotencyFailure($startup);
}

/** @var string $engine */
$engine = normalizeEngine($engine);
$baseline = readIdempotencyBaseline($baselinePath);
$declaredPasses = declaredPasses($baseline);
$knownPasses = knownPasses($baseline, $declaredPasses);
if ($only !== null && !in_array($only, $knownPasses, true)) {
    reportIdempotencyFailure([sprintf(
        'Pass "%s" is not declared by the idempotency baseline.',
        $only,
    )]);
}
$entries = readIdempotencyEntries($baseline, $engine, $today, $knownPasses);
// The enforced passes come from the baseline, so turning one on is a decision recorded there rather than a
// flag somebody remembered to add. --pass narrows an investigative run to one declared pass without changing
// what the ordinary gate enforces.
$passes = $only === null ? $declaredPasses : [$only];

$observed = $supplied === []
    ? executePasses($root, $passes)
    : readSuppliedResults($supplied, $suppliedStatuses, $passes, (int) $expectedTests);

exit(judge($entries, $observed, $engine, $passes));

/**
 * Reduce a driver name to the engine identifier the baseline uses.
 *
 * @param   string  $engine  Driver or engine name as the workflow spells it.
 *
 * @return  string  The engine identifier.
 *
 * @since   2.0.0
 */
function normalizeEngine(string $engine): string
{
    return match ($engine) {
        'pgsql', 'postgres' => 'postgresql',
        default => $engine,
    };
}

/**
 * Read the baseline document, refusing anything that is not a decodable object.
 *
 * @param   string  $path  Absolute path to the baseline.
 *
 * @return  array<string, mixed>  The decoded baseline.
 *
 * @since   2.0.0
 */
function readIdempotencyBaseline(string $path): array
{
    $raw = is_file($path) ? file_get_contents($path) : false;
    /** @var mixed $decoded */
    $decoded = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($decoded)) {
        reportIdempotencyFailure([sprintf('%s is missing or is not well-formed JSON.', $path)]);
    }

    /** @var array<string, mixed> $decoded */
    return $decoded;
}

/**
 * Read the passes the baseline declares.
 *
 * @param   array<string, mixed>  $baseline  Decoded baseline.
 *
 * @return  list<string>  Pass names, in execution order.
 *
 * @since   2.0.0
 */
function declaredPasses(array $baseline): array
{
    /** @var mixed $scope */
    $scope = $baseline['scope'] ?? null;
    /** @var mixed $passes */
    $passes = is_array($scope) ? ($scope['enforced_passes'] ?? null) : null;
    if (!is_array($passes) || !array_is_list($passes) || $passes === []) {
        reportIdempotencyFailure(['The baseline must declare scope.enforced_passes.']);
    }

    $names = [];
    foreach ($passes as $pass) {
        if (!is_string($pass) || $pass === '') {
            reportIdempotencyFailure(['Every declared pass must be a non-empty string.']);
        }
        $names[] = $pass;
    }

    return $names;
}

/**
 * Read every pass the baseline recognizes, including an explicitly pending measurement when one exists.
 *
 * A pending exploratory pass still needs a declared identity so a misspelled `--pass` cannot silently execute
 * the ordinary suite order under a name nobody governs. Once no pending pass remains, this returns only the
 * enforced sequence.
 *
 * @param   array<string, mixed>  $baseline  Decoded baseline.
 * @param   list<string>          $enforced  Validated enforced passes.
 *
 * @return  list<string>  Enforced passes followed by the pending pass, when one is declared.
 *
 * @since   2.0.0
 */
function knownPasses(array $baseline, array $enforced): array
{
    /** @var mixed $scope */
    $scope = $baseline['scope'] ?? null;
    /** @var mixed $pending */
    $pending = is_array($scope) ? ($scope['not_yet_enforced'] ?? null) : null;
    if ($pending === null) {
        return $enforced;
    }
    if (!is_array($pending) || !is_string($pending['pass'] ?? null) || $pending['pass'] === '') {
        reportIdempotencyFailure(['scope.not_yet_enforced must name one non-empty pass.']);
    }

    /** @var string $pass */
    $pass = $pending['pass'];
    if (in_array($pass, $enforced, true)) {
        reportIdempotencyFailure([sprintf(
            'Pass "%s" cannot be both enforced and declared as not yet enforced.',
            $pass,
        )]);
    }

    return [...$enforced, $pass];
}

/**
 * Read and validate the baseline entries, refusing an exemption nobody owns or one that has expired.
 *
 * @param   array<string, mixed>  $baseline  Decoded baseline.
 * @param   string                $engine    Engine the suite just ran against.
 * @param   string                $today     Date the expiries are judged against, as `Y-m-d`.
 * @param   list<string>          $passes    Pass identities the baseline is allowed to record.
 *
 * @return  array<string, array{observed: bool, applies: bool, passes: list<string>}>  Entries by test identifier.
 *
 * @since   2.0.0
 */
function readIdempotencyEntries(array $baseline, string $engine, string $today, array $passes): array
{
    /** @var mixed $declared */
    $declared = $baseline['entries'] ?? null;
    if (!is_array($declared) || !array_is_list($declared)) {
        reportIdempotencyFailure(['The baseline must declare "entries" as an array.']);
    }

    $errors = [];
    $entries = [];
    foreach ($declared as $index => $entry) {
        if (!is_array($entry)) {
            $errors[] = sprintf('Baseline entry at position %d is not an object.', $index);
            continue;
        }
        $test = $entry['test'] ?? null;
        if (!is_string($test) || $test === '') {
            $errors[] = sprintf('Baseline entry at position %d names no test.', $index);
            continue;
        }
        $entryPasses = $entry['passes'] ?? null;
        $validatedPasses = [];
        if (!is_array($entryPasses) || !array_is_list($entryPasses) || $entryPasses === []) {
            $errors[] = sprintf('Baseline entry %s must name a non-empty "passes" list.', $test);
        } else {
            foreach ($entryPasses as $pass) {
                if (!is_string($pass) || !in_array($pass, $passes, true)) {
                    $errors[] = sprintf(
                        'Baseline entry %s names an unknown pass "%s".',
                        $test,
                        is_scalar($pass) ? (string) $pass : 'invalid',
                    );
                    continue;
                }
                $validatedPasses[] = $pass;
            }
            if (count($validatedPasses) !== count(array_unique($validatedPasses))) {
                $errors[] = sprintf('Baseline entry %s names a pass more than once.', $test);
            }
        }
        foreach (['owner', 'finding', 'removal'] as $field) {
            $value = $entry[$field] ?? null;
            if (is_string($value) && trim($value) !== '' && $value !== 'UNASSIGNED') {
                continue;
            }
            $errors[] = sprintf(
                'Baseline entry %s needs a non-empty "%s". An exemption nobody owns, that names no finding, '
                . 'or that does not say what removing it takes, is a permission.',
                $test,
                $field,
            );
        }
        $expires = $entry['expires'] ?? null;
        if (!is_string($expires) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $expires) !== 1) {
            $errors[] = sprintf('Baseline entry %s needs an expiry date as YYYY-MM-DD.', $test);
        } elseif ($expires < $today) {
            $errors[] = sprintf(
                'Baseline entry %s expired on %s. Make the test idempotent or record a new decision; an '
                . 'exemption does not outlive the work that justified it.',
                $test,
                $expires,
            );
        }

        $entries[$test] = [
            'observed' => in_array($engine, engineList($entry, 'observed_on'), true),
            'applies' => in_array($engine, engineList($entry, 'applies_to'), true),
            'passes' => $validatedPasses,
        ];
    }

    if ($errors !== []) {
        reportIdempotencyFailure($errors);
    }

    return $entries;
}

/**
 * Read one of an entry's engine lists.
 *
 * @param   array<string, mixed>  $entry  A baseline entry.
 * @param   string                $field  Either `observed_on` or `applies_to`.
 *
 * @return  list<string>  Engine identifiers.
 *
 * @since   2.0.0
 */
function engineList(array $entry, string $field): array
{
    /** @var mixed $value */
    $value = $entry[$field] ?? null;
    if (!is_array($value) || !array_is_list($value)) {
        return [];
    }

    $engines = [];
    foreach ($value as $engine) {
        if (is_string($engine) && $engine !== '') {
            $engines[] = normalizeEngine($engine);
        }
    }

    return $engines;
}

/**
 * Run every selected pass and collect the tests that failed in each.
 *
 * Every enforced pass runs by default, and `--pass` narrows an investigative execution to one of them. Every
 * selected pass runs even after an earlier one fails so the resulting evidence is complete. The preceding
 * full suite owns coverage; these passes disable PCOV's executor hooks as well as coverage reporting so
 * an enabled coverage extension does not instrument repeated database work whose coverage is not consumed.
 *
 * @param   string        $root    Repository root.
 * @param   list<string>  $passes  Declared pass names.
 *
 * @return  array<string, array{failures: list<string>, tests: int, status: int}>  Complete result by pass.
 *
 * @since   2.0.0
 */
function executePasses(string $root, array $passes): array
{
    $observed = [];
    $generated = null;
    $expectedTests = expectedIntegrationTestCount($root);
    foreach ($passes as $pass) {
        $log = sprintf('%s/build/idempotency/%s.junit.xml', $root, $pass);
        @mkdir(dirname($log), 0o755, true);
        $configuration = null;
        if ($pass === 'reverse') {
            $configuration = $generated = reversedClassOrderConfiguration($root);
        }
        $command = sprintf(
            'cd %s && %s -d pcov.enabled=0 vendor/bin/phpunit --no-coverage '
            . '--testsuite integration --colors=never --log-junit %s%s',
            escapeshellarg($root),
            escapeshellarg(PHP_BINARY),
            escapeshellarg($log),
            $configuration === null ? '' : ' --configuration ' . escapeshellarg($configuration),
        );

        fwrite(STDOUT, sprintf("== idempotency pass \"%s\"\n", $pass));
        $status = 0;
        passthru($command, $status);

        if ($generated !== null) {
            @unlink($generated);
            $generated = null;
        }

        if (!is_file($log)) {
            reportIdempotencyFailure([sprintf(
                'The "%s" pass produced no JUnit report (exit %d), so nothing can be judged. That is a broken '
                . 'run rather than a known non-idempotent test, and it fails here on purpose.',
                $pass,
                $status,
            )]);
        }
        $observed[$pass] = readJUnitResult($log, $expectedTests, $status);
    }

    return $observed;
}

/**
 * Ask PHPUnit how many integration tests the current tree collects before judging any execution report.
 *
 * A report's own `tests` attribute cannot prove completeness because a truncated producer can truncate
 * the summary with the cases. Collection is an independent source of truth and must match every pass.
 *
 * @param   string  $root  Repository root containing PHPUnit and its configuration.
 *
 * @return  int  Number of integration tests PHPUnit collected.
 *
 * @since   2.0.0
 */
function expectedIntegrationTestCount(string $root): int
{
    $command = sprintf(
        'cd %s && %s -d pcov.enabled=0 vendor/bin/phpunit --no-coverage '
        . '--testsuite integration --list-tests --colors=never',
        escapeshellarg($root),
        escapeshellarg(PHP_BINARY),
    );
    $lines = [];
    $status = 0;
    exec($command . ' 2>&1', $lines, $status);
    if ($status !== 0) {
        reportIdempotencyFailure([sprintf(
            'PHPUnit could not collect the integration suite before the run (exit %d).',
            $status,
        )]);
    }

    $tests = 0;
    foreach ($lines as $line) {
        if (preg_match('/^\s*-\s+\S+::\S+/', $line) === 1) {
            $tests++;
        }
    }
    if ($tests === 0) {
        reportIdempotencyFailure(['PHPUnit collected no integration tests, so execution cannot be judged.']);
    }

    return $tests;
}

/**
 * Write a configuration that runs the integration suite with its classes in reverse order.
 *
 * The property the roadmap states is that the suite gives the same result "in a different class order".
 * `--order-by=reverse` is not that: it reverses the order of the individual tests as well, so a class whose
 * methods are written to run in declaration order fails for a reason that has nothing to do with a reused
 * database. The first execution of this check made exactly that mistake and reported 38 failures, most of
 * them several methods deep inside one class.
 *
 * PHPUnit runs the `<file>` entries of a test suite in the order they are listed, so listing the classes
 * backwards varies class order and leaves the order of methods inside each class alone. The configuration is
 * written beside `phpunit.xml.dist` so that its relative bootstrap, cache and source paths keep resolving,
 * and it is removed as soon as the pass has run.
 *
 * @param   string  $root  Repository root.
 *
 * @return  string  Absolute path to the generated configuration.
 *
 * @since   2.0.0
 */
function reversedClassOrderConfiguration(string $root): string
{
    $files = [];
    /** @var iterable<string, SplFileInfo> $found */
    $found = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root . '/tests/Integration', FilesystemIterator::SKIP_DOTS),
    );
    foreach ($found as $file) {
        if (str_ends_with($file->getFilename(), 'Test.php')) {
            $files[] = $file->getPathname();
        }
    }

    if ($files === []) {
        reportIdempotencyFailure(['The integration suite has no test classes, so a class order cannot be varied.']);
    }

    sort($files, SORT_STRING);
    $files = array_reverse($files);

    $entries = '';
    foreach ($files as $file) {
        $entries .= sprintf("            <file>%s</file>\n", htmlspecialchars($file, ENT_XML1));
    }

    $template = file_get_contents($root . '/phpunit.xml.dist');
    if ($template === false) {
        reportIdempotencyFailure(['phpunit.xml.dist could not be read.']);
    }

    $replaced = preg_replace(
        '#<testsuite name="integration">.*?</testsuite>#s',
        sprintf("<testsuite name=\"integration\">\n%s        </testsuite>", $entries),
        $template,
        1,
        $count,
    );
    if (!is_string($replaced) || $count !== 1) {
        reportIdempotencyFailure(['phpunit.xml.dist declares no "integration" test suite to reorder.']);
    }

    $path = $root . '/.phpunit.idempotency.xml';
    if (file_put_contents($path, $replaced) === false) {
        reportIdempotencyFailure([sprintf('The reversed configuration could not be written to %s.', $path)]);
    }

    return $path;
}

/**
 * Read results collected elsewhere.
 *
 * @param   array<string, string>  $supplied       Report path by pass name.
 * @param   array<string, int>     $statuses       PHPUnit exit status by pass name.
 * @param   list<string>           $passes         Declared pass names.
 * @param   int                    $expectedTests  Independent suite-collection count.
 *
 * @return  array<string, array{failures: list<string>, tests: int, status: int}>  Complete result by pass.
 *
 * @since   2.0.0
 */
function readSuppliedResults(array $supplied, array $statuses, array $passes, int $expectedTests): array
{
    $missingReports = array_values(array_diff($passes, array_keys($supplied)));
    $missingStatuses = array_values(array_diff($passes, array_keys($statuses)));
    $unknownReports = array_values(array_diff(array_keys($supplied), $passes));
    $unknownStatuses = array_values(array_diff(array_keys($statuses), $passes));
    $errors = [];
    if ($missingReports !== []) {
        $errors[] = 'Supplied evidence is missing report(s) for: ' . implode(', ', $missingReports) . '.';
    }
    if ($missingStatuses !== []) {
        $errors[] = 'Supplied evidence is missing runner status(es) for: ' . implode(', ', $missingStatuses) . '.';
    }
    if ($unknownReports !== [] || $unknownStatuses !== []) {
        $unknown = array_values(array_unique(array_merge($unknownReports, $unknownStatuses)));
        $errors[] = 'Supplied evidence names undeclared pass(es): ' . implode(', ', $unknown) . '.';
    }
    if ($errors !== []) {
        reportIdempotencyFailure($errors);
    }

    $observed = [];
    foreach ($passes as $pass) {
        $path = $supplied[$pass];
        if (!is_file($path)) {
            reportIdempotencyFailure([sprintf('The "%s" pass report %s does not exist.', $pass, $path)]);
        }
        $observed[$pass] = readJUnitResult($path, $expectedTests, $statuses[$pass]);
    }

    return $observed;
}

/**
 * Read and validate one complete JUnit execution report.
 *
 * @param   string  $path           Absolute path to a JUnit report.
 * @param   int     $expectedTests  Test count independently collected before execution.
 * @param   int     $status         PHPUnit process exit status for this report.
 *
 * @return  array{failures: list<string>, tests: int, status: int}  Validated execution evidence.
 *
 * @since   2.0.0
 */
function readJUnitResult(string $path, int $expectedTests, int $status): array
{
    $previous = libxml_use_internal_errors(true);
    $document = simplexml_load_file($path);
    libxml_use_internal_errors($previous);
    if ($document === false) {
        reportIdempotencyFailure([sprintf('%s is not readable XML.', $path)]);
    }

    $cases = $document->xpath('//testcase') ?: [];
    $tests = count($cases);
    $errors = [];
    if ($tests !== $expectedTests) {
        $errors[] = sprintf(
            '%s contains %d testcase(s), but independent collection found %d.',
            $path,
            $tests,
            $expectedTests,
        );
    }

    $summaries = $document->getName() === 'testsuite' ? [$document] : [];
    if ($summaries === []) {
        foreach ($document->children() as $child) {
            if ($child->getName() === 'testsuite') {
                $summaries[] = $child;
            }
        }
    }
    if ($summaries === []) {
        $errors[] = sprintf('%s carries no top-level testsuite summary.', $path);
    }

    $declaredTests = 0;
    $declaredErrors = 0;
    $declaredFailures = 0;
    $declaredSkipped = 0;
    $declaredDiagnostics = 0;
    foreach ($summaries as $summary) {
        foreach (['tests', 'errors', 'failures'] as $attribute) {
            $value = (string) ($summary[$attribute] ?? '');
            if ($value === '' || !ctype_digit($value)) {
                $errors[] = sprintf('%s has no non-negative %s total.', $path, $attribute);
                continue 2;
            }
        }
        $declaredTests += (int) $summary['tests'];
        $declaredErrors += (int) $summary['errors'];
        $declaredFailures += (int) $summary['failures'];
        $skipped = (string) ($summary['skipped'] ?? '0');
        if (!ctype_digit($skipped)) {
            $errors[] = sprintf('%s has a malformed skipped total.', $path);
        } else {
            $declaredSkipped += (int) $skipped;
        }
        foreach (['warnings', 'risky', 'deprecations', 'notices'] as $attribute) {
            $value = (string) ($summary[$attribute] ?? '0');
            if (!ctype_digit($value)) {
                $errors[] = sprintf('%s has a malformed %s total.', $path, $attribute);
                continue;
            }
            $declaredDiagnostics += (int) $value;
        }
    }

    $failing = [];
    $actualErrors = 0;
    $actualFailures = 0;
    $actualSkipped = 0;
    foreach ($cases as $case) {
        // SimpleXML hands back an empty element rather than null for a child that is not there, so a
        // self-closing <testcase/> would read as failing if this asked for null.
        $class = (string) ($case['class'] ?? '');
        if ($class === '') {
            $class = str_replace('.', '\\', (string) ($case['classname'] ?? ''));
        }
        $name = (string) ($case['name'] ?? '');
        if ($class === '' || $name === '') {
            $errors[] = sprintf('%s contains a testcase without a class and method identifier.', $path);
            continue;
        }
        $hasError = isset($case->error);
        $hasFailure = isset($case->failure);
        $hasSkipped = isset($case->skipped);
        $actualErrors += $hasError ? 1 : 0;
        $actualFailures += $hasFailure ? 1 : 0;
        $actualSkipped += $hasSkipped ? 1 : 0;
        if (!$hasError && !$hasFailure) {
            continue;
        }
        $identifier = $class . '::' . $name;
        if (!in_array($identifier, $failing, true)) {
            $failing[] = $identifier;
        }
    }

    if ($declaredTests !== $tests || $declaredErrors !== $actualErrors || $declaredFailures !== $actualFailures) {
        $errors[] = sprintf(
            '%s summary says %d test(s), %d error(s), and %d failure(s), but contains %d, %d, and %d.',
            $path,
            $declaredTests,
            $declaredErrors,
            $declaredFailures,
            $tests,
            $actualErrors,
            $actualFailures,
        );
    }
    if ($declaredSkipped !== $actualSkipped) {
        $errors[] = sprintf(
            '%s summary says %d skipped test(s), but contains %d skipped testcase outcome(s).',
            $path,
            $declaredSkipped,
            $actualSkipped,
        );
    }
    if (($document->xpath('//testsuite/error') ?: []) !== []) {
        $errors[] = sprintf('%s contains a suite-level runner error not attributable to a testcase.', $path);
    }

    $expectedStatus = $actualErrors > 0 ? 2 : ($actualFailures + $declaredDiagnostics > 0 ? 1 : 0);
    if ($status !== $expectedStatus) {
        $errors[] = sprintf(
            '%s belongs to runner exit %d, but its accounted testcase outcomes and diagnostics require exit %d.',
            $path,
            $status,
            $expectedStatus,
        );
    }
    if ($errors !== []) {
        reportIdempotencyFailure($errors);
    }

    return ['failures' => $failing, 'tests' => $tests, 'status' => $status];
}

/**
 * Compare what the passes observed with what the baseline records.
 *
 * @param   array<string, array{observed: bool, applies: bool, passes: list<string>}>  $entries  Baseline
 *          entries by test.
 * @param   array<string, array{failures: list<string>, tests: int, status: int}>  $observed  Results by pass.
 * @param   string                                               $engine    Engine under test.
 * @param   list<string>                                         $passes    Declared pass names.
 *
 * @return  int  Process exit status: zero when the run matched the record exactly.
 *
 * @since   2.0.0
 */
function judge(array $entries, array $observed, string $engine, array $passes): int
{
    $errors = [];
    $missing = array_values(array_diff($passes, array_keys($observed)));
    $unknown = array_values(array_diff(array_keys($observed), $passes));
    if ($missing !== [] || $unknown !== []) {
        reportIdempotencyFailure([
            sprintf(
                'The judged pass set is incomplete: missing [%s], undeclared [%s].',
                implode(', ', $missing),
                implode(', ', $unknown),
            ),
        ]);
    }
    $failedSomewhere = [];
    $failedByPass = [];
    foreach ($observed as $pass => $result) {
        foreach ($result['failures'] as $test) {
            $failedSomewhere[$test][] = $pass;
            $failedByPass[$pass][$test] = true;
        }
    }

    $unrecorded = [];
    foreach ($failedByPass as $pass => $tests) {
        foreach (array_keys($tests) as $test) {
            $entry = $entries[$test] ?? null;
            if ($entry !== null && $entry['applies'] && in_array($pass, $entry['passes'], true)) {
                continue;
            }
            $unrecorded[] = sprintf(
                '%s (failed in the %s pass on %s)',
                $test,
                $pass,
                $engine,
            );
        }
    }

    if ($unrecorded !== []) {
        sort($unrecorded, SORT_STRING);
        $errors[] = sprintf(
            "%d test(s) failed against a reused database and are not in the baseline:\n     - %s\n"
            . "     Either the change made a test non-idempotent — fix it — or the run found a case the "
            . "record does not carry, in which case add it to docs/quality/idempotency-baseline.json with an "
            . "owner, an expiry and what removing it takes.",
            count($unrecorded),
            implode("\n     - ", $unrecorded),
        );
    }

    $stale = [];
    foreach ($entries as $test => $entry) {
        if (!$entry['observed']) {
            continue;
        }
        foreach ($entry['passes'] as $pass) {
            if (!in_array($pass, $passes, true) || isset($failedByPass[$pass][$test])) {
                continue;
            }
            $stale[] = sprintf('%s (recorded for the %s pass)', $test, $pass);
        }
    }

    if ($stale !== []) {
        sort($stale, SORT_STRING);
        $errors[] = sprintf(
            "These baseline entries record a failure on %s that no longer happens, so they must be deleted — "
            . "the record only ever shrinks:\n     - %s",
            $engine,
            implode("\n     - ", $stale),
        );
    }

    if ($errors !== []) {
        reportIdempotencyFailure($errors);
    }

    fwrite(STDOUT, sprintf(
        "Kumwe suite idempotency verified on %s: %d pass(es) executed, %d recorded non-idempotent test(s), "
        . "nothing new.\n",
        $engine,
        count($observed),
        count($failedSomewhere),
    ));
    foreach ($passes as $pass) {
        if (!array_key_exists($pass, $observed)) {
            continue;
        }
        fwrite(STDOUT, sprintf(
            "  %-8s %d test(s), %d recorded failure(s), runner exit %d\n",
            $pass,
            $observed[$pass]['tests'],
            count($observed[$pass]['failures']),
            $observed[$pass]['status'],
        ));
    }

    return 0;
}

/**
 * Print every failure and terminate with a non-zero status.
 *
 * @param   list<string>  $errors  Validation failures.
 *
 * @return  never
 *
 * @since   2.0.0
 */
function reportIdempotencyFailure(array $errors): never
{
    $errors = array_values(array_unique($errors));
    fwrite(STDERR, "Kumwe suite idempotency check failed:\n");
    foreach ($errors as $error) {
        fwrite(STDERR, ' - ' . $error . "\n");
    }
    exit(1);
}
