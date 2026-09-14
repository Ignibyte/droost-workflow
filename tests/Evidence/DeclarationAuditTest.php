<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Evidence;

use Droost\Workflow\Evidence\EvaluationReport;
use Droost\Workflow\Evidence\EvidenceStore;
use Droost\Workflow\Evidence\CheckRecord;
use Droost\Workflow\Evidence\CheckState;
use Droost\Workflow\Evidence\DeclarationAudit;
use Droost\Workflow\Evidence\WorkType;
use Droost\Workflow\Evidence\Fault;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Scope is audited against the diff, and the asymmetry is deliberate.
 *
 * Growing past the plan blocks; shrinking inside it does not. A run that wedged
 * because the agent found a simpler way would teach it to pad its declarations,
 * which is worse than no declaration at all.
 */
#[CoversClass(DeclarationAudit::class)]
final class DeclarationAuditTest extends TestCase {

  /**
   * One check from an audit, by name.
   *
   * @param \Droost\Workflow\Evidence\DeclarationAudit $audit
   *   The audit.
   * @param string $name
   *   The item.
   *
   * @return \Droost\Workflow\Evidence\CheckRecord
   *   The check. Absent is a failed test, not a NULL — see hasCheck().
   */
  private function check(DeclarationAudit $audit, string $name): CheckRecord {
    foreach ($audit->checks() as $check) {
      if ($check->name === $name) {
        return $check;
      }
    }
    // Not `return NULL` behind a `?->`: a missing check then reads as an
    // assertion about a state that is NULL, which is the wrong sentence for
    // "the audit never produced this check at all".
    $this->fail(sprintf('the audit produced no "%s" check', $name));
  }

  /**
   * A file touched that nobody declared blocks, and is named.
   */
  public function testUndeclaredFilesBlockAsScopeCreep(): void {
    $audit = new DeclarationAudit(
      ['web/themes/custom/kchockey'],
      [],
      ['web/themes/custom/kchockey/css/tokens.css', 'web/modules/custom/sneaky/sneaky.module'],
    );

    $this->assertSame(['web/modules/custom/sneaky/sneaky.module'], $audit->undeclared());
    $check = $this->check($audit, 'declared_files');
    $this->assertSame(CheckState::Blocked, $check->state);
    $this->assertSame(Fault::Agent, $check->fault);
    $this->assertStringContainsString('sneaky.module', (string) $check->summary);
  }

  /**
   * A declared directory covers the files created under it.
   *
   * Making an agent enumerate every file it will create would make the
   * declaration a chore nobody writes honestly.
   */
  public function testDeclaredDirectoryCoversWhatIsUnderIt(): void {
    $audit = new DeclarationAudit(
      ['web/themes/custom/kchockey'],
      [],
      [
        'web/themes/custom/kchockey/kchockey.info.yml',
        'web/themes/custom/kchockey/components/navbar/navbar.twig',
      ],
    );

    $this->assertSame([], $audit->undeclared());
    $this->assertSame(CheckState::Satisfied, $this->check($audit, 'declared_files')->state);
  }

  /**
   * A plan that shrank is recorded and does not block.
   */
  public function testDeclaredButUntouchedIsRecordedNotBlocked(): void {
    $audit = new DeclarationAudit(['src/A.php', 'src/B.php'], [], ['src/A.php']);

    $this->assertSame(['src/B.php'], $audit->untouched());
    $check = $this->check($audit, 'declared_files');
    $this->assertSame(CheckState::Satisfied, $check->state);
    $this->assertStringContainsString('src/B.php', (string) $check->summary, 'recorded, so a reader can see the plan moved');
  }

