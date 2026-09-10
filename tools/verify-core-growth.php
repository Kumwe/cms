<?php

/**
 * Check or record App production growth against the Core Growth baseline.
 *
 * The check scans every class-like under `src/`, classifies it through `docs/architecture/layers.json`, digests its
 * public surface and compares the inventory with `docs/architecture/governance/core-growth-baseline.json`, the
 * capability index, the migration ledger and the Core Growth Records. It refuses a stale capability index, a stale
 * baseline, a duplicate FQCN or service owner, a reintroduced extracted namespace or removed symbol, a likely
 * duplicate responsibility, unrecorded portable growth without an approved Core Growth Record, unrecorded host
 * growth, a reference to a retired namespace, and a broken Core Growth Record. Every failure line starts
 * `Core growth: ` and names the file, the rule and the fix.
 *
 * Usage:
 *   php tools/verify-core-growth.php [--root=PATH]            check; exit 1 on any failure
 *   php tools/verify-core-growth.php --record [--root=PATH]   re-run the check, then rewrite the baseline when
 *                                                             only stale or unrecorded entries remain
 *
 * `--root` replaces the repository root for fixture runs.
 *
 * @since  2.0.0
 */

declare(strict_types=1);

use Kumwe\App\Tools\Governance\CoreGrowthGate;
use Kumwe\App\Tools\Governance\GovernanceViolation;
use Kumwe\App\Tools\Governance\ToolOutput;

require_once __DIR__ . '/Governance/bootstrap.php';

$usage = 'Usage: php tools/verify-core-growth.php [--record] [--root=PATH]';
$root = dirname(__DIR__);
$record = false;
$errors = [];

foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--record') {
        if ($record) {
            $errors[] = '--record is given twice; pass it once. ' . $usage;
            continue;
        }
        $record = true;
        continue;
    }
    if (str_starts_with($argument, '--root=')) {
        $root = rtrim(substr($argument, strlen('--root=')), '/');
        continue;
    }
    $errors[] = sprintf('Unknown argument %s. %s', $argument, $usage);
}
if ($errors !== []) {
    exit(ToolOutput::fail('Core growth', $errors));
}

try {
    $gate = new CoreGrowthGate($root);
    if (!$record) {
        $check = $gate->check();
        if ($check['failures'] !== []) {
            exit(ToolOutput::fail('Core growth', $check['failures']));
        }
        exit(ToolOutput::succeed(sprintf(
            'Core growth verified (%d production symbols; %d recorded growth entries; no duplicate owners).',
            $check['symbols'],
            $check['recorded'],
        )));
    }
    $result = $gate->record();
    if ($result['failures'] !== []) {
        exit(ToolOutput::fail('Core growth', [
            ...$result['failures'],
            sprintf('%s was not written: clear the findings above first.', CoreGrowthGate::BASELINE_PATH),
        ]));
    }
    $lines = [];
    foreach (['added', 'removed', 'expanded', 'renamed'] as $change) {
        foreach ($result[$change] as $fqcn) {
            $lines[] = sprintf('  %s %s', $change, $fqcn);
        }
    }
    $lines[] = sprintf(
        'Core growth baseline recorded (%d production symbols; %d recorded growth entries; '
        . '%d added, %d removed, %d expanded, %d renamed).',
        $result['symbols'],
        $result['recorded'],
        count($result['added']),
        count($result['removed']),
        count($result['expanded']),
        count($result['renamed']),
    );
    exit(ToolOutput::succeed(implode(PHP_EOL, $lines)));
} catch (GovernanceViolation $violation) {
    exit(ToolOutput::fail('Core growth', [$violation->getMessage()]));
}
