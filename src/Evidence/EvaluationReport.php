<?php

declare(strict_types=1);

namespace Droost\Workflow\Evidence;

use Droost\Workflow\Config\GateSettings;
use Droost\Workflow\Spec\SpecContract;

/**
 * Renders a round's evaluation from the store, and stops where it must.
 *
 * The evaluation used to be a form. `pack/templates/evaluation.md` was copied
 * per round and filled in by hand, and filling it took hours of re-deriving
 * facts the run had already established: which gates ran, what they exited
 * with, how long each took, what the agent actually asked droost for. None of
 * that is judgement. It is a query, and it now happens here.
 *
 * What the template was really for survives the automation: the discrimination
 * in its §4 — that a green is not a measurement, and that fourteen of fifteen
 * gates can report green having measured nothing. `CheckState::measured()`
 * holds one half of that (satisfied and recorded rest on a measurement;
 * not_applicable and unblocked are honest and are NOT measurements) and
 * `duration_ms == 0` holds the other (the tool never spawned). Both are printed
 * per gate, in a column that answers the question rather than leaving it to a
 * reader who is tired by §4.
 *
 * THREE SECTIONS ARE DELIBERATELY NOT GENERATED, and the output says so where
 * they belong rather than dropping them:
 *
 *   * §5, the build verdict, is the spec's acceptance criteria checked against
 *     a running site. Deriving it from the run record would be the subject
 *     grading its own homework.
 *   * §6's three scores rest on §5 and on judgement about what each gate was
 *     pointed at, which no column holds.
 *   * §8's round findings are defects found in droost BY the round. The
 *     `finding` table holds something else entirely — what the gates found in
 *     the code under test — and printing one under the other's heading would
 *     quietly retire the more valuable of the two.
 *
 * Read-only by contract. Every statement this class issues is a SELECT; the
 * only write anywhere near it is the store's own schema bootstrap, which
 * happens on open whoever opened it. An unreadable store is allowed to throw:
 * a report that says "nothing was recorded" when the truth is "the record
 * could not be read" is precisely the unmeasured thing wearing the costume of
 * a measured one that the template's first paragraph warns about.
 */
final class EvaluationReport {

  /**
   * The tools that answer a question about a codebase.
   *
   * Copied from `GroundingResolver::KNOWLEDGE_TOOLS` in the droost_workflow
   * Drupal module (droost/modules/droost_workflow/src/Gate/). Duplicated on
   * purpose: that class needs Drupal's database connection to resolve a
   * citation against the symbol graph, and this package boots no Drupal — a
   * `use` statement here would make the standalone `droost-workflow` binary
   * depend on a module it must be able to run without.
   *
   * The two lists are a pair. If one gains a tool, so must the other, or a run
   * that grounded itself through the new tool will render as a run that asked
   * the codebase nothing.
   */
  public const array KNOWLEDGE_TOOLS = [
    'droost_search',
    'droost_symbol',
    'droost_graph',
    'droost_module_patterns',
    'droost_module_docs',
    'droost_deprecations',
    'droost_entities',
    'droost_routes',
    'droost_capabilities',
    'droost_architecture',
  ];

  /**
   * The decision-graph tool, counted against the knowledge tools.
   *
   * The ratio between the two is the number that exposed the original defect:
   * 6 knowledge calls against 179 router calls across 39 rounds, in a system
   * whose reports all claimed the codebase had been consulted.
   */
  public const string ROUTER_TOOL = 'droost_decide';

  /**
   * What a cell says when the store simply does not hold the fact.
   *
   * Never an empty cell. "An empty cell is an unmeasured thing wearing the
   * costume of a measured one" is the template's own first instruction, and it
   * applies twice as hard to a generated document, where a blank reads as a
   * value of zero rather than as an absence nobody recorded.
   */
  public const string NOT_RECORDED = '— not recorded —';

  /**
   * A phase's place in the run, as SQL.
   *
   * Phases do not sort alphabetically into the order they happen — the
   * alphabetical sequence is code, complete, plan, test, and none of that is
   * right. Anything reading "the latest row" has to order by this.
   */
  private const string PHASE_RANK = "CASE phase
    WHEN 'plan' THEN 1 WHEN 'code' THEN 2 WHEN 'test' THEN 3
    WHEN 'complete' THEN 4 ELSE 5 END";

  /**
   * Where a free-text cell is cut, in characters.
   *
   * Only the prose columns are cut, and the cut is marked. Identity columns —
   * gate, phase, file, rule — are printed whole however long they are, because
   * a truncated identifier is worse than a wide table.
   */
  private const int MAX_CELL = 200;

  /**
   * How much of one transcript stream is printed, in characters.
   *
   * The store caps a stream at 16KB; a round with fifteen gates and two
   * streams each would still be a quarter of a megabyte of markdown, which is
   * not a document anyone reads. The middle is what gets dropped — the head
   * carries the command and the first failure, the tail carries the summary
   * and the exit — and the cut says exactly how many characters went with it.
   */
  private const int MAX_TRANSCRIPT = 2000;

  /**
   * How many findings one check prints before the rest are counted instead.
   *
   * A phpcs run can produce hundreds. The overflow is stated with its count
   * rather than dropped, so the document names its own omission.
   */
  private const int MAX_FINDING_ROWS = 50;

  /**
   * Constructs an EvaluationReport.
   *
   * @param \Droost\Workflow\Evidence\EvidenceStore $store
   *   The store to read. Never written to.
   */
  public function __construct(private readonly EvidenceStore $store) {}

  /**
   * Fingerprints of what each gate would examine now, from the last render().
   *
   * @var array<string, string|null>
   */
  private array $currentSubjects = [];

  /**
   * Whether a satisfied gate's verdict still describes the code.
   *
   * Three answers, and the third is the one that matters most: "unknown" is
   * not "unchanged". A gate with no resolvable subject — one that talks to a
   * site, or runs a whole suite by configuration — has no fingerprint and can
   * never be shown to have expired, and saying so is more honest than a tick.
   *
   * @param string $runId
   *   The run.
   * @param array<array-key, mixed> $row
   *   The latest check row for the gate.
   *
   * @return string
   *   A cell: `yes`, `EXPIRED`, or `unknown`.
   */
  private function stillDescribesTheCode(string $runId, array $row): string {
    $name = self::text($row, 'name') ?? '';
    $state = self::text($row, 'state') ?? '';
    if ($state !== 'satisfied' && $state !== 'recorded') {
      return self::NOT_RECORDED;
    }
    $stored = self::text($row, 'subject_hash');
    if ($stored === NULL || !array_key_exists($name, $this->currentSubjects)) {
      // Nothing to compare: the gate has no path set, or the row predates
      // fingerprinting. Not "unchanged" — genuinely unknown.
      return 'unknown';
    }
    $current = $this->currentSubjects[$name];
    if ($current === NULL) {
      // It HAD a subject when the verdict was recorded and has none now: the
      // code the gate passed over is gone. That is the most complete form of
      // "the code moved", and it read `unknown` under a note blaming levers
      // that carry no paths — the opposite of what happened.
      return '**EXPIRED** (subject gone)';
    }

    return $this->store->stillGreen($runId, self::text($row, 'phase') ?? '', $name, $current)
      ? 'yes'
      : '**EXPIRED**';
  }

  /**
   * The evaluation for one run, as markdown.
   *
   * @param string $runId
   *   The run.
   * @param array<string, string|null> $currentSubjects
   *   Gate name to a fingerprint of what that gate WOULD examine as the tree
   *   stands now. Supplying it is what makes a stored green expire: a verdict
   *   alone is a sticker, and only the comparison says whether it still
   *   describes the code. Empty means the caller could not compute them, which
   *   is reported as unknown and never as unchanged.
   *
   * @return string
   *   The document, following the structure of pack/templates/evaluation.md.
   *
   * @throws \Droost\Workflow\Evidence\EvidenceError
   *   When the store cannot be opened. Deliberately not caught: see the class
   *   docblock.
   */
  public function render(string $runId, array $currentSubjects = []): string {
    $this->currentSubjects = $currentSubjects;
    $run = $this->rows('SELECT * FROM run WHERE run_id = ?', [$runId])[0] ?? [];
    $checks = $this->rows(
      // Ordered by the phase's real SEQUENCE, not its name. `ORDER BY phase`
      // sorts `complete` before `test`, and §3 takes the last row as the latest
      // state — so a gate that failed at complete after passing at test read
      // `satisfied`, with the run's actual final word discarded as if it were
      // an earlier attempt.
      'SELECT *, ' . self::PHASE_RANK . ' AS phase_rank FROM check_result
        WHERE run_id = ? ORDER BY phase_rank, kind, name, attempt',
      [$runId],
    );

    $sections = [
      $this->preamble($runId, $run, $checks),
      $this->identity($run, $runId),
      $this->environmentStub(),
      "---\n",
      $this->levers($run, $checks),
      "---\n",
      $this->gateVerdicts($runId, $checks),
      $this->toolLedger($runId),
      $this->grounding($runId),
      $this->seekerFindings($runId),
      $this->transcripts($runId, $checks),
      $this->buildVerdictStub(),
      $this->scoreStub(),
      "---\n",
      $this->observabilityStub(),
      "---\n",
      $this->findings($runId),
    ];

    return implode("\n", $sections);
  }

