<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Gate;

use Droost\Workflow\Config\GateSettings;
use Droost\Workflow\Config\WorkflowConfig;
use Droost\Workflow\Gate\ShellGateExecutor;
use Droost\Workflow\Pack\PackMaterializer;
use Droost\Workflow\Tests\WorkflowTestCase;

/**
 * A mandatory gate is given something to measure, and something it can run.
 *
 * Both halves were measured by a reviewer running three real tickets through
 * real tools from `init` defaults, and between them they cost a whole ticket
 * and made a second one meaningless.
 *
 * PHPSTAN WAS GIVEN NO PATH AT ALL. `argvFor()` hands phpcs a `.` when the
 * repository has no ruleset; the branch directly beneath it passed phpstan
 * nothing, phpstan exited 1 with "At least one path must be specified", and
 * that became a labelled pass:
 *
 *   "gate":"phpstan","status":"passed","exit_code":1,
 *   "summary":"phpstan was given no path to analyse — a labeled pass"
 *   tally: {"passed": 1}
 *
 * `init` writes `phpstan: { level: 6 }` with no `paths`, so on every project
 * without a `phpstan.neon` — which is what init creates — the mandatory
 * analyser reported `passed` over zero files for the life of the project. The
 * reviewer planted a real return-type defect, watched phpstan find it in 0.8s
 * by hand, and watched the gate say passed. The prose was honest; the status
 * word and the tally were not, and the tally is what the evidence document
 * counts.
 *
 * AND INIT'S OWN DEFAULT COULD NOT PASS INIT'S OWN GATE. The shipped lever
 * file names `Drupal,DrupalPractice`; nothing in this package's dependency
 * tree provides it, and the README says the package "requires no Drupal". So
 * the documented happy path for a plain PHP project produced
 * `ERROR: the "Drupal" coding standard is not installed`, on a mandatory gate,
 * on a surface with no `gate-waive`, with `standard` frozen into run.json at
 * begin. Three attempts, `exhausted: true`, and `reset` the only exit.
 */
final class MandatoryGatesMeasureTest extends WorkflowTestCase {

  /**
   * Phpstan is handed the project's own source, and not the dependencies.
   */
  public function testPhpstanIsGivenSomethingToAnalyse(): void {
    $root = $this->projectWithTools();
    mkdir($root . '/src', 0755, TRUE);
    mkdir($root . '/tests', 0755, TRUE);
    mkdir($root . '/vendor/acme', 0755, TRUE);
    mkdir($root . '/.claude/hooks', 0755, TRUE);
    file_put_contents($root . '/src/Money.php', "<?php\n");
    file_put_contents($root . '/tests/MoneyTest.php', "<?php\n");
    file_put_contents($root . '/vendor/acme/Dep.php', "<?php\n");
    file_put_contents($root . '/.claude/hooks/droost-workflow-guard.php', "<?php\n");

    $argv = $this->argvFor($root, new GateSettings('phpstan', TRUE, ['level' => 6]));

    $this->assertContains('src', $argv, 'the project\'s own source is analysed');
    $this->assertContains('tests', $argv, 'and its tests');
    $this->assertNotContains('vendor', $argv, 'its dependencies are not its code');
    $this->assertNotContains(
      '.claude',
      $argv,
      'and neither is what droost installed — phpstan has no ignore flag, so '
      . 'the only way to keep those out is not to hand them over',
    );
  }

  /**
   * Anything that already names a subject is left alone.
   *
   * The default exists for the case where nothing else answers. A `paths`
   * lever or a `phpstan.neon` is somebody's decision and outranks it.
   */
  public function testSubjectsNamedElsewhereWin(): void {
    $root = $this->projectWithTools();
    mkdir($root . '/src', 0755, TRUE);
    file_put_contents($root . '/src/Money.php', "<?php\n");

    $lever = $this->argvFor($root, new GateSettings('phpstan', TRUE, ['paths' => 'src']));
    $this->assertCount(
      1,
      array_keys($lever, 'src', TRUE),
      'the lever names the subject once, not alongside a discovered copy',
    );

    file_put_contents($root . '/phpstan.neon', "parameters:\n  paths:\n    - src\n");
    $configured = $this->argvFor($root, new GateSettings('phpstan', TRUE, ['level' => 6]));
    $this->assertNotContains(
      'src',
      $configured,
      'a phpstan.neon names its own paths, and is not second-guessed',
    );
  }

