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

    $verdicts = $this->verdicts((new EvaluationReport($store))->render('r1'));

    $this->assertSame('yes', $verdicts['phpcs']);
    $this->assertSame(
      'no — the tool never spawned (`duration_ms: 0`)',
      $verdicts['phpstan'],
      'a pass that took no time is a tool that never ran',
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
   * count it prints is what pins the list at ten.
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
      '| **Knowledge : router ratio** | 10 : 1 |',
      $report,
      'ten names in, ten knowledge calls out',
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
    $this->assertSame(
      9,
      substr_count(str_replace('\\|', '', $row), '|') - 1,
      'the gate row still has its nine columns: ' . $row,
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
      // Eleven parts is the §4 table's nine columns plus the empty ends. §3's
      // gate table opens with the same two cells and has four columns, so the
      // count is what tells them apart.
      $cells = array_map(trim(...), explode('|', $line));
      if (count($cells) === 11 && str_starts_with($cells[1], '`')) {
        $verdicts[trim($cells[1], '`')] = $cells[8];
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
    foreach (explode("\n", $report) as $line) {
      $cells = array_map(trim(...), explode('|', $line));
      if (count($cells) === 11 && $cells[1] === '`phpcs`') {
        return $cells[9];
      }
    }

    return '(no phpcs row)';
  }

}
