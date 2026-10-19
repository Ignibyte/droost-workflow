<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Intake;

use Droost\Workflow\Cli\ArgvDispatcher;
use Droost\Workflow\Config\GateSettings;
use Droost\Workflow\Event\RunEventLog;
use Droost\Workflow\Gate\GateExecutorInterface;
use Droost\Workflow\Gate\GateResult;
use Droost\Workflow\Gate\GateStatus;
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
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The intake: opened, checked, approved or abandoned, and nothing built first.
 *
 * One complete intake passes every check; each wall is then broken alone,
 * from that complete intake, and must fail with its own finding. A check that
 * a complete intake passes and a broken one also passes measures nothing.
 */
#[CoversClass(IntakeCheck::class)]
#[CoversClass(IntakeService::class)]
#[CoversClass(IntakeState::class)]
#[CoversClass(IntakeStore::class)]
#[CoversClass(IntakeTables::class)]
final class IntakeTest extends WorkflowTestCase {

  /**
   * Each service complete() made, and its project.
   *
   * @var \WeakMap<\Droost\Workflow\Intake\IntakeService, string>|null
   */
  private ?\WeakMap $roots = NULL;

  private const MODEL = <<<'MD'
# Harbor Books — content model

## Constructs

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
    $store = $this->store($service);
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
    $state = $this->store($service)->load();
    $this->assertNotNull($state);
    $this->store($service)->save($state->withAudit((string) hash_file('sha256', $root . '/droost/intake/audit.json'), '2026-10-08T00:04:00+00:00'));
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

