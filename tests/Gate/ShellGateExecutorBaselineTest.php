<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Gate;

use Droost\Workflow\Baseline\Baseline;
use Droost\Workflow\Baseline\BaselineContext;
use Droost\Workflow\Baseline\BaselineManifest;
use Droost\Workflow\Baseline\BaselineStore;
use Droost\Workflow\Baseline\FindingKey;
use Droost\Workflow\Config\GateSettings;
use Droost\Workflow\Gate\GateStatus;
use Droost\Workflow\Gate\ShellGateExecutor;
use Droost\Workflow\Tests\WorkflowTestCase;

/**
 * The executor against a baseline: only NEW findings fail, both counts ride.
 */
final class ShellGateExecutorBaselineTest extends WorkflowTestCase {

  /**
   * The argv the fake runner saw, per call.
   *
   * @var list<list<string>>
   */
  private array $argv = [];

  /**
   * Phpcs: an inherited error passes with both counts; a new one fails by name.
   */
  public function testPhpcsPartitionsErrorsByKey(): void {
    $root = $this->rootWithTools(['vendor/bin/phpcs']);
    mkdir($root . '/web', 0775, TRUE);
    file_put_contents($root . '/web/a.php', "<?php\n\$old = 1;\n\$fresh = 2;\n");
    $oldKey = FindingKey::of($root, 'web/a.php', 'Drupal.X', 'Old debt', 2);
    $this->writeBaseline($root, ['phpcs' => ['file' => 'phpcs.json', 'count' => 1]], [
      'phpcs.json' => json_encode([
        'v' => 1,
        'gate' => 'phpcs',
        'findings' => [
        ['key' => $oldKey, 'file' => 'web/a.php', 'rule' => 'Drupal.X', 'message' => 'Old debt', 'line' => 2],
        ],
      ], JSON_THROW_ON_ERROR),
    ]);
    $context = new BaselineContext($this->load($root));
    $gate = new GateSettings('phpcs', TRUE, ['standard' => 'Drupal']);

    // Only the inherited error: passes, and says so.
    $onlyOld = $this->executor([2, $this->phpcsJson($root, [['Old debt', 'Drupal.X', 2, 'ERROR']]), ''])
      ->executeWithBaseline($gate, $root, $context);
    $this->assertSame(GateStatus::Passed, $onlyOld->status);
    $this->assertSame('phpcs passed — 0 new, 1 inherited', $onlyOld->summary);
    $this->assertSame(1, $onlyOld->inherited);
    $this->assertSame(0, $onlyOld->new);
    $this->assertSame(1, $onlyOld->toArray()['inherited']);

    // The inherited one plus a new one: fails, naming the new one only.
    $withNew = $this->executor([2, $this->phpcsJson($root, [
      ['Old debt', 'Drupal.X', 2, 'ERROR'],
      ['Fresh mistake', 'Drupal.Y', 3, 'ERROR'],
      ['Just a warning', 'Drupal.W', 3, 'WARNING'],
    ]), '',
    ])->executeWithBaseline($gate, $root, $context);
    $this->assertSame(GateStatus::Failed, $withNew->status);
    $this->assertStringStartsWith('phpcs FAILED — 1 new error(s) the baseline does not record (1 inherited): web/a.php:3 Fresh mistake', $withNew->summary);
    $this->assertSame(1, $withNew->inherited);
    $this->assertSame(1, $withNew->new);
    $this->assertCount(1, $withNew->findings, 'the findings list carries the new ones only');
    $this->assertSame('Drupal.Y', $withNew->findings[0]['rule']);

    // The same inherited finding on a shifted line is still inherited.
    file_put_contents($root . '/web/a.php', "<?php\n// inserted\n\$old = 1;\n\$fresh = 2;\n");
    $shifted = $this->executor([2, $this->phpcsJson($root, [['Old debt', 'Drupal.X', 3, 'ERROR']]), ''])
      ->executeWithBaseline($gate, $root, $context);
    $this->assertSame(GateStatus::Passed, $shifted->status, 'a shifted line keeps its key');
    $this->assertSame(1, $shifted->inherited);
  }

