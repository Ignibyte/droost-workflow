<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Baseline;

use Droost\Workflow\Baseline\Baseline;
use Droost\Workflow\Baseline\BaselineError;
use Droost\Workflow\Baseline\BaselineStore;
use Droost\Workflow\Baseline\BaselineWriter;
use Droost\Workflow\Config\WorkflowConfig;
use Droost\Workflow\Gate\ShellGateExecutor;
use Droost\Workflow\Tests\WorkflowTestCase;

/**
 * Measuring the tree and writing the baseline down — only downward, by default.
 */
final class BaselineWriterTest extends WorkflowTestCase {

  /**
   * How many phpcs errors the fake tool reports.
   */
  private int $phpcsErrors = 2;

  /**
   * How many findings the fake phpstan generates into its baseline.
   */
  private int $phpstanCount = 2;

  /**
   * The files the fake prettier reports unformatted.
   *
   * @var list<string>
   */
  private array $unformatted = ['js/x.js'];

  /**
   * The coverage the fake phpunit prints.
   */
  private float $coverage = 34.0;

  /**
   * The MSI the fake infection prints.
   */
  private float $msi = 41.0;

  /**
   * The first write records what the level judges; the rest is skipped by name.
   */
  public function testFirstWriteRecordsTheMeasuredDebt(): void {
    $root = $this->legacyRoot();
    $writer = $this->writer();
    $config = WorkflowConfig::load($root);

    $measured = $writer->measure($config, $root);
    $this->assertSame(
      ['phpcs' => 2, 'phpstan' => 2, 'prettier' => 1, 'coverage' => 34, 'mutation' => 41],
      $measured->bill(),
    );
    $this->assertArrayHasKey('eslint', $measured->skipped, 'no eslint binary: skipped, named');
    $this->assertArrayHasKey('config_clean', $measured->skipped);
    $this->assertStringContainsString('no site', $measured->skipped['config_clean']);

    $manifest = $writer->write($root, $measured, 'max', 'abc123');

    $this->assertSame('max', $manifest->preset);
    $this->assertSame('abc123', $manifest->generatedCommit);
    $this->assertSame(2, $manifest->count('phpcs'));
    $this->assertSame(2, $manifest->count('phpstan'));
    $this->assertSame(1, $manifest->count('prettier'));
    $this->assertSame(34, $manifest->count('coverage'));
    $this->assertSame(41, $manifest->count('mutation'));
    $this->assertNull($manifest->count('eslint'));
    $this->assertSame([], $manifest->grown);

    $baseline = BaselineStore::load($root);
    $this->assertNotNull($baseline);
    $this->assertCount(2, $baseline->findings('phpcs'));
    $this->assertSame(['js/x.js'], $baseline->prettierFiles());
    $this->assertSame(34.0, $baseline->metric('coverage'));
    $this->assertSame(41.0, $baseline->metric('msi'));
    $this->assertSame(2, $baseline->phpstanInheritedCount());
    $wrapper = (string) file_get_contents($root . '/droost/baseline/' . Baseline::PHPSTAN_WRAPPER);
    $this->assertStringContainsString("- ../../phpstan.neon\n", $wrapper, 'the project\'s own config is included first');
    $this->assertStringContainsString('- phpstan-baseline.neon', $wrapper);
    $this->assertStringContainsString('reportUnmatchedIgnoredErrors: false', $wrapper);
    $this->assertFileDoesNotExist($root . '/droost/baseline/.phpstan-measure.neon', 'the scratch file is removed');
    $this->assertNotNull(BaselineStore::hash($root));
  }

