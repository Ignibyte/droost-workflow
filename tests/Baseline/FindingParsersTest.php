<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Baseline;

use Droost\Workflow\Baseline\FindingParsers;
use Droost\Workflow\Tests\WorkflowTestCase;

/**
 * Each tool's machine output read into the one finding shape.
 */
final class FindingParsersTest extends WorkflowTestCase {

  /**
   * The phpcs JSON report: files, messages, error versus warning.
   */
  public function testPhpcsReport(): void {
    $root = $this->makeRoot();
    mkdir($root . '/web', 0775, TRUE);
    file_put_contents($root . '/web/a.php', "<?php\n\$x = 1;\n\$y = 2;\n");
    $json = json_encode([
      'totals' => ['errors' => 1, 'warnings' => 1, 'fixable' => 0],
      'files' => [
        $root . '/web/a.php' => [
          'errors' => 1,
          'warnings' => 1,
          'messages' => [
            [
              'message' => 'Missing doc',
              'source' => 'Drupal.Commenting.X',
              'severity' => 5,
              'fixable' => FALSE,
              'type' => 'ERROR',
              'line' => 2,
              'column' => 1,
            ],
            [
              'message' => 'Long line',
              'source' => 'Drupal.Files.LineLength',
              'severity' => 5,
              'fixable' => FALSE,
              'type' => 'WARNING',
              'line' => 3,
              'column' => 1,
            ],
          ],
        ],
      ],
    ], JSON_THROW_ON_ERROR);

    $findings = FindingParsers::phpcs($json, $root);

    $this->assertCount(2, $findings);
    $this->assertSame('web/a.php', $findings[0]['file'], 'paths are project-relative');
    $this->assertSame('Drupal.Commenting.X', $findings[0]['rule']);
    $this->assertTrue($findings[0]['error']);
    $this->assertFalse($findings[1]['error']);
    $this->assertSame(40, strlen($findings[0]['key']));
    $this->assertSame([], FindingParsers::phpcs('not json', $root));
    $this->assertSame([], FindingParsers::phpcs('', $root));
  }

  /**
   * The eslint and stylelint JSON reports.
   */
  public function testEslintAndStylelintReports(): void {
    $root = $this->makeRoot();
    $eslint = json_encode([
      [
        'filePath' => $root . '/js/a.js',
        'messages' => [
        ['ruleId' => 'no-unused-vars', 'severity' => 2, 'message' => 'x is unused', 'line' => 4],
        ['ruleId' => 'semi', 'severity' => 1, 'message' => 'Missing semicolon', 'line' => 5],
        ],
      ],
    ], JSON_THROW_ON_ERROR);
    $found = FindingParsers::eslint($eslint, $root);
    $this->assertCount(2, $found);
    $this->assertSame('js/a.js', $found[0]['file']);
    $this->assertTrue($found[0]['error']);
    $this->assertFalse($found[1]['error']);

    $stylelint = json_encode([
      [
        'source' => $root . '/css/a.css',
        'warnings' => [
        ['rule' => 'color-no-invalid-hex', 'severity' => 'error', 'text' => 'Unexpected invalid hex', 'line' => 7],
        ],
      ],
    ], JSON_THROW_ON_ERROR);
    $found = FindingParsers::stylelint($stylelint, $root);
    $this->assertCount(1, $found);
    $this->assertSame('color-no-invalid-hex', $found[0]['rule']);
    $this->assertTrue($found[0]['error']);
  }

  /**
   * Prettier's "[warn] file" lines, from either stream, without the summary.
   */
  public function testPrettierUnformattedFiles(): void {
    $stderr = "Checking formatting...\n[warn] js/b.js\n[warn] js/a.js\n[warn] Code style issues found in 2 files. Run Prettier with --write to fix.\n";
    $this->assertSame(['js/a.js', 'js/b.js'], FindingParsers::prettierUnformatted('', $stderr, '/repo'));
    $this->assertSame(['css/x.css'], FindingParsers::prettierUnformatted("[warn] /repo/css/x.css\n", '', '/repo'));
    $this->assertSame([], FindingParsers::prettierUnformatted('All matched files use Prettier code style!', '', '/repo'));
  }