  /**
   * Without a baseline entry for the gate, the whole-tree verdict applies.
   */
  public function testGateTheBaselineDoesNotRecordIsJudgedWhole(): void {
    $root = $this->rootWithTools(['vendor/bin/phpcs']);
    $this->writeBaseline($root, ['prettier' => ['file' => 'prettier.txt', 'count' => 0]], ['prettier.txt' => '']);
    $context = new BaselineContext($this->load($root));
    $result = $this->executor([2, $this->phpcsJson($root, [['Any', 'Drupal.X', 1, 'ERROR']]), ''])
      ->executeWithBaseline(new GateSettings('phpcs', TRUE), $root, $context);
    $this->assertSame(GateStatus::Failed, $result->status);
    $this->assertNull($result->inherited, 'no baseline for phpcs, no counts');
  }

  /**
   * Phpstan runs through the wrapper and reports inherited from the neon.
   */
  public function testPhpstanRunsThroughTheWrapperAndCounts(): void {
    $root = $this->rootWithTools(['vendor/bin/phpstan']);
    $this->writeBaseline($root, ['phpstan' => ['file' => 'phpstan-baseline.neon', 'count' => 3]], [
      'phpstan-baseline.neon' => "parameters:\n\tignoreErrors:\n\t\t-\n\t\t\tmessage: '#a#'\n\t\t\tcount: 2\n\t\t\tpath: src/A.php\n\t\t-\n\t\t\tmessage: '#b#'\n\t\t\tcount: 1\n\t\t\tpath: src/B.php\n",
      Baseline::PHPSTAN_WRAPPER => "includes:\n\t- phpstan-baseline.neon\nparameters:\n\treportUnmatchedIgnoredErrors: false\n",
    ]);
    $context = new BaselineContext($this->load($root));
    $gate = new GateSettings('phpstan', TRUE, ['level' => 'max']);

    $clean = $this->executor([0, '{"totals":{"errors":0,"file_errors":0},"files":{},"errors":[]}', ''])
      ->executeWithBaseline($gate, $root, $context);
    $this->assertSame(GateStatus::Passed, $clean->status);
    $this->assertSame('phpstan passed — 0 new, 3 inherited', $clean->summary);
    $this->assertSame(3, $clean->inherited);
    $this->assertContains('-c', $this->argv[0]);
    $this->assertContains('droost/baseline/phpstan.wrapper.neon', $this->argv[0]);
    $this->assertContains('--level=max', $this->argv[0], 'the level is the dial\'s, never the baseline\'s');

    $dirty = $this->executor([1, '{"totals":{"errors":0,"file_errors":2},"files":{},"errors":[]}', ''])
      ->executeWithBaseline($gate, $root, $context);
    $this->assertSame(GateStatus::Failed, $dirty->status);
    $this->assertSame('phpstan FAILED — 2 new error(s) the baseline does not record (3 inherited)', $dirty->summary);
    $this->assertSame(2, $dirty->new);
  }

  /**
   * Prettier: a recorded file is inherited until the run touches it.
   */
  public function testPrettierInheritsRecordedFilesUntilTouched(): void {
    $root = $this->rootWithTools(['node_modules/.bin/prettier']);
    $this->writeBaseline($root, ['prettier' => ['file' => 'prettier.txt', 'count' => 2]], [
      'prettier.txt' => "js/legacy.js\njs/touched.js\n",
    ]);
    $context = new BaselineContext($this->load($root), ['js/touched.js']);
    $gate = new GateSettings('prettier', TRUE);
    $stderr = "[warn] js/legacy.js\n[warn] js/touched.js\n[warn] Code style issues found in 2 files.\n";

    $result = $this->executor([1, '', $stderr])->executeWithBaseline($gate, $root, $context);

    $this->assertSame(GateStatus::Failed, $result->status);
    $this->assertSame(1, $result->inherited, 'legacy.js is untouched, so inherited');
    $this->assertSame(1, $result->new, 'touched.js was changed by the run: formatting it is part of the change');
    $this->assertStringContainsString('js/touched.js', $result->summary);

    $untouched = $this->executor([1, '', "[warn] js/legacy.js\n"])->executeWithBaseline($gate, $root, new BaselineContext($this->load($root)));
    $this->assertSame(GateStatus::Passed, $untouched->status);
    $this->assertSame('prettier passed — 0 new, 1 inherited unformatted file(s)', $untouched->summary);
  }

