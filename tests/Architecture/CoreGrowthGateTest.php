<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Architecture;

use Kumwe\App\Tests\Unit\Governance\GovernanceFixture;
use Kumwe\App\Tools\Governance\CoreGrowthGate;
use Kumwe\App\Tools\Governance\CoreGrowthInventory;
use Kumwe\App\Tools\Governance\LayerClassifier;
use Kumwe\App\Tools\Governance\SchemaValidator;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Proves the Core Growth gate holds for this repository and is registered in every lane that must run it.
 *
 * The committed `docs/architecture/governance/core-growth-baseline.json` records every production class-like,
 * every growth entry it carries cites an approved Core Growth Record that names that symbol, recording it again
 * is byte-identical, the real tree passes the check with no duplicate owner, reintroduction or unrecorded growth,
 * and the check is wired into `composer qa`, the quality contract and both CI steps directly after the
 * capability-index check.
 *
 * @since  2.0.0
 */
#[CoversNothing]
final class CoreGrowthGateTest extends TestCase
{
    /**
     * Repository root.
     *
     * @var    string
     * @since  2.0.0
     */
    private string $root;

    /**
     * Load the governance classes once.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 2) . '/tools/Governance/bootstrap.php';
        require_once dirname(__DIR__) . '/Unit/Governance/GovernanceFixture.php';
    }

    /**
     * Resolve the repository root.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    /**
     * The real repository passes the check through the tool with the counts the committed baseline carries.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheRepositoryPassesTheCheck(): void
    {
        $baseline = (new CoreGrowthGate($this->root))->readBaseline();
        self::assertNotNull($baseline);
        $recorded = 0;
        foreach ($baseline['symbols'] as $symbol) {
            if ($symbol['growth'] !== null) {
                ++$recorded;
            }
        }

        $check = self::runGate([]);
        self::assertSame(0, $check['status'], $check['output']);
        self::assertSame(
            sprintf(
                'Core growth verified (%d production symbols; %d recorded growth entries; no duplicate owners).',
                count($baseline['symbols']),
                $recorded,
            ),
            $check['output'],
        );
    }

    /**
     * The committed baseline records every production declaration: schema-valid, sorted, every `growth` entry
     * citing an approved Core Growth Record that names the symbol, and byte-identical to what recording produces
     * now, twice.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheCommittedBaselineRecordsEveryDeclarationAndIsByteStable(): void
    {
        $committed = file_get_contents($this->root . '/' . CoreGrowthGate::BASELINE_PATH);
        self::assertIsString($committed);
        $decoded = json_decode($committed, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertSame(
            [],
            (new SchemaValidator())->validate(
                $decoded,
                $this->root . '/docs/architecture/governance/schemas/' . CoreGrowthGate::SCHEMA_FILE,
            ),
        );
        self::assertSame(CoreGrowthGate::SCHEMA, $decoded['schema']);
        self::assertSame(CoreGrowthGate::NOTE, $decoded['note']);
        /** @var array<string, array{kind: string, layer: string, surface: string, growth: mixed}> $symbols */
        $symbols = $decoded['symbols'];
        $names = array_keys($symbols);
        $sorted = $names;
        sort($sorted, SORT_STRING);
        self::assertSame($sorted, $names, 'The baseline is sorted by FQCN.');
        foreach ($symbols as $fqcn => $symbol) {
            if ($symbol['growth'] === null) {
                continue;
            }
            self::assertIsArray($symbol['growth'], $fqcn);
            if (isset($symbol['growth']['record'])) {
                self::assertIsString($symbol['growth']['record'], $fqcn);
                $this->assertApprovedRecordNames($symbol['growth']['record'], $fqcn);
                continue;
            }
            self::assertIsString($symbol['growth']['classification'] ?? null, $fqcn . ' host growth is unclassified.');
        }
        self::assertStringNotContainsString($this->root, $committed, 'No absolute path leaks into the baseline.');

        $inventory = CoreGrowthInventory::scan(
            $this->root,
            LayerClassifier::fromFile($this->root . '/' . CoreGrowthGate::LAYER_GRAPH),
        );
        self::assertSame(array_keys($inventory->symbols()), $names, 'Every production declaration is recorded.');
        foreach ($inventory->symbols() as $fqcn => $symbol) {
            self::assertSame($symbol['surface'], $symbols[$fqcn]['surface'], $fqcn);
            self::assertSame($symbol['layer'], $symbols[$fqcn]['layer'], $fqcn);
            self::assertSame($symbol['kind'], $symbols[$fqcn]['kind'], $fqcn);
        }

