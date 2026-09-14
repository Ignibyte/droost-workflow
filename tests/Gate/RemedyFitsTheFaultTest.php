<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Gate;

use Droost\Workflow\Config\GateSettings;
use Droost\Workflow\Driver\FreshProcessSiteDriver;
use Droost\Workflow\Gate\ShellGateExecutor;
use Droost\Workflow\Tests\WorkflowTestCase;

/**
 * An environment block names the thing that would actually unstick it.
 *
 * `GateResult::toolMissing()` is the only way into `blocked/environment`, and
 * it wrote one sentence — "Install %s — `composer require --dev` or
 * `npm install` it" — for every caller. Eight call sites reach it, and on
 * five of them that sentence is false in the expensive direction: it names an
 * action that cannot work, on the one surface whose whole job is to tell an
 * operator what to type.
 *
 *   - phpunit with no `phpunit.xml` IS installed.
 *   - A contributed gate's `cmd` exiting 127 names some program that has
 *     nothing to do with the gate's name; `composer require --dev acme_audit`
 *     fetches nothing.
 *   - A suite that ran green but measured no coverage needs a PHP EXTENSION.
 *   - A gate that reached a site driver which cannot run it is not
 *     installable at all.
 *   - rendered_check needs drush, whatever the gate is called.
 *
 * `Fault::Environment`'s guidance is "Show the OPERATOR the remedy below and
 * ask them to run it in their terminal". Five of the eight sent them to run
 * something that does nothing, which spends an operator's trust once and does
 * not get it back.
 */
final class RemedyFitsTheFaultTest extends WorkflowTestCase {

  /**
   * Phpunit with no config is a config problem, and says which file.
   */
  public function testPhpunitWithoutItsConfigIsNotAboutPackages(): void {
    $remedy = $this->remedyFor(
      $this->rootWith(['phpunit']),
      new GateSettings('phpunit', TRUE, []),
    );

    $this->assertStringContainsString('phpunit.xml', $remedy, 'it names the file to write');
    $this->assertStringContainsString(
      'IS installed',
      $remedy,
      'and says outright that fetching the package again is not the move',
    );
  }

  /**
   * A custom gate's 127 points at the lever that chose the program.
   */
  public function testCustomCommandsNameTheLeverThatChoseThem(): void {
    $remedy = $this->remedyFor(
      $this->rootWith([]),
      new GateSettings('custom:acme_audit', TRUE, ['cmd' => 'droost-no-such-program-xyz --all']),
    );

    $this->assertStringContainsString(
      'gates.custom.acme_audit.cmd',
      $remedy,
      'the lever path as the operator would type it — `gates.custom:acme_audit.cmd`, '
      . 'which is what the gate NAME spells, is not a key in anybody\'s file',
    );
  }

  /**
   * A module's gate cannot be corrected in the lever file, and does not say so.
   *
   * The same fault, a different owner. `gates.contributed.<id>` accepts only
   * `on` and `mode`, so telling an operator to fix the command there sends
   * them to write a key the config reader discards.
   */
  public function testModuleCommandsSendNobodyToTheLeverFile(): void {
    $remedy = $this->remedyFor(
      $this->rootWith([]),
      new GateSettings('module:snyk', TRUE, ['cmd' => 'droost-no-such-program-xyz test']),
    );

    $this->assertStringContainsString(
      'gates.contributed.snyk.on: false',
      $remedy,
      'the one switch that file really does own',
    );
    $this->assertStringNotContainsString(
      'gates.contributed.snyk.cmd',
      $remedy,
      'and not the key it would silently discard',
    );
  }

  /**
   * A missing coverage driver is an extension, not a package.
   */
  public function testMissingCoverageDriversNameAnExtension(): void {
    $root = $this->rootWith(['phpunit']);
    file_put_contents($root . '/phpunit.xml', "<phpunit/>\n");
    $remedy = $this->remedyFor(
      $root,
      new GateSettings('coverage', TRUE, ['min' => 50]),
      // A green suite that reported no Lines: row — which is what phpunit
      // does when neither xdebug nor pcov is loaded.
      [0, "OK (12 tests, 30 assertions)\n", ''],
    );

    $this->assertStringContainsString('pcov', $remedy, 'it names a driver');
    $this->assertStringContainsString(
      'EXTENSION',
      $remedy,
      'and says why no composer or npm install can reach it',
    );
  }

  /**
   * A gate no driver implements cannot be installed, and is not described so.
   */
  public function testGatesNoDriverImplementsAreNotCalledInstallable(): void {
    $driver = new FreshProcessSiteDriver(
      static fn (): array => [0, '', ''],
      static fn (): int => 0,
    );
    $remedy = (string) $driver
      ->run(new GateSettings('wiki_fresh', TRUE, []), $this->makeRoot())
      ->remedy;

    $this->assertStringContainsString('gates.wiki_fresh.on: false', $remedy);
    $this->assertStringContainsString(
      'nothing for `composer require` to fetch',
      $remedy,
      'and it rules the default answer OUT rather than leaving the reader to try it',
    );
  }