  /**
   * The title, and what kind of document this is.
   *
   * @param string $runId
   *   The run.
   * @param array<array-key, mixed> $run
   *   The run row, or an empty array when there is none.
   * @param list<array<array-key, mixed>> $checks
   *   Every adjudicated check of the run.
   *
   * @return string
   *   The section.
   */
  private function preamble(string $runId, array $run, array $checks): string {
    $out = '# Evaluation — `' . self::escape($runId) . "`\n\n"
      . "Rendered from the evidence store. Every value below is a row droost\n"
      . "wrote about what it measured; the sections a human must fill say so in\n"
      . "place, and say why.\n\n"
      . "> **The one rule the rest hangs from.** `run.json` is writable by the\n"
      . "> agent — the wall guards only `modules/custom` and `themes/custom` —\n"
      . "> so `phases` is the subject's claim about itself. Nothing here reads\n"
      . "> `phases`. Every VERDICT below is a row droost wrote from a process\n"
      . "> droost started.\n"
      . ">\n"
      . "> **The exception, stated because it was not.** §1's preset, mode,\n"
      . "> enforcement, base commit and spec path are copied THROUGH `run.json`\n"
      . "> on their way into the store, so an agent editing that file before a\n"
      . "> phase changes what §1 prints. A reviewer edited `medium`/`soft` to\n"
      . "> `max`/`hard`, ran one phase, and §1 said `max` and `hard` while the\n"
      . "> frozen medium gate set was what actually ran. Those rows are marked\n"
      . "> `reported` below, and the two digests are marked with what they\n"
      . "> actually cover: droost computes them, over the file `run.json` names,\n"
      . "> and only across the `## Tooling plan` section. The gate set in §3 and\n"
      . "> §4 is the check on all of it.\n";

    // Before anything else a reader might act on: does the record still follow
    // from itself? A shell can reach this file — the guard refuses the obvious
    // routes and a reviewer defeated it in four more within minutes — so the
    // question is not whether forging is possible but whether it is visible.
    $break = $this->store->integrity($runId);
    $out .= "\n> **What the integrity check does and does not establish.** Every\n"
      . "> verdict carries a digest over the previous verdict's digest and its\n"
      . "> own columns, and the ids are contiguous because nothing deletes from\n"
      . "> that table. So an altered verdict, an invented one, or a deletion in\n"
      . "> the middle of the record breaks the chain and is named below. So does\n"
      . "> a deletion from the END: the run row carries the digest it should end\n"
      . "> at, written on every insert, so truncating the record and blanking\n"
      . "> that head are each visible on their own. And the concession that an\n"
      . "> empty digest means \"written before the chain existed\" is bounded\n"
      . "> twice over — by a watermark that cannot exceed the rows that exist,\n"
      . "> and by a mark the file only carries if it really does hold rows that\n"
      . "> old. A store created for this round carries neither, so in it every\n"
      . "> verdict must show a digest.\n"
      . "> \n"
      . "> It does NOT establish that the record is untampered, and cannot. Every\n"
      . "> input to the check — the algorithm, the digests, the head, the\n"
      . "> watermark — lives in a file the agent being measured can write, so\n"
      . "> anyone willing to read `EvidenceStore::chain()` can recompute the\n"
      . "> whole chain around whatever they changed. Deleting the record\n"
      . "> ENTIRELY and clearing the head also passes, because what is left is\n"
      . "> not a doctored record but an empty one — which every section below\n"
      . "> reports as empty rather than as green. What the chain catches is\n"
      . "> accident, corruption and casual editing, and it makes a deliberate\n"
      . "> forgery cost specific knowledge and several coordinated writes rather\n"
      . "> than one UPDATE. It does not catch a determined local forger, and no\n"
      . "> scheme kept entirely inside that file could.\n"
      . "> \n"
      . "> If this record has to hold against that, it has to leave the machine:\n"
      . "> commit the rendered evaluation, where a diff shows what changed, or\n"
      . "> write it somewhere the agent has no credentials for. That is an\n"
      . "> operator's decision and droost cannot make it from in here.\n";
    if ($break !== NULL) {
      $out .= sprintf(
        "\n> ## ⚠ THIS RECORD HAS BEEN ALTERED\n"
        . "> \n"
        . "> Every verdict carries a digest over the previous verdict's digest\n"
        . "> and its own contents, so a row that was changed, inserted or removed\n"
        . "> by anything other than droost breaks the chain from that point on.\n"
        . "> The chain first fails at row %d — `%s` at `%s`.\n"
        . "> \n"
        . "> Read NOTHING below as evidence. Re-run the round.\n",
        $break['row'],
        self::escape($break['name']),
        self::escape($break['phase']),
      );
    }

    if ($run === [] && $checks === []) {
      $out .= "\n> **This run has no rows.** The store holds neither a `run`\n"
        . "> record nor a single adjudicated check for `" . self::escape($runId) . "`.\n"
        . "> Every table below is empty because nothing was recorded — which is\n"
        . "> a finding about the round, not a rendering failure. Check the run\n"
        . "> id, and check whether `EvidenceRecorder::lastError()` was set while\n"
        . "> the phases ran.\n";
    }
    elseif ($run === []) {
      $out .= "\n> **No `run` row.** The checks below were recorded without one,\n"
        . "> so §1 and §3 can say nothing about the levers this round ran at.\n";
    }

    return $out;
  }

  /**
   * Section 1 — who this round was.
   *
   * @param array<array-key, mixed> $run
   *   The run row, or an empty array.
   * @param string $runId
   *   The run.
   *
   * @return string
   *   The section.
   */
  private function identity(array $run, string $runId): string {
    $ticket = $this->store->declared($runId, 'work_item');
    $rows = [
      ['Round', self::code($runId), 'measured'],
      ['Started (as recorded)', self::code(self::text($run, 'started_at')), 'reported'],
      ['Preset', self::code(self::text($run, 'preset')), 'reported'],
      ['Mode', self::code(self::text($run, 'mode')), 'reported'],
      ['Enforcement (requested)', self::code(self::text($run, 'enforcement')), 'reported'],
      ['Base commit', self::code(self::text($run, 'base_commit')), 'reported'],
      ['Spec', self::code(self::text($run, 'spec_path')), 'reported'],
      // NOT `measured`, and not "Spec hash" either — both were wrong, and a
      // reviewer proved both by running them. droost does the hashing, but over
      // whatever file `run.json` names, so pointing a run at a decoy moves the
      // digest and it stays perfectly honest about the decoy. And the digest
      // covers ONLY the `## Tooling plan` section: two specs differing in
      // title, grounding table and headings hash identically. A reader seeing
      // "Spec hash: measured" concludes the document is pinned. One section is.
      [
        'Tooling-plan hash',
        self::code(self::text($run, 'spec_hash')),
        'hashed by droost, over the file run.json names',
      ],
      [
        'Spec frozen at',
        self::code(self::text($run, 'spec_frozen_at')),
        // Not "hashed by droost" — nothing is hashed and no file is involved.
        // This is droost's own clock at plan exit, which is a STRONGER
        // provenance than the digest above it; copying the digest's caveat
        // onto it gave the weaker claim to the stronger fact.
        "droost's clock, at plan exit",
      ],
      [
        'Ticket / request',
        $ticket === [] ? self::NOT_RECORDED : self::code(implode(', ', $ticket)),
        $ticket === [] ? '—' : 'declared',
      ],
      ['Subject (model, effort, host, version)', self::NOT_RECORDED, '—'],
      ['Driver (tmux / interactive / CI)', self::NOT_RECORDED, '—'],
      ['Elapsed', self::NOT_RECORDED, '—'],
      ['Operator interventions', self::NOT_RECORDED, '—'],
    ];

    return "## 1. Round identity\n\n"
      . self::table(['Field', 'Value', 'Source'], $rows)
      . "\nWhat §1 cannot say, and why:\n\n"
      . "- **`reported` means the value reached the store THROUGH `run.json`.**\n"
      . "  droost recorded it faithfully; it did not verify it. An agent that\n"
      . "  edits that file before a phase changes these rows, and the gate set\n"
      . "  that actually ran — §3 and §4 — is the check on them.\n"
      . "- **The tooling-plan hash is not a hash of the spec.** It covers the\n"
      . "  `## Tooling plan` section and nothing else, so two specs differing in\n"
      . "  title, grounding and criteria share a digest. The freeze does NOT\n"
      . "  rest on it — it compares the recorded TEXT row by row, because \"every\n"
      . "  row that was there is still there\" is not a question a hash can\n"
      . "  answer. Read the digest as a tripwire, never as a seal.\n"
      . "- **Subject, driver and elapsed** are facts about the harness that ran\n"
      . "  the round, not about the run. Nothing writes them to the store, so\n"
      . "  they are captured by hand from the template's §2.\n"
      . "- **Enforcement is the REQUESTED value.** `{requested, effective,\n"
      . "  reason}` is computed at read time and never persisted, so no\n"
      . "  archived round — this one included — can say whether its discipline\n"
      . "  actually held. That blind spot is the template's §7.3, and it is\n"
      . "  still dark.\n"
      . "- **Operator interventions** leave a trace in the record only where an\n"
      . "  operator unblocked a check; see the `unblocked by the operator` rows\n"
      . "  in §4. Everything else an operator did during the round is invisible\n"
      . "  from here.\n";
  }