  /**
   * Coverage: under the target but at the floor passes; below the floor fails.
   */
  public function testCoverageRatchetsFromTheInheritedFloor(): void {
    $root = $this->rootWithTools(['vendor/bin/phpunit']);
    file_put_contents($root . '/phpunit.xml', '<phpunit/>');
    $this->writeBaseline($root, ['coverage' => ['file' => 'metrics.json', 'count' => 34]], [
      'metrics.json' => json_encode(['coverage' => 34.0], JSON_THROW_ON_ERROR),
    ]);
    $context = new BaselineContext($this->load($root));
    $gate = new GateSettings('coverage', TRUE, ['min' => 80]);

    $above = $this->executor([0, "OK\n  Lines:   35.00% (35/100)\n", ''])->executeWithBaseline($gate, $root, $context);
    $this->assertSame(GateStatus::Passed, $above->status);
    $this->assertStringContainsString('under the 80% target but at or above the inherited floor 34.0%', $above->summary);

    $below = $this->executor([0, "OK\n  Lines:   30.00% (30/100)\n", ''])->executeWithBaseline($gate, $root, $context);
    $this->assertSame(GateStatus::Failed, $below->status);
    $this->assertStringContainsString('fell BELOW the inherited floor 34.0%', $below->summary);

    $meets = $this->executor([0, "OK\n  Lines:   85.00% (85/100)\n", ''])->executeWithBaseline($gate, $root, $context);
    $this->assertSame('coverage 85.0% meets min 80%', $meets->summary, 'meeting the target needs no ratchet talk');
  }

  /**
   * Mutation: infection is told the inherited floor, and the target is named.
   */
  public function testMutationFloorReplacesMinMsi(): void {
    $root = $this->rootWithTools(['vendor/bin/infection']);
    $this->writeBaseline($root, ['mutation' => ['file' => 'metrics.json', 'count' => 41]], [
      'metrics.json' => json_encode(['msi' => 41.0], JSON_THROW_ON_ERROR),
    ]);
    $context = new BaselineContext($this->load($root));
    $gate = new GateSettings('mutation', TRUE, ['msi_min' => 80]);

    $result = $this->executor([0, "Mutation Score Indicator (MSI): 43%\n", ''])->executeWithBaseline($gate, $root, $context);

    $this->assertContains('--min-msi=41.00', $this->argv[0]);
    $this->assertNotContains('--min-msi=80', $this->argv[0]);
    $this->assertSame(GateStatus::Passed, $result->status);
    $this->assertStringContainsString('MSI 43.0% is under the 80% target but at or above the inherited floor 41.0%', $result->summary);
  }

  /**
   * The plain execute() path is unchanged: no context, no counts.
   */
  public function testPlainExecuteCarriesNoCounts(): void {
    $root = $this->rootWithTools(['vendor/bin/phpcs']);
    $result = $this->executor([0, $this->phpcsJson($root, []), ''])->execute(new GateSettings('phpcs', TRUE), $root);
    $this->assertSame(GateStatus::Passed, $result->status);
    $this->assertNull($result->inherited);
    $this->assertNull($result->new);
  }