        $first = (new CoreGrowthGate($this->root))->preview();
        self::assertSame([], $first['failures']);
        self::assertSame($committed, $first['json'], 'The committed baseline is what recording produces.');
        self::assertSame($committed, (new CoreGrowthGate($this->root))->preview()['json']);
        self::assertSame([], $first['added']);
        self::assertSame([], $first['removed']);
        self::assertSame([], $first['expanded']);
    }

    /**
     * The check is a `composer qa` member directly after the capability-index check, a quality-contract check, and
     * a step in both CI lanes directly after `composer kumwe:capability-index-check`.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheGateIsRegisteredInEveryLane(): void
    {
        $composer = $this->document($this->root . '/composer.json');
        self::assertSame('php tools/verify-core-growth.php', $composer['scripts']['kumwe:core-growth-check'] ?? null);
        self::assertSame(
            'php tools/verify-core-growth.php --record',
            $composer['scripts']['kumwe:core-growth-record'] ?? null,
        );
        /** @var list<string> $qa */
        $qa = $composer['scripts']['qa'];
        $index = array_search('@kumwe:capability-index-check', $qa, true);
        self::assertIsInt($index);
        self::assertSame('@kumwe:core-growth-check', $qa[$index + 1] ?? null);
        self::assertSame(1, count(array_keys($qa, '@kumwe:core-growth-check', true)));

        $contract = $this->document($this->root . '/docs/quality/contract.json');
        /** @var list<array<string, mixed>> $checks */
        $checks = $contract['checks'];
        $ids = array_column($checks, 'id');
        $position = array_search('core-growth', $ids, true);
        self::assertIsInt($position);
        self::assertSame('capability-index', $ids[$position - 1]);
        $check = $checks[$position];
        self::assertSame('kumwe:core-growth-check', $check['composer_script'] ?? null);
        self::assertSame('composer kumwe:core-growth-check', $check['command'] ?? null);
        self::assertSame('platform-architecture', $check['owner'] ?? null);
        self::assertSame(CoreGrowthGate::BASELINE_PATH, $check['artifact'] ?? null);
        self::assertTrue($check['in_qa'] ?? false);
        self::assertSame(['local', 'ci', 'nightly', 'release'], $check['cadence'] ?? null);
        self::assertSame('quality', $check['workflows']['ci']['job'] ?? null);
        self::assertSame('composer kumwe:core-growth-check', $check['workflows']['ci']['contains'] ?? null);

        $workflow = file_get_contents($this->root . '/.github/workflows/ci.yml');
        self::assertIsString($workflow);
        self::assertSame(2, substr_count($workflow, "          composer kumwe:core-growth-check\n"));
        self::assertSame(
            2,
            substr_count(
                $workflow,
                "          composer kumwe:capability-index-check\n          composer kumwe:core-growth-check\n",
            ),
        );
    }

    /**
     * Run `tools/verify-core-growth.php` against the real repository with the given arguments.
     *
     * @param   list<string>  $arguments  Arguments such as `--root=PATH`.
     *
     * @return  array{status: int, output: string}  Exit status and combined output.
     *
     * @since   2.0.0
     */
    private static function runGate(array $arguments): array
    {
        $command = sprintf(
            '%s %s',
            escapeshellarg(PHP_BINARY),
            escapeshellarg(GovernanceFixture::repositoryRoot() . '/tools/verify-core-growth.php'),
        );
        foreach ($arguments as $argument) {
            $command .= ' ' . escapeshellarg($argument);
        }
        $lines = [];
        $status = 0;
        exec($command . ' 2>&1', $lines, $status);

        return ['status' => $status, 'output' => implode("\n", $lines)];
    }

    /**
     * The cited Core Growth Record exists, is `decision: approved` with a named reviewer, and lists the symbol.
     *
     * @param   string  $record  Record identifier such as `KUMWE-CGR-2026-001`.
     * @param   string  $fqcn    The symbol whose growth entry cites the record.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function assertApprovedRecordNames(string $record, string $fqcn): void
    {
        $path = $this->root . '/docs/architecture/core-growth/' . $record . '.md';
        $bytes = file_get_contents($path);
        self::assertIsString($bytes, $fqcn . ' cites ' . $record . ', which does not exist.');
        $end = strpos($bytes, "\n---\n", 4);
        self::assertIsInt($end, $record . ' has no front matter.');
        $frontMatter = substr($bytes, 0, $end + 1);
        self::assertStringContainsString("\ndecision: approved\n", $frontMatter, $record . ' is not approved.');
        self::assertMatchesRegularExpression('/\nreviewer: "[^"]+"\n/', $frontMatter, $record . ' names no reviewer.');
        self::assertStringContainsString("\n  - " . $fqcn . "\n", $frontMatter, $record . ' does not name ' . $fqcn);
    }

    /**
     * Decode one repository JSON object.
     *
     * @param   string  $path  Document path.
     *
     * @return  array<string, mixed>  Decoded object.
     *
     * @since   2.0.0
     */
    private function document(string $path): array
    {
        $bytes = file_get_contents($path);
        self::assertIsString($bytes, $path);
        $decoded = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded, $path);

        return $decoded;
    }
}