  /**
   * Phpstan's JSON totals and its baseline's count lines.
   */
  public function testPhpstanCounts(): void {
    $this->assertSame(5, FindingParsers::phpstanErrorCount('{"totals":{"errors":1,"file_errors":4},"files":{},"errors":[]}'));
    $this->assertSame(0, FindingParsers::phpstanErrorCount('{"totals":{"errors":0,"file_errors":0},"files":{},"errors":[]}'));
    $this->assertNull(FindingParsers::phpstanErrorCount('Fatal error: memory'));

    $neon = "# total 3 errors\n\nparameters:\n\tignoreErrors:\n\t\t-\n\t\t\tmessage: '#Call to an undefined method#'\n\t\t\tidentifier: method.notFound\n\t\t\tcount: 2\n\t\t\tpath: src/A.php\n\n\t\t-\n\t\t\tmessage: '#Parameter#'\n\t\t\tcount: 1\n\t\t\tpath: src/B.php\n";
    $this->assertSame(3, FindingParsers::phpstanBaselineCount($neon));
    $this->assertSame(0, FindingParsers::phpstanBaselineCount("parameters:\n\tignoreErrors: []\n"));
  }

  /**
   * The two metric percentages.
   */
  public function testMetricPercentages(): void {
    $this->assertSame(34.21, FindingParsers::coveragePercent("Code Coverage Report Summary:\n  Classes: 10.00% (1/10)\n  Methods: 20.00% (2/10)\n  Lines:   34.21% (13/38)\n"));
    $this->assertNull(FindingParsers::coveragePercent("OK (3 tests)\n"));
    $this->assertSame(41.0, FindingParsers::msiPercent("Metrics:\n         Mutation Score Indicator (MSI): 41%\n"));
    $this->assertNull(FindingParsers::msiPercent(''));
  }

  /**
   * The summary infection 0.35 prints, with no MSI line (F-89).
   *
   * Without `--with-uncovered` it mutates only covered lines, so the MSI its
   * `--min-msi` checks is the Covered Code MSI; with the flag it prints both,
   * and the MSI line is the one that counts.
   */
  public function testInfectionThreeFiveSummary(): void {
    $summary = "3 mutations were generated:\n       2 mutants were killed by Test Framework\n       1 covered mutants were not detected\n\nMetrics:\n         Mutation Code Coverage: 100%\n         Covered Code MSI: 66%\n";
    $this->assertSame(66.0, FindingParsers::msiPercent($summary));
    $this->assertSame(3, FindingParsers::mutantCount($summary));

    $withUncovered = "Metrics:\n         Mutation Score Indicator (MSI): 40%\n         Mutation Code Coverage: 60%\n         Covered Code MSI: 66%\n";
    $this->assertSame(40.0, FindingParsers::msiPercent($withUncovered), 'the whole-code MSI wins when printed');
    $this->assertSame(0, FindingParsers::mutantCount("0 mutations were generated:\n"));
    $this->assertNull(FindingParsers::mutantCount("In CoverageChecker.php line 89:\n"));
  }

  /**
   * The phpstan JSON report: messages under files, and the analyser's own.
   *
   * `phpstanErrorCount()` above reads the totals; this reads the MESSAGES,
   * which nothing did before — the plain path recorded a phpstan report as
   * its three top-level wrappers and the evidence document printed
   * "— not recorded —" for every file, line and message.
   */
  public function testPhpstanReport(): void {
    $root = $this->makeRoot();
    $json = json_encode([
      'totals' => ['errors' => 1, 'file_errors' => 1],
      'files' => [
        $root . '/src/A.php' => [
          'errors' => 1,
          'messages' => [
            ['message' => 'Bad return', 'line' => 9, 'ignorable' => TRUE, 'identifier' => 'return.type'],
          ],
        ],
      ],
      'errors' => ['Child process error: out of memory'],
    ], JSON_THROW_ON_ERROR);

    $findings = FindingParsers::phpstan($json, $root);

    $this->assertCount(2, $findings);
    $this->assertSame('src/A.php', $findings[0]['file'], 'paths are project-relative');
    $this->assertSame('return.type', $findings[0]['rule'], 'the identifier is the rule');
    $this->assertSame(9, $findings[0]['line']);
    $this->assertTrue($findings[0]['error']);
    $this->assertSame('', $findings[1]['file'], 'an analyser failure has no file');
    $this->assertSame('phpstan', $findings[1]['rule']);
    $this->assertSame('Child process error: out of memory', $findings[1]['message']);
    $this->assertSame([], FindingParsers::phpstan('not json', $root));
  }

}