  /**
   * Measure() runs the gate's tool raw, with extra arguments appended.
   */
  public function testMeasureReturnsRawOutputOrNull(): void {
    $root = $this->rootWithTools(['vendor/bin/phpstan']);
    $executor = $this->executor([0, '{"totals":{"errors":0,"file_errors":0}}', '']);
    $run = $executor->measure(new GateSettings('phpstan', TRUE, ['level' => 6]), $root, ['--generate-baseline=droost/baseline/phpstan-baseline.neon']);
    $this->assertNotNull($run);
    $this->assertSame(0, $run['exit']);
    $this->assertContains('--generate-baseline=droost/baseline/phpstan-baseline.neon', $run['argv']);
    $this->assertNull($executor->measure(new GateSettings('phpcs', TRUE), $root), 'a missing binary measures nothing');
    $this->assertNull($executor->measure(new GateSettings('custom:x', TRUE, ['cmd' => 'true', 'phase' => 'code']), $root), 'a custom gate is not measured');
  }

  /**
   * A root with the named tool binaries present (empty files suffice).
   *
   * @param list<string> $binaries
   *   Root-relative binary paths.
   *
   * @return string
   *   The root.
   */
  private function rootWithTools(array $binaries): string {
    $root = $this->makeRoot();
    foreach ($binaries as $binary) {
      $dir = dirname($root . '/' . $binary);
      if (!is_dir($dir)) {
        mkdir($dir, 0775, TRUE);
      }
      file_put_contents($root . '/' . $binary, '');
    }
    return $root;
  }

  /**
   * Writes a baseline with the given manifest gates and files.
   *
   * @param string $root
   *   The root.
   * @param array<string, array{file: string, count: int}> $gates
   *   Manifest gate entries (the hash is filled in).
   * @param array<string, string> $files
   *   File name to content.
   */
  private function writeBaseline(string $root, array $gates, array $files): void {
    $entries = [];
    foreach ($gates as $gate => $entry) {
      $entries[$gate] = $entry + ['sha256' => hash('sha256', $files[$entry['file']] ?? '')];
    }
    BaselineStore::write($root, new BaselineManifest('2026-09-08T00:00:00+00:00', 'deadbeef', 'max', $entries), $files);
  }

  /**
   * The loaded baseline, asserted present.
   *
   * @param string $root
   *   The root.
   *
   * @return \Droost\Workflow\Baseline\Baseline
   *   The baseline.
   */
  private function load(string $root): Baseline {
    $baseline = BaselineStore::load($root);
    $this->assertNotNull($baseline);
    return $baseline;
  }

  /**
   * An executor whose runner returns one canned outcome and records argv.
   *
   * @param array{int, string, string} $outcome
   *   Exit code, stdout, stderr.
   *
   * @return \Droost\Workflow\Gate\ShellGateExecutor
   *   The executor.
   */
  private function executor(array $outcome): ShellGateExecutor {
    $this->argv = [];
    return new ShellGateExecutor(
      function (array $argv, string $cwd, int $timeout) use ($outcome): array {
        $this->argv[] = $argv;
        return $outcome;
      },
      static fn (): int => 0,
    );
  }

  /**
   * A phpcs JSON report for web/a.php.
   *
   * @param string $root
   *   The root (the report prints absolute paths).
   * @param list<array{string, string, int, string}> $messages
   *   Message, source, line, type.
   *
   * @return string
   *   The report.
   */
  private function phpcsJson(string $root, array $messages): string {
    $errors = 0;
    $warnings = 0;
    $list = [];
    foreach ($messages as [$message, $source, $line, $type]) {
      $type === 'ERROR' ? $errors++ : $warnings++;
      $list[] = [
        'message' => $message,
        'source' => $source,
        'severity' => 5,
        'fixable' => FALSE,
        'type' => $type,
        'line' => $line,
        'column' => 1,
      ];
    }
    return json_encode([
      'totals' => ['errors' => $errors, 'warnings' => $warnings, 'fixable' => 0],
      'files' => [$root . '/web/a.php' => ['errors' => $errors, 'warnings' => $warnings, 'messages' => $list]],
    ], JSON_THROW_ON_ERROR);
  }

}