  /**
   * Section 2 — the environment, which is captured before the run exists.
   *
   * @return string
   *   The stub.
   */
  private function environmentStub(): string {
    return "## 2. Environment — captured BEFORE the subject starts\n\n"
      . "**Not generated, and it cannot be.** Three of this section's rows are\n"
      . "unrecoverable after the fact — gate arming, enforcement effectiveness,\n"
      . "and whether a patch truly landed — which is exactly why the template\n"
      . "asks for them before the subject starts. A store written DURING the\n"
      . "run is the wrong side of that line.\n\n"
      . "Fill it from `pack/templates/evaluation.md` §2, with the four traps it\n"
      . "lists.\n";
  }

  /**
   * Section 3 — the levers, as far as the record knows them.
   *
   * @param array<array-key, mixed> $run
   *   The run row, or an empty array.
   * @param list<array<array-key, mixed>> $checks
   *   Every adjudicated check of the run.
   *
   * @return string
   *   The section.
   */
  private function levers(array $run, array $checks): string {
    $rows = [
      [
        self::code('mode'),
        self::code(self::text($run, 'mode')),
        'frozen into the run at begin',
      ],
      [
        self::code('preset'),
        self::code(self::text($run, 'preset')),
        'the effort every other lever\'s meaning is relative to',
      ],
      [
        self::code('enforcement'),
        self::code(self::text($run, 'enforcement')),
        'the requested value only — see §1',
      ],
      [
        self::code('require_run'),
        self::NOT_RECORDED,
        'the guard re-reads raw YAML on every write, and writes nothing back',
      ],
      [
        self::code('max_gate_retries'),
        self::NOT_RECORDED,
        'attempts per gate are countable in §4; the configured ceiling is not stored',
      ],
      [
        self::code('seekers'),
        self::NOT_RECORDED,
        'seeker rows land in `seeker_finding` and render in §4d; the lever does not',
      ],
      [
        self::code('baseline'),
        self::NOT_RECORDED,
        'the baseline hash lives in `run.json`, not here',
      ],
      [
        self::code('work_item'),
        'see §1',
        'declared with `--work-item`, and read by any contributed check that asks',
      ],
      [
        'write gates — the seven `allow_*`',
        self::NOT_RECORDED,
        'a tool REFUSAL reaches the ledger as `outcome: fail` (§4a); the arming does not',
      ],
    ];

    return "## 3. The lever table\n\n"
      . "The template's `Probe`, `Expected`, `Fired?` and `Verdict` columns are\n"
      . "the evaluator's work — a probe is a command someone ran before the\n"
      . "round, and a verdict is a judgement about whether what fired matched\n"
      . "what was asked for. Neither is a row. What the record holds is the\n"
      . "value, and whether anything was adjudicated under it.\n\n"
      . self::table(['Lever', 'In the record', 'Note'], $rows)
      . "\n### Gates\n\n"
      . self::table(
        ['Gate', 'Adjudicated in', 'Attempts', 'Latest state'],
        $this->gateLeverRows($checks),
      )
      . "\nA gate with `" . self::NOT_RECORDED . "` in the second column was never\n"
      . "adjudicated in this run at all — which is NOT the same as off. A gate\n"
      . "turned off by the preset or by the lever file still produces a row,\n"
      . "stated as `not applicable`. No row means the phase that owns the gate\n"
      . "never ran, or the recorder failed while it did.\n";
  }

  /**
   * One row per known gate, plus every gate the run named that is not one.
   *
   * @param list<array<array-key, mixed>> $checks
   *   Every adjudicated check of the run.
   *
   * @return list<list<string>>
   *   The rendered rows.
   */
  private function gateLeverRows(array $checks): array {
    $seen = [];
    foreach ($checks as $check) {
      if (self::text($check, 'kind') !== 'gate') {
        continue;
      }
      $name = (string) (self::text($check, 'name') ?? '');
      $phase = (string) (self::text($check, 'phase') ?? '');
      $seen[$name]['phases'][$phase] = $phase;
      // Insertion order is the query's order, which is now run order.
      $seen[$name]['attempts'] = max(
        $seen[$name]['attempts'] ?? 0,
        self::number($check, 'attempt') ?? 0,
      );
      // The rows arrive ordered by attempt, so the last one wins.
      $seen[$name]['state'] = self::text($check, 'state');
    }

    $names = GateSettings::KNOWN_GATES;
    foreach (array_keys($seen) as $name) {
      if (!in_array($name, $names, TRUE)) {
        $names[] = $name;
      }
    }

    $rows = [];
    foreach ($names as $name) {
      $found = $seen[$name] ?? NULL;
      $rows[] = [
        self::code($name),
        $found === NULL ? self::NOT_RECORDED : self::code(implode(', ', $found['phases'])),
        $found === NULL ? '0' : (string) $found['attempts'],
        $found === NULL ? self::NOT_RECORDED : self::stateLabel(is_string($found['state']) ? $found['state'] : NULL),
      ];
    }

    return $rows;
  }

  /**
   * Section 4 — what each gate concluded, and whether it measured anything.
   *
   * @param string $runId
   *   The run.
   * @param list<array<array-key, mixed>> $checks
   *   Every adjudicated check of the run.
   *
   * @return string
   *   The section.
   */
  private function gateVerdicts(string $runId, array $checks): string {
    $gates = array_values(array_filter(
      $checks,
      static fn (array $check): bool => self::text($check, 'kind') === 'gate',
    ));
    if ($gates === []) {
      return "## 4. Gate verdicts\n\n"
        . "No gate was adjudicated in this run. A phase whose configured gate\n"
        . "set is non-empty and whose evidence is empty has not run its gates,\n"
        . "whatever `phases` says about it.\n"
        . $this->remedies($checks)
        . $this->declarations($runId, $checks)
        . $this->otherChecks($checks);
    }

    // Keyed by phase and name so the newest attempt heads the table and the
    // ones it superseded survive under it. The old run record kept only the
    // last attempt and a counter, so a run could prove the feedback loop had
    // fired and never what it corrected.
    $byItem = [];
    foreach ($gates as $gate) {
      $key = (self::text($gate, 'phase') ?? '') . "\0" . (self::text($gate, 'name') ?? '');
      $byItem[$key][] = $gate;
    }

    $rows = [];
    $earlier = [];
    $measured = 0;
    foreach ($byItem as $attempts) {
      $latest = $attempts[count($attempts) - 1];
      $state = CheckState::tryFrom((string) (self::text($latest, 'state') ?? ''));
      $duration = self::number($latest, 'duration_ms');
      $recorded = $latest['measured'] ?? NULL;
      $verdict = self::measurement($state, $duration, is_numeric($recorded) ? (int) $recorded : NULL);
      if ($verdict === 'yes') {
        $measured++;
      }
      $rows[] = [
        self::code(self::text($latest, 'name')),
        self::code(self::text($latest, 'phase')),
        self::stateLabel(self::text($latest, 'state')),
        self::faultLabel(self::text($latest, 'fault')),
        self::cell(self::number($latest, 'exit_code')),
        $duration === NULL ? self::NOT_RECORDED : (string) $duration,
        self::text($latest, 'invocation') === NULL ? 'no' : 'yes',
        $verdict,
        $this->stillDescribesTheCode($runId, $latest),
      ];
      if (count($attempts) > 1) {
        $earlier[] = $this->earlierAttempts($attempts);
      }
    }

    $out = "## 4. Gate verdicts\n\n"
      . "A green is not a measurement. For the GATES in this table —\n"
      . "`satisfied` and `recorded` rest on something droost collected;\n"
      . "`not applicable` and `unblocked by the operator` are honest and are\n"
      . "NOT measurements; `asked for, could not run here` means the gate was\n"
      . "wanted and this surface could not perform it; and a measured state\n"
      . "with `duration_ms: 0` means the tool never spawned. (Elsewhere in this\n"
      . "document `recorded` is also used for a DECLARATION droost kept but\n"
      . "could not check — that is not a measurement, and the row says so.)\n\n"
      . "**Still true?** re-measures the fingerprint of what each gate examined,\n"
      . "as the tree stands now:\n\n"
      . "- `yes` — the subject is byte-identical to what the verdict was about.\n"
      . "- `**EXPIRED**` — the subject has changed since. Not a green now.\n"
      . "- `**EXPIRED** (subject gone)` — the gate HAD a subject and it no\n"
      . "  longer exists. The most complete form of expiry there is.\n"
      . "- `unknown` — the gate has no `paths` lever, so there is nothing to\n"
      . "  fingerprint and there never was. NOT the same as unchanged.\n\n"
      . self::table(
        [
          'Gate', 'Phase', 'State', 'Fault', 'Exit', '`duration_ms`',
          'Invocation recorded', 'Measured anything?', 'Still true?',
        ],
        $rows,
      )
      . sprintf(
        "\n**%d gate%s adjudicated. %d measured something.** That second number\n"
        . "is the INPUT to §6's V score, and it is not the score: a gate that\n"
        . "could not have failed scores zero whatever its status, and no column\n"
        . "here knows what a gate was pointed at.\n",
        count($rows),
        count($rows) === 1 ? '' : 's',
        $measured,
      )
      . $this->expiryNote($rows);

    if ($earlier !== []) {
      $out .= "\n### Earlier attempts\n\n"
        . "The store is append-only: a retried gate gets a new row rather than\n"
        . "overwriting the one it failed on. This is the feedback loop, with\n"
        . "what it corrected.\n\n"
        . implode('', $earlier);
    }

    $out .= $this->invocations($byItem);

    return $out
      . $this->remedies($checks)
      . $this->declarations($runId, $checks)
      . $this->otherChecks($checks);
  }

