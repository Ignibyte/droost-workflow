<?php

declare(strict_types=1);

namespace Drupal\Tests\droost_workflow\Unit\Config;

use Drupal\Tests\droost_workflow\Unit\WorkflowTestCase;
use Drupal\droost_workflow\Config\ConfigError;
use Drupal\droost_workflow\Support\DataError;
use Drupal\droost_workflow\Config\GateSettings;
use Drupal\droost_workflow\Config\Mode;
use Drupal\droost_workflow\Config\Phase;
use Drupal\droost_workflow\Config\Provenance;
use Drupal\droost_workflow\Config\WorkflowConfig;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Loading and validating droost.workflow.yml.
 */
class WorkflowConfigTest extends WorkflowTestCase {

  /**
   * REQ-001: a full lever file parses with no Drupal anywhere in the picture.
   */
  public function testParsesFullLeverFile(): void {
    $root = $this->makeRootWithConfig(<<<'YAML'
      mode: pair
      preset: custom
      phases: [plan, code, test, document, complete]
      gates:
        phpcs:          { on: true,  standard: "Drupal,DrupalPractice" }
        phpstan:        { on: true,  level: 6 }
        phpunit:        { on: true }
        mutation:       { on: false, msi_min: 0 }
        playwright:     { on: false }
        coverage:       { on: false, min: 0 }
        rendered_check: { on: true }
      max_gate_retries: 3
      YAML);

    $config = WorkflowConfig::load($root);

    $this->assertSame(Mode::Pair, $config->mode);
    $this->assertSame('custom', $config->preset);
    $this->assertSame(3, $config->maxGateRetries);
    $this->assertSame(Provenance::File, $config->provenance);
    $this->assertSame(Phase::names(), $config->phaseNames());
    $this->assertSame(6, $config->gate('phpstan')->option('level'));
  }

  /**
   * REQ-001: nothing on the parse path imports a Drupal class.
   *
   * The claim that makes both execution surfaces possible, asserted rather
   * than trusted: if this ever fails, the CLI surface has quietly acquired a
   * dependency on a booted site.
   */
  public function testEngineImportsNoDrupalSymbols(): void {
    $offenders = [];
    $dir = new \RecursiveDirectoryIterator(dirname(__DIR__, 4) . '/src');
    foreach (new \RecursiveIteratorIterator($dir) as $file) {
      if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
        continue;
      }
      $source = file_get_contents($file->getPathname());
      $source = $source === FALSE ? '' : $source;
      foreach (explode("\n", $source) as $line) {
        if (str_starts_with($line, 'use Drupal\\')
          && !str_starts_with($line, 'use Drupal\\droost_workflow\\')) {
          $offenders[] = $file->getFilename() . ': ' . trim($line);
        }
      }
    }

