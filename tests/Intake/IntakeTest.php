<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Intake;

use Droost\Workflow\Cli\ArgvDispatcher;
use Droost\Workflow\Config\GateSettings;
use Droost\Workflow\Event\RunEventLog;
use Droost\Workflow\Gate\GateExecutorInterface;
use Droost\Workflow\Gate\GateResult;
use Droost\Workflow\Gate\GateStatus;
use Droost\Workflow\Gate\NullSiteDriver;
use Droost\Workflow\Intake\IntakeCheck;
use Droost\Workflow\Intake\IntakeError;
use Droost\Workflow\Intake\IntakeFinding;
use Droost\Workflow\Intake\IntakeService;
use Droost\Workflow\Intake\IntakeState;
use Droost\Workflow\Intake\IntakeStore;
use Droost\Workflow\Intake\IntakeTables;
use Droost\Workflow\Mode\RunStateOnlySink;
use Droost\Workflow\State\RunStateStore;
use Droost\Workflow\Tests\WorkflowTestCase;
use Droost\Workflow\Vcs\VcsInterface;
use Droost\Workflow\WorkflowFacade;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * The intake: opened, checked, approved or abandoned, and nothing built first.
 *
 * One complete intake passes every check; each wall is then broken alone,
 * from that complete intake, and must fail with its own finding. A check that
 * a complete intake passes and a broken one also passes measures nothing.
 */
#[CoversClass(IntakeCheck::class)]
#[CoversClass(IntakeService::class)]
#[CoversClass(IntakeStore::class)]
#[CoversClass(IntakeTables::class)]
final class IntakeTest extends WorkflowTestCase {

  private const MODEL = <<<'MD'
# Harbor Books — content model

## Tooling plan

| Construct | What it is | Evidence | Rung | Why |
|---|---|---|---|---|
| Event | a content type | p:/#r2, p:/events#r1, d:data/events.ts#EVENTS | 2 | three records the cards render |
| Events listing | a View page | p:/events#r1 | 2 | the listing |
| Upcoming events block | a View block | p:/#r2 | 2 | the home page's three |
| Contact form | a Webform | p:/contact#f1 | 3 | name, email, message |
| Main menu | a menu | frame | 1 | the header's links |

## Routes

- / — the home page
- /events — the events listing
- /contact — the contact page

## Not built

| Evidence | Why not |
|---|---|
MD;

  private const ROADMAP = <<<'MD'
# Roadmap

| Rung | Ticket | Builds | Consumes | Pages |
|---|---|---|---|---|
| 1 | tickets/R1.md | the theme and the frame | — | frame, / |
| 2 | tickets/R2.md | the events | 1 | /events, / |
| 3 | tickets/R3.md | the contact page | 2 | /contact |
MD;

  private const QUESTIONS = <<<'MD'
# Questions

| Id | Question | Recommendation | Answer | Decided by |
|---|---|---|---|---|
| Q1 | Who edits the events? | Staff editors | Staff editors | human |
MD;

  /**
   * A complete intake passes every check.
   */
  public function testCompleteIntakeHasNoFindings(): void {
    [, $service] = $this->complete();
    $this->assertSame([], $this->messages($service->check()));
  }

  /**
   * The audit is the tool's: changed after `intake audit`, it fails.
   */
  public function testEditedAuditFails(): void {
    [$root, $service] = $this->complete();
    $audit = $root . '/droost/intake/audit.json';
    file_put_contents($audit, str_replace('"count": 3', '"count": 4', (string) file_get_contents($audit)));
    $this->assertFinding($service, 'files', 'audit.json has changed since `intake audit` wrote it');
  }

  /**
   * An audit no `intake audit` recorded fails.
   */
  public function testUnrecordedAuditFails(): void {
    [, $service] = $this->complete();
    $store = $service->store();
    $state = $store->load();
    $this->assertNotNull($state);
    $store->save(new IntakeState($state->id, $state->status, $state->request, $state->source, $state->openedAt));
    $this->assertFinding($service, 'files', 'not written by `droost-workflow intake audit`');
  }

