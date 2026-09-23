<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Evidence;

use Droost\Workflow\Evidence\CheckRecord;
use Droost\Workflow\Evidence\CheckState;
use Droost\Workflow\Evidence\EvaluationReport;
use Droost\Workflow\Evidence\EvidenceStore;
use Droost\Workflow\Evidence\SubjectHasher;
use Droost\Workflow\Evidence\Fault;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The evaluation renders itself, and refuses to render its own verdict.
 *
 * Two halves, and the second matters more. The first is that the queryable
 * facts of a round come out as a document nobody has to re-derive by hand. The
 * second is that the three sections which are NOT facts stay unwritten, and say
 * why in the output — because a generated build verdict would agree with the
 * subject by construction, and a generated score would look exactly like one
 * somebody earned.
 */
#[CoversClass(EvaluationReport::class)]
final class EvaluationReportTest extends TestCase {

  use ReadsTheReport;

  /**
   * A scratch project root, removed after each test.
   */
  private string $root;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    $this->root = sys_get_temp_dir() . '/droost-evaluation-' . bin2hex(random_bytes(6));
    mkdir($this->root, 0775, TRUE);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    if (is_dir($this->root)) {
      exec('rm -rf ' . escapeshellarg($this->root));
    }
  }

  /**
   * A run with no rows renders a real document that says so.
   *
   * The empty case is the one most likely to be met in anger: a run id typed
   * wrong, or a recorder that failed silently while the phases ran. A crash
   * there tells the reader nothing, and a document full of blanks tells them
   * something false.
   */
  public function testEmptyRunRendersEveryHeadingAndSaysNothingWasRecorded(): void {
    $report = (new EvaluationReport(new EvidenceStore($this->root)))->render('nobody');

    $this->assertStringContainsString('# Evaluation — `nobody`', $report);
    $this->assertStringContainsString('**This run has no rows.**', $report);
    foreach ([
      '## 1. Round identity',
      '## 2. Environment',
      '## 3. The lever table',
      '## 4. Gate verdicts',
      '## 4a. The tool-call ledger',
      '## 4b. Grounding',
      '## 5. Build verdict',
      '## 6. Score',
      '## 7. Observability',
      '## 8. Findings',
    ] as $heading) {
      $this->assertStringContainsString($heading, $report, $heading . ' is missing');
    }
    $this->assertStringContainsString(EvaluationReport::NOT_RECORDED, $report);
  }

  /**
   * No cell anywhere is blank — not a value cell, not a header.
   *
   * "An empty cell is an unmeasured thing wearing the costume of a measured
   * one" is the template's own first instruction, and it bites harder in a
   * generated document: a blank reads as a zero somebody measured rather than
   * as an absence nobody recorded.
   */
  public function testNoCellIsEverBlank(): void {
    $this->seed();
    $report = (new EvaluationReport(new EvidenceStore($this->root)))->render('r1');

    $this->assertStringNotContainsString('|  |', $report);
    $this->assertStringNotContainsString('| |', $report);
  }

  /**
   * The template path points where a project reads it, not where it ships.
   *
   * `pack/templates/evaluation.md` is the source in this repo; `init`
   * installs it at `.claude/templates/evaluation.md`, and that is the only
   * copy an installed project has. The rendered document told the reader to
   * "Fill it from `pack/templates/…`", a path absent in their project.
   */
  public function testTheTemplatePathIsTheInstalledCopy(): void {
    $this->seed();
    $report = (new EvaluationReport(new EvidenceStore($this->root)))->render('r1');

    $this->assertStringContainsString('.claude/templates/evaluation.md', $report, 'the installed copy');
    $this->assertStringNotContainsString(
      'from `pack/templates/evaluation.md`',
      $report,
      'not the source path, which an installed project does not have',
    );
  }

  /**
   * The last column separates a green from a measurement.
   *
   * Fourteen of fifteen gates can report green having measured nothing, and
   * there are two independent ways to do it: a state that is honest but is not
   * a measurement, and a measured state whose tool never spawned. Both are
   * answered per gate rather than left to a reader who is tired by §4.
   */
  public function testMeasuredColumnTellsGreenFromWhatWasMeasured(): void {
    $store = new EvidenceStore($this->root);
    $store->record('r1', 'code', new CheckRecord(
      'gate', 'phpcs', CheckState::Satisfied, Fault::None, 'passed', NULL, NULL, 0, 'phpcs', NULL, 880,
    ));
    $store->record('r1', 'code', new CheckRecord(
      'gate', 'phpstan', CheckState::Satisfied, Fault::None, 'nothing to analyse', NULL, NULL, 0, 'phpstan', NULL, 0,
    ));
    $store->record('r1', 'test', new CheckRecord(
      'gate', 'playwright', CheckState::NotApplicable, Fault::None, 'off — by preset low',
    ));
    $store->record('r1', 'test', new CheckRecord(
      'gate', 'mutation', CheckState::Unblocked, Fault::None, 'lifted by the operator',
    ));
    $store->record('r1', 'test', new CheckRecord(
      'gate', 'module:snyk', CheckState::Recorded, Fault::None, '2 issues, mode: report', NULL, NULL, 1, 'snyk', NULL, 4200,
    ));
    // Report mode demoted a tool the shell could not find (KCH-3's snyk): the
    // row carries exit 127, a duration, and measured = FALSE.
    $store->record('r1', 'test', new CheckRecord(
      'gate', 'module:absent', CheckState::Recorded, Fault::None, 'report — could not run: absent', NULL, NULL, 127, 'absent', NULL, 9, [], '', '', '', FALSE,
    ));
    // And one the executor refused to spawn at all: no exit code to store.
    $store->record('r1', 'test', new CheckRecord(
      'gate', 'module:unspawned', CheckState::Recorded, Fault::None, 'report — could not run: unspawned', NULL, NULL, NULL, 'unspawned', NULL, NULL, [], '', '', '', FALSE,
    ));

    $verdicts = $this->verdicts((new EvaluationReport($store))->render('r1'));

    $this->assertSame('yes', $verdicts['phpcs']);
    $this->assertSame(
      'no — the tool never spawned (`duration_ms: 0`)',
      $verdicts['phpstan'],
      'a pass that took no time is a tool that never ran',
    );
    $this->assertSame(
      'no — the tool was not found on the PATH (exit 127); recorded, not blocking',
      $verdicts['module:absent'],
      'the shell\'s 127 is a fact, not an "unproven"',
    );
    $this->assertSame(
      'no — the tool could not run (binary or config absent); recorded, not blocking',
      $verdicts['module:unspawned'],
    );
    $this->assertSame('no — not applicable', $verdicts['playwright']);
    $this->assertSame(
      'no — unblocked by the operator',
      $verdicts['mutation'],
      'an operator lifting a block is honest, and is not a measurement',
    );
    $this->assertSame(
      'yes',
      $verdicts['module:snyk'],
      'a gate in report mode measured exactly as much as one in block mode',
    );
  }

  /**
   * A state this build does not know is never read as a measurement.
   *
   * The store is deliberately forward-readable: a database written by a newer
   * droost is read rather than refused, because the evidence is the point. That
   * generosity must not extend to scoring — a state nobody here can interpret
   * is the one thing that must not come out as "yes, measured".
   */
  public function testUnknownStateIsNeverReadAsMeasured(): void {
    $store = new EvidenceStore($this->root);
    $store->connection()->prepare(
      'INSERT INTO check_result
        (run_id, phase, attempt, kind, name, state, fault, summary, duration_ms, adjudicated_at)
       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([
      'r1', 'code', 1, 'gate', 'phpcs', 'transcended', 'none',
      'from a later droost', 900, '2026-09-13T09:00:00+00:00',
    ]);

    $verdicts = $this->verdicts((new EvaluationReport($store))->render('r1'));

    $this->assertSame('no — the stored state is not one this build knows', $verdicts['phpcs']);
  }

  /**
   * Every superseded attempt survives into the report.
   *
   * The old run record kept the last attempt and a counter, so a round could
   * prove the feedback loop had fired and never what it corrected. The store is
   * append-only; this is the reader that makes that worth something.
   */
  public function testEarlierAttemptsSurviveIntoTheReport(): void {
    $store = new EvidenceStore($this->root);
    $store->record('r1', 'code', new CheckRecord(
      'gate', 'phpstan', CheckState::Blocked, Fault::Agent, '2 errors on a new method', NULL, NULL, 1, 'phpstan', NULL, 700,
    ));
    $store->record('r1', 'code', new CheckRecord(
      'gate', 'phpstan', CheckState::Satisfied, Fault::None, 'phpstan passed', NULL, NULL, 0, 'phpstan', NULL, 720,
    ));

    $report = (new EvaluationReport($store))->render('r1');

    $this->assertStringContainsString('### Earlier attempts', $report);
    $this->assertStringContainsString('2 attempts, ending satisfied', $report);
    $this->assertStringContainsString('2 errors on a new method', $report);
    $this->assertSame('yes', $this->verdicts($report)['phpstan'], 'the headline row is the latest attempt');
  }

  /**
   * A gate that never ran has no earlier attempts worth the name.
   *
   * Off and no-site gates are recorded once per phase attempt too, so a
   * ticket with ONE real phpstan retry listed twenty "Earlier attempts" —
   * nineteen of them "eslint in code — 4 attempts, ending not applicable" —
   * under a heading promising "the feedback loop, with what it corrected".
   */
  public function testEarlierAttemptsSkipGatesThatNeverRan(): void {
    $store = new EvidenceStore($this->root);
    foreach ([1, 2, 3, 4] as $attempt) {
      $store->record('r1', 'code', new CheckRecord(
        'gate', 'eslint', CheckState::NotApplicable, Fault::None, 'off — by preset, attempt ' . $attempt,
      ));
    }
    $store->record('r1', 'code', new CheckRecord(
      'gate', 'phpstan', CheckState::Blocked, Fault::Agent, '1 error', NULL, NULL, 1, 'phpstan', NULL, 700,
    ));
    $store->record('r1', 'code', new CheckRecord(
      'gate', 'phpstan', CheckState::Satisfied, Fault::None, 'phpstan passed', NULL, NULL, 0, 'phpstan', NULL, 720,
    ));

    $report = (new EvaluationReport($store))->render('r1');

    $this->assertStringContainsString('2 attempts, ending satisfied', $report, 'the real retry is listed');
    $this->assertStringNotContainsString('4 attempts', $report, 'a gate that never ran is not a retry');
    $this->assertStringNotContainsString('`eslint` in `code` — 4', $report);
  }

  /**
   * Rounds are counted as rounds, findings as findings, and clean rounds show.
   *
   * Every ledger restates the whole set, so a finding appears once per round
   * that mentions it — and §4d counted ROWS: "4 findings recorded, 2 still
   * open" for two findings across two rounds, with "still open" counting a
   * row a later round had resolved. And a clean round wrote no row, so the
   * inspection that cleared the board was invisible.
   */
  public function testSeekerRoundsAreCountedAsRoundsAndFindingsAsFindings(): void {
    $store = new EvidenceStore($this->root);
    $store->upsertRun('r1', ['preset' => 'max']);
    $f1 = ['id' => 'F1', 'severity' => 'MEDIUM', 'location' => 'src/a.php', 'finding' => 'dead branch'];
    $f2 = ['id' => 'F2', 'severity' => 'LOW', 'location' => 'src/b.php', 'finding' => 'stale comment'];
    $store->recordSeekerFindings('r1', 'code', 1, [
      $f1 + ['status' => 'open'],
      $f2 + ['status' => 'open'],
    ]);
    $store->recordSeekerFindings('r1', 'code', 2, [
      $f1 + ['status' => 'resolved'],
    ]);
    $store->recordSeekerFindings('r1', 'complete', 3, []);

    $report = (new EvaluationReport($store))->render('r1');

    $this->assertStringContainsString(
      '**2 distinct findings across 3 rounds; 1 still open**',
      $report,
      'F1 and F2 once each; F1 resolved by its latest round, F2 open by its',
    );
    $this->assertStringContainsString('- round 3: clean — the inspection found nothing', $report, 'the clean round is visible');
    $this->assertStringContainsString('- round 1: 2 findings', $report);
    $this->assertStringNotContainsString('3 findings recorded', $report, 'rows are not findings');
  }

  /**
   * An environment block prints its remedy; nothing else may.
   *
   * A remedy printed beside work the agent must simply do reads as a way out of
   * doing it. In a live round an agent asked to WAIVE the gate holding back an
   * HTML-entity-encoded `javascript:` URL. The report is one more surface that
   * must not offer that door.
   */
  public function testOnlyAnEnvironmentBlockPrintsItsRemedy(): void {
    $store = new EvidenceStore($this->root);
    $store->record('r1', 'test', new CheckRecord(
      'gate', 'phpunit', CheckState::Blocked, Fault::Environment,
      'no phpunit.xml at the project root', 'drush droost:workflow:install', NULL, 2, 'phpunit', NULL, 9,
    ));
    $store->record('r1', 'test', new CheckRecord(
      'gate', 'phpcs', CheckState::Blocked, Fault::Agent, '3 errors', NULL, NULL, 2, 'phpcs', NULL, 800,
    ));

    $report = (new EvaluationReport($store))->render('r1');

    $this->assertStringContainsString('### Environment blocks, and who may lift them', $report);
    $this->assertStringContainsString('remedy: `drush droost:workflow:install`', $report);
    $this->assertStringContainsString('OPERATOR lifts', $report);
    $this->assertSame(
      1,
      substr_count($report, '- remedy: `'),
      'the agent block is offered no way out, so exactly one remedy is printed',
    );
  }

  /**
   * The knowledge-to-router ratio is counted, not claimed.
   *
   * This is the number that exposed the original defect: 6 knowledge calls
   * against 179 router calls across 39 rounds, in a system whose reports all
   * said the codebase had been consulted.
   */
  public function testKnowledgeToRouterRatioIsCountedFromTheLedger(): void {
    $store = new EvidenceStore($this->root);
    for ($i = 0; $i < 7; $i++) {
      $store->recordToolCall('r1', 'plan', EvaluationReport::ROUTER_TOOL, 'ok');
    }
    $store->recordToolCall('r1', 'plan', 'droost_symbol', 'ok');
    $store->recordToolCall('r1', 'code', 'droost_search', 'ok');
    $store->recordToolCall('r1', 'code', 'droost_scaffold', 'fail');

    $report = (new EvaluationReport($store))->render('r1');

    $this->assertStringContainsString('| **Knowledge : router ratio** | 2 : 7 |', $report);
    $this->assertStringContainsString('| Total calls | 10 |', $report);
    $this->assertStringContainsString('| Distinct tools | 4 |', $report);
    $this->assertStringContainsString('| Refusals (`outcome: fail`) | 1 |', $report);
    $this->assertStringContainsString('| `droost_search` | 1 | knowledge |', $report);
    $this->assertStringContainsString('| `droost_scaffold` | 1 | — |', $report);
  }

  /**
   * A run that asked the codebase nothing is told it asked nothing.
   *
   * The grounding table is prose the agent writes about itself; the ledger is
   * not. A round can cite perfectly and have looked nothing up, and only the
   * count says so.
   */
  public function testZeroKnowledgeCallsSayTheCodebaseWasNeverAsked(): void {
    $store = new EvidenceStore($this->root);
    $store->recordToolCall('r1', 'plan', EvaluationReport::ROUTER_TOOL, 'ok');

    $report = (new EvaluationReport($store))->render('r1');

    $this->assertStringContainsString('**zero here means the run never asked the codebase anything**', $report);
    $this->assertStringContainsString('| **Knowledge : router ratio** | 0 : 1 |', $report);
  }

  /**
   * All ten knowledge tools are counted as knowledge, and nothing else is.
   *
   * The list is duplicated from GroundingResolver in the Drupal module, which
   * this package must not depend on. Duplication that nobody watches drifts,
   * and the drift here is invisible from the output: a run grounded through a
   * tool one list has and the other does not would render as a run that asked
   * the codebase nothing. So the ratio is driven through every name, and the
   * count it prints is what pins the list at twelve.
   */
  public function testEveryKnowledgeToolIsCountedAsKnowledge(): void {
    $store = new EvidenceStore($this->root);
    foreach (EvaluationReport::KNOWLEDGE_TOOLS as $tool) {
      $store->recordToolCall('r1', 'plan', $tool, 'ok');
    }
    $store->recordToolCall('r1', 'plan', EvaluationReport::ROUTER_TOOL, 'ok');
    $store->recordToolCall('r1', 'code', 'droost_scaffold', 'ok');

    $report = (new EvaluationReport($store))->render('r1');

    $this->assertStringContainsString(
      '| **Knowledge : router ratio** | 12 : 1 |',
      $report,
      'twelve names in, twelve knowledge calls out',
    );
    foreach (EvaluationReport::KNOWLEDGE_TOOLS as $tool) {
      $this->assertStringContainsString('| `' . $tool . '` | 1 | knowledge |', $report);
    }
    $this->assertStringContainsString('| `droost_scaffold` | 1 | — |', $report);
  }

  /**
   * Grounding names all three tiers, including the one with no rows.
   *
   * An absent tier is a reading, not a gap. Most often it is `contrib`, whose
   * absence means nobody asked whether a module already does this — the most
   * valuable row in the table and the easiest to leave out.
   */
  public function testGroundingNamesEveryTierIncludingTheEmptyOne(): void {
    $store = new EvidenceStore($this->root);
    $store->connection()->prepare(
      'INSERT INTO grounding_row (run_id, phase, tier, asked, found, citation, resolved, store)
       VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute(['r1', 'plan', 'custom', 'is there a rink bundle?', 'nothing matched', 'none:rink', 1, 'symbol graph']);

    $report = (new EvaluationReport($store))->render('r1');

    $this->assertStringContainsString('| `custom` | 1 | 1 | 1 | symbol graph |', $report);
    $this->assertStringContainsString('| `contrib` | 0 | 0 | 0 | ' . EvaluationReport::NOT_RECORDED . ' |', $report);
    $this->assertStringContainsString('| `core` | 0 | 0 | 0 | ', $report);
  }

  /**
   * With no grounding rows the section says so rather than implying a verdict.
   */
  public function testGroundingWithNoRowsSaysSoPlainly(): void {
    $this->seed();

    $report = (new EvaluationReport(new EvidenceStore($this->root)))->render('r1');

    $this->assertStringContainsString('**The store holds no grounding rows for this run.**', $report);
    $this->assertStringContainsString('That is not a', $report);
  }

  /**
   * Every gate droost knows is named, whether or not it ever ran.
   *
   * A gate missing from the report reads as a gate nobody thought about. A gate
   * present with no adjudication reads as what it is — and the text says the
   * thing the record genuinely cannot tell: never-reached is not off.
   */
  public function testEveryKnownGateIsNamedEvenWhenItNeverRan(): void {
    $this->seed();

    $report = (new EvaluationReport(new EvidenceStore($this->root)))->render('r1');

    foreach (['eslint', 'stylelint', 'prettier', 'mutation', 'coverage', 'wiki_fresh', 'grounding_check'] as $gate) {
      $this->assertStringContainsString(
        '| `' . $gate . '` | ' . EvaluationReport::NOT_RECORDED . ' | 0 |',
        $report,
        $gate . ' is missing from the lever table',
      );
    }
    $this->assertStringContainsString('which is NOT the same as off', $report);
  }

  /**
   * The build verdict is refused, in those words, in the output.
   *
   * The whole point of the exercise. A verdict derived from the run record
   * would agree with the subject every time, including the times the subject
   * was wrong, and a reader who did not know that would have no way to tell.
   */
  public function testBuildVerdictIsRefusedAndTheOutputSaysWhy(): void {
    $this->seed();

    $report = (new EvaluationReport(new EvidenceStore($this->root)))->render('r1');

    $this->assertStringContainsString('verified live, not from the run record', $report);
    $this->assertStringContainsString(
      'circularity the template exists to prevent',
      $report,
      'the refusal has to be legible to the person reading the document, not only to the person reading the class',
    );
    $this->assertStringContainsString('| # | Criterion (EARS) | How verified | Result |', $report);
  }

  /**
   * The three scores are left empty, and the count that feeds one says so.
   */
  public function testScoreIsNotGeneratedAndSaysWhatItsInputIs(): void {
    $this->seed();

    $report = (new EvaluationReport(new EvidenceStore($this->root)))->render('r1');

    $this->assertStringContainsString('| **V** verification | `/10` |', $report);
    $this->assertStringContainsString('is the INPUT to §6\'s V score, and it is not the score', $report);
    $this->assertStringContainsString('1 gate adjudicated. 1 measured something.', $report);
  }

  /**
   * The round's own findings are never filled in from the gates' findings.
   *
   * They are different things wearing one heading in the template: a gate
   * finding is droost working, and a round finding is droost broken. Promoting
   * one into the other would retire the more valuable of the two silently.
   */
  public function testGateFindingsAreGroupedAndNeverBecomeRoundFindings(): void {
    $store = new EvidenceStore($this->root);
    $store->record('r1', 'code', new CheckRecord(
      'gate', 'phpcs', CheckState::Blocked, Fault::Agent, '2 errors', NULL, NULL, 2, 'phpcs', NULL, 900,
      [
        ['file' => 'src/a.php', 'line' => 3, 'rule' => 'Drupal.Arrays', 'message' => 'indent'],
        ['key' => 'totals', 'detail' => ['errors' => 2]],
      ],
    ));

    $report = (new EvaluationReport($store))->render('r1');

    $this->assertStringContainsString('### 8a. Check findings', $report);
    $this->assertStringContainsString('#### `phpcs` — `code`, attempt 1 — BLOCKED (agent), 2 findings', $report);
    $this->assertStringContainsString('| `src/a.php` | 3 | `Drupal.Arrays` | indent |', $report);
    $this->assertStringContainsString('### 8b. Round findings', $report);
    $this->assertStringContainsString('**Not generated.**', $report);
    $this->assertStringNotContainsString('| 1 | indent |', $report, 'a gate finding is not a round finding');
  }

  /**
   * A pipe in a value is escaped rather than eating the rest of the row.
   *
   * Found live in the seeker report before it was found here: a row carrying a
   * raw `|` renders as two short columns and a lost value, with nothing
   * anywhere saying a value was lost.
   */
  public function testPipesAndNewlinesNeverBreakTheRow(): void {
    $store = new EvidenceStore($this->root);
    $store->record('r1', 'code', new CheckRecord(
      'gate', 'phpcs', CheckState::Blocked, Fault::Agent, "3 errors | 1 warning\nsecond line",
      NULL, NULL, 2, 'phpcs | tee out.txt', NULL, 900,
      [['file' => 'src/a.php', 'line' => 3, 'rule' => 'Drupal.Arrays', 'message' => 'a | b']],
    ));
    $store->record('r1', 'code', new CheckRecord(
      'gate', 'phpcs', CheckState::Satisfied, Fault::None, 'passed', NULL, NULL, 0, 'phpcs', NULL, 880,
    ));

    $report = (new EvaluationReport($store))->render('r1');

    $this->assertStringContainsString('3 errors \\| 1 warning second line', $report);
    $this->assertStringContainsString('| a \\| b |', $report);
    $row = '';
    foreach (explode("\n", $report) as $line) {
      if (str_starts_with($line, '| `phpcs` | `code` |') && substr_count($line, '|') > 6) {
        $row = $line;
      }
    }
    $this->assertNotSame('', $row, 'the gate row is in the report at all');
    // The COUNT, not any column's position: a pipe inside a finding message
    // that escaped wrongly adds a cell and silently shifts every column right
    // of it. Kept in step with §4's header by hand, which is the only place
    // in this suite that still needs to be.
    $this->assertSame(
      10,
      substr_count(str_replace('\\|', '', $row), '|') - 1,
      'the gate row still has its ten columns: ' . $row,
    );
    $this->assertStringContainsString("phpcs | tee out.txt", $report, 'the invocation is printed raw, outside the table, so it can be run');
  }

  /**
   * Rendering changes nothing in the store.
   *
   * Read-only is a contract, not an intention. A report that could write is a
   * report that could tidy away the row it did not like, and no reader of the
   * output would ever know.
   */
  public function testRenderingWritesNothing(): void {
    $this->seed();
    $store = new EvidenceStore($this->root);
    $before = $this->dump($store);

    (new EvaluationReport($store))->render('r1');
    (new EvaluationReport($store))->render('a-run-that-does-not-exist');

    $this->assertSame($before, $this->dump($store));
  }

  /**
   * A blocked check that is not a gate still shows its remedy.
   *
   * The remedy lives on the check, not on the gate, and a declaration audit
   * can block on the environment exactly as phpunit can. Withholding the one
   * command that clears it is what wedged a live run for an hour: the agent
   * could do nothing right and the operator was never shown why.
   */
  public function testBlockedNonGateChecksAlsoShowTheirRemedy(): void {
    $store = new EvidenceStore($this->root);
    $store->record('r1', 'code', new CheckRecord(
      'declaration', 'declared_files', CheckState::Blocked, Fault::Environment,
      'the diff cannot be read — no git in this container', 'install git',
    ));

    $report = (new EvaluationReport($store))->render('r1');

    $this->assertStringContainsString('`declared_files` (declaration) in `code`', $report);
    $this->assertStringContainsString('- remedy: `install git`', $report);
  }

  /**
   * Declarations show the promise and the audit as two separate facts.
   *
   * A declared count with no audit is a promise nobody checked; an audit with
   * nothing declared is a check with nothing to hold the diff to. Both read as
   * green from a distance, and neither is.
   */
  public function testDeclarationsShowThePromiseBesideTheAudit(): void {
    $store = new EvidenceStore($this->root);
    $store->declare('r1', 'plan', 'file', 'web/modules/custom/rink/rink.module');
    $store->declare('r1', 'plan', 'file', 'web/modules/custom/rink/rink.info.yml');
    $store->declare('r1', 'plan', 'test', 'Drupal\Tests\rink\Kernel\RinkTest');
    $store->record('r1', 'code', new CheckRecord(
      'declaration', 'declared_files', CheckState::Satisfied, Fault::None, '2 of 2 present in the diff',
    ));

    $report = (new EvaluationReport($store))->render('r1');

    $this->assertStringContainsString('### Declarations — what the plan promised', $report);
    $this->assertStringContainsString('| `file` | 2 | `code` | satisfied | — | 2 of 2 present in the diff |', $report);
    $this->assertStringContainsString(
      '| `test` | 1 | ' . EvaluationReport::NOT_RECORDED,
      $report,
      'a promised test that nothing audited is named, not dropped',
    );
    $this->assertStringContainsString('nothing audited this promise', $report);
    $this->assertStringContainsString('- `web/modules/custom/rink/rink.module`', $report);
    $this->assertStringContainsString('- `Drupal\Tests\rink\Kernel\RinkTest`', $report);
  }

  /**
   * A run that declared nothing and audited nothing is told the diff was free.
   */
  public function testNoDeclarationsAtAllIsStatedAsTheFindingItIs(): void {
    $this->seed();

    $report = (new EvaluationReport(new EvidenceStore($this->root)))->render('r1');

    $this->assertStringContainsString('The plan declared no files and no tests', $report);
    $this->assertStringContainsString('the diff was held to nothing', $report);
  }

  /**
   * The transcript is what the tool said, not what droost made of it.
   *
   * The two are stored apart because they answer different questions, and the
   * transcript is the only one that helps when the parse was wrong or the tool
   * died before producing anything structured.
   */
  public function testTranscriptsPrintWhatTheToolSaid(): void {
    $store = new EvidenceStore($this->root);
    $store->record('r1', 'code', new CheckRecord(
      'gate', 'phpstan', CheckState::Blocked, Fault::Agent, '1 error', NULL, NULL, 1, 'phpstan', NULL, 700,
      [],
      "  Line   Foo.php\n  12     Call to an undefined method\n",
      "PHP Warning: something\n",
    ));

    $report = (new EvaluationReport($store))->render('r1');

    $this->assertStringContainsString('## 4c. Transcripts — what the tool actually said', $report);
    // And the sections ascend. They were assembled 4a, 4b, 4d, 4c.
    $this->assertLessThan(
      (int) strpos($report, '## 4d.'),
      (int) strpos($report, '## 4c.'),
      '4c comes before 4d',
    );
    $this->assertStringContainsString('#### `phpstan` — `code`, attempt 1, `stdout` — BLOCKED', $report);
    $this->assertStringContainsString('Call to an undefined method', $report);
    $this->assertStringContainsString('#### `phpstan` — `code`, attempt 1, `stderr` — BLOCKED', $report);
    $this->assertStringContainsString('PHP Warning: something', $report);
  }

  /**
   * With no transcript the section says the parse cannot be checked.
   *
   * An absent transcript is not a finding about the code. It is a limit on how
   * far the document can be audited, and an unwritten limit is a false claim
   * of coverage.
   */
  public function testNoTranscriptSaysTheParseCannotBeChecked(): void {
    $this->seed();

    $report = (new EvaluationReport(new EvidenceStore($this->root)))->render('r1');

    $this->assertStringContainsString('**No transcript was recorded for this run.**', $report);
    $this->assertStringContainsString('it belongs in §7.3', $report);
  }

  /**
   * A long transcript loses its middle, and says how much went.
   *
   * The head carries the command and the first failure, the tail carries the
   * summary and the exit. Cutting silently would make a report that looks
   * complete and is not — the one failure mode this whole document exists to
   * prevent.
   */
  public function testLongTranscriptsAreCutInTheMiddleAndSayHowMuch(): void {
    $store = new EvidenceStore($this->root);
    $store->record('r1', 'code', new CheckRecord(
      'gate', 'phpcs', CheckState::Blocked, Fault::Agent, 'many errors', NULL, NULL, 2, 'phpcs', NULL, 900,
      [],
      'HEAD-MARKER' . str_repeat('x', 5000) . 'TAIL-MARKER',
    ));

    $report = (new EvaluationReport($store))->render('r1');

    $this->assertStringContainsString('HEAD-MARKER', $report);
    $this->assertStringContainsString('TAIL-MARKER', $report);
    $this->assertStringContainsString('characters cut from the middle', $report);
    $this->assertMatchesRegularExpression(
      '/… 3022 of 5022 characters cut from the middle …/',
      $report,
      'the cut is stated exactly, not approximately',
    );
  }

  /**
   * A transcript containing a code fence cannot end the block early.
   *
   * Otherwise one tool printing three backticks spills the rest of the round's
   * output into the prose, and every table after it renders as plain text.
   */
  public function testTranscriptFenceGrowsPastItsOwnContent(): void {
    $store = new EvidenceStore($this->root);
    $store->record('r1', 'code', new CheckRecord(
      'gate', 'phpcs', CheckState::Blocked, Fault::Agent, 'odd output', NULL, NULL, 2, 'phpcs', NULL, 900,
      [],
      "before\n```\nfenced\n```\nafter",
    ));

    $report = (new EvaluationReport($store))->render('r1');

    $this->assertStringContainsString("````\nbefore", $report, 'the fence grows past the longest run inside');
    $this->assertStringContainsString('## 4a. The tool-call ledger', $report, 'and the sections after it survive');
  }

  /**
   * A minimal round: one run row, one measured gate.
   */
  private function seed(): void {
    $store = new EvidenceStore($this->root);
    $store->upsertRun('r1', [
      'started_at' => '2026-09-13T09:00:00+00:00',
      'preset' => 'max',
      'mode' => 'agentic',
      'enforcement' => 'hard',
      'base_commit' => 'deadbeef',
      'spec_path' => 'droost/droost-workflow/spec-r1.md',
      'spec_hash' => 'abc123',
    ]);
    $store->record('r1', 'code', new CheckRecord(
      'gate', 'phpcs', CheckState::Satisfied, Fault::None, 'phpcs passed', NULL, NULL, 0, 'phpcs', NULL, 880,
    ));
  }

  /**
   * The "Measured anything?" cell of §4, keyed by gate.
   *
   * @param string $report
   *   The rendered document.
   *
   * @return array<string, string>
   *   Gate name to verdict.
   */
  private function verdicts(string $report): array {
    $verdicts = [];
    foreach (explode("\n", $report) as $line) {
      $cells = array_map(trim(...), explode('|', $line));
      // §4's rows, named by their gate. The CELL comes from gateCell(), which
      // finds the column by its heading — this loop only needs to know which
      // gates have rows, not where any column sits.
      if (count($cells) < 4 || !str_starts_with($cells[1], '`')) {
        continue;
      }
      $gate = trim($cells[1], '`');
      $cell = $this->gateCell($report, $gate, 'Measured anything?');
      if ($cell !== NULL) {
        $verdicts[$gate] = $cell;
      }
    }

    return $verdicts;
  }

  /**
   * Every row of every table, as one comparable string.
   *
   * @param \Droost\Workflow\Evidence\EvidenceStore $store
   *   The store.
   *
   * @return string
   *   The dump.
   */
  private function dump(EvidenceStore $store): string {
    $pdo = $store->connection();
    $sql = "SELECT name FROM sqlite_master WHERE type = 'table' ORDER BY name";
    $dump = [];
    foreach ($this->query($pdo, $sql) as $table) {
      $name = is_array($table) && is_scalar($table['name'] ?? NULL)
        ? (string) $table['name']
        : '';
      if ($name !== '') {
        $dump[$name] = $this->query($pdo, 'SELECT * FROM "' . $name . '"');
      }
    }

    return (string) json_encode($dump);
  }

  /**
   * One query, with the statement proved rather than assumed.
   *
   * @param \PDO $pdo
   *   The connection.
   * @param string $sql
   *   The statement.
   *
   * @return array<array-key, mixed>
   *   The rows.
   */
  private function query(\PDO $pdo, string $sql): array {
    $statement = $pdo->query($sql);
    $this->assertInstanceOf(\PDOStatement::class, $statement);

    return $statement->fetchAll() ?: [];
  }

  /**
   * A green expires in the report when the code it was green about moves.
   *
   * This is the claim the whole evidence design rests on, and until now it was
   * only a claim. `SubjectHasher` and `EvidenceStore::stillGreen()` were built,
   * unit-tested and called by NOTHING — infrastructure for a consumer that was
   * never written. So a verdict recorded at 03:00 still read as current at
   * 03:05 after three files changed, and the only thing that would have caught
   * it was a human re-running the tool, which is the out-of-band measurement
   * this whole record exists to replace.
   *
   * Three states, and the third is the one that keeps it honest: `unknown` is
   * not `yes`. A gate with no resolvable subject cannot be shown to have
   * expired, and saying so beats a tick nobody earned.
   */
  public function testGreenExpiresWhenItsSubjectMoves(): void {
    mkdir($this->root . '/src', 0775, TRUE);
    file_put_contents($this->root . '/src/A.php', "<?php\n// one\n");

    $store = new EvidenceStore($this->root);
    $store->upsertRun('r1', ['preset' => 'medium']);
    $store->record('r1', 'code', new CheckRecord(
      'gate', 'phpcs', CheckState::Satisfied, Fault::None, 'clean',
      NULL, SubjectHasher::hash($this->root, ['src']), 0, 'phpcs src', NULL, 880,
    ));

    $fresh = SubjectHasher::hash($this->root, ['src']);
    $this->assertIsString($fresh);
    $this->assertSame(
      'yes',
      $this->stillTrue((new EvaluationReport($store))->render('r1', ['phpcs' => $fresh])),
      'unchanged code leaves the verdict standing',
    );

    file_put_contents($this->root . '/src/A.php', "<?php\n// two\n");
    $moved = SubjectHasher::hash($this->root, ['src']);
    $this->assertIsString($moved);
    $this->assertSame(
      '**EXPIRED**',
      $this->stillTrue((new EvaluationReport($store))->render('r1', ['phpcs' => $moved])),
      'a green about code that has since changed is not a green now',
    );

    $this->assertSame(
      'unknown',
      $this->stillTrue((new EvaluationReport($store))->render('r1', [])),
      'a gate whose subject could not be fingerprinted is unknown, never unchanged',
    );
  }

  /**
   * The `Still true?` cell of the phpcs row.
   *
   * @param string $report
   *   The rendered evaluation.
   *
   * @return string
   *   The cell.
   */
  private function stillTrue(string $report): string {
    return $this->gateCell($report, 'phpcs', 'Still true?') ?? '(no phpcs row)';
  }

  /**
   * The latest state is the run's last word, not its alphabetically-last phase.
   *
   * `ORDER BY phase` sorts `complete` before `test`, and §3 takes the last row
   * it sees as the current one. So a real feedback loop — phpunit fails at
   * test, is fixed, then fails again at complete on a regression the fix
   * introduced — rendered as `satisfied`, with the run's ACTUAL final word
   * discarded as though it were an earlier attempt.
   *
   * This is the worst shape a reporting bug can take here: not a missing row,
   * but a green printed over a red, in the document people read instead of the
   * run.
   */
  public function testLatestStateIsChronologicalNotAlphabetical(): void {
    $store = new EvidenceStore($this->root);
    $store->upsertRun('r1', ['preset' => 'medium']);
    $store->record('r1', 'test', new CheckRecord(
      'gate', 'phpunit', CheckState::Blocked, Fault::Agent, 'FAILED — 2 failing', NULL, NULL, 1, 'phpunit', NULL, 10,
    ));
    $store->record('r1', 'test', new CheckRecord(
      'gate', 'phpunit', CheckState::Satisfied, Fault::None, 'passed — 14 tests', NULL, NULL, 0, 'phpunit', NULL, 10,
    ));
    $store->record('r1', 'complete', new CheckRecord(
      'gate', 'phpunit', CheckState::Blocked, Fault::Agent, 'FAILED — a regression the fix introduced', NULL, NULL, 1, 'phpunit', NULL, 10,
    ));

    $row = '';
    foreach (explode("\n", (new EvaluationReport($store))->render('r1')) as $line) {
      if (str_starts_with($line, '| `phpunit` |') && substr_count($line, '|') <= 6) {
        $row = $line;
      }
    }

    $this->assertStringContainsString('BLOCKED', $row, "the run's final word is what §3 reports");
    $this->assertStringContainsString('`test, complete`', $row, 'and the phases read in the order they happened');
  }

  /**
   * The expiry column tells three different things apart.
   *
   * A reviewer found it collapsing them, each collapse producing a confident
   * false sentence in the document people read INSTEAD of the run:
   *
   *   * every `recorded` gate printed `**EXPIRED**` over untouched code,
   *     forever, because `stillGreen()` tested for `Satisfied` alone while the
   *     column admitted `recorded` too. Every contributed `mode: report` gate —
   *     droost_snyk and its kin — would have read EXPIRED in every report ever
   *     rendered, under prose asserting the code had moved;
   *   * a green whose subject was DELETED read `unknown`, the quietest possible
   *     answer for the loudest possible expiry, under a note blaming levers
   *     that carry no paths — which was the opposite of what happened.
   *
   * Three states, three causes, and the difference between them is the only
   * thing that makes the column worth printing.
   */
  public function testExpiryTellsRecordedFromSatisfiedAndGoneFromUnconfigured(): void {
    mkdir($this->root . '/subject', 0775, TRUE);
    file_put_contents($this->root . '/subject/a.php', "<?php // one\n");
    $hash = SubjectHasher::hash($this->root, ['subject']);
    $this->assertIsString($hash);

    $store = new EvidenceStore($this->root);
    $store->upsertRun('r1', ['preset' => 'medium']);
    // A reporting gate: measured, non-blocking, fingerprinted.
    $store->record('r1', 'test', new CheckRecord(
      'gate', 'snyk', CheckState::Recorded, Fault::None, '2 issues, mode: report',
      NULL, $hash, 1, 'snyk', NULL, 900,
    ));
    // An ordinary green over the same subject.
    $store->record('r1', 'test', new CheckRecord(
      'gate', 'phpcs', CheckState::Satisfied, Fault::None, 'clean', NULL, $hash, 0, 'phpcs', NULL, 800,
    ));
    // And one with no fingerprint at all.
    $store->record('r1', 'test', new CheckRecord(
      'gate', 'phpunit', CheckState::Satisfied, Fault::None, '14 tests', NULL, NULL, 0, 'phpunit', NULL, 900,
    ));

    $report = new EvaluationReport($store);
    $unchanged = ['snyk' => $hash, 'phpcs' => $hash];

    $this->assertSame('yes', $this->expiryOf($report->render('r1', $unchanged), 'snyk'),
      'a reporting gate over untouched code has NOT expired');
    $this->assertSame('yes', $this->expiryOf($report->render('r1', $unchanged), 'phpcs'));
    $this->assertSame('unknown', $this->expiryOf($report->render('r1', $unchanged), 'phpunit'),
      'a gate with no path set is unknown, never unchanged');

    // Move the code: both fingerprinted gates expire, whatever their state.
    file_put_contents($this->root . '/subject/a.php', "<?php // two\n");
    $moved = SubjectHasher::hash($this->root, ['subject']);
    $this->assertIsString($moved);
    $after = $report->render('r1', ['snyk' => $moved, 'phpcs' => $moved]);
    $this->assertSame('**EXPIRED**', $this->expiryOf($after, 'snyk'));
    $this->assertSame('**EXPIRED**', $this->expiryOf($after, 'phpcs'));

    // Delete it: a configured subject that resolves to nothing is gone, and
    // says so — NULL in the map, not absent from it.
    exec('rm -rf ' . escapeshellarg($this->root . '/subject'));
    $gone = $report->render('r1', ['snyk' => NULL, 'phpcs' => NULL]);
    $this->assertSame('**EXPIRED** (subject gone)', $this->expiryOf($gone, 'phpcs'));
    $this->assertSame('unknown', $this->expiryOf($gone, 'phpunit'),
      'and the gate that never had a subject is still merely unknown');
  }

  /**
   * The `Still true?` cell for one gate.
   *
   * @param string $report
   *   The rendered evaluation.
   * @param string $gate
   *   The gate name.
   *
   * @return string
   *   The cell.
   */
  private function expiryOf(string $report, string $gate): string {
    return $this->gateCell($report, $gate, 'Still true?')
      ?? '(no row for ' . $gate . ')';
  }

  /**
   * The legend describes the values the column actually produces.
   *
   * Prose in this file goes stale silently, and that is the most dangerous
   * thing in it: a wrong number invites checking and a wrong sentence does not.
   * Today alone, the legend told a reader that `unknown` meant "the gate had no
   * resolvable subject to fingerprint" — which after the split is precisely the
   * `**EXPIRED** (subject gone)` case, so the legend handed that phrase to the
   * other cell — while the value it was describing appeared in no legend at
   * all.
   *
   * Asserted as a property, not a string: every value `stillDescribesTheCode()`
   * can return has to appear in the rendered explanation. The next value added
   * will be forgotten the same way.
   */
  public function testEveryExpiryValueAppearsInTheLegend(): void {
    $produced = [];
    mkdir($this->root . '/subject', 0775, TRUE);
    file_put_contents($this->root . '/subject/a.php', "<?php // one\n");
    $hash = SubjectHasher::hash($this->root, ['subject']);
    $this->assertIsString($hash);

    $store = new EvidenceStore($this->root);
    $store->upsertRun('r1', ['preset' => 'medium']);
    $store->record('r1', 'code', new CheckRecord(
      'gate', 'phpcs', CheckState::Satisfied, Fault::None, 'clean', NULL, $hash, 0, 'phpcs', NULL, 800,
    ));
    $store->record('r1', 'code', new CheckRecord(
      'gate', 'phpunit', CheckState::Satisfied, Fault::None, 'ran', NULL, NULL, 0, 'phpunit', NULL, 900,
    ));
    $report = new EvaluationReport($store);

    // Drive each of the four outcomes and collect what renders.
    $produced[] = $this->expiryOf($report->render('r1', ['phpcs' => $hash]), 'phpcs');
    file_put_contents($this->root . '/subject/a.php', "<?php // two\n");
    $moved = SubjectHasher::hash($this->root, ['subject']);
    $this->assertIsString($moved);
    $produced[] = $this->expiryOf($report->render('r1', ['phpcs' => $moved]), 'phpcs');
    $produced[] = $this->expiryOf($report->render('r1', ['phpcs' => NULL]), 'phpcs');
    $produced[] = $this->expiryOf($report->render('r1', ['phpcs' => $moved]), 'phpunit');

    $rendered = $report->render('r1', ['phpcs' => $moved]);
    foreach (array_unique($produced) as $value) {
      $this->assertStringContainsString(
        $value,
        $rendered,
        sprintf('the column can print "%s", so the document has to explain it', $value),
      );
    }
    $this->assertCount(4, array_unique($produced), 'all four outcomes were exercised');
  }

  /**
   * §4 does not claim a declaration rests on something droost collected.
   *
   * `declared_tests` is `Recorded` and rests on nothing droost collected — by
   * its own summary. §4's header sentence said `recorded` means measured, and
   * that sentence is the one a reviewer carries to every other section.
   */
  public function testTheGateLegendDoesNotSpeakForDeclarations(): void {
    $store = new EvidenceStore($this->root);
    $store->upsertRun('r1', ['preset' => 'medium']);
    $store->record('r1', 'code', new CheckRecord(
      'gate', 'phpcs', CheckState::Satisfied, Fault::None, 'clean', NULL, NULL, 0, 'phpcs', NULL, 800,
    ));

    $report = (new EvaluationReport($store))->render('r1');

    $this->assertStringContainsString('For the GATES in this table', $report);
    $this->assertStringContainsString(
      'could not check',
      $report,
      'and the third way a row reads green from a distance is named',
    );
  }

  /**
   * A seeker's findings reach the document, not just the table.
   *
   * `recordSeekerFindings()` was wired this morning and `seekerFindings()` was
   * read by nothing — a writer without a reader, which is the same dead-seam
   * shape as every serious defect found this week, committed hours after
   * writing a commit message about that shape. Meanwhile §3 asserted in a table
   * cell that "seeker rows land in `seeker_finding`" while the document never
   * printed one.
   *
   * The cost is already on the record: across four rounds, 6, 25, 12 and 20
   * findings were caught and recorded as 0, 0, 6 and 2.
   */
  public function testSeekerFindingsReachTheDocument(): void {
    $store = new EvidenceStore($this->root);
    $store->upsertRun('r1', ['preset' => 'high']);
    $store->recordSeekerFindings('r1', 'code', 1, [
      [
        'id' => 'F1',
        'severity' => 'CRITICAL',
        'location' => 'src/Rink.php:20',
        'finding' => 'the cache is never invalidated',
        'status' => 'open',
      ],
      [
        'id' => 'F2',
        'severity' => 'MEDIUM',
        'location' => 'src/Rink.php:44',
        'finding' => 'the new branch has no test',
        'status' => 'resolved',
      ],
    ]);

    $report = (new EvaluationReport($store))->render('r1');

    $this->assertStringContainsString('the cache is never invalidated', $report);
    $this->assertStringContainsString('src/Rink.php:44', $report);
    $this->assertStringContainsString('CRITICAL', $report);
    $this->assertStringContainsString(
      '**2 distinct findings across 1 round; 1 still open**',
      $report,
      'and the count comes from the same rows the table does, so they cannot disagree',
    );
  }

  /**
   * With no findings, the document says which of three things that means.
   *
   * "No rows" is ambiguous in a way that matters: no inspection was due, one
   * ran and found nothing, or one ran and its ledger was never recorded. A
   * blank section reads as the second, which is the flattering one.
   */
  public function testNoSeekerFindingsSaysWhichOfTheThreeItWas(): void {
    // SEEKERS OFF — the round was never adversarially read, and §4d must say
    // so rather than list three possibilities and defer to a §4 row that said
    // `— not recorded —`. A round that nothing reviewed and a round reviewed
    // and found clean are opposite facts; they used to render identically.
    $off = new EvidenceStore($this->root);
    $off->upsertRun('r1', ['preset' => 'low', 'seekers' => 'off']);
    $report = (new EvaluationReport($off))->render('r1');
    $this->assertStringContainsString('NOT adversarially reviewed', $report);
    $this->assertStringContainsString('`seekers` was `off`', $report);
    $this->assertStringContainsString('COVERAGE, not about quality', $report);
    $this->assertStringContainsString('seekers: { on: true }', $report);
    $this->assertStringNotContainsString('cannot be read back', $report);

    // SEEKERS ON, no rows — reviewed and clean, or a ledger that never landed.
    mkdir($this->root . '/on', 0775, TRUE);
    $on = new EvidenceStore($this->root . '/on');
    $on->upsertRun('r2', ['preset' => 'medium', 'seekers' => 'on']);
    $report = (new EvaluationReport($on))->render('r2');
    $this->assertStringContainsString('`seekers` was **on**', $report);
    $this->assertStringNotContainsString('NOT adversarially reviewed', $report);

    // PRE-v7 — the lever was not recorded, so the honest answer is that it
    // cannot be read back. The old three-way sentence survives exactly here.
    mkdir($this->root . '/prev7', 0775, TRUE);
    $old = new EvidenceStore($this->root . '/prev7');
    $old->upsertRun('r3', ['preset' => 'low']);
    $report = (new EvaluationReport($old))->render('r3');
    $this->assertStringContainsString('cannot be read back', $report);
    $this->assertStringContainsString('They are not equivalent', $report);
    $this->assertStringNotContainsString('NOT adversarially reviewed', $report);
  }

  /**
   * The lever renders in the lever table now that the run records it.
   */
  public function testTheSeekerLeverIsReportedNotJustItsRows(): void {
    $store = new EvidenceStore($this->root);
    $store->upsertRun('r1', ['preset' => 'low', 'seekers' => 'off']);

    $report = (new EvaluationReport($store))->render('r1');

    $this->assertMatchesRegularExpression(
      '/`seekers`[^\n]*\*\*off\*\*/',
      $report,
      'the lever table says off, not "not recorded"',
    );
  }

  /**
   * Section 4b does not contradict its own table about core (F-28).
   *
   * The prose asserted "core resolves against the brain, and only the brain,"
   * and that "a round reporting core citations resolving against the symbol
   * graph has a wrong probe, not a finding." The autoloader fallback landed in
   * the grounding redesign and was verified live in KCH-3, after which the
   * table four lines above printed `brain, autoloader` while the paragraph
   * below denied it. Harmless where the table is read; dangerous where the
   * prose is, because it tells a reader that a correct observation is a broken
   * instrument.
   */
  public function testSection4bDoesNotDenyTheStoresItReports(): void {
    $store = new EvidenceStore($this->root);
    $store->upsertRun('r1', ['preset' => 'medium', 'seekers' => 'on']);
    $store->recordGroundingRow(
      'r1',
      'code',
      'core',
      'the node entity class',
      'Drupal\\node\\Entity\\Node',
      'Drupal\\node\\Entity\\Node',
      TRUE,
      'autoloader',
    );

    $report = (new EvaluationReport($store))->render('r1');

    $this->assertStringNotContainsString(
      'and only the brain',
      $report,
      'the prose may not deny a store the table can print',
    );
    $this->assertStringContainsString('autoloader', $report);
  }

}