  /**
   * A declared test is RECORDED, because droost cannot see which tests ran.
   *
   * This check used to block, and could not be satisfied by any real test name.
   * `ranTests()` returns the test gates' summary and invocation strings, and
   * neither ever contains a test name: a passing phpunit gate stores "phpunit
   * passed — 3 test(s), 7 assertion(s)" and an argv with no filter and no path.
   * A substring search over those could only fail on a real name and succeed on
   * an accident:
   *
   *     --tests=WidgetTest::testReturns   blocked forever, zero budget spent
   *     --tests=phpunit                   advanced immediately
   *     --tests=test                      advanced ("--do-not-fail-on-empty-…")
   *
   * So an agent naming its tests honestly was wedged at every level above
   * `low`, and one naming a meaningless word walked through — the exact
   * inversion this class exists to prevent, shipped inside the class, with the
   * plan skill's own worked example as the trigger.
   *
   * The old test passed because it handed `ranTests` a fabricated
   * `Drupal\Tests\kchockey\Unit\RinkTest::testItRenders` that production
   * cannot produce. A fixture that invents its input proves nothing about the
   * path it claims to cover.
   *
   * droost records the promise and says plainly that it is not a verification.
   * Making it one needs the phpunit gate to emit JUnit XML and the executor to
   * read class and method names out of it.
   */
  public function testDeclaredTestsAreRecordedNotVerified(): void {

    foreach (['RinkTest::testItRenders', 'RinkTest', 'phpunit', 'test'] as $declared) {
      $audit = new DeclarationAudit([], [$declared], []);
      $check = $this->check($audit, 'declared_tests');

      $this->assertSame(
        CheckState::Recorded,
        $check->state,
        sprintf('"%s" is recorded — no declaration is cheaper than another', $declared),
      );
      $this->assertSame(Fault::None, $check->fault);
      $this->assertStringContainsString(
        $declared,
        $check->summary,
        'and the promise itself is on the record',
      );
    }

    $this->assertSame(
      [],
      (new DeclarationAudit([], ['RinkTest'], []))->missingTests(),
      'nothing is reported missing, because nothing here can tell',
    );
  }

  /**
   * The run's own record is never scope creep.
   *
   * The workflow writes run.json, the evidence store and the spec itself.
   * Blocking a phase because the workflow wrote its own record would be
   * absurd, and a lock file a build legitimately rewrote is the same class.
   */
  public function testTheRunsOwnRecordAndLockFilesAreExempt(): void {
    $audit = new DeclarationAudit(
      ['src/A.php'],
      [],
      [
        'src/A.php',
        'droost/droost-workflow/run.json',
        'droost/droost-workflow/tmp-spec-hh-3.md',
        'composer.lock',
        'package-lock.json',
      ],
    );

    $this->assertSame([], $audit->undeclared());
  }

  /**
   * Installing the product is not undeclared work.
   *
   * The lock files were exempt and their MANIFESTS were not, which made the
   * product's own documented first step — `composer require --dev
   * droost/workflow` — a scope-creep block carrying an AGENT fault, on a file
   * the agent did not choose to write. A walk hit it during ordinary setup.
   *
   * The declaration is the requirement; the manifest is how a package manager
   * records it. And the trees the manifests fill are here too: most
   * repositories gitignore them, and the ones that do not saw every transitive
   * dependency of a single `require` reported as creep.
   */
  public function testDependencyBookkeepingIsNotScopeCreep(): void {
    $audit = new DeclarationAudit(
      ['src/A.php'],
      [],
      [
        'src/A.php',
        'composer.json',
        'composer.lock',
        'package.json',
        'package-lock.json',
        'vendor/droost/workflow/src/WorkflowFacade.php',
        'node_modules/left-pad/index.js',
      ],
    );

    $this->assertSame([], $audit->undeclared());
  }

  /**
   * But a source file under a custom module is still creep.
   *
   * The exemption must stay a list of bookkeeping, not a hole wide enough to
   * park real work in.
   */
  public function testRealWorkIsStillCaught(): void {
    $audit = new DeclarationAudit(
      ['src/A.php'],
      [],
      ['src/A.php', 'composer.json', 'web/modules/custom/acme/src/Sneaky.php'],
    );

    $this->assertSame(
      ['web/modules/custom/acme/src/Sneaky.php'],
      $audit->undeclared(),
    );
  }

  /**
   * Two spellings of the same path compare equal.
   */
  public function testPathSpellingsAreNormalised(): void {
    $audit = new DeclarationAudit(['./src//A.php'], [], ['src/A.php']);

    $this->assertSame([], $audit->undeclared());
    $this->assertSame([], $audit->untouched());
  }

  /**
   * With no tests declared, there is no coverage check at all.
   *
   * An item that cannot fail should not be on the list pretending it passed.
   */
  public function testNoDeclaredTestsMeansNoCoverageCheck(): void {
    $audit = new DeclarationAudit(['src/A.php'], [], ['src/A.php']);

    $this->assertFalse($this->hasCheck($audit, 'declared_tests'));
    // One again: `mandatory_measured` is no longer one of these. It is not a
    // declaration check — it asks whether the GATES looked at anything — and
    // living here put it behind the facade's early return for a run that
    // declared nothing, which is exactly the run it was written for.
    $this->assertCount(1, $audit->checks());
    $this->assertFalse($this->hasCheck($audit, 'mandatory_measured'));
  }