  /**
   * How many verdicts could be re-measured for expiry, and how many had moved.
   *
   * Said out loud because the quiet failure is a column of `unknown` that reads
   * like a column of ticks. A gate's fingerprint comes from its `paths` lever,
   * and the DEFAULT levers for phpcs and phpstan carry no `paths` at all —
   * those tools are pointed by phpcs.xml.dist and phpstan.neon, which the run
   * record never sees. So for a stock project this mechanism currently judges
   * almost nothing, and a reader is entitled to know that rather than infer it.
   *
   * @param list<list<string>> $rows
   *   The rendered gate rows; the expiry cell is the last of each.
   *
   * @return string
   *   The note.
   */
  private function expiryNote(array $rows): string {
    $checkable = 0;
    $expired = 0;
    foreach ($rows as $row) {
      $cell = $row[array_key_last($row)] ?? '';
      if ($cell === 'yes' || str_starts_with($cell, '**EXPIRED**')) {
        $checkable++;
      }
      if (str_starts_with($cell, '**EXPIRED**')) {
        $expired++;
      }
    }

    if ($checkable === 0) {
      return "\n**No verdict here could be checked for expiry.** A gate's\n"
        . "fingerprint comes from its `paths` lever, and the default levers for\n"
        . "the mandatory trio carry none — phpcs and phpstan are pointed by\n"
        . "their own config files, which this record never sees. Treat every\n"
        . "green above as unverified against the tree as it now stands.\n";
    }

    return sprintf(
      "\n**%d of %d verdict%s could be re-measured against the tree as it now\n"
      . "stands; %d had expired.** An expired verdict was green about code that\n"
      . "has since moved, which is not a green now. The rest had no resolvable\n"
      . "subject to fingerprint, which is not the same as unchanged.\n",
      $checkable,
      count($rows),
      count($rows) === 1 ? '' : 's',
      $expired,
    );
  }

  /**
   * The superseded attempts of one gate, as a bullet list.
   *
   * @param list<array<array-key, mixed>> $attempts
   *   Every attempt of one gate in one phase, oldest first.
   *
   * @return string
   *   The list.
   */
  private function earlierAttempts(array $attempts): string {
    $latest = $attempts[count($attempts) - 1];
    $out = sprintf(
      "- %s in %s — %d attempts, ending %s:\n",
      self::code(self::text($latest, 'name')),
      self::code(self::text($latest, 'phase')),
      count($attempts),
      self::stateLabel(self::text($latest, 'state')),
    );
    foreach (array_slice($attempts, 0, -1) as $attempt) {
      $out .= sprintf(
        "  - attempt %s — %s (%s), exit %s, %s ms: %s\n",
        self::cell(self::number($attempt, 'attempt')),
        self::stateLabel(self::text($attempt, 'state')),
        self::faultLabel(self::text($attempt, 'fault')),
        self::cell(self::number($attempt, 'exit_code')),
        self::cell(self::number($attempt, 'duration_ms')),
        self::cell(self::text($attempt, 'summary')),
      );
    }

    return $out;
  }

  /**
   * The exact command line of every gate that recorded one.
   *
   * Printed outside the table because it is the one field a reader is meant to
   * copy and run, and a table cell mangles it — the pipes get escaped and long
   * argv wraps into noise.
   *
   * Every DISTINCT command, not just the latest attempt's. A retry that
   * narrowed its paths ran a different tool over a different subject, and a
   * report showing only the command that finally passed would hide the one
   * question worth asking about it.
   *
   * @param array<string, list<array<array-key, mixed>>> $byItem
   *   The gates, keyed by phase and name, oldest attempt first.
   *
   * @return string
   *   The section, or an empty string when nothing recorded one.
   */
  private function invocations(array $byItem): string {
    $lines = [];
    foreach ($byItem as $attempts) {
      $seen = [];
      foreach ($attempts as $attempt) {
        $invocation = self::text($attempt, 'invocation');
        if ($invocation === NULL || isset($seen[$invocation])) {
          continue;
        }
        $seen[$invocation] = TRUE;
        $lines[] = sprintf(
          "# %s (%s), attempt %s\n%s\n",
          self::text($attempt, 'name') ?? '',
          self::text($attempt, 'phase') ?? '',
          self::number($attempt, 'attempt') ?? 0,
          $invocation,
        );
      }
    }
    if ($lines === []) {
      return "\n**No gate recorded an invocation.** Nothing in this section can\n"
        . "be re-run by the reader, and nothing corroborates that a tool was\n"
        . "spawned other than its own duration.\n";
    }

    // fence(), not a fixed ``` — an invocation is built from the `command`,
    // `args` and `paths` a gate's lever file names, and droost.workflow.yml is
    // a file the guard deliberately exempts from the plan-phase block. A
    // crafted path could close this fence and write a "## Verdict — PASS"
    // heading into a report whose every gate was blocked.
    $body = implode("\n", $lines);

    return "\n### Invocations, as recorded\n\n" . self::fence($body) . "\n";
  }

  /**
   * The newest attempt of every item, gates and everything else alike.
   *
   * @param list<array<array-key, mixed>> $checks
   *   Every adjudicated check of the run, oldest attempt first.
   *
   * @return list<array<array-key, mixed>>
   *   One row per phase, kind and name.
   */
  private static function latest(array $checks): array {
    $byItem = [];
    foreach ($checks as $check) {
      $key = (self::text($check, 'phase') ?? '')
        . "\0" . (self::text($check, 'kind') ?? '')
        . "\0" . (self::text($check, 'name') ?? '');
      $byItem[$key] = $check;
    }

    return array_values($byItem);
  }

  /**
   * Every blocked check that names a command, and who is allowed to run it.
   *
   * Over every kind, not just the gates. A blocked environment fault whose
   * remedy the report withheld is what wedged a live run for an hour: the
   * agent could do nothing right, the operator was never shown the one command
   * that would have cleared it, and nothing in the record said so.
   *
   * The converse is the harder rule and the store already enforces it — a
   * remedy may ride only on an environment fault. A command printed beside
   * work the agent must simply do reads as a way out of doing it, and in a
   * live round an agent asked to waive the gate that was holding back an
   * HTML-entity-encoded `javascript:` URL. This section is one more surface
   * that must not offer that door.
   *
   * @param list<array<array-key, mixed>> $checks
   *   Every adjudicated check of the run.
   *
   * @return string
   *   The section, or an empty string when nothing blocked on the environment.
   */
  private function remedies(array $checks): string {
    $lines = [];
    foreach (self::latest($checks) as $check) {
      $remedy = self::text($check, 'remedy');
      $state = CheckState::tryFrom((string) (self::text($check, 'state') ?? ''));
      $fault = Fault::tryFrom((string) (self::text($check, 'fault') ?? '')) ?? Fault::None;
      // The actual rule, not a proxy for it. "Carries a remedy" was standing in
      // for "an operator may lift this", and `Fault::operatorMayUnblock()` —
      // which says exactly that and had no caller — is the rule itself. The
      // two agree today because `CheckRecord` refuses a remedy on any other
      // fault, but one of them is the definition and the other is a symptom.
      if ($state !== CheckState::Blocked || !$fault->operatorMayUnblock()) {
        continue;
      }
      $lines[] = sprintf(
        "- %s (%s) in %s — %s\n  - %s\n  - remedy: `%s`\n",
        self::code(self::text($check, 'name')),
        self::cell(self::text($check, 'kind')),
        self::code(self::text($check, 'phase')),
        self::cell(self::text($check, 'summary')),
        // What to DO about this fault. `Fault::guidance()` has said it all
        // along — "this is the work, not the setup", or "show the OPERATOR the
        // remedy and do not run it yourself" — and nothing rendered it, so the
        // one sentence distinguishing "fix the code" from "ask a human" reached
        // nobody. Another writer with no reader.
        self::escape($fault->guidance()),
        self::escape((string) $remedy),
      );
    }
    if ($lines === []) {
      return '';
    }

    return "\n### Environment blocks, and who may lift them\n\n"
      . "A block carrying `environment` cannot be satisfied from where the\n"
      . "agent stands. The phase still does not advance — an OPERATOR lifts\n"
      . "it, recorded as `unblocked by the operator`, and the agent may propose\n"
      . "that and never perform it. Every other fault has no such door, and\n"
      . "carries no remedy for the record to print.\n\n"
      . implode('', $lines);
  }