  /**
   * A clean tree measures phpstan as zero, never as "not measured".
   *
   * PHPStan refuses to write a baseline from zero errors unless told an
   * empty one is fine; the first live bill on the clean room read
   * "not measured — phpstan did not write a baseline (exit 1)" for a gate
   * with nothing to inherit. Zero is a number the manifest records.
   */
  public function testCleanTreeMeasuresPhpstanAsZero(): void {
    $this->phpstanCount = 0;
    $root = $this->legacyRoot();
    $writer = $this->writer();

    $measured = $writer->measure(WorkflowConfig::load($root), $root);
    $this->assertArrayNotHasKey('phpstan', $measured->skipped, 'zero findings is a measurement');

    $manifest = $writer->write($root, $measured, 'max', 'abc123');
    $this->assertSame(0, $manifest->count('phpstan'));
    $baseline = BaselineStore::load($root);
    $this->assertNotNull($baseline);
    $this->assertSame(0, $baseline->phpstanInheritedCount());
  }

  /**
   * A refresh drops paid-off debt and keeps the rest.
   */
  public function testRefreshShrinks(): void {
    $root = $this->legacyRoot();
    $writer = $this->writer();
    $writer->write($root, $writer->measure(WorkflowConfig::load($root), $root), 'max', 'a');

    $this->phpcsErrors = 1;
    $this->phpstanCount = 1;
    $this->unformatted = [];
    $this->coverage = 40.0;
    $manifest = $writer->write($root, $writer->measure(WorkflowConfig::load($root), $root), 'max', 'b', refresh: TRUE);

    $this->assertSame(1, $manifest->count('phpcs'));
    $this->assertSame(1, $manifest->count('phpstan'));
    $this->assertSame(0, $manifest->count('prettier'));
    $this->assertSame(40, $manifest->count('coverage'), 'the floor rises with the tree');
    $this->assertSame('b', $manifest->generatedCommit);
    $this->assertSame([], $manifest->grown);
  }

  /**
   * A refresh that would record MORE debt is refused, naming each gate.
   */
  public function testRefreshRefusesGrowth(): void {
    $root = $this->legacyRoot();
    $writer = $this->writer();
    $writer->write($root, $writer->measure(WorkflowConfig::load($root), $root), 'max', 'a');
    $before = BaselineStore::hash($root);

    $this->phpcsErrors = 3;
    $this->coverage = 30.0;
    try {
      $writer->write($root, $writer->measure(WorkflowConfig::load($root), $root), 'max', 'b', refresh: TRUE);
      $this->fail('growth was accepted without --grow');
    }
    catch (BaselineError $e) {
      $this->assertStringContainsString('phpcs +1', $e->getMessage());
      $this->assertStringContainsString('coverage +4', $e->getMessage());
      $this->assertStringContainsString('--grow --reason', $e->getMessage());
    }
    $this->assertSame($before, BaselineStore::hash($root), 'a refused refresh changes nothing on disk');
  }

  /**
   * Growing with a reason records the growth in the manifest, forever.
   */
  public function testGrowWithReasonIsRecorded(): void {
    $root = $this->legacyRoot();
    $writer = $this->writer();
    $writer->write($root, $writer->measure(WorkflowConfig::load($root), $root), 'max', 'a');

    $this->phpcsErrors = 3;
    $this->coverage = 30.0;
    $manifest = $writer->write(
      $root,
      $writer->measure(WorkflowConfig::load($root), $root),
      'max',
      'b',
      refresh: TRUE,
      grow: TRUE,
      reason: 'legacy import merged from the old repo',
    );

    $this->assertSame(3, $manifest->count('phpcs'));
    $this->assertSame(30, $manifest->count('coverage'), 'the floor may fall only with --grow');
    $this->assertCount(1, $manifest->grown);
    $this->assertSame('legacy import merged from the old repo', $manifest->grown[0]['reason']);
    $this->assertSame(['phpcs' => 1, 'coverage' => 4], $manifest->grown[0]['added']);
  }