  /**
   * Init writes a phpcs standard the project can actually run.
   */
  public function testInitWritesStandardsTheProjectHas(): void {
    $plain = $this->makeRoot();
    (new PackMaterializer())->init($plain);
    $this->assertSame(
      'PSR12',
      WorkflowConfig::load($plain)->gates['phpcs']->options['standard'] ?? NULL,
      'a plain PHP package gets the standard phpcs itself ships with',
    );

    $drupal = $this->makeRoot();
    mkdir($drupal . '/web/core/lib', 0755, TRUE);
    file_put_contents($drupal . '/web/core/lib/Drupal.php', "<?php\n");
    (new PackMaterializer())->init($drupal);
    $this->assertStringContainsString(
      'Drupal',
      (string) (WorkflowConfig::load($drupal)->gates['phpcs']->options['standard'] ?? ''),
      'and a Drupal site still gets Drupal\'s',
    );

    // A MODULE CHECKOUT has neither coder nor a docroot — coder lives at the
    // site — and was written PSR-12, so a valid two-space Drupal class failed
    // phpcs with seven errors as an AGENT fault. Drupal's standard with an
    // honest environment fault ("install drupal/coder") beats a wrong one.
    $module = $this->makeRoot();
    file_put_contents($module . '/acme.info.yml', "name: Acme\ntype: module\ncore_version_requirement: ^10 || ^11\n");
    (new PackMaterializer())->init($module);
    $this->assertStringContainsString(
      'Drupal',
      (string) (WorkflowConfig::load($module)->gates['phpcs']->options['standard'] ?? ''),
      'and a module checkout gets Drupal\'s — the repository shape droost itself is',
    );
  }

  /**
   * A Drupal site is analysed where its own code is, not where Drupal's is.
   */
  public function testTheSitesOwnCodeIsAnalysedNotItsCore(): void {
    $root = $this->drupalSite();

    $argv = $this->argvFor($root, new GateSettings('phpstan', TRUE, ['level' => 6]));

    $this->assertContains('web/modules/custom', $argv, 'the site\'s modules are its own');
    $this->assertContains('web/themes/custom', $argv, 'and so are its themes');
    $this->assertNotContains(
      'web',
      $argv,
      'the docroot itself is never the subject — it carries core and contrib',
    );
    foreach ($argv as $one) {
      $this->assertStringNotContainsString(
        'contrib',
        $one,
        'a contributed module is somebody else\'s code and nobody here can fix it',
      );
    }
  }

  /**
   * The docroot can BE the project root, and core is still not the subject.
   */
  public function testTheDocrootCanBeTheProjectRoot(): void {
    $root = $this->projectWithTools();
    foreach (['core/lib', 'modules/custom/acme', 'modules/contrib/token', 'src'] as $dir) {
      mkdir($root . '/' . $dir, 0755, TRUE);
    }
    file_put_contents($root . '/core/lib/Drupal.php', "<?php\n");
    file_put_contents($root . '/modules/custom/acme/Acme.php', "<?php\n");
    file_put_contents($root . '/modules/contrib/token/Token.php', "<?php\n");
    file_put_contents($root . '/src/Helper.php', "<?php\n");

    $argv = $this->argvFor($root, new GateSettings('phpstan', TRUE, ['level' => 6]));

    $this->assertContains('modules/custom', $argv, 'the site\'s own modules');
    $this->assertContains('src', $argv, 'and anything outside Drupal\'s layout');
    $this->assertNotContains('core', $argv, 'but not Drupal itself');
    $this->assertNotContains('modules', $argv, 'and not contrib alongside custom');
  }