  /**
   * What the plan promised, and what the diff was held to.
   *
   * A declaration is the one contract in the run that the agent writes and is
   * then measured against: the plan names the files it will change and the
   * tests it will add, and the code phase adjudicates the diff against that
   * promise. It is not a gate — nothing spawns, so the §4 table's spawn test
   * would mark every one of them unproven — and it is not a footnote either,
   * because a run that quietly dropped the tests it promised is exactly what
   * it exists to catch.
   *
   * @param string $runId
   *   The run.
   * @param list<array<array-key, mixed>> $checks
   *   Every adjudicated check of the run.
   *
   * @return string
   *   The section.
   */
  private function declarations(string $runId, array $checks): string {
    $audits = [];
    foreach (self::latest($checks) as $check) {
      if (self::text($check, 'kind') === 'declaration') {
        $audits[(string) (self::text($check, 'name') ?? '')] = $check;
      }
    }
    $promised = [
      'file' => $this->store->declared($runId, 'file'),
      'test' => $this->store->declared($runId, 'test'),
    ];
    if ($audits === [] && $promised['file'] === [] && $promised['test'] === []) {
      return "\n### Declarations\n\n"
        . "The plan declared no files and no tests, and nothing adjudicated a\n"
        . "declaration. On a run whose plan phase produced a spec this is a\n"
        . "finding, not an absence: the diff was held to nothing.\n";
    }

    $rows = [];
    foreach (['file' => 'declared_files', 'test' => 'declared_tests'] as $kind => $name) {
      $audit = $audits[$name] ?? NULL;
      $rows[] = [
        self::code($kind),
        (string) count($promised[$kind]),
        $audit === NULL ? self::NOT_RECORDED : self::code(self::text($audit, 'phase')),
        $audit === NULL ? self::NOT_RECORDED : self::stateLabel(self::text($audit, 'state')),
        $audit === NULL ? self::NOT_RECORDED : self::faultLabel(self::text($audit, 'fault')),
        $audit === NULL
          ? 'nothing audited this promise'
          : self::cell(self::text($audit, 'summary')),
      ];
    }
    // Any other declaration check, so a kind added later is never swallowed.
    foreach ($audits as $name => $audit) {
      if (in_array($name, ['declared_files', 'declared_tests'], TRUE)) {
        continue;
      }
      $rows[] = [
        self::code($name),
        self::NOT_RECORDED,
        self::code(self::text($audit, 'phase')),
        self::stateLabel(self::text($audit, 'state')),
        self::faultLabel(self::text($audit, 'fault')),
        self::cell(self::text($audit, 'summary')),
      ];
    }

    $out = "\n### Declarations — what the plan promised\n\n"
      . self::table(
        ['Kind', 'Declared', 'Audited in', 'State', 'Fault', 'Summary'],
        $rows,
      )
      . "\nThree things read as green from a distance and none of them is.\n"
      . "A declared count with no audit is a promise nobody checked. An audit\n"
      . "with nothing declared is a check with nothing to hold the diff to.\n"
      . "And — the common case, so read the rows rather than the states — an\n"
      . "audit that RAN and could not check what it was given: `declared_tests`\n"
      . "is always `recorded`, because droost sees that a suite ran and how many\n"
      . "tests it held, never which ones. Only `declared_files` is audited\n"
      . "against the diff.\n";

    foreach ($promised as $kind => $values) {
      if ($values === []) {
        continue;
      }
      $out .= "\n" . ucfirst($kind) . "s declared:\n\n";
      foreach (array_slice($values, 0, self::MAX_FINDING_ROWS) as $value) {
        $out .= '- `' . self::escape($value) . "`\n";
      }
      if (count($values) > self::MAX_FINDING_ROWS) {
        $out .= sprintf(
          "- … and %d more, not printed.\n",
          count($values) - self::MAX_FINDING_ROWS,
        );
      }
    }

    return $out;
  }

  /**
   * Section 4d — what the adversarial reviewer actually found.
   *
   * These rows were written by `recordSeeker()` and read by nothing, which is
   * the same dead-seam shape as everything else found this week: a writer
   * without a reader is a table nobody can query, and §3 asserted in a cell
   * that "seeker rows land in `seeker_finding`" while the document never
   * printed one.
   *
   * The cost of that gap is already on the record. `RunState`'s docblock keeps
   * it: across four rounds, 6, 25, 12 and 20 findings were caught and recorded
   * as 0, 0, 6 and 2. An adversarial review whose findings cannot be read back
   * is ceremony.
   *
   * @param string $runId
   *   The run.
   *
   * @return string
   *   The section.
   */
  private function seekerFindings(string $runId): string {
    $rows = $this->store->seekerFindings($runId);
    $heading = "## 4d. The seeker's findings — what an adversarial read caught\n\n";

    if ($rows === []) {
      return $heading
        . "No seeker findings are recorded for this run. That means one of three\n"
        . "things, and they are not equivalent: no inspection was due at this\n"
        . "level, an inspection ran and found nothing, or an inspection ran and\n"
        . "its ledger was never recorded. The seeker rows in §4 say which.\n";
    }

    $table = [];
    $open = 0;
    foreach ($rows as $row) {
      $status = self::text($row, 'status') ?? '';
      if (stripos($status, 'open') !== FALSE) {
        $open++;
      }
      $table[] = [
        self::code(self::text($row, 'ref')),
        self::cell(self::number($row, 'round')),
        self::escape(strtoupper(self::text($row, 'severity') ?? '')),
        self::code(self::text($row, 'location')),
        self::escape(self::text($row, 'finding') ?? ''),
        self::escape($status),
      ];
    }

    return $heading
      . "Written from the ledger droost PARSED, never from the agent's summary\n"
      . "of it — the counts and the rows come from the same place, so they\n"
      . "cannot disagree.\n\n"
      . self::table(['Ref', 'Round', 'Severity', 'Location', 'Finding', 'Status'], $table)
      . sprintf(
        "\n**%d finding%s recorded, %d still open.** An open finding at the end\n"
        . "of a run is not a failure of the run — it is the part a reader has to\n"
        . "judge, which is why it is here rather than summarised away.\n",
        count($table),
        count($table) === 1 ? '' : 's',
        $open,
      );
  }

  /**
   * Section 4c — what the tools said, beside what droost made of them.
   *
   * The findings are what droost PARSED and this is what the tool said, and
   * the difference is the whole point of keeping both. It is the only thing
   * that helps when the parse was wrong, when the tool died before producing
   * anything structured, or when a reader simply does not believe the summary
   * — which, in a document whose subject is whether a green measured anything,
   * is a reader doing their job.
   *
   * @param string $runId
   *   The run.
   * @param list<array<array-key, mixed>> $checks
   *   Every adjudicated check of the run, for the phases it names.
   *
   * @return string
   *   The section.
   */
  private function transcripts(string $runId, array $checks): string {
    $heading = "## 4c. Transcripts — what the tool actually said\n\n";
    $rows = [];
    foreach (self::phases($checks) as $phase) {
      foreach ($this->store->transcripts($runId, $phase) as $row) {
        $rows[] = [$phase, $row];
      }
    }
    if ($rows === []) {
      return $heading
        . "**No transcript was recorded for this run.** Every verdict above\n"
        . "rests on what droost parsed, with nothing to check the parse\n"
        . "against. That is not a finding about the code — it is a limit on\n"
        . "how far this document can be audited, and it belongs in §7.3.\n";
    }

    $out = $heading
      . "What droost parsed is in §4 and §8a. This is what the tool wrote,\n"
      . "which is the only thing that helps when the parse was wrong or the\n"
      . "tool died before producing anything structured.\n";
    foreach ($rows as [$phase, $row]) {
      $content = self::text($row, 'content') ?? '';
      $out .= sprintf(
        "\n#### %s — %s, attempt %s, `%s` — %s\n\n%s\n",
        self::code(self::text($row, 'name')),
        self::code($phase),
        self::cell(self::number($row, 'attempt')),
        self::escape((string) (self::text($row, 'stream') ?? '')),
        self::stateLabel(self::text($row, 'state')),
        self::fence($content),
      );
    }

    return $out;
  }