  /**
   * Growing without a reason, a second first-write, and a refresh of nothing.
   */
  public function testTheRefusals(): void {
    $root = $this->legacyRoot();
    $writer = $this->writer();
    $measured = $writer->measure(WorkflowConfig::load($root), $root);

    try {
      $writer->write($root, $measured, 'max', 'a', refresh: TRUE);
      $this->fail('refreshed nothing');
    }
    catch (BaselineError $e) {
      $this->assertStringContainsString('no baseline', $e->getMessage());
    }

    $writer->write($root, $measured, 'max', 'a');
    try {
      $writer->write($root, $measured, 'max', 'a');
      $this->fail('overwrote a baseline without --refresh');
    }
    catch (BaselineError $e) {
      $this->assertStringContainsString('already holds a baseline', $e->getMessage());
    }

    try {
      $writer->write($root, $measured, 'max', 'a', refresh: TRUE, grow: TRUE);
      $this->fail('grew without a reason');
    }
    catch (BaselineError $e) {
      $this->assertStringContainsString('--reason', $e->getMessage());
    }
  }

  /**
   * A tool missing at refresh keeps the debt it recorded before.
   */
  public function testUnmeasuredGateCarriesForward(): void {
    $root = $this->legacyRoot();
    $writer = $this->writer();
    $writer->write($root, $writer->measure(WorkflowConfig::load($root), $root), 'max', 'a');

    unlink($root . '/vendor/bin/phpcs');
    $measured = $writer->measure(WorkflowConfig::load($root), $root);
    $this->assertArrayHasKey('phpcs', $measured->skipped);
    $manifest = $writer->write($root, $measured, 'max', 'b', refresh: TRUE);

    $this->assertSame(2, $manifest->count('phpcs'), 'the recorded debt survives a missing tool');
    $this->assertNotNull(BaselineStore::load($root)?->findings('phpcs'));
  }

  /**
   * A tool that could not run is "not measured", never zero findings.
   *
   * The first real site to measure at xhigh got "eslint would inherit 0"
   * while eslint was crashing on core's scaffolded config (F-EMT-9b). A
   * baseline that records a clean bill for a tool that never read a file
   * makes the tool's first real run fail on debt the record denies.
   */
  public function testToolThatCouldNotRunIsNotMeasuredAsZero(): void {
    $root = $this->legacyRoot();
    file_put_contents($root . '/node_modules/.bin/eslint', '');
    mkdir($root . '/web/modules/custom/fx/js', 0775, TRUE);
    file_put_contents($root . '/web/modules/custom/fx/js/fx.js', "console.log(1);\n");

    $measured = $this->writer()->measure(WorkflowConfig::load($root), $root);

    $this->assertArrayNotHasKey('eslint', $measured->findings, 'a crash is not a measurement');
    $this->assertArrayHasKey('eslint', $measured->skipped);
    $this->assertStringStartsWith('eslint could not run (exit 2)', $measured->skipped['eslint']);
    $this->assertStringContainsString('gates.eslint.config', $measured->skipped['eslint']);
    // The tools that did run are still measured.
    $this->assertArrayHasKey('phpcs', $measured->findings);
  }

  /**
   * Only what the level turns on is measured.
   */
  public function testOffGatesAreNotMeasured(): void {
    $root = $this->legacyRoot("preset: high\n");
    $measured = $this->writer()->measure(WorkflowConfig::load($root), $root);
    $this->assertArrayNotHasKey('coverage', $measured->bill(), 'coverage is off at high');
    $this->assertStringContainsString('off at level high', $measured->skipped['coverage']);
    $this->assertArrayHasKey('phpcs', $measured->bill());
  }

  /**
   * A legacy project root with the fake toolchain present.
   *
   * @param string $lever
   *   The lever file's content.
   *
   * @return string
   *   The root.
   */
  private function legacyRoot(string $lever = "preset: max\n"): string {
    $root = $this->makeRootWithConfig($lever);
    $binaries = [
      'vendor/bin/phpcs',
      'vendor/bin/phpstan',
      'vendor/bin/phpunit',
      'vendor/bin/infection',
      'node_modules/.bin/prettier',
    ];
    foreach ($binaries as $binary) {
      $dir = dirname($root . '/' . $binary);
      if (!is_dir($dir)) {
        mkdir($dir, 0775, TRUE);
      }
      file_put_contents($root . '/' . $binary, '');
    }
    file_put_contents($root . '/phpunit.xml', '<phpunit/>');
    file_put_contents($root . '/phpstan.neon', "parameters:\n  level: 6\n");
    mkdir($root . '/web', 0775, TRUE);
    file_put_contents($root . '/web/a.php', "<?php\n\$one = 1;\n\$two = 2;\n\$three = 3;\n");
    return $root;
  }