    $this->assertSame([], $offenders);
  }

  /**
   * REQ-005: anything that names no preset resolves to factory.
   *
   * One rule, three situations. An earlier revision gave a file that exists
   * the weaker "custom" set, so creating an empty file silently turned three
   * gates off — the regression guard for that is the equality assertions.
   *
   * @param string|null $yaml
   *   The lever file's contents, or NULL to write no file at all.
   * @param \Drupal\droost_workflow\Config\Provenance $expected
   *   The provenance the loader should report.
   */
  #[DataProvider('unspecifiedPresetCases')]
  public function testUnspecifiedPresetIsAlwaysFactory(
    ?string $yaml,
    Provenance $expected,
  ): void {
    $root = $yaml === NULL
      ? $this->makeRoot()
      : $this->makeRootWithConfig($yaml);

    $config = WorkflowConfig::load($root);
    $builtIn = WorkflowConfig::builtIn();

    $this->assertSame('factory', $config->preset);
    $this->assertSame($expected, $config->provenance);
    $this->assertSame($builtIn->resolvedGates(), $config->resolvedGates());
    $this->assertTrue($config->gate('playwright')->on);
    $this->assertTrue($config->gate('coverage')->on);
    $this->assertTrue($config->gate('mutation')->on);
    $this->assertSame('max', $config->gate('phpstan')->option('level'));
  }

  /**
   * Documents that name no preset.
   *
   * @return array<string, array{string|null, \Drupal\droost_workflow\Config\Provenance}>
   *   Case name to file contents and expected provenance.
   */
  public static function unspecifiedPresetCases(): array {
    return [
      'no file at all' => [NULL, Provenance::BuiltIn],
      'empty file' => ['', Provenance::File],
      'comments only' => ["# nothing here\n", Provenance::File],
      'other settings only' => ["mode: pair\n", Provenance::File],
    ];
  }

  /**
   * A lever file that exists but cannot be used is an error, not a default.
   *
   * @param string $kind
   *   Which unusable thing to put at the config path.
   * @param string $expected
   *   Text the message must contain.
   */
  #[DataProvider('unusableConfigCases')]
  public function testUnusableConfigIsRefused(
    string $kind,
    string $expected,
  ): void {
    $root = $this->makeRoot();
    $path = $root . '/droost.workflow.yml';
    match ($kind) {
      'directory' => mkdir($path, 0755),
      'dangling symlink' => symlink($root . '/gone', $path),
      'unreadable' => (function () use ($path): void {
        file_put_contents($path, "mode: pair\n");
        chmod($path, 0000);
      })(),
      default => $this->fail('Unknown case: ' . $kind),
    };

    if ($kind === 'unreadable' && is_readable($path)) {
      $this->markTestSkipped('Running as a user that ignores file modes.');
    }

    $this->expectException(ConfigError::class);
    $this->expectExceptionMessage($expected);
    WorkflowConfig::load($root);
  }

  /**
   * Things at the config path that are not a usable file.
   *
   * @return array<string, array{string, string}>
   *   Case name to the kind and the expected message fragment.
   */
  public static function unusableConfigCases(): array {
    return [
      'directory' => ['directory', 'is not a regular file'],
      'dangling symlink' => ['dangling symlink', 'is not a regular file'],
      'unreadable' => ['unreadable', 'is not readable'],
    ];
  }

  /**
   * REQ-004: every unknown name is refused, by name, with its vocabulary.
   *
   * @param array<array-key, mixed> $raw
   *   The document.
   * @param string $expected
   *   The exact message expected.
   */
  #[DataProvider('refusalCases')]
  public function testUnknownNamesAreRefusedByName(
    array $raw,
    string $expected,
  ): void {
    $this->expectException(ConfigError::class);
    $this->expectExceptionMessage($expected);
    WorkflowConfig::fromArray(
      $raw,
      'droost.workflow.yml',
    );
  }

  /**
   * One case per vocabulary the loader guards.
   *
   * @return array<string, array{array<array-key, mixed>, string}>
   *   Case name to the document and its expected message.
   */
  public static function refusalCases(): array {
    return [
      'unknown setting' => [
        ['gate' => []],
        'droost.workflow.yml: unknown setting "gate" (known: mode, phases, '
        . 'preset, gates, max_gate_retries)',
      ],
      'unknown gate' => [
        ['gates' => ['phpstain' => ['on' => TRUE]]],
        'droost.workflow.yml: unknown gate "phpstain" (known: phpcs, phpstan, '
        . 'phpunit, mutation, playwright, coverage, rendered_check)',
      ],
      'unknown gate option' => [
        ['gates' => ['phpcs' => ['levl' => 1]]],
        'droost.workflow.yml: gate "phpcs" has no option "levl" '
        . '(accepts: on, standard)',
      ],
      'option on a gate with none' => [
        ['gates' => ['phpunit' => ['min' => 1]]],
        'droost.workflow.yml: gate "phpunit" has no option "min" (accepts: on)',
      ],
      'unknown phase' => [
        ['phases' => ['plan', 'deploy', 'complete']],
        'droost.workflow.yml: unknown phase "deploy" (known: plan, code, '
        . 'test, document, complete)',
      ],
      'unknown mode' => [
        ['mode' => 'solo'],
        'droost.workflow.yml: unknown mode "solo" (known: automated, pair)',
      ],
      'unknown preset' => [
        ['preset' => 'turbo'],
        'droost.workflow.yml: unknown preset "turbo" (known: custom, factory, '
        . 'fast)',
      ],
      'dropped plan' => [
        ['phases' => ['code', 'complete']],
        'droost.workflow.yml: phases must include "plan"',
      ],
      'dropped complete' => [
        ['phases' => ['plan', 'code']],
        'droost.workflow.yml: phases must include "complete"',
      ],
      'duplicate phase' => [
        ['phases' => ['plan', 'plan', 'complete']],
        'droost.workflow.yml: phase "plan" is listed more than once; each '
        . 'phase runs at most once',
      ],
      'phases out of order' => [
        ['phases' => ['plan', 'test', 'code', 'complete']],
        'droost.workflow.yml: phases must be a subsequence of plan, code, '
        . 'test, document, complete — got: plan, test, code, complete',
      ],
      'empty phases' => [
        ['phases' => []],
        'droost.workflow.yml: phases must include "plan"',
      ],
      'retries out of range' => [
        ['max_gate_retries' => 11],
        'droost.workflow.yml: max_gate_retries must be between 0 and 10, '
        . 'got 11',
      ],
      'contradictory switch' => [
        ['gates' => ['phpstan' => ['on' => TRUE, 'level' => 'off']]],
        'droost.workflow.yml: gate "phpstan" sets on: true and level: off',
      ],
      'quoted level' => [
        ['gates' => ['phpstan' => ['level' => '6']]],
        'remove the quotes to make it a number',
      ],
      'level out of range' => [
        ['gates' => ['phpstan' => ['level' => 12]]],
        'droost.workflow.yml: gates.phpstan.level must be between 0 and 9, '
        . '"max" or "off", got 12',
      ],
      'percent out of range' => [
        ['gates' => ['coverage' => ['min' => 101]]],
        'droost.workflow.yml: gates.coverage.min must be between 0 and 100',
      ],
    ];
  }

  /**
   * A blank lever is a statement, not a silence.
   *
   * The regression guard for the security finding: an authored document's
   * explicit null must be reported, never quietly replaced by the preset.
   *
   * @param string $yaml
   *   A lever file with one key left blank.
   */
  #[DataProvider('blankLeverCases')]
  public function testBlankLeversAreRefused(string $yaml): void {
    $root = $this->makeRootWithConfig($yaml);
    $this->expectException(ConfigError::class);
    WorkflowConfig::load($root);
  }

  /**
   * Levers written with no value.
   *
   * @return array<string, array{string}>
   *   Case name to file contents.
   */
  public static function blankLeverCases(): array {
    return [
      'blank preset' => ["preset:\n"],
      'blank mode' => ["mode:\n"],
      'blank phases' => ["phases:\n"],
      'blank retries' => ["max_gate_retries:\n"],
      'blank gate switch' => ["gates:\n  phpcs:\n    on:\n"],
      'blank level' => ["gates:\n  phpstan:\n    level:\n"],
      'blank threshold' => ["gates:\n  coverage:\n    min:\n"],
    ];
  }

  /**
   * A document that is not a mapping says so.
   *
   * @param string $yaml
   *   The file's contents.
   * @param string $expected
   *   Text the message must contain.
   */
  #[DataProvider('notMappingCases')]
  public function testNonMappingDocumentsAreRefused(
    string $yaml,
    string $expected,
  ): void {
    $root = $this->makeRootWithConfig($yaml);
    $this->expectException(ConfigError::class);
    $this->expectExceptionMessage($expected);
    WorkflowConfig::load($root);
  }

  /**
   * Documents whose root is the wrong shape.
   *
   * @return array<string, array{string, string}>
   *   Case name to contents and expected message fragment.
   */
  public static function notMappingCases(): array {
    return [
      'a list' => ["- plan\n- code\n", 'got a list'],
      'a scalar' => ["just a string\n", 'got string'],
      'unparseable' => ["gates:\n\tphpcs: true\n", 'could not be parsed'],
    ];
  }

  /**
   * A parse error must not print the developer's directory tree.
   */
  public function testParseErrorsDoNotLeakTheAbsolutePath(): void {
    $root = $this->makeRootWithConfig("gates:\n\tphpcs: { on: true }\n");

    try {
      WorkflowConfig::load($root);
      $this->fail('Expected a ConfigError.');
    }
    catch (ConfigError $e) {
      $this->assertStringNotContainsString($root, $e->getMessage());
      $this->assertStringContainsString('droost.workflow.yml', $e->getMessage());
    }
  }

  /**
   * A vocabulary refusal is not a wrapped type error.
   *
   * Proves the catch(DataError) around the resolver does not intercept the
   * ConfigErrors thrown inside it.
   */
  public function testVocabularyRefusalsAreNotWrappedTypeErrors(): void {
    try {
      WorkflowConfig::fromArray(
        ['gates' => ['phpstain' => ['on' => TRUE]]],
        'droost.workflow.yml',
      );
      $this->fail('Expected a ConfigError.');
    }
    catch (ConfigError $e) {
      $this->assertNull($e->getPrevious());
    }
  }

  /**
   * A genuine type error is wrapped, keeping its dotted path.
   */
  public function testTypeErrorsAreWrappedWithTheirPath(): void {
    try {
      WorkflowConfig::fromArray(
        ['gates' => ['phpcs' => ['standard' => 123]]],
        'droost.workflow.yml',
      );
      $this->fail('Expected a ConfigError.');
    }
    catch (ConfigError $e) {
      $this->assertInstanceOf(
        DataError::class,
        $e->getPrevious(),
      );
      $this->assertStringContainsString(
        'gates.phpcs.standard',
        $e->getMessage(),
      );
    }
  }

  /**
   * A string bound for a tool's argv cannot smuggle anything.
   *
   * @param string $standard
   *   The candidate value.
   * @param bool $accepted
   *   Whether it should be accepted.
   */
  #[DataProvider('toolArgumentCases')]
  public function testToolArgumentsAreConstrained(
    string $standard,
    bool $accepted,
  ): void {
    $build = static fn (): GateSettings =>
      WorkflowConfig::fromArray(
        ['gates' => ['phpcs' => ['standard' => $standard]]],
        'droost.workflow.yml',
      )->gate('phpcs');

    if (!$accepted) {
      $this->expectException(ConfigError::class);
      $build();
      return;
    }
    $this->assertSame($standard, $build()->option('standard'));
  }

  /**
   * Values the gate runner might inherit.
   *
   * @return array<string, array{string, bool}>
   *   Case name to the value and whether it is acceptable.
   */
  public static function toolArgumentCases(): array {
    return [
      'the default' => ['Drupal,DrupalPractice', TRUE],
      'a ruleset path' => ['vendor/acme/ruleset.xml', TRUE],
      'a hyphenated name' => ['My-Standard_1.0', TRUE],
      'a shell metacharacter' => ['Drupal; touch /tmp/pwned', FALSE],
      'a pipe' => ['Drupal | cat', FALSE],
      'traversal' => ['../../../etc/passwd', FALSE],
      'traversal in the middle' => ['a/../b', FALSE],
      'empty' => ['', FALSE],
    ];
  }

  /**
   * A project root that cannot be used is a caller error, not a default.
   */
  public function testUnusableProjectRootIsRefused(): void {
    $this->expectException(\InvalidArgumentException::class);
    WorkflowConfig::load(
      $this->makeRoot() . '/does-not-exist',
    );
  }

}