  /**
   * The phases a run recorded anything in, in the order it recorded them.
   *
   * Insertion order rather than a canonical phase list: the record's own
   * sequence is a fact, and a hard-coded order would quietly reorder a run
   * that did something unexpected.
   *
   * @param list<array<array-key, mixed>> $checks
   *   Every adjudicated check of the run.
   *
   * @return list<string>
   *   The phase names.
   */
  private static function phases(array $checks): array {
    $phases = [];
    foreach ($checks as $check) {
      $phase = self::text($check, 'phase');
      if ($phase !== NULL) {
        $phases[$phase] = $phase;
      }
    }

    return array_values($phases);
  }

  /**
   * One fenced block, cut in the middle and honest about the cut.
   *
   * The fence is grown past the longest run of backticks the content holds, so
   * a transcript that itself contains a code fence cannot end the block early
   * and spill the rest of the round's output into the prose.
   *
   * @param string $content
   *   The raw stream.
   *
   * @return string
   *   The block.
   */
  private static function fence(string $content): string {
    $length = mb_strlen($content);
    if ($length > self::MAX_TRANSCRIPT) {
      $half = intdiv(self::MAX_TRANSCRIPT, 2);
      $content = mb_substr($content, 0, $half)
        . sprintf("\n\n… %d of %d characters cut from the middle …\n\n", $length - self::MAX_TRANSCRIPT, $length)
        . mb_substr($content, -$half);
    }
    $ticks = 3;
    if (preg_match_all('/`+/', $content, $runs) > 0) {
      foreach ($runs[0] as $run) {
        $ticks = max($ticks, strlen($run) + 1);
      }
    }
    $fence = str_repeat('`', $ticks);

    return $fence . "\n" . $content . "\n" . $fence;
  }

  /**
   * The checks of a run that are not gates.
   *
   * Listed rather than filtered away: a spec or seeker check that blocked a
   * phase is part of the round's verdict, and a §4 that silently dropped it
   * would read as a phase with nothing left to satisfy. Declarations are the
   * one kind missing here, because they have a richer section of their own.
   *
   * @param list<array<array-key, mixed>> $checks
   *   Every adjudicated check of the run.
   *
   * @return string
   *   The section.
   */
  private function otherChecks(array $checks): string {
    $rows = [];
    foreach ($checks as $check) {
      if (in_array(self::text($check, 'kind'), ['gate', 'declaration'], TRUE)) {
        continue;
      }
      $rows[] = [
        self::code(self::text($check, 'kind')),
        self::code(self::text($check, 'name')),
        self::code(self::text($check, 'phase')),
        self::cell(self::number($check, 'attempt')),
        self::stateLabel(self::text($check, 'state')),
        self::faultLabel(self::text($check, 'fault')),
        self::cell(self::text($check, 'summary')),
      ];
    }
    if ($rows === []) {
      return "\n### Checks that are neither gates nor declarations\n\nNone recorded.\n";
    }

    return "\n### Checks that are neither gates nor declarations\n\n"
      . "Every attempt, not just the latest — there are few of them and each\n"
      . "one is a thing a phase could not end without.\n\n"
      . self::table(
        ['Kind', 'Item', 'Phase', 'Attempt', 'State', 'Fault', 'Summary'],
        $rows,
      );
  }

  /**
   * Section 4a — what droost was actually asked for.
   *
   * The only part of the record that is not the subject's account of itself:
   * the agent does not write these rows, and a refusal lands here exactly the
   * way a success does.
   *
   * @param string $runId
   *   The run.
   *
   * @return string
   *   The section.
   */
  private function toolLedger(string $runId): string {
    $calls = $this->rows(
      'SELECT phase, tool, outcome, COUNT(*) AS n FROM tool_call
        WHERE run_id = ? GROUP BY phase, tool, outcome ORDER BY phase, tool',
      [$runId],
    );
    if ($calls === []) {
      return "## 4a. The tool-call ledger — what droost was ACTUALLY asked\n\n"
        . "**The ledger is empty for this run.** Not one droost tool was called\n"
        . "through a surface that records to the evidence store. Whatever the\n"
        . "spec's grounding table claims, this run asked the codebase nothing\n"
        . "that droost saw — unless the recording surface itself was not wired,\n"
        . "which is a defect in droost and belongs in §8.\n";
    }

    $total = 0;
    $refusals = 0;
    $perTool = [];
    $perPhase = [];
    foreach ($calls as $call) {
      $n = self::number($call, 'n') ?? 0;
      $tool = (string) (self::text($call, 'tool') ?? '');
      $phase = self::text($call, 'phase') ?? '';
      $total += $n;
      $perTool[$tool] = ($perTool[$tool] ?? 0) + $n;
      $perPhase[$phase]['calls'] = ($perPhase[$phase]['calls'] ?? 0) + $n;
      $perPhase[$phase]['tools'][$tool] = $tool;
      $perPhase[$phase]['knowledge'] = ($perPhase[$phase]['knowledge'] ?? 0)
        + (in_array($tool, self::KNOWLEDGE_TOOLS, TRUE) ? $n : 0);
      $perPhase[$phase]['router'] = ($perPhase[$phase]['router'] ?? 0)
        + ($tool === self::ROUTER_TOOL ? $n : 0);
      if (self::text($call, 'outcome') === 'fail') {
        $refusals += $n;
      }
    }
    arsort($perTool);

    $knowledge = 0;
    foreach (self::KNOWLEDGE_TOOLS as $tool) {
      $knowledge += $perTool[$tool] ?? 0;
    }
    $router = $perTool[self::ROUTER_TOOL] ?? 0;

    $headline = [
      ['Total calls', (string) $total, 'every tool result, successes and refusals alike'],
      ['Distinct tools', (string) count($perTool), '—'],
      [
        '**Knowledge calls** (the ten in `KNOWLEDGE_TOOLS`)',
        (string) $knowledge,
        $knowledge === 0
          ? '**zero here means the run never asked the codebase anything**, whatever its grounding table says'
          : 'the codebase was reached',
      ],
      [
        'Router calls (' . self::code(self::ROUTER_TOOL) . ')',
        (string) $router,
        'the decision graph, which answers about droost rather than about the code',
      ],
      [
        '**Knowledge : router ratio**',
        sprintf('%d : %d', $knowledge, $router),
        'the number that exposed the original defect — 6 : 179 across 39 rounds',
      ],
      [
        'Refusals (`outcome: fail`)',
        (string) $refusals,
        'each one is a gate that was off, or an argument that was wrong',
      ],
    ];

    $phaseRows = [];
    foreach ($perPhase as $phase => $facts) {
      $phaseRows[] = [
        $phase === '' ? '— before the run opened —' : self::code($phase),
        (string) $facts['calls'],
        (string) count($facts['tools']),
        (string) $facts['knowledge'],
        (string) $facts['router'],
      ];
    }

    $toolRows = [];
    foreach ($perTool as $tool => $n) {
      $toolRows[] = [
        self::code($tool),
        (string) $n,
        self::toolClass($tool),
      ];
    }

    return "## 4a. The tool-call ledger — what droost was ACTUALLY asked\n\n"
      . "One row per tool result, successes and refusals alike. This is the\n"
      . "only place in the system that is not the subject's account of itself.\n\n"
      . self::table(['Measure', 'Value', 'Reading'], $headline)
      . "\n### By phase\n\n"
      . "The JSONL ledger this replaced carried no phase, so its lines could\n"
      . "not be attributed to one from the record alone.\n\n"
      . self::table(['Phase', 'Calls', 'Distinct tools', 'Knowledge', 'Router'], $phaseRows)
      . "\n### Every tool called\n\n"
      . self::table(['Tool', 'Calls', 'Class'], $toolRows)
      . "\nWhat this section still cannot answer, and the template's §4a asks\n"
      . "for: **tooling-plan fidelity** (every droost tool the Tooling plan\n"
      . "named, against this ledger) and **scaffold-vs-handwritten** (how many\n"
      . "added files came from a blueprint and how many were typed). Both need\n"
      . "the spec and the diff, neither of which is in the store.\n";
  }