    $this->yes($service);
    $approved = $service->approve('terminal');
    $this->assertSame(IntakeState::APPROVED, $approved->status);
    $this->assertArrayHasKey('model.md', $approved->digest);
    $this->assertFileDoesNotExist($this->store($service)->stateDir() . '/tool-calls.jsonl', 'the intake\'s knowledge calls are not the first run\'s');
    $this->assertFileExists($this->store($service)->stateDir() . '/history/' . $approved->id . '.tool-calls.jsonl');

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
    $this->assertTrue($this->store($service)->load()?->isOpen());
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
    foreach ((new RunEventLog($this->store($service)->stateDir()))->read() as $event) {
      $types[] = $event->type;
    }
    $this->assertSame(['intake.opened', 'intake.abandoned'], $types);
  }

  /**
   * The agent approves on the human's yes, from a shell with no terminal.
   *
   * Owner, 2026-10-08: approval is not a human-only command. The approval
   * records how it was run and the human's yes the guard recorded.
   */
  public function testTheAgentApprovesOnTheHumansYes(): void {
    [$root, $service] = $this->complete();
    $state = $this->store($service)->load();
    $this->assertNotNull($state);
    $this->store($service)->appendAnswer($state->id, 'Approve this roadmap?', 'Approve', 'AskUserQuestion', '2026-10-08T00:05:00+00:00');
    $lines = [];
    $sink = function (string $line) use (&$lines): void {
      $lines[] = $line;
    };
    $clock = static fn (): string => '2026-10-08T00:00:00+00:00';
    $code = (new ArgvDispatcher($sink, $sink, $clock, static fn (): string => 'run-x'))->dispatch(['intake', 'approve'], $root);
    $this->assertSame(ArgvDispatcher::EXIT_OK, $code, implode("\n", $lines));
    $this->assertSame(IntakeState::APPROVED, $this->store($service)->load()?->status);
    $approved = NULL;
    foreach ((new RunEventLog($this->store($service)->stateDir()))->read() as $event) {
      if ($event->type === 'intake.approved') {
        $approved = $event->payload;
      }
    }
    $this->assertIsArray($approved);
    $this->assertContains($approved['via'], ['agent', 'terminal']);
    $this->assertSame(['question' => 'Approve this roadmap?', 'answer' => 'Approve'], $approved['human'], 'the approval names the human\'s yes');
  }

  /**
   * Abandon and answer are the operator's: refused with no terminal.
   */
  public function testOperatorVerbsNeedTheirTerminal(): void {
    if (defined('STDIN') && stream_isatty(STDIN)) {
      $this->markTestSkipped('the suite has a terminal on STDIN; the refusal is for a shell without one');
    }
    [$root] = $this->complete();
    foreach ([['intake', 'abandon', 'why'], ['intake', 'answer', 'q', 'a']] as $argv) {
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
   * Ask puts a batch on record as pending, and says so in the log.
   */
  public function testAskRecordsPendingAndEmits(): void {
    [$root, $service] = $this->complete();
    $this->batch($root, [$this->question('who edits the events')]);
    $pending = $service->ask('droost/intake/ask.json');
    $options = ['Staff editors', 'Each venue', 'Defer to your recommendation'];
    $expected = [
      [
        'id' => 'Q1',
        'question' => 'who edits the events',
        'options' => $options,
        'recommended' => 0,
        'asked_at' => '2026-10-08T00:00:00+00:00',
      ],
    ];
    $this->assertSame($expected, $pending);
    $this->assertSame($expected, $this->loaded($service)->pending, 'intake.json holds the batch');
    $asked = $this->payloads($service, 'intake.asked');
    $this->assertCount(1, $asked);
    $this->assertSame('2026-10-08T00:00:00+00:00', $asked[0]['asked_at'] ?? NULL);
    $this->assertSame([
      [
        'id' => 'Q1',
        'question' => 'who edits the events',
        'options' => $options,
        'recommended' => 0,
      ],
    ], $asked[0]['questions'] ?? NULL);

    // A second batch replaces the first; the approval question needs no row.
    $this->batch($root, [$this->question('Approve this roadmap?', ['Approve', 'Not yet'])]);
    $pending = $service->ask((string) realpath($root . '/droost/intake/ask.json'));
    $this->assertSame(['approval'], array_column($pending, 'id'));
    $this->assertSame(['approval'], array_column($this->loaded($service)->pending, 'id'));
  }

  /**
   * Ask refuses a batch it cannot put to the human, and changes nothing.
   *
   * @param string $file
   *   Where the batch is, project-relative.
   * @param mixed $body
   *   What it holds: a value to encode, a string written as it is, or NULL
   *   for no file at all.
   * @param string $message
   *   What the refusal says.
   */
  #[DataProvider('refusedBatches')]
  public function testAskRefuses(string $file, mixed $body, string $message): void {
    [$root, $service] = $this->complete();
    if ($body !== NULL) {
      @mkdir(dirname($root . '/' . $file), 0777, TRUE);
      file_put_contents($root . '/' . $file, is_string($body) ? $body : (string) json_encode($body));
    }
    try {
      $service->ask($file);
      $this->fail('the batch was accepted');
    }
    catch (IntakeError $e) {
      $this->assertStringContainsString($message, $e->getMessage());
    }
    $this->assertSame([], $this->loaded($service)->pending);
    $this->assertSame([], $this->payloads($service, 'intake.asked'));
  }

  /**
   * Batches ask refuses, each with what its refusal says.
   *
   * @return array<string, array{string, mixed, string}>
   *   The cases.
   */
  public static function refusedBatches(): array {
    $q = [
      'question' => 'Who edits the events?',
      'options' => ['Staff editors', 'Each venue'],
      'recommended' => 0,
    ];
    $one = static fn (array $change): array => ['questions' => [$change + $q]];
    $batch = 'droost/intake/ask.json';
    return [
      'outside the intake' => ['droost/ask.json', $one([]), 'is not a file in droost/intake/'],
      'missing' => ['droost/intake/none.json', NULL, 'is not a file in droost/intake/'],
      'not JSON' => [$batch, '{not json', 'is not JSON with a "questions" list'],
      'no questions' => [$batch, ['questions' => []], 'ask 1 to 4 questions at a time'],
      'five questions' => [$batch, ['questions' => array_fill(0, 5, $q)], 'ask 1 to 4 questions at a time'],
      'not in questions.md' => [$batch, $one(['question' => 'Is the shop real?']), 'is no row of questions.md'],
      'one option' => [$batch, $one(['options' => ['Staff editors']]), 'needs 2 to 6 "options"'],
      'seven options' => [$batch, $one(['options' => array_map('strval', range(1, 7))]), 'needs 2 to 6 "options"'],
      'a long option' => [$batch, $one(['options' => ['Staff', str_repeat('x', 201)]]), 'not text of 1 to 200 bytes'],
      'a bad recommendation' => [$batch, $one(['recommended' => 2]), '"recommended" is not the index'],
      'a repeat' => [$batch, ['questions' => [$q, $q]], 'question 2 repeats question 1'],
      'a long question' => [
        $batch,
        $one(['question' => str_repeat('q', 1001)]),
        'needs "question" text of 1 to 1000 bytes',
      ],
      'an option twice' => [
        $batch,
        $one(['options' => ['Staff editors', 'staff editors']]),
        'offers "staff editors" twice',
      ],
      'approval with other options' => [
        $batch,
        $one(['question' => 'Approve this roadmap?', 'options' => ['Yes', 'No']]),
        'offers exactly Approve and Not yet',
      ],
    ];
  }

  /**
   * Ask needs an open intake.
   */
  public function testAskNeedsAnOpenIntake(): void {
    $root = $this->makeRootWithConfig("preset: custom\nmode: interactive\nseekers:\n  on: false\n");
    $this->expectException(IntakeError::class);
    $this->expectExceptionMessage('no intake is open');
    (new IntakeService($root))->ask('droost/intake/ask.json');
  }

  /**
   * Status reports the pending questions and every answer on record.
   */
  public function testStatusReportsPendingAndRecorded(): void {
    [$root, $service] = $this->complete();
    $this->batch($root, [$this->question('Who edits the events?')]);
    $service->ask('droost/intake/ask.json');
    $status = $service->status();
    $this->assertSame($this->loaded($service)->pending, $status['pending'] ?? NULL);
    $this->assertSame([
      [
        'question' => 'Who edits the events?',
        'answer' => 'Staff editors',
        'via' => 'AskUserQuestion',
        'at' => '2026-10-08T00:02:00+00:00',
      ],
    ], $status['recorded'] ?? NULL);
    $this->assertSame(1, $status['answers'] ?? NULL);
  }

  /**
   * Answer clears the question it answers, and records how it came.
   */
  public function testAnswerClearsPendingAndRecordsVia(): void {
    [$root, $service] = $this->complete();
    $this->batch($root, [
      $this->question('Who edits the events?'),
      $this->question('Approve this roadmap?', ['Approve', 'Not yet']),
    ]);
    $service->ask('droost/intake/ask.json');
    $service->answer('who edits the events', 'Staff editors', 'cockpit');
    $this->assertSame(['approval'], array_column($this->loaded($service)->pending, 'id'), 'only the unanswered question is left');
    $this->assertSame('cockpit', $this->lastRecord($service)['via']);
    $answered = $this->payloads($service, 'intake.answered');
    $this->assertSame('cockpit', $answered[count($answered) - 1]['via'] ?? NULL);

    $service->answer('Approve this roadmap?', 'Not yet');
    $this->assertSame([], $this->loaded($service)->pending);
    $this->assertSame('terminal', $this->lastRecord($service)['via'], 'terminal by default');
  }

  /**
   * Answer knows two ways an answer comes, and refuses any other.
   */
  public function testAnswerRefusesAnUnknownVia(): void {
    [, $service] = $this->complete();
    try {
      $service->answer('Who edits the events?', 'Staff editors', 'agent');
      $this->fail('an answer via the agent was recorded');
    }
    catch (IntakeError $e) {
      $this->assertStringContainsString('--via is terminal or cockpit, not "agent"', $e->getMessage());
    }
    $this->assertCount(1, $this->store($service)->records($this->loaded($service)->id), 'nothing was recorded');
  }

  /**
   * Approval needs the human's yes on record, and only a yes.
   */
  public function testApprovalNeedsTheHumansYes(): void {
    [$root, $service] = $this->complete();
    $id = $this->loaded($service)->id;
    $this->assertApprovalRefused($service, 'no yes is on record');
    foreach (['Not yet', 'Approve? No.', 'Yes, but change rung 3', 'Approve with changes'] as $n => $answer) {
      $this->store($service)->appendAnswer($id, 'Approve this roadmap?', $answer, 'cockpit', '2026-10-08T00:0' . $n . ':00+00:00');
      $this->assertApprovalRefused($service, 'answered "' . $answer . '" to "Approve this roadmap?", which is not a yes');
    }

    // A question that only mentions approval is not the roadmap's approval.
    $this->rewrite($root, 'questions.md', '| Q1 | Who edits the events? | Staff editors | Staff editors | human |', "| Q1 | Who edits the events? | Staff editors | Staff editors | human |\n| Q2 | Do posts need approval before publishing? | No | Yes | human |");
    $this->store($service)->appendAnswer($id, 'Do posts need approval before publishing?', 'Yes', 'cockpit', '2026-10-08T00:05:00+00:00');
    $this->assertApprovalRefused($service, 'answered "Approve with changes"');

    $this->store($service)->appendAnswer($id, 'approve this roadmap', 'Approve (Recommended)', 'cockpit', '2026-10-08T00:06:00+00:00');
    $this->assertSame(IntakeState::APPROVED, $service->approve('agent')->status, 'the latest answer is a yes');
    $this->assertSame([], $this->loaded($service)->pending);
  }

  /**
   * A terminal proves nothing: the operator records their yes too.
   */
  public function testTheOperatorsApprovalNeedsTheirYesOnRecord(): void {
    [, $service] = $this->complete();
    $this->assertApprovalRefused($service, 'intake answer "Approve this roadmap?" "Approve"', 'terminal');
    $this->yes($service);
    $this->assertSame(IntakeState::APPROVED, $service->approve('terminal')->status);
  }

  /**
   * An approval question asked again waits for its answer.
   */
  public function testApprovalWaitsWhileItsQuestionIsPending(): void {
    [$root, $service] = $this->complete();
    $this->yes($service);
    $this->batch($root, [$this->question('Approve this roadmap?', ['Approve', 'Not yet'])]);
    $service->ask('droost/intake/ask.json');
    $this->assertApprovalRefused($service, '"Approve this roadmap?" is pending');
  }

  /**
   * The approval's digest holds each rung's ticket.
   *
   * A host that imports the tickets can then tell they are the ones the
   * human approved. A ticket kept outside droost/intake is keyed under the
   * project, never by a path that differs between machines.
   */
  public function testApprovalDigestsTheRungTickets(): void {
    [$root, $service] = $this->complete();
    @mkdir($root . '/design', 0777, TRUE);
    file_put_contents($root . '/design/R3.md', "# R3, kept with the design\n");
    $this->rewrite($root, 'roadmap.md', '| 3 | tickets/R3.md |', '| 3 | design/R3.md |');
    $this->yes($service);
    $digest = $service->approve('terminal')->digest;
    foreach (['tickets/R1.md', 'tickets/R2.md'] as $ticket) {
      $this->assertSame(hash_file('sha256', $root . '/droost/intake/' . $ticket), $digest[$ticket] ?? NULL, $ticket);
    }
    $this->assertSame(hash_file('sha256', $root . '/design/R3.md'), $digest['design/R3.md'] ?? NULL, 'keyed under the project');
    $this->assertSame(hash_file('sha256', $root . '/droost/intake/roadmap.md'), $digest['roadmap.md'] ?? NULL);
    foreach (array_keys($digest) as $key) {
      $this->assertStringStartsNotWith('/', (string) $key, 'no absolute key');
    }
  }

  /**
   * Ask jails its batch to the intake's directory, links resolved.
   */
  public function testAskRefusesLinksOutOfTheIntake(): void {
    [$root, $service] = $this->complete();
    file_put_contents($root . '/outside.json', (string) json_encode(['questions' => [$this->question('Who edits the events?')]]));
    symlink($root . '/outside.json', $root . '/droost/intake/linked.json');
    foreach (['droost/intake/linked.json', 'droost/intake/../../outside.json'] as $file) {
      try {
        $service->ask($file);
        $this->fail($file . ' was read');
      }
      catch (IntakeError $e) {
        $this->assertStringContainsString('is not a file in droost/intake/', $e->getMessage(), $file);
      }
    }
    $this->assertSame([], $this->loaded($service)->pending);
  }

  /**
   * The asked event carries what its schema requires.
   */
  public function testTheAskedEventIsShapedAsItsSchemaSays(): void {
    [$root, $service] = $this->complete();
    $this->batch($root, [$this->question('Who edits the events?')]);
    $service->ask('droost/intake/ask.json');
    $schema = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/schema/run-event.v1.json'), TRUE);
    $def = is_array($schema) && is_array($schema['$defs'] ?? NULL) ? ($schema['$defs']['intake.asked'] ?? NULL) : NULL;
    $this->assertIsArray($def, 'the schema defines intake.asked');
    $items = is_array($def['properties'] ?? NULL) && is_array($def['properties']['questions'] ?? NULL) ? ($def['properties']['questions']['items'] ?? NULL) : NULL;
    $this->assertIsArray($items);
    $payload = $this->payloads($service, 'intake.asked')[0] ?? [];
    foreach (is_array($def['required'] ?? NULL) ? $def['required'] : [] as $key) {
      $this->assertArrayHasKey(is_string($key) ? $key : '', $payload);
    }
    $questions = $payload['questions'] ?? NULL;
    $this->assertIsArray($questions);
    foreach ($questions as $question) {
      $this->assertIsArray($question);
      foreach (is_array($items['required'] ?? NULL) ? $items['required'] : [] as $key) {
        $this->assertArrayHasKey(is_string($key) ? $key : '', $question);
      }
      $this->assertIsInt($question['recommended'] ?? NULL);
    }
  }

  /**
   * An intake.json written before pending existed still loads.
   */
  public function testAnOlderStateFileLoads(): void {
    [, $service] = $this->complete();
    $path = $this->store($service)->stateDir() . '/intake.json';
    $data = json_decode((string) file_get_contents($path), TRUE);
    $this->assertIsArray($data);
    unset($data['pending']);
    file_put_contents($path, (string) json_encode($data));
    $this->assertSame([], $this->loaded($service)->pending);
  }

  /**
   * The CLI refuses an answer option it would misread, terminal or not.
   */
  public function testTheCliRefusesAnOptionWithoutItsValue(): void {
    [$root, $service] = $this->complete();
    $lines = [];
    $sink = function (string $line) use (&$lines): void {
      $lines[] = $line;
    };
    $cli = new ArgvDispatcher($sink, $sink, static fn (): string => '2026-10-08T00:00:00+00:00', static fn (): string => 'run-x');
    $code = $cli->dispatch(['intake', 'answer', '--via', 'cockpit', 'Who edits the events?', 'Me'], $root);
    $this->assertSame(ArgvDispatcher::EXIT_USAGE, $code);
    $this->assertStringContainsString('with its "=": "--via" is not one', implode("\n", $lines));
    $this->assertCount(1, $this->store($service)->records($this->loaded($service)->id), 'nothing was recorded');
  }

  /**
   * The CLI's ask prints the pending batch.
   */
  public function testTheCliAsks(): void {
    [$root, $service] = $this->complete();
    $this->batch($root, [$this->question('Who edits the events?')]);
    $lines = [];
    $sink = function (string $line) use (&$lines): void {
      $lines[] = $line;
    };
    $cli = new ArgvDispatcher($sink, $sink, static fn (): string => '2026-10-08T00:00:00+00:00', static fn (): string => 'run-x');
    $code = $cli->dispatch(['intake', 'ask', '--file=droost/intake/ask.json'], $root);
    $this->assertSame(ArgvDispatcher::EXIT_OK, $code, implode("\n", $lines));
    $this->assertStringContainsString('"id": "Q1"', implode("\n", $lines));
    $this->assertSame(['Q1'], array_column($this->loaded($service)->pending, 'id'));
  }

  /**
   * Writes an ask batch to droost/intake/ask.json.
   *
   * @param string $root
   *   The project.
   * @param list<array<string, mixed>> $questions
   *   The questions.
   */
  private function batch(string $root, array $questions): void {
    file_put_contents($root . '/droost/intake/ask.json', (string) json_encode(['questions' => $questions]));
  }

  /**
   * One batch entry.
   *
   * @param string $question
   *   Its words.
   * @param list<string>|null $options
   *   Its options; three by default.
   *
   * @return array<string, mixed>
   *   The entry.
   */
  private function question(string $question, ?array $options = NULL): array {
    return [
      'question' => $question,
      'options' => $options ?? ['Staff editors', 'Each venue', 'Defer to your recommendation'],
      'recommended' => 0,
    ];
  }

  /**
   * Records the human's yes to the approval question.
   *
   * @param \Droost\Workflow\Intake\IntakeService $service
   *   The service.
   */
  private function yes(IntakeService $service): void {
    $this->store($service)->appendAnswer($this->loaded($service)->id, 'Approve this roadmap?', 'Approve', 'terminal', '2026-10-08T00:09:00+00:00');
  }

  /**
   * Asserts approval is refused, saying why, and the intake stays open.
   *
   * @param \Droost\Workflow\Intake\IntakeService $service
   *   The service.
   * @param string $message
   *   What the refusal says.
   * @param string $via
   *   How approve is run.
   */
  private function assertApprovalRefused(IntakeService $service, string $message, string $via = 'agent'): void {
    try {
      $service->approve($via);
      $this->fail('approved: ' . $message);
    }
    catch (IntakeError $e) {
      $this->assertStringContainsString($message, $e->getMessage());
    }
    $this->assertTrue($this->loaded($service)->isOpen());
  }

  /**
   * The intake as the store holds it now.
   *
   * @param \Droost\Workflow\Intake\IntakeService $service
   *   The service.
   *
   * @return \Droost\Workflow\Intake\IntakeState
   *   The state.
   */
  private function loaded(IntakeService $service): IntakeState {
    $state = $this->store($service)->load();
    $this->assertNotNull($state);
    return $state;
  }

  /**
   * The latest answer row on record.
   *
   * @param \Droost\Workflow\Intake\IntakeService $service
   *   The service.
   *
   * @return array{question: string, answer: string, via: string, at: string}
   *   The row.
   */
  private function lastRecord(IntakeService $service): array {
    $records = $this->store($service)->records($this->loaded($service)->id);
    $this->assertNotSame([], $records);
    return $records[count($records) - 1];
  }

  /**
   * The payloads of one event type in the intake's log, oldest first.
   *
   * @param \Droost\Workflow\Intake\IntakeService $service
   *   The service.
   * @param string $type
   *   The event type.
   *
   * @return list<array<string, mixed>>
   *   The payloads.
   */
  private function payloads(IntakeService $service, string $type): array {
    $out = [];
    foreach ((new RunEventLog($this->store($service)->stateDir()))->read() as $event) {
      if ($event->type === $type) {
        $out[] = $event->payload;
      }
    }
    return $out;
  }

  /**
   * The intake's store, read from the project's files as every surface does.
   *
   * @param \Droost\Workflow\Intake\IntakeService $service
   *   A service complete() made.
   *
   * @return \Droost\Workflow\Intake\IntakeStore
   *   The store.
   */
  private function store(IntakeService $service): IntakeStore {
    $root = $this->roots[$service] ?? NULL;
    $this->assertIsString($root, 'the service came from complete()');
    return new IntakeStore($root);
  }

  /**
   * A complete intake: the four files, the audit, a look, the answer.
   *
   * @return array{string, \Droost\Workflow\Intake\IntakeService}
   *   The project and the service.
   */
  private function complete(): array {
    $root = $this->makeRootWithConfig("preset: custom\nmode: interactive\nseekers:\n  on: false\n");
    $service = new IntakeService($root, static fn (): string => '2026-10-08T00:00:00+00:00');
    $this->roots ??= new \WeakMap();
    $this->roots[$service] = $root;
    $state = $service->start('hey build me a site, no mistakes, off this html', 'src/harbor');
    $dir = $root . '/droost/intake';
    copy(__DIR__ . '/fixtures/audit.json', $dir . '/audit.json');
    $this->store($service)->save($state->withAudit((string) hash_file('sha256', $dir . '/audit.json'), '2026-10-08T00:01:00+00:00'));
    file_put_contents($dir . '/model.md', self::MODEL . "\n");
    file_put_contents($dir . '/roadmap.md', self::ROADMAP . "\n");
    file_put_contents($dir . '/questions.md', self::QUESTIONS . "\n");
    foreach (['R1', 'R2', 'R3'] as $ticket) {
      file_put_contents($dir . '/tickets/' . $ticket . '.md', "# {$ticket}\n");
    }
    $this->store($service)->appendAnswer($state->id, 'Who edits the events?', 'Staff editors', 'AskUserQuestion', '2026-10-08T00:02:00+00:00');
    $this->looked($root);
    return [$root, $service];
  }

  /**
   * Writes the row droost writes when the intake reads the site.
   */
  private function looked(string $root): void {
    $row = [
      'tool' => 'droost_search',
      'outcome' => 'ok',
      'at' => '2026-10-08T00:03:00+00:00',
      'run' => NULL,
      'phase' => NULL,
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
    return new WorkflowFacade($executor, new RunStateOnlySink(), $clock, static fn (): string => 'run-intake', NULL, $vcs);
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