  /**
   * A docroot holding no custom code measures nothing, rather than core.
   *
   * "Given no path to analyse" is already a recorded, honest outcome that
   * names the lever which points the gate. A confident verdict over 15,000
   * files nobody here maintains is not.
   */
  public function testNoCustomTreesMeasuresNothingRatherThanCore(): void {
    $root = $this->projectWithTools();
    mkdir($root . '/web/core/lib', 0755, TRUE);
    mkdir($root . '/web/modules/contrib/token', 0755, TRUE);
    file_put_contents($root . '/web/core/lib/Drupal.php', "<?php\n");
    file_put_contents($root . '/web/modules/contrib/token/Token.php', "<?php\n");

    $this->assertSame(
      [],
      array_values(array_filter(
        $this->argvFor($root, new GateSettings('phpstan', TRUE, ['level' => 6])),
        static fn (string $one): bool => !str_starts_with($one, '-')
          && !str_ends_with($one, 'phpstan')
          && $one !== 'analyse',
      )),
      'nothing of the project\'s own is here, and that is said rather than guessed',
    );
  }

  /**
   * A module's own hook file at the root is part of what is analysed.
   *
   * Only directories were ever candidates. On a contrib-module checkout —
   * the repository shape droost itself is — `acme.module` holds every hook
   * implementation and sits beside `src/`, and it was never handed over.
   */
  public function testTheModuleCheckoutsRootFilesAreAnalysed(): void {
    $root = $this->projectWithTools();
    mkdir($root . '/src', 0755, TRUE);
    file_put_contents($root . '/src/Acme.php', "<?php\n");
    file_put_contents($root . '/acme.module', "<?php\n");
    file_put_contents($root . '/acme.install', "<?php\n");
    file_put_contents($root . '/acme.info.yml', "type: module\n");
    file_put_contents($root . '/README.md', "# acme\n");

    $argv = $this->argvFor($root, new GateSettings('phpstan', TRUE, ['level' => 6]));

    $this->assertContains('acme.module', $argv, 'the hooks are analysed');
    $this->assertContains('acme.install', $argv, 'and the schema');
    $this->assertContains('src', $argv, 'alongside the classes');
    $this->assertNotContains('acme.info.yml', $argv, 'but not YAML');
    $this->assertNotContains('README.md', $argv, 'and not prose');
  }

  /**
   * A site's scaffold files are not the site's code.
   *
   * `index.php` and `update.php` at a docroot are Drupal's, so when the
   * project root IS the docroot they are not candidates the way a module
   * checkout's own root files are.
   */
  public function testTheDocrootsScaffoldIsNotCandidateCode(): void {
    $root = $this->projectWithTools();
    mkdir($root . '/core/lib', 0755, TRUE);
    mkdir($root . '/modules/custom/acme', 0755, TRUE);
    file_put_contents($root . '/core/lib/Drupal.php', "<?php\n");
    file_put_contents($root . '/index.php', "<?php\n");
    file_put_contents($root . '/update.php', "<?php\n");
    file_put_contents($root . '/modules/custom/acme/acme.module', "<?php\n");

    $argv = $this->argvFor($root, new GateSettings('phpstan', TRUE, ['level' => 6]));

    $this->assertContains('modules/custom', $argv);
    $this->assertNotContains('index.php', $argv, 'the front controller is Drupal\'s');
    $this->assertNotContains('update.php', $argv);
  }

  /**
   * A directory whose name is an option never reaches the tool's argv.
   *
   * An argv array stops SHELL injection and not ARGUMENT injection. A
   * reviewer made `--generate-baseline=defused.neon` a directory, dropped one
   * `.php` inside so it was discovered, and the mandatory analyser exited 0
   * with "[OK] Baseline generated with 1 error" — over a real defect it finds
   * by hand in under a second.
   */
  public function testDirectoryNamesThatAreOptionsNeverReachArgv(): void {
    $root = $this->projectWithTools();
    mkdir($root . '/src', 0755, TRUE);
    mkdir($root . '/--generate-baseline=defused.neon', 0755, TRUE);
    file_put_contents($root . '/src/Money.php', "<?php\n");
    file_put_contents($root . '/--generate-baseline=defused.neon/Bait.php', "<?php\n");

    $argv = $this->argvFor($root, new GateSettings('phpstan', TRUE, ['level' => 6]));

    $this->assertContains('src', $argv, 'the real source is still analysed');
    $this->assertNotContains(
      '--generate-baseline=defused.neon',
      $argv,
      'and a directory cannot hand phpstan a flag that defuses it',
    );
  }