  /**
   * A writer over an executor whose tools are fakes reading this test's knobs.
   *
   * @return \Droost\Workflow\Baseline\BaselineWriter
   *   The writer.
   */
  private function writer(): BaselineWriter {
    $executor = new ShellGateExecutor(
      function (array $argv, string $cwd, int $timeout): array {
        $binary = basename($argv[0]);
        switch ($binary) {
          case 'phpcs':
            $messages = [];
            for ($i = 0; $i < $this->phpcsErrors; $i++) {
              $messages[] = [
                'message' => 'Debt ' . $i,
                'source' => 'Drupal.Legacy.Rule',
                'severity' => 5,
                'fixable' => FALSE,
                'type' => 'ERROR',
                'line' => 2 + $i,
                'column' => 1,
              ];
            }
            $report = json_encode([
              'totals' => ['errors' => count($messages), 'warnings' => 0, 'fixable' => 0],
              'files' => [
                $cwd . '/web/a.php' => [
                  'errors' => count($messages),
                  'warnings' => 0,
                  'messages' => $messages,
                ],
              ],
            ], JSON_THROW_ON_ERROR);
            return [$messages === [] ? 0 : 2, $report, ''];

          case 'phpstan':
            // The real tool: zero errors + --generate-baseline is a refusal
            // (exit 1) unless --allow-empty-baseline is passed too.
            $this->assertContains('--allow-empty-baseline', $argv, 'a clean tree must still measure (phpstan refuses an empty baseline without the flag)');
            foreach ($argv as $arg) {
              $this->assertStringStartsNotWith('--error-format', $arg, 'phpstan refuses --error-format beside --generate-baseline');
              if (str_starts_with($arg, '--generate-baseline=')) {
                if ($this->phpstanCount === 0) {
                  file_put_contents($cwd . '/' . substr($arg, strlen('--generate-baseline=')), "parameters:\n\tignoreErrors: []\n");
                  continue;
                }
                $entries = '';
                for ($i = 0; $i < $this->phpstanCount; $i++) {
                  $entries .= "\t\t-\n\t\t\tmessage: '#Debt {$i}#'\n\t\t\tcount: 1\n\t\t\tpath: ../../web/a.php\n";
                }
                file_put_contents($cwd . '/' . substr($arg, strlen('--generate-baseline=')), "parameters:\n\tignoreErrors:\n" . $entries);
              }
            }
            return [0, '', ''];

          case 'eslint':
            // The live crash (F-EMT-9b): core's scaffolded config extends
            // plugins the project never installed; eslint exits 2 with a
            // banner and reads nothing.
            return [
              2,
              '',
              "Oops! Something went wrong! :(\n\nESLint: 8.57.1\n\nESLint couldn't find the config \"airbnb-base\" to extend from.\n",
            ];

          case 'prettier':
            $lines = array_map(static fn (string $f): string => '[warn] ' . $f, $this->unformatted);
            return [$this->unformatted === [] ? 0 : 1, '', implode("\n", $lines) . "\n"];

          case 'phpunit':
            return [0, sprintf("OK\n  Lines:   %.2f%% (34/100)\n", $this->coverage), ''];

          case 'infection':
            return [0, sprintf("Mutation Score Indicator (MSI): %d%%\n", (int) $this->msi), ''];
        }
        return [127, '', 'not found'];
      },
      static fn (): int => 0,
    );
    return new BaselineWriter($executor, static fn (): string => '2026-09-08T12:00:00+00:00');
  }

}