  /**
   * Section 4b — whether the spec's grounding citations reached anything.
   *
   * @param string $runId
   *   The run.
   *
   * @return string
   *   The section.
   */
  private function grounding(string $runId): string {
    $rows = $this->rows(
      'SELECT * FROM grounding_row WHERE run_id = ? ORDER BY tier, phase',
      [$runId],
    );
    $heading = "## 4b. Grounding — the three tiers, and whether each was reached\n\n";
    if ($rows === []) {
      return $heading
        . "**The store holds no grounding rows for this run.** That is not a\n"
        . "verdict on the round's grounding — it means either the spec carried\n"
        . "no grounding table, or `grounding_check` never ran, or it ran and\n"
        . "recorded nothing. §4's row for `grounding_check` says which, and §4a\n"
        . "says whether any knowledge tool was reached at all. The two halves\n"
        . "fail independently, and a pass on one tells you nothing about the\n"
        . "other.\n";
    }

    // The three tiers appear whether or not the spec claimed them. A tier
    // with no rows is a reading in itself — most often the `contrib` one,
    // which is the tier whose absence means nobody asked whether a module
    // already does this.
    $tiers = [];
    foreach (SpecContract::TIERS as $tier) {
      $tiers[$tier] = ['rows' => 0, 'cited' => 0, 'resolved' => 0, 'stores' => []];
    }
    foreach ($rows as $row) {
      $tier = (string) (self::text($row, 'tier') ?? '');
      $tiers[$tier] ??= ['rows' => 0, 'cited' => 0, 'resolved' => 0, 'stores' => []];
      $tiers[$tier]['rows']++;
      $tiers[$tier]['cited'] += self::text($row, 'citation') === NULL ? 0 : 1;
      $tiers[$tier]['resolved'] += (self::number($row, 'resolved') ?? 0) === 1 ? 1 : 0;
      $store = self::text($row, 'store');
      if ($store !== NULL) {
        $tiers[$tier]['stores'][$store] = $store;
      }
    }

    $summary = [];
    foreach ($tiers as $tier => $facts) {
      $stores = $facts['stores'];
      $summary[] = [
        self::code($tier),
        (string) $facts['rows'],
        (string) $facts['cited'],
        (string) $facts['resolved'],
        $stores === [] ? self::NOT_RECORDED : self::cell(implode(', ', $stores)),
      ];
    }

    $detail = [];
    foreach ($rows as $row) {
      $detail[] = [
        self::code(self::text($row, 'tier')),
        self::code(self::text($row, 'phase')),
        self::cell(self::text($row, 'asked')),
        self::cell(self::text($row, 'found')),
        self::code(self::text($row, 'citation')),
        (self::number($row, 'resolved') ?? 0) === 1 ? 'yes' : 'no',
      ];
    }

    return $heading
      . self::table(
        ['Tier', 'Rows', 'Cited', 'Citations that resolved', 'Store that answered'],
        $summary,
      )
      . "\n- **A tier with rows but no resolvable citation is not grounded.**\n"
      . "  Prose in `Found` is the agent's account of itself; the citation is\n"
      . "  what the site confirms.\n"
      . "- **Half two is §4a, not this table.** A citation proves the symbol\n"
      . "  exists and can be copied out of a file. The ledger is what makes the\n"
      . "  lookup a fact.\n"
      . "- **Core resolves against the brain, and only the brain.** Core is a\n"
      . "  scope nothing indexes by default. A round reporting core citations\n"
      . "  resolving against the symbol graph has a wrong probe, not a finding.\n"
      . "\n### Every row\n\n"
      . self::table(
        ['Tier', 'Phase', 'Asked', 'Found', 'Citation', 'Resolved'],
        $detail,
      );
  }

  /**
   * Section 5 — refused, and the refusal is the point.
   *
   * @return string
   *   The stub.
   */
  private function buildVerdictStub(): string {
    // The two load-bearing phrases sit unbroken on their own lines. A reader
    // — or a grep — looking for the refusal must find it whole, and hard
    // wrapping has already split one of them once.
    return "## 5. Build verdict\n\n"
      . "**Not generated, and it must not be.** This section checks the\n"
      . "spec's own EARS acceptance criteria as they behave on a running\n"
      . "site — **verified live, not from the run record**. Generating it\n"
      . "from the run record instead would be exactly the\n"
      . "circularity the template exists to prevent: the run record is the\n"
      . "subject's account of itself, and a verdict derived from it would\n"
      . "agree with the subject by construction, every time, including the\n"
      . "times the subject was wrong.\n\n"
      . "Fill one row per criterion, against a running site:\n\n"
      . self::table(['#', 'Criterion (EARS)', 'How verified', 'Result'], [])
      . "\nEverything droost knows that bears on this is already above: §4 says\n"
      . "which gates measured anything, §4a says what the agent actually asked\n"
      . "for, §8a says what the gates found. None of it says the thing got\n"
      . "built.\n";
  }

  /**
   * Section 6 — three numbers, none of them derivable here.
   *
   * @return string
   *   The stub.
   */
  private function scoreStub(): string {
    return "## 6. Score — `S / V / B`\n\n"
      . "**Not generated.** Three numbers, never collapsed — a product can be\n"
      . "disciplined and useless at once — and not one of them is a query:\n\n"
      . "- **S** (setup) asks whether a stranger could install droost and build\n"
      . "  one thing with no operator rescue. Zero if this round arranged setup\n"
      . "  in advance. Nothing in the store knows what was arranged.\n"
      . "- **V** (verification) is not §4's count of gates that measured\n"
      . "  something. That count is its input. A gate that could not have\n"
      . "  failed scores zero whatever its status, and whether a gate could\n"
      . "  have failed is a judgement about what it was pointed at.\n"
      . "- **B** (build) rests on §5, which is not generated for the reason\n"
      . "  given there.\n\n"
      . self::table(['Axis', 'Score', 'Basis'], [
        [
          '**S** setup',
          '`/10`',
          'Could a stranger install it and build one thing with no operator rescue? **Zero if this round arranged setup in advance.**',
        ],
        [
          '**V** verification',
          '`/10`',
          'Of the gates claiming green, how many measured something (§4)? A gate that could not have failed scores zero whatever its status.',
        ],
        [
          '**B** build',
          '`/10`',
          'Did the requested thing get built, correctly, against §5?',
        ],
      ])
      . "\nComparable only to a round at the same preset, install shape and §2.\n";
  }

  /**
   * Section 7 — the observability chain, which is watched rather than stored.
   *
   * @return string
   *   The stub.
   */
  private function observabilityStub(): string {
    return "## 7. Observability — the entire chain\n\n"
      . "**Not generated.** The chain's links are observed while the round runs\n"
      . "— a guard refusal on a custom write, a tool refusal's text, an\n"
      . "artefact on disk — and a store written by droost can only report the\n"
      . "links droost itself is on. Link 8 in particular (`phases`) is\n"
      . "subject-written by design, and corroborating it is the evaluator's\n"
      . "job.\n\n"
      . "Fill §7.1, §7.2 and §7.3 from `pack/templates/evaluation.md`. Two of\n"
      . "its blind spots are now lit and should be answered from above rather\n"
      . "than listed as dark: knowledge-layer usage is §4a, and tool refusals\n"
      . "are the refusal count in §4a. Enforcement effectiveness is still dark\n"
      . "— see §1.\n";
  }

  /**
   * Section 8 — the gates' findings, and the round's, which are not the same.
   *
   * @param string $runId
   *   The run.
   *
   * @return string
   *   The section.
   */
  private function findings(string $runId): string {
    $rows = $this->rows(
      'SELECT f.seq, f.file, f.line, f.rule, f.message, f.detail,
              c.id AS check_id, c.phase, c.kind, c.name, c.attempt, c.state, c.fault
         FROM finding f JOIN check_result c ON c.id = f.check_id
        WHERE c.run_id = ?
        ORDER BY c.phase, c.name, c.attempt, f.seq',
      [$runId],
    );

    $out = "## 8. Findings\n\n"
      . "Two different things share this heading in the template, and only one\n"
      . "of them is a row. They are split here so the more valuable one is not\n"
      . "quietly retired by the one that generates itself.\n\n"
      . "### 8a. Check findings — what the run's checks found in the code\n\n";

    if ($rows === []) {
      $out .= "No check recorded a structured finding in this run. Read that\n"
        . "against §4: a gate that measured nothing also finds nothing, and the\n"
        . "two look identical from here.\n";
    }
    else {
      $grouped = [];
      foreach ($rows as $row) {
        $grouped[(string) (self::number($row, 'check_id') ?? 0)][] = $row;
      }
      foreach ($grouped as $group) {
        $head = $group[0];
        $out .= sprintf(
          "\n#### %s — %s, attempt %s — %s (%s), %d finding%s\n\n",
          self::code(self::text($head, 'name')),
          self::code(self::text($head, 'phase')),
          self::cell(self::number($head, 'attempt')),
          self::stateLabel(self::text($head, 'state')),
          self::faultLabel(self::text($head, 'fault')),
          count($group),
          count($group) === 1 ? '' : 's',
        );
        $cells = [];
        foreach (array_slice($group, 0, self::MAX_FINDING_ROWS) as $finding) {
          $cells[] = [
            self::cell(self::number($finding, 'seq')),
            self::code(self::text($finding, 'file')),
            self::cell(self::number($finding, 'line')),
            self::code(self::text($finding, 'rule')),
            self::cell(self::text($finding, 'message')),
            self::cell(self::text($finding, 'detail')),
          ];
        }
        $out .= self::table(['#', 'File', 'Line', 'Rule', 'Message', 'Detail'], $cells);
        if (count($group) > self::MAX_FINDING_ROWS) {
          $out .= sprintf(
            "\n%d further finding%s on this check is not printed. Read them\n"
            . "from the store rather than assuming the first %d are typical.\n",
            count($group) - self::MAX_FINDING_ROWS,
            count($group) - self::MAX_FINDING_ROWS === 1 ? '' : 's',
            self::MAX_FINDING_ROWS,
          );
        }
      }
    }

    return $out . "\n### 8b. Round findings — what this round found in droost\n\n"
      . "**Not generated.** These are defects the ROUND exposed in the product,\n"
      . "and there is no table anywhere that holds them: finding one is the\n"
      . "reading, not the record. Nothing above can be promoted into this\n"
      . "section — a gate finding is droost working.\n\n"
      . self::table(
        ['#', 'Finding', 'Evidence (`file:line` / command + output)', 'Severity', 'Fixed at source?'],
        [],
      )
      . "\n> A finding names a file and line, or a command and its output, or a\n"
      . "> field in a recorded artefact. \"The agent said it passed\" is not\n"
      . "> evidence — it is the thing under test.\n";
  }