  /**
   * A verdict records what the tool was pointed at.
   *
   * A green is meant to expire when the code it was green about moves, and
   * the fingerprint came from the `paths` LEVER alone — which the default
   * levers do not carry. So on a stock project nothing was fingerprinted and
   * every "Still true?" read `unknown`, while the recorded invocation named
   * `src tests` on the same page. The executor knows what it handed over.
   */
  public function testTheResultRecordsWhatItWasPointedAt(): void {
    $root = $this->projectWithTools();
    mkdir($root . '/src', 0755, TRUE);
    mkdir($root . '/tests', 0755, TRUE);
    file_put_contents($root . '/src/Money.php', "<?php\n");
    file_put_contents($root . '/tests/MoneyTest.php', "<?php\n");
    $executor = new ShellGateExecutor(static fn (): array => [0, '{}', ''], static fn (): int => 0);

    $default = $executor->execute(new GateSettings('phpstan', TRUE, ['level' => 6]), $root);
    $this->assertSame(['src', 'tests'], $default->subjects, 'the default subject is recorded');

    $lever = $executor->execute(new GateSettings('phpstan', TRUE, ['paths' => 'src']), $root);
    $this->assertSame(['src'], $lever->subjects, 'a lever records itself');

    file_put_contents($root . '/phpstan.neon', "parameters:\n  paths:\n    - src\n");
    $configured = $executor->execute(new GateSettings('phpstan', TRUE, ['level' => 6]), $root);
    $this->assertSame(
      [],
      $configured->subjects,
      'a tool reading its own config has a subject this cannot see, and says so rather than guessing',
    );

    $absent = $executor->execute(new GateSettings('eslint', TRUE), $this->makeRoot());
    $this->assertSame([], $absent->subjects, 'a tool that never ran measured nothing');
  }

  /**
   * A Drupal site laid out the way composer scaffolds one.
   *
   * @return string
   *   The root.
   */
  private function drupalSite(): string {
    $root = $this->projectWithTools();
    foreach ([
      'web/core/lib/Drupal',
      'web/modules/contrib/token/src',
      'web/modules/custom/acme/src',
      'web/themes/custom/acme_theme',
    ] as $dir) {
      mkdir($root . '/' . $dir, 0755, TRUE);
    }
    // Both: a real docroot has the marker file AND the namespace directory.
    file_put_contents($root . '/web/core/lib/Drupal.php', "<?php\n");
    file_put_contents($root . '/web/core/lib/Drupal/Component.php', "<?php\n");
    file_put_contents($root . '/web/modules/contrib/token/src/Token.php', "<?php\n");
    file_put_contents($root . '/web/modules/custom/acme/src/Acme.php', "<?php\n");
    file_put_contents($root . '/web/themes/custom/acme_theme/acme.theme', "<?php\n");

    return $root;
  }