  /**
   * A rendered check names the drush it looked for, and where.
   */
  public function testRenderedChecksNameTheDrushTheyLookedFor(): void {
    $driver = new FreshProcessSiteDriver(
      static fn (): array => [0, '', ''],
      static fn (): int => 0,
      // Deliberately NOT a suffix of the default `vendor/bin/drush`: asserting
      // on a path the default contains is a test that passes whether or not
      // the configured value is read at all.
      'tools/drush-8',
    );
    $remedy = (string) $driver
      ->run(new GateSettings('rendered_check', TRUE, []), $this->makeRoot())
      ->remedy;

    $this->assertStringContainsString(
      'tools/drush-8',
      $remedy,
      'the configured path, not the default one — otherwise the operator checks the wrong file',
    );
    $this->assertStringContainsString('drush/drush', $remedy, 'and what to install');
  }

  /**
   * A binary that really is absent still gets the install sentence.
   *
   * The counterweight. The default was not wrong, it was over-applied, and
   * narrowing it must not lose the case it was written for.
   */
  public function testGenuinelyAbsentBinariesStillSayInstallIt(): void {
    $remedy = $this->remedyFor($this->rootWith([]), new GateSettings('phpcs', TRUE, []));

    $this->assertStringContainsString('composer require --dev', $remedy);
    $this->assertStringContainsString('gates.phpcs.on: false', $remedy);
  }

  /**
   * No two of these faults are handed the same sentence.
   *
   * The regression that matters. Each of the cases above can be made to pass
   * on its own by widening one remedy until it mentions everything; what
   * actually went wrong was five faults sharing one answer, so the test for
   * it has to be about the set.
   */
  public function testEachFaultGetsItsOwnAnswer(): void {
    $absent = $this->rootWith([]);
    $installed = $this->rootWith(['phpunit']);
    $covered = $this->rootWith(['phpunit']);
    file_put_contents($covered . '/phpunit.xml', "<phpunit/>\n");
    $driver = new FreshProcessSiteDriver(
      static fn (): array => [0, '', ''],
      static fn (): int => 0,
    );

    $remedies = [
      'absent binary' => $this->remedyFor($absent, new GateSettings('phpcs', TRUE, [])),
      'no phpunit.xml' => $this->remedyFor($installed, new GateSettings('phpunit', TRUE, [])),
      'custom cmd 127' => $this->remedyFor(
        $absent,
        new GateSettings('custom:acme_audit', TRUE, ['cmd' => 'droost-no-such-program-xyz']),
      ),
      'module cmd 127' => $this->remedyFor(
        $absent,
        new GateSettings('module:snyk', TRUE, ['cmd' => 'droost-no-such-program-xyz']),
      ),
      'no coverage driver' => $this->remedyFor(
        $covered,
        new GateSettings('coverage', TRUE, ['min' => 50]),
        [0, "OK (12 tests, 30 assertions)\n", ''],
      ),
      'no driver for it' => (string) $driver
        ->run(new GateSettings('wiki_fresh', TRUE, []), $absent)->remedy,
      'no drush' => (string) $driver
        ->run(new GateSettings('rendered_check', TRUE, []), $absent)->remedy,
    ];

    foreach ($remedies as $fault => $remedy) {
      $this->assertNotSame('', $remedy, $fault . ' tells the reader something');
    }
    $this->assertCount(
      count($remedies),
      array_unique($remedies),
      sprintf(
        '%d faults, %d distinct answers — one sentence is doing duty for two '
        . 'different things again',
        count($remedies),
        count(array_unique($remedies)),
      ),
    );
  }

  /**
   * A project root holding the named binaries and nothing else.
   *
   * @param list<string> $tools
   *   Binaries to place under vendor/bin.
   *
   * @return string
   *   The root.
   */
  private function rootWith(array $tools): string {
    $root = $this->makeRoot();
    mkdir($root . '/vendor/bin', 0755, TRUE);
    foreach ($tools as $tool) {
      file_put_contents($root . '/vendor/bin/' . $tool, "#!/bin/sh\nexit 0\n");
      chmod($root . '/vendor/bin/' . $tool, 0755);
    }

    return $root;
  }

  /**
   * The remedy a shell gate carries when it could not run.
   *
   * @param string $root
   *   The project.
   * @param \Droost\Workflow\Config\GateSettings $gate
   *   The gate.
   * @param array{int, string, string}|null $answer
   *   What the runner should answer, when the tool is expected to run at all.
   *
   * @return string
   *   The remedy, '' when the result carries none.
   */
  private function remedyFor(string $root, GateSettings $gate, ?array $answer = NULL): string {
    $executor = new ShellGateExecutor(
      static fn (array $argv): array => $answer ?? [
        // 127 is what a shell answers for a program it cannot find, which is
        // the fault the custom-command case is about.
        str_contains(implode(' ', $argv), 'droost-no-such-program-xyz') ? 127 : 0,
        '',
        '',
      ],
      static fn (): int => 0,
    );
    return (string) $executor->execute($gate, $root)->remedy;
  }

}