  /**
   * A legacy project's own record is never counted as scope creep.
   *
   * `ltrim($path, "./")` strips a character SET, so `.droost-workflow/run.json`
   * became `droost-workflow/run.json` and matched no exemption. Latent until
   * the state-dir fix put the evidence store in that directory — at which point
   * a legacy project's audit would have been blocked by its own database, which
   * is the second time that exact absurdity nearly shipped.
   */
  public function testLegacyStateDirIsExemptFromScopeCreep(): void {
    $audit = new DeclarationAudit(
      ['src'],
      [],
      [
        'src/a.php',
        '.droost-workflow/run.json',
        '.droost-workflow/evidence.sqlite',
        './droost/droost-workflow/run.json',
        'composer.lock',
      ],
    );

    $this->assertSame([], $audit->undeclared());
  }

  /**
   * Whether the audit produced a check by that name at all.
   *
   * Absence is a real verdict and a different one from a NULL state: an item
   * that cannot fail should not be on the list pretending it passed.
   *
   * @param \Droost\Workflow\Evidence\DeclarationAudit $audit
   *   The audit.
   * @param string $name
   *   The check name.
   *
   * @return bool
   *   TRUE when the audit produced it.
   */
  private function hasCheck(DeclarationAudit $audit, string $name): bool {
    foreach ($audit->checks() as $check) {
      if ($check->name === $name) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * The kind a contributed check reads is a kind some verb can write.
   *
   * `WorkItemDeclared` — the shipped worked example for contributed checks —
   * blocks a phase when `declared($runId, 'work_item')` is empty, with fault
   * `agent`, whose own guidance reads "There is no waiver for it". Nothing
   * anywhere could write that kind: the declaration loop was hard-coded to
   * `file` and `test`, and `declare-changes` took only `--files`, `--tests` and
   * `--type`.
   *
   * So any site adding the documented `work_item:` block got every run failing
   * at plan, permanently, told to declare a ticket by a tool with no way to
   * declare one — the SpecFreeze deadlock's exact shape, which this very file
   * names in a comment fifty lines away.
   *
   * Asserted as a property, because the next contributed check will read the
   * next kind: every kind any check reads must be writable through the facade.
   */
  public function testEveryDeclarationKindReadByCheckIsWritable(): void {
    $root = sys_get_temp_dir() . '/droost-kinds-' . bin2hex(random_bytes(6));
    mkdir($root . '/droost/droost-workflow', 0775, TRUE);

    $store = new EvidenceStore($root);
    $store->upsertRun('r1', ['preset' => 'medium']);

    // The kinds droost itself reads back anywhere, contributed checks included.
    foreach (['file', 'test', 'work_item'] as $kind) {
      $store->declare('r1', 'plan', $kind, 'value-' . $kind, date('c'));
      $this->assertSame(
        ['value-' . $kind],
        $store->declared('r1', $kind),
        sprintf('"%s" is a kind something can actually declare', $kind),
      );
    }

    exec('rm -rf ' . escapeshellarg($root));
  }

  /**
   * The unmeasured mandatory gate is recorded, whatever was declared.
   *
   * `type_coverage` covers this and only when a work TYPE was declared, so an
   * agent that declares nothing got no check — and a live walk watched a phase
   * complete with phpstan having measured nothing, `blocked: []`, and no row
   * anywhere naming it.
   *
   * My first fix put it in `checks()`, which made it unreachable in that very
   * case: the facade returns early when a run declared no files, no tests and
   * no type, so the check written for an agent that declares nothing was the
   * one thing that agent never got. A reviewer drove such a run and found zero
   * declaration rows in the store. It is its own method now, called
   * unconditionally.
   *
   * RECORDED rather than blocked, deliberately: the remedy is a lever, levers
   * freeze at begin, and a block could not be cleared from inside the run it
   * stopped. This project has shipped that deadlock twice.
   */
  public function testUnmeasuredMandatoryGateIsRecordedWithoutWorkType(): void {
    $record = DeclarationAudit::mandatoryMeasured(['phpcs'], [], 'test');

    $this->assertNotNull($record, 'no declaration of any kind is needed');
    $this->assertSame('mandatory_measured', $record->name);
    $this->assertSame(CheckState::Recorded, $record->state, 'and it does not wedge the run');
    $this->assertStringContainsString('phpstan, phpunit', $record->summary);
    $this->assertStringContainsString('gates.phpstan.paths', $record->summary, 'naming the lever');
  }

  /**
   * And it stays quiet when the trio really did measure something.
   */
  public function testNothingIsRecordedWhenTheTrioMeasured(): void {
    $this->assertNull(
      DeclarationAudit::mandatoryMeasured(['phpcs', 'phpstan', 'phpunit'], [], 'test'),
    );
  }

  /**
   * The gate the LEVEL turned off is not one that failed to measure.
   *
   * `preset: low` runs no phpunit. Recording that as an unmeasured mandatory
   * gate would blame the agent for the operator's decision, which is the shape
   * this file's own docblock warns about.
   */
  public function testGateTurnedOffByTheLevelIsNotRecorded(): void {
    $this->assertNull(
      DeclarationAudit::mandatoryMeasured(['phpcs', 'phpstan'], ['phpunit'], 'test'),
    );
  }

  /**
   * It is not asked at the code phase, where phpunit has not run yet.
   */
  public function testItIsNotAskedBeforeTheTestPhase(): void {
    $this->assertNull(
      DeclarationAudit::mandatoryMeasured(['phpcs'], [], 'code'),
      'at code, phpunit has not run — saying so would be a false alarm every run',
    );
  }

  /**
   * A gate binary is creep when the AGENT rewrites it, not when composer does.
   *
   * `vendor/` and `node_modules/` were exempted so `composer require` would
   * stop being charged to the agent, and they took `vendor/bin/` and
   * `node_modules/.bin/` with them — where every gate droost runs lives. A
   * reviewer stubbed all three binaries with `exit 0` and watched the code
   * phase PASS with phpcs and phpstan recorded `satisfied`.
   *
   * Making them unconditionally undeclared then walled off ordinary work:
   * composer rewrites `vendor/bin/*` on every install, so a plain `composer
   * update` blocked the run with no waiver. The third wall I moved rather than
   * removed today.
   *
   * The audit cannot see WHO wrote a file; it can see whether the package
   * manager ran, and a manifest or lock in the same diff is that. The guard is
   * the layer that knows who and refuses the agent outright — this is the
   * backstop for a host with no hooks, where nothing else is watching.
   */
  public function testGateBinariesAreCreepOnlyWithoutTheirManifest(): void {
    $cases = [
      'composer install, lock changed' => [
        ['src/A.php', 'composer.json', 'composer.lock', 'vendor/bin/phpcs'],
        [],
      ],
      'npm ci, lock changed' => [
        ['src/A.php', 'package-lock.json', 'node_modules/.bin/eslint'],
        [],
      ],
      'an agent rewriting a gate binary' => [
        ['src/A.php', 'vendor/bin/phpcs'],
        ['vendor/bin/phpcs'],
      ],
      // The mixed case, which is the one a single flag would get wrong:
      // composer really did run for PHP, and nothing explains the node binary.
      'composer for php, an agent for node' => [
        ['src/A.php', 'composer.lock', 'vendor/bin/phpcs', 'node_modules/.bin/eslint'],
        ['node_modules/.bin/eslint'],
      ],
      // And the trees around them stay exempt either way.
      'ordinary dependency files' => [
        ['src/A.php', 'vendor/acme/lib/Thing.php', 'node_modules/left-pad/index.js'],
        [],
      ],
    ];
    foreach ($cases as $label => [$changed, $want]) {
      $got = (new DeclarationAudit(['src/A.php'], [], $changed))->undeclared();
      sort($got);
      sort($want);
      $this->assertSame($want, $got, $label);
    }
  }

  /**
   * Where a recorded check lands, asserted rather than claimed.
   *
   * The docblock said `mandatory_measured` reaches "the evaluation and the stop
   * hook's checklist". The second half was false: that query is `state IN
   * ('blocked','pending')` and a recorded check is neither — by design, since
   * the stop hook holds a turn on unresolved WORK. I wrote the claim; nothing
   * checked it.
   *
   * So the reach is pinned. If a future change makes a recorded check block,
   * or drops it from the report, this says so.
   */
  public function testRecordedChecksReachTheEvaluationNotTheStopHook(): void {
    $root = sys_get_temp_dir() . '/reach-' . bin2hex(random_bytes(6));
    mkdir($root . '/droost/droost-workflow', 0775, TRUE);
    $store = new EvidenceStore($root);
    $store->upsertRun('r1', ['preset' => 'medium']);
    $record = DeclarationAudit::mandatoryMeasured(['phpunit'], [], 'test');
    $this->assertNotNull($record);
    $store->record('r1', 'test', $record);

    $report = (new EvaluationReport($store))->render('r1');
    $this->assertStringContainsString('mandatory_measured', $report, 'a reader sees it');
    $this->assertStringContainsString('measured nothing this run', $report, 'with its own words');

    $this->assertSame(
      [],
      array_values(array_filter(
        $store->unresolved('r1', 'test'),
        static fn (array $row): bool => $row['name'] === 'mandatory_measured',
      )),
      'and it does not hold the turn, which is what recorded means',
    );

    exec('rm -rf ' . escapeshellarg($root));
  }

  /**
   * The audit answers for exactly the phases the facade asks it about.
   *
   * These two lists live in different files and drifted: the audit's condition
   * named `complete`, and `WorkflowFacade::auditDeclarations()` has never
   * called it there. A branch no run could enter READ as an enforced rule —
   * "coverage is checked at complete" was in the source, in a condition, in
   * front of anybody reviewing it, and no run has ever evaluated it.
   *
   * Dead code cannot be caught by testing behaviour, because it has none. So
   * this reads the facade's phase list out of its source and asks the audit
   * about every phase there is: the ones the facade names must produce rows,
   * and the ones it does not must produce none. Adding `complete` back to
   * either side without the other now fails here.
   */
  public function testTheAuditAnswersForThePhasesTheFacadeAsksAbout(): void {
    $facade = (string) file_get_contents(dirname(__DIR__, 2) . '/src/WorkflowFacade.php');
    $body = strstr($facade, 'private function auditDeclarations(RunOutcome $outcome');
    $this->assertIsString($body, 'the facade still has the method that decides this');
    $this->assertSame(
      1,
      preg_match('/!in_array\(\$phase, \[([^\]]+)\], TRUE\)/', $body, $match),
      'and it still decides with an in_array over a phase list',
    );
    preg_match_all('/Phase::(\w+)/', $match[1], $names);
    $asked = array_map(static fn (string $name): string => strtolower($name), $names[1]);
    sort($asked);
    $this->assertNotSame([], $asked, 'the facade names at least one phase');

    // A declaration of every kind, so the audit has something to say wherever
    // it is willing to speak. An empty fixture would make "no rows" the answer
    // everywhere and the assertion vacuous.
    $audit = new DeclarationAudit(
      ['src/A.php'],
      ['ThingTest'],
      ['src/A.php'],
      WorkType::Code,
      [],
      [],
    );

    $answered = [];
    foreach (['plan', 'code', 'test', 'complete'] as $phase) {
      if ($audit->checks($phase) !== []) {
        $answered[] = $phase;
      }
    }
    sort($answered);

    $this->assertSame(
      $asked,
      $answered,
      'every phase the facade audits gets rows, and no phase it skips has a rule pretending to run',
    );
  }

  /**
   * A gate binary is exempt only if a package manager plausibly wrote it.
   *
   * THE EXEMPTION HAD NO TEST AT ALL. A reviewer mutated
   * `packageManagerWrote()` to `return TRUE` — every rewritten gate binary
   * exempt, which is the whole attack this rule exists to survive — and the
   * entire suite stayed green. The commit that shipped it said "eleven
   * mutations, eleven caught"; this was not one of them.
   *
   * And the rule was weak on its own terms. It searched the first 4KB for
   * words a package manager tends to use, and a COMMENT carries words as well
   * as code does:
   *
   *   "#!/bin/sh\nexit 0"                     -> creep, correctly
   *   "#!/bin/sh\n# vendor/composer\nexit 0"   -> EXEMPT
   *   "#!/bin/sh\nexit 0 # autoload.php"       -> EXEMPT
   *
   * One comment line stubbed all three gate binaries invisibly. What a real
   * proxy DOES is execute a computed path, and a stub that runs nothing
   * cannot fake that.
   */
  public function testGateBinariesNeedThePackageManagerToBeExempt(): void {
    $root = sys_get_temp_dir() . '/droost-bin-' . bin2hex(random_bytes(6));
    mkdir($root . '/vendor/bin', 0775, TRUE);

    // Composer's real proxy, as this very repository's vendor/bin holds it.
    $proxy = "#!/usr/bin/env php\n<?php\n"
      . "\$GLOBALS['_composer_bin_dir'] = __DIR__;\n"
      . "return include __DIR__ . '/..'.'/squizlabs/php_codesniffer/bin/phpcs';\n";

    $cases = [
      'a bare stub' => ["#!/bin/sh\nexit 0\n", ['vendor/bin/phpcs']],
      'a stub whose COMMENT names composer' => ["#!/bin/sh\n# vendor/composer\nexit 0\n", ['vendor/bin/phpcs']],
      'a stub whose comment names the autoloader' => ["#!/bin/sh\nexit 0 # autoload.php\n", ['vendor/bin/phpcs']],
      'a stub whose comment names __DIR__' => ["#!/bin/sh\n# __DIR__ is fine\nexit 0\n", ['vendor/bin/phpcs']],
      'composer\'s own proxy' => [$proxy, []],
      'a proxy requiring the autoloader' => ["#!/usr/bin/env php\n<?php\nrequire __DIR__ . '/../autoload.php';\n", []],
      'an npm shim' => ["#!/bin/sh\nbasedir=\$(dirname \"\$0\")\nexec node \"\$basedir/../x/bin/x\" \"\$@\"\n", []],
    ];
    foreach ($cases as $label => [$body, $expected]) {
      file_put_contents($root . '/vendor/bin/phpcs', $body);
      $audit = new DeclarationAudit(
        ['src'],
        [],
        ['vendor/bin/phpcs', 'composer.lock'],
        NULL,
        [],
        [],
        $root,
      );
      $this->assertSame($expected, $audit->undeclared(), $label);
    }
    exec('rm -rf ' . escapeshellarg($root));
  }

  /**
   * A summary is a sentence, whatever the size of the diff.
   *
   * `work_type` already knew this and cut its list at five. The scope check
   * imploded the whole list, and a 20,000-file diff — a generated build
   * directory committed, an `npm install` inside the repo, neither exotic —
   * gave `declared_files` a 1,080,341-byte summary.
   *
   * That is not a cosmetic number. The stop hook writes the summary of every
   * unresolved check to stderr, so the message telling the agent what to do
   * became a megabyte of file paths, repeated on every stop attempt until the
   * block cleared.
   */
  public function testSummariesStayReadableOnWideDiffs(): void {
    $changed = [];
    for ($i = 0; $i < 20000; $i++) {
      $changed[] = sprintf('web/modules/custom/acme/src/Generated/Thing%05d.php', $i);
    }

    $checks = (new DeclarationAudit([], [], $changed, NULL, [], []))->checks('code');
    $scope = NULL;
    foreach ($checks as $check) {
      if ($check->name === 'declared_files') {
        $scope = $check;
      }
    }
    $this->assertNotNull($scope, 'undeclared changes are still a block');

    $this->assertLessThan(
      4000,
      strlen($scope->summary),
      'a sentence, not the diff',
    );
    $this->assertStringContainsString(
      '20000 path(s) changed without being declared',
      $scope->summary,
      'and the count is stated, so truncation hides nothing',
    );
    $this->assertStringContainsString(
      'and 19980 more',
      $scope->summary,
      'including how much is not shown',
    );
  }

  /**
   * A list that fits is printed whole, with no "and 0 more".
   */
  public function testShortListsAreNotTruncated(): void {
    $changed = ['web/modules/custom/acme/acme.module', 'web/modules/custom/acme/src/Thing.php'];

    $scope = NULL;
    foreach ((new DeclarationAudit([], [], $changed, NULL, [], []))->checks('code') as $check) {
      if ($check->name === 'declared_files') {
        $scope = $check;
      }
    }
    $this->assertNotNull($scope);

    foreach ($changed as $path) {
      $this->assertStringContainsString($path, $scope->summary, 'every one of the two is named');
    }
    $this->assertStringNotContainsString('more', $scope->summary, 'and nothing is withheld');
  }

}