  /**
   * A directory the project ignores is not the project's code.
   *
   * `notTheProjectsCode()` knows `vendor/`, `node_modules/` and droost's own
   * installed directories BY NAME, which covers the common shapes and nothing
   * else. Measured on a live round: the subject staged the droost packages
   * under test into a gitignored `droost-packages/` to feed a path repo,
   * phpcs found it and walked droost's OWN SOURCE, and the code phase failed
   * on 112 style errors in `droost-packages/workflow/src/Config/
   * PresetResolver.php` — recorded as the agent's fault, blocking a ticket
   * that had not touched a line of it.
   *
   * Asking git beats growing the list: the answer is the project's own.
   */
  public function testGitIgnoredDirectoriesAreNotAnalysed(): void {
    $root = $this->projectWithTools();
    mkdir($root . '/.git', 0755, TRUE);
    foreach (['src', 'staged/inner'] as $dir) {
      mkdir($root . '/' . $dir, 0755, TRUE);
      file_put_contents($root . '/' . $dir . '/Thing.php', "<?php\n");
    }

    // Read as TEXT, not asked of git: ddev does not mount `.git` into the
    // container the gates run in, so a rule that needs git is not there.
    file_put_contents($root . '/.gitignore', "# staging\n/staged/\n*.log\n!keep\n");
    $executor = new ShellGateExecutor(
      static fn (): array => [0, '', ''],
      static fn (): int => 0,
    );
    $method = new \ReflectionMethod(ShellGateExecutor::class, 'argvFor');
    $argv = $method->invoke($executor, new GateSettings('phpstan', TRUE, ['level' => 1]), '/bin/phpstan', $root);

    $this->assertIsArray($argv);
    $this->assertContains('src', $argv, 'the project\'s own code is analysed');
    $this->assertNotContains('staged', $argv, 'and what the project ignores is not');
  }

  /**
   * On a Drupal site, phpcs judges the site's own code and never core.
   *
   * PHPStan was scoped to the project's own trees; phpcs was left at `.`
   * with a vendored `--ignore` that knows nothing about Drupal, so on a site
   * it walked `<docroot>/core` and reported style errors in Drupal's own
   * files — against a README that promises the gates never judge core. Two
   * gates, one question, two answers. Driven on both docroot spellings,
   * because the one that exposed it was `html/`.
   */
  public function testPhpcsOnTheSiteNeverJudgesCore(): void {
    foreach (['web', 'html'] as $docroot) {
      $root = $this->makeRoot();
      mkdir($root . '/' . $docroot . '/core/lib', 0755, TRUE);
      file_put_contents($root . '/' . $docroot . '/core/lib/Drupal.php', "<?php\n");
      mkdir($root . '/' . $docroot . '/modules/custom/acme', 0755, TRUE);
      file_put_contents($root . '/' . $docroot . '/modules/custom/acme/acme.module', "<?php\n");

      $method = new \ReflectionMethod(ShellGateExecutor::class, 'argvFor');
      $executor = new ShellGateExecutor(
        static fn (): array => [0, '', ''],
        static fn (): int => 0,
      );
      $argv = $method->invoke(
        $executor,
        new GateSettings('phpcs', TRUE, ['standard' => 'Drupal,DrupalPractice']),
        '/bin/phpcs',
        $root,
      );
      $this->assertIsArray($argv);

      $this->assertNotContains('.', $argv, $docroot . ': `.` is the whole site, core included');
      $this->assertContains($docroot . '/modules/custom', $argv, $docroot . ': the site\'s own code is the subject');
      foreach ($argv as $argument) {
        $this->assertNotSame(
          $docroot . '/core',
          $argument,
          $docroot . ': core is never handed to a gate',
        );
      }
    }
  }

  /**
   * A project root with the mandatory binaries present.
   *
   * @return string
   *   The root.
   */
  private function projectWithTools(): string {
    $root = $this->makeRoot();
    mkdir($root . '/vendor/bin', 0755, TRUE);
    foreach (['phpcs', 'phpstan', 'phpunit'] as $tool) {
      file_put_contents($root . '/vendor/bin/' . $tool, "#!/bin/sh\nexit 0\n");
      chmod($root . '/vendor/bin/' . $tool, 0755);
    }

    return $root;
  }

  /**
   * The argv a gate is invoked with.
   *
   * @param string $root
   *   The project.
   * @param \Droost\Workflow\Config\GateSettings $gate
   *   The gate.
   *
   * @return list<string>
   *   The arguments, without the binary.
   */
  private function argvFor(string $root, GateSettings $gate): array {
    $seen = [];
    $executor = new ShellGateExecutor(
      function (array $argv) use (&$seen): array {
        $seen = $argv;
        return [0, '{}', ''];
      },
      static fn (): int => 0,
    );
    $executor->execute($gate, $root);

    return array_map(static fn (mixed $one): string => (string) $one, $seen);
  }

}