  /**
   * Runs one SELECT.
   *
   * The row shape is deliberately not claimed to be string-keyed. The
   * connection sets PDO::FETCH_ASSOC as its default, so in practice every row
   * arrives keyed by column name — but that is a fact about a constructor two
   * classes away, not something provable here, and a phpdoc type is a claim
   * rather than a proof. The readers below take whatever arrives and say so.
   *
   * @param string $sql
   *   The statement. Always a SELECT: this class never writes.
   * @param list<string> $args
   *   The bound parameters.
   *
   * @return list<array<array-key, mixed>>
   *   The rows.
   */
  private function rows(string $sql, array $args = []): array {
    $statement = $this->store->connection()->prepare($sql);
    $statement->execute($args);
    $rows = [];
    foreach ($statement->fetchAll() ?: [] as $row) {
      if (is_array($row)) {
        $rows[] = $row;
      }
    }

    return $rows;
  }

  /**
   * Whether a check's latest state rests on something droost collected.
   *
   * Two independent ways to be green having measured nothing, and both are
   * answered here so no reader has to hold the rule in their head: the state
   * may be honest-but-unmeasured, or the state may be measured while the tool
   * it names never spawned.
   *
   * @param \Droost\Workflow\Evidence\CheckState|null $state
   *   The state, or NULL when the stored value is not one droost knows.
   * @param int|null $durationMs
   *   How long the check took, when that was recorded.
   * @param int|null $measured
   *   What the executor recorded: 0 when the tool ran and examined nothing,
   *   1 when it did, NULL for a row written before it could say.
   *
   * @return string
   *   The cell.
   */
  private static function measurement(?CheckState $state, ?int $durationMs, ?int $measured = NULL): string {
    if ($state === NULL) {
      return 'no — the stored state is not one this build knows';
    }
    if (!$state->measured()) {
      return 'no — ' . $state->label();
    }
    // The executor's own answer, when the row carries one. This column and
    // `EvidenceStore::measuredGates()` are two implementations of one question
    // and they disagreed: over a labelled pass — phpcs exit 16, phpunit finding
    // no tests — this said no and the blocking one said yes. Both read the same
    // recorded fact now, so they cannot drift again.
    if ($measured === 0) {
      return 'no — the tool ran and examined nothing (a labeled pass)';
    }
    if ($durationMs === NULL) {
      return 'unproven — no duration recorded, so nothing says the tool spawned';
    }
    if ($durationMs === 0) {
      return 'no — the tool never spawned (`duration_ms: 0`)';
    }

    return 'yes';
  }

  /**
   * A state's human phrase, from the stored value.
   *
   * @param string|null $state
   *   The stored value.
   *
   * @return string
   *   The label.
   */
  private static function stateLabel(?string $state): string {
    $resolved = $state === NULL ? NULL : CheckState::tryFrom($state);

    return $resolved === NULL ? self::cell($state) : $resolved->label();
  }

  /**
   * A fault's phrase, from the stored value.
   *
   * @param string|null $fault
   *   The stored value.
   *
   * @return string
   *   The label.
   */
  private static function faultLabel(?string $fault): string {
    $resolved = $fault === NULL ? NULL : Fault::tryFrom($fault);
    if ($resolved === NULL) {
      return self::cell($fault);
    }

    return $resolved === Fault::None ? '—' : $resolved->value;
  }

  /**
   * Which side of the ledger's one interesting division a tool sits on.
   *
   * @param string $tool
   *   The tool id.
   *
   * @return string
   *   The class.
   */
  private static function toolClass(string $tool): string {
    if (in_array($tool, self::KNOWLEDGE_TOOLS, TRUE)) {
      return 'knowledge';
    }

    return $tool === self::ROUTER_TOOL ? 'router' : '—';
  }

  /**
   * A markdown table, or a header and a note when there are no rows.
   *
   * @param list<string> $headers
   *   The column headings.
   * @param list<list<string>> $rows
   *   The rows, already rendered as cells.
   *
   * @return string
   *   The table.
   */
  private static function table(array $headers, array $rows): string {
    $out = '| ' . implode(' | ', $headers) . " |\n"
      . '|' . str_repeat('---|', count($headers)) . "\n";
    foreach ($rows as $row) {
      $out .= '| ' . implode(' | ', $row) . " |\n";
    }

    return $out;
  }

  /**
   * One cell, safe to sit between two pipes, and never empty.
   *
   * Newlines and unescaped pipes both break a markdown table silently — a row
   * carrying a raw `|` renders as two short columns and a lost value, which a
   * live round found in the seeker report before it was found here.
   *
   * Nothing renders as a blank. Every column of every table in this document
   * holds either a value or NOT_RECORDED, headers included, so a reader
   * scanning for a gap finds a sentence rather than a space.
   *
   * @param mixed $value
   *   Whatever the column held.
   *
   * @return string
   *   The cell.
   */
  private static function cell(mixed $value): string {
    if ($value === NULL || $value === '') {
      return self::NOT_RECORDED;
    }
    if (!is_scalar($value)) {
      return self::NOT_RECORDED;
    }
    $text = self::escape((string) $value);

    return mb_strlen($text) > self::MAX_CELL
      ? mb_substr($text, 0, self::MAX_CELL) . ' … (cut)'
      : $text;
  }

  /**
   * One cell, rendered as a code span when there is anything to render.
   *
   * @param mixed $value
   *   Whatever the column held.
   *
   * @return string
   *   The cell.
   */
  private static function code(mixed $value): string {
    $cell = self::cell($value);

    return $cell === self::NOT_RECORDED ? $cell : '`' . str_replace('`', "'", $cell) . '`';
  }

  /**
   * Flattens a value onto one line and escapes what a table cell cannot hold.
   *
   * @param string $value
   *   The text.
   *
   * @return string
   *   The flattened text.
   */
  private static function escape(string $value): string {
    // `/u` makes preg_replace return NULL on malformed UTF-8, and the old
    // `?? $value` fallback then handed back the RAW value — newlines intact.
    // This is the single defence for every free-text cell in the document, and
    // it failed open on exactly the input most likely to be hostile or corrupt:
    // raw bytes out of a tool. A gate summary carrying one stray 0xC3 could
    // therefore close its table and open a heading of its own.
    //
    // So: collapse without /u when the unicode pass fails, which cannot fail,
    // and strip control characters either way. A cell is one line, always.
    $flat = preg_replace('/\s+/u', ' ', $value);
    if (!is_string($flat)) {
      $flat = (string) preg_replace('/[\x00-\x1F\x7F]+|\s+/', ' ', $value);
    }
    $flat = (string) preg_replace('/[\x00-\x1F\x7F]/', '', $flat);

    return str_replace('|', '\\|', trim($flat));
  }

  /**
   * A stored string, or NULL when the column is empty.
   *
   * @param array<array-key, mixed> $row
   *   The row.
   * @param string $key
   *   The column.
   *
   * @return string|null
   *   The value.
   */
  private static function text(array $row, string $key): ?string {
    $value = $row[$key] ?? NULL;

    return is_scalar($value) && (string) $value !== '' ? (string) $value : NULL;
  }

  /**
   * A stored integer, or NULL when the column holds nothing numeric.
   *
   * NULL and 0 are different answers and must stay different: 0 is a gate that
   * took no time, which is a finding, and NULL is a gate nobody timed.
   *
   * @param array<array-key, mixed> $row
   *   The row.
   * @param string $key
   *   The column.
   *
   * @return int|null
   *   The value.
   */
  private static function number(array $row, string $key): ?int {
    $value = $row[$key] ?? NULL;

    return is_numeric($value) ? (int) $value : NULL;
  }

}