  /**
   * A decision citing what the audit does not hold fails.
   */
  public function testCitingAnUnknownIdFails(): void {
    [$root, $service] = $this->complete();
    $this->rewrite($root, 'model.md', 'p:/contact#f1 | 3', 'p:/contact#f9 | 3');
    $this->assertFinding($service, 'evidence', '"p:/contact#f9" is cited and is not in the audit');
  }

  /**
   * A route left out of the model fails; set aside, it passes.
   */
  public function testUndecidedRouteFails(): void {
    [$root, $service] = $this->complete();
    $this->rewrite($root, 'model.md', "- /contact — the contact page\n", '');
    $this->assertFinding($service, 'coverage', 'route /contact is not decided');

    $this->rewrite($root, 'model.md', "| Evidence | Why not |\n|---|---|\n", "| Evidence | Why not |\n|---|---|\n| r:/contact | the owner takes mail by phone |\n");
    $this->assertNotContains('coverage', array_map(static fn (IntakeFinding $f): string => $f->check, $service->check()));
  }

  /**
   * A pattern in the model and in a rung names the paths it matches.
   */
  public function testPatternRouteCoversItsInstances(): void {
    [$root, $service] = $this->complete();
    $audit = json_decode((string) file_get_contents($root . '/droost/intake/audit.json'), TRUE);
    $this->assertIsArray($audit);
    $this->assertIsArray($audit['routes']);
    $audit['routes'][] = [
      'id' => 'r:/events/poetry-night',
      'path' => '/events/poetry-night',
      'status' => 404,
      'declared' => FALSE,
      'pattern' => NULL,
      'notFound' => FALSE,
    ];
    file_put_contents($root . '/droost/intake/audit.json', (string) json_encode($audit, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    $state = $service->store()->load();
    $this->assertNotNull($state);
    $service->store()->save($state->withAudit((string) hash_file('sha256', $root . '/droost/intake/audit.json'), '2026-10-08T00:04:00+00:00'));
    $this->assertFinding($service, 'coverage', 'route /events/poetry-night is not decided');

    $this->rewrite($root, 'model.md', "- /events — the events listing\n", "- /events — the events listing\n- /events/:id — an event's page\n");
    $this->assertFinding($service, 'coverage', 'route /events/poetry-night is in the model and in no rung\'s Pages');

    $this->rewrite($root, 'roadmap.md', '| 2 | tickets/R2.md | the events | 1 | /events, / |', '| 2 | tickets/R2.md | the events | 1 | /events, /events/{id}, / |');
    $this->assertNotContains('coverage', array_map(static fn (IntakeFinding $f): string => $f->check, $service->check()));
  }

  /**
   * A route in the model and in no rung fails.
   */
  public function testRouteInNoRungFails(): void {
    [$root, $service] = $this->complete();
    $this->rewrite($root, 'roadmap.md', '| 3 | tickets/R3.md | the contact page | 2 | /contact |', '| 3 | tickets/R3.md | the contact page | 2 | — |');
    $this->assertFinding($service, 'coverage', 'route /contact is in the model and in no rung\'s Pages');
  }

  /**
   * A repeated structure in the main content no construct renders fails.
   */
  public function testUndecidedStructureFails(): void {
    [$root, $service] = $this->complete();
    $this->rewrite($root, 'model.md', '| Upcoming events block | a View block | p:/#r2 | 2 |', '| Upcoming events block | a View block | p:/ | 2 |');
    $this->rewrite($root, 'model.md', 'p:/#r2, p:/events#r1, d:', 'p:/events#r1, d:');
    $this->assertFinding($service, 'coverage', 'p:/#r2 (cards, 3 of them) is not decided');
  }

  /**
   * A form, a set of records and the frame each must be decided.
   */
  public function testUndecidedFormRecordsAndFrameFail(): void {
    [$root, $service] = $this->complete();
    $this->rewrite($root, 'model.md', "| Contact form | a Webform | p:/contact#f1 | 3 | name, email, message |\n", '');
    $this->rewrite($root, 'model.md', ', d:data/events.ts#EVENTS', '');
    $this->rewrite($root, 'model.md', "| Main menu | a menu | frame | 1 | the header's links |\n", '');
    $messages = implode("\n", $this->messages($service->check()));
    $this->assertStringContainsString('the form p:/contact#f1 is not decided', $messages);
    $this->assertStringContainsString('d:data/events.ts#EVENTS (records) is not decided', $messages);
    $this->assertStringContainsString('the frame (the header\'s and footer\'s menus) is not decided', $messages);
    $this->assertStringNotContainsString('d:data/events.ts#BookEvent', $messages, 'an interface is not content to decide');
    $this->assertStringNotContainsString('d:lib/api.yaml#api', $messages, 'an API with only a health check holds nothing to decide');
  }

  /**
   * The model as it stands must have been put to droost.
   */
  public function testModelChangedAfterTheConsultFails(): void {
    [$root, $service] = $this->complete();
    $this->rewrite($root, 'model.md', 'three records the cards render', 'the records the cards render');
    $this->assertFinding($service, 'consulted', 'never put to droost');
  }

  /**
   * A question must have an answer on record, and the file must agree.
   */
  public function testAnswersMustBeOnRecordAndAgree(): void {
    [$root, $service] = $this->complete();
    $this->rewrite($root, 'questions.md', '| Q1 | Who edits the events? | Staff editors | Staff editors | human |', '| Q1 | Who edits the events? | Staff editors | Volunteers | human |');
    $this->assertFinding($service, 'answered', 'Q1: the answer on record is "Staff editors", and the file says "Volunteers"');

    $this->rewrite($root, 'questions.md', '| Q1 | Who edits the events? | Staff editors | Volunteers | human |', "| Q1 | Who edits the events? | Staff editors | Staff editors | human |\n| Q2 | Is the events data real? | Placeholder | Real | human |");
    $this->assertFinding($service, 'answered', 'Q2 has no answer on record');
  }

  /**
   * The ladder's shape: order, what each rung consumes, its ticket.
   */
  public function testLadderShapeFails(): void {
    [$root, $service] = $this->complete();
    $this->rewrite($root, 'roadmap.md', '| 2 | tickets/R2.md | the events | 1 |', '| 2 | tickets/R2.md | the events | — |');
    $this->assertFinding($service, 'ladder', 'rung 2 consumes nothing');

    $this->rewrite($root, 'roadmap.md', '| 2 | tickets/R2.md | the events | — |', '| 2 | tickets/R2.md | the events | 3 |');
    $this->assertFinding($service, 'ladder', 'rung 2 consumes rung 3, which is not below it');

    $this->rewrite($root, 'roadmap.md', '| 2 | tickets/R2.md | the events | 3 |', '| 2 | tickets/R2.md | the events | 1 |');
    unlink($root . '/droost/intake/tickets/R3.md');
    $this->assertFinding($service, 'ladder', 'rung 3\'s ticket "tickets/R3.md" is not a file');
  }

  /**
   * A construct in no rung, or shown where only an earlier rung builds, fails.
   */
  public function testConstructOutOfItsRungFails(): void {
    [$root, $service] = $this->complete();
    $this->rewrite($root, 'model.md', '| Main menu | a menu | frame | 1 |', '| Main menu | a menu | frame | 9 |');
    $this->assertFinding($service, 'ladder', '"Main menu" is in no rung');

    $this->rewrite($root, 'model.md', '| Main menu | a menu | frame | 9 |', '| Main menu | a menu | frame | 1 |');
    $this->rewrite($root, 'roadmap.md', '| 2 | tickets/R2.md | the events | 1 | /events, / |', '| 2 | tickets/R2.md | the events | 1 | /events |');
    $this->assertFinding($service, 'ladder', '"Event" is built in rung 2 and shown on /, which only rung(s) 1 build');
  }

  /**
   * No run opens while an intake is open; approval opens the way.
   */
  public function testRunRefusedUntilApproval(): void {
    [$root, $service] = $this->complete();
    try {
      $this->facade()->run($root);
      $this->fail('a run opened while the intake was open');
    }
    catch (IntakeError $e) {
      $this->assertStringContainsString('nothing builds until the human approves the roadmap', $e->getMessage());
    }
    $this->assertFileDoesNotExist((new RunStateStore($root))->path());

    $approved = $service->approve();
    $this->assertSame(IntakeState::APPROVED, $approved->status);
    $this->assertArrayHasKey('model.md', $approved->digest);
    $this->assertFileDoesNotExist($service->store()->stateDir() . '/tool-calls.jsonl', 'the intake\'s consults are not the first run\'s');
    $this->assertFileExists($service->store()->stateDir() . '/history/' . $approved->id . '.tool-calls.jsonl');

    $this->facade()->run($root);
    $this->assertFileExists((new RunStateStore($root))->path());
  }

  /**
   * Approval refuses an intake that is not ready, saying what is missing.
   */
  public function testApprovalRefusesFindings(): void {
    [$root, $service] = $this->complete();
    $this->rewrite($root, 'questions.md', 'Who edits the events?', 'Who writes the events?');
    try {
      $service->approve();
      $this->fail('an intake with an unanswered question was approved');
    }
    catch (IntakeError $e) {
      $this->assertStringContainsString('not ready to approve', $e->getMessage());
      $this->assertStringContainsString('[answered] Q1 has no answer on record', $e->getMessage());
    }
    $this->assertTrue($service->store()->load()?->isOpen());
  }

  /**
   * One intake at a time, and none over an open run.
   */
  public function testOnlyOneIntakeIsOpen(): void {
    [, $service] = $this->complete();
    $this->expectException(IntakeError::class);
    $this->expectExceptionMessageMatches('/intake intake-[0-9a-f]{12} is open/');
    $service->start('again', 'src');
  }

  /**
   * An intake does not open over a run.
   */
  public function testNoIntakeOverAnOpenRun(): void {
    $root = $this->makeRootWithConfig("preset: custom\nmode: interactive\nseekers:\n  on: false\n");
    $this->facade()->run($root);
    $this->expectException(IntakeError::class);
    $this->expectExceptionMessageMatches('/run .* is open/');
    (new IntakeService($root))->start('build me a site', 'src');
  }

  /**
   * Abandoning needs a reason, and closes the intake.
   */
  public function testAbandon(): void {
    [, $service] = $this->complete();
    $closed = $service->abandon('the owner chose another design');
    $this->assertSame(IntakeState::ABANDONED, $closed->status);
    $this->assertSame('the owner chose another design', $closed->reason);
    $types = [];
    foreach ((new RunEventLog($service->store()->stateDir()))->read() as $event) {
      $types[] = $event->type;
    }
    $this->assertSame(['intake.opened', 'intake.abandoned'], $types);
  }

  /**
   * Approve, abandon and answer are the operator's: refused with no terminal.
   */
  public function testOperatorVerbsNeedTheirTerminal(): void {
    if (defined('STDIN') && stream_isatty(STDIN)) {
      $this->markTestSkipped('the suite has a terminal on STDIN; the refusal is for a shell without one');
    }
    [$root] = $this->complete();
    foreach ([['intake', 'approve'], ['intake', 'abandon', 'why'], ['intake', 'answer', 'q', 'a']] as $argv) {
      $lines = [];
      $sink = function (string $line) use (&$lines): void {
        $lines[] = $line;
      };
      $code = (new ArgvDispatcher($sink, $sink, static fn (): string => '2026-10-08T00:00:00+00:00', static fn (): string => 'run-x'))->dispatch($argv, $root);
      $this->assertSame(ArgvDispatcher::EXIT_USAGE, $code, implode(' ', $argv));
      $this->assertStringContainsString('is the operator\'s command', implode("\n", $lines));
    }
    $this->assertTrue((new IntakeStore($root))->load()?->isOpen(), 'nothing was approved or abandoned');
  }

  /**
   * A complete intake: the four files, the audit, the consult, the answer.
   *
   * @return array{string, \Droost\Workflow\Intake\IntakeService}
   *   The project and the service.
   */
  private function complete(): array {
    $root = $this->makeRootWithConfig("preset: custom\nmode: interactive\nseekers:\n  on: false\n");
    $service = new IntakeService($root, static fn (): string => '2026-10-08T00:00:00+00:00');
    $state = $service->start('hey build me a site, no mistakes, off this html', 'src/harbor');
    $dir = $root . '/droost/intake';
    copy(__DIR__ . '/fixtures/audit.json', $dir . '/audit.json');
    $service->store()->save($state->withAudit((string) hash_file('sha256', $dir . '/audit.json'), '2026-10-08T00:01:00+00:00'));
    file_put_contents($dir . '/model.md', self::MODEL . "\n");
    file_put_contents($dir . '/roadmap.md', self::ROADMAP . "\n");
    file_put_contents($dir . '/questions.md', self::QUESTIONS . "\n");
    foreach (['R1', 'R2', 'R3'] as $ticket) {
      file_put_contents($dir . '/tickets/' . $ticket . '.md', "# {$ticket}\n");
    }
    $service->store()->appendAnswer($state->id, 'Who edits the events?', 'Staff editors', 'AskUserQuestion', '2026-10-08T00:02:00+00:00');
    $this->consult($root);
    return [$root, $service];
  }

  /**
   * Writes the consult row droost writes for the model as it stands.
   */
  private function consult(string $root): void {
    $row = [
      'tool' => 'droost_consult',
      'outcome' => 'ok',
      'at' => '2026-10-08T00:03:00+00:00',
      'run' => NULL,
      'phase' => NULL,
      'detail' => ['spec' => 'model.md', 'sha' => hash_file('sha256', $root . '/droost/intake/model.md'), 'items' => []],
    ];
    file_put_contents((new RunStateStore($root))->directory() . '/tool-calls.jsonl', json_encode($row) . "\n", FILE_APPEND);
  }

  /**
   * A facade whose gates all pass and whose vcs knows nothing.
   *
   * @return \Droost\Workflow\WorkflowFacade
   *   The facade.
   */
  private function facade(): WorkflowFacade {
    $executor = new class() implements GateExecutorInterface {

      /**
       * {@inheritdoc}
       */
      public function execute(GateSettings $gate, string $projectRoot): GateResult {
        return GateResult::ran($gate->name, GateStatus::Passed, 0, 1, $gate->name . ' passed', [], $gate->name);
      }

    };
    $vcs = new class() implements VcsInterface {

      /**
       * {@inheritdoc}
       */
      public function head(string $projectRoot): ?string {
        return NULL;
      }

      /**
       * {@inheritdoc}
       */
      public function isRepository(string $projectRoot): bool {
        return FALSE;
      }

      /**
       * {@inheritdoc}
       */
      public function changedFiles(string $projectRoot, ?string $base): array {
        return [];
      }

    };
    $clock = static fn (): string => '2026-10-08T00:00:00+00:00';
    return new WorkflowFacade($executor, new NullSiteDriver(), new RunStateOnlySink(), $clock, static fn (): string => 'run-intake', NULL, $vcs);
  }

  /**
   * Replaces text in one of the intake's files, which must hold it once.
   */
  private function rewrite(string $root, string $file, string $from, string $to): void {
    $path = $root . '/droost/intake/' . $file;
    $text = (string) file_get_contents($path);
    $this->assertSame(1, substr_count($text, $from), sprintf('%s holds "%s" once', $file, $from));
    file_put_contents($path, str_replace($from, $to, $text));
  }

  /**
   * Asserts the check finds one finding of a check, holding a phrase.
   */
  private function assertFinding(IntakeService $service, string $check, string $phrase): void {
    $findings = $service->check();
    $matching = array_filter($findings, static fn (IntakeFinding $f): bool => $f->check === $check && str_contains($f->message, $phrase));
    $this->assertNotEmpty($matching, sprintf("no [%s] finding saying \"%s\"; found:\n%s", $check, $phrase, implode("\n", $this->messages($findings))));
  }

  /**
   * The findings as lines.
   *
   * @param list<\Droost\Workflow\Intake\IntakeFinding> $findings
   *   The findings.
   *
   * @return list<string>
   *   Each as `[check] message`.
   */
  private function messages(array $findings): array {
    return array_map(static fn (IntakeFinding $f): string => sprintf('[%s] %s', $f->check, $f->message), $findings);
  }

}
