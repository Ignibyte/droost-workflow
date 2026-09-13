<?php

declare(strict_types=1);

namespace Droost\Workflow\Evidence;

use Droost\Workflow\Config\GateSettings;

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
   * Where a free-text cell is cut, in characters.
   *
   * Only the prose columns are cut, and the cut is marked. Identity columns —
   * gate, phase, file, rule — are printed whole however long they are, because
   * a truncated identifier is worse than a wide table.
   */
  private const int MAX_CELL = 200;

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
   * The evaluation for one run, as markdown.
   *
   * @param string $runId
   *   The run.
   *
   * @return string
   *   The document, following the structure of pack/templates/evaluation.md.
   *
   * @throws \Droost\Workflow\Evidence\EvidenceError
   *   When the store cannot be opened. Deliberately not caught: see the class
   *   docblock.
   */
  public function render(string $runId): string {
    $run = $this->rows('SELECT * FROM run WHERE run_id = ?', [$runId])[0] ?? [];
    $checks = $this->rows(
      'SELECT * FROM check_result WHERE run_id = ? ORDER BY phase, kind, name, attempt',
      [$runId],
    );

    $sections = [
      $this->preamble($runId, $run, $checks),
      $this->identity($run, $runId),
      $this->environmentStub(),
      "---\n",
      $this->levers($run, $checks),
      "---\n",
      $this->gateVerdicts($checks),
      $this->toolLedger($runId),
      $this->grounding($runId),
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
      . "> `phases`. It reads the evidence store, which droost writes from what\n"
      . "> it collected, never from what the agent reported.\n";

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
    $rows = [
      ['Round', self::code($runId)],
      ['Started (as recorded)', self::code(self::text($run, 'started_at'))],
      ['Preset', self::code(self::text($run, 'preset'))],
      ['Mode', self::code(self::text($run, 'mode'))],
      ['Enforcement (requested)', self::code(self::text($run, 'enforcement'))],
      ['Base commit', self::code(self::text($run, 'base_commit'))],
      ['Spec', self::code(self::text($run, 'spec_path'))],
      ['Spec hash', self::code(self::text($run, 'spec_hash'))],
      ['Spec frozen at', self::code(self::text($run, 'spec_frozen_at'))],
      ['Ticket / request', self::NOT_RECORDED],
      ['Subject (model, effort, host, version)', self::NOT_RECORDED],
      ['Driver (tmux / interactive / CI)', self::NOT_RECORDED],
      ['Elapsed', self::NOT_RECORDED],
      ['Operator interventions', self::NOT_RECORDED],
    ];

    return "## 1. Round identity\n\n"
      . self::table(['Field', 'Value'], $rows)
      . "\nWhat §1 cannot say, and why:\n\n"
      . "- **Ticket, subject, driver and elapsed** are facts about the harness\n"
      . "  that ran the round, not about the run. Nothing writes them to the\n"
      . "  store, so they are captured by hand from the template's §2.\n"
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
        'seeker rows land in `seeker_finding`; the lever itself does not',
      ],
      [
        self::code('baseline'),
        self::NOT_RECORDED,
        'the baseline hash lives in `run.json`, not here',
      ],
      [
        self::code('work_item'),
        self::NOT_RECORDED,
        'never enters the run record at all',
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
   * @param list<array<array-key, mixed>> $checks
   *   Every adjudicated check of the run.
   *
   * @return string
   *   The section.
   */
  private function gateVerdicts(array $checks): string {
    $gates = array_values(array_filter(
      $checks,
      static fn (array $check): bool => self::text($check, 'kind') === 'gate',
    ));
    if ($gates === []) {
      return "## 4. Gate verdicts\n\n"
        . "No gate was adjudicated in this run. A phase whose configured gate\n"
        . "set is non-empty and whose evidence is empty has not run its gates,\n"
        . "whatever `phases` says about it.\n"
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
      $verdict = self::measurement($state, $duration);
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
      ];
      if (count($attempts) > 1) {
        $earlier[] = $this->earlierAttempts($attempts);
      }
    }

    $out = "## 4. Gate verdicts\n\n"
      . "A green is not a measurement. The last column says which is which:\n"
      . "`satisfied` and `recorded` rest on something droost collected;\n"
      . "`not applicable` and `unblocked by the operator` are honest and are\n"
      . "NOT measurements; and a measured state with `duration_ms: 0` means the\n"
      . "tool never spawned.\n\n"
      . self::table(
        [
          'Gate', 'Phase', 'State', 'Fault', 'Exit', '`duration_ms`',
          'Invocation recorded', 'Measured anything?',
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
      );

    if ($earlier !== []) {
      $out .= "\n### Earlier attempts\n\n"
        . "The store is append-only: a retried gate gets a new row rather than\n"
        . "overwriting the one it failed on. This is the feedback loop, with\n"
        . "what it corrected.\n\n"
        . implode('', $earlier);
    }

    $out .= $this->invocations($byItem);

    return $out . $this->otherChecks($checks);
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
   * @param array<string, list<array<array-key, mixed>>> $byItem
   *   The gates, keyed by phase and name, oldest attempt first.
   *
   * @return string
   *   The section, or an empty string when nothing recorded one.
   */
  private function invocations(array $byItem): string {
    $lines = [];
    foreach ($byItem as $attempts) {
      $latest = $attempts[count($attempts) - 1];
      $invocation = self::text($latest, 'invocation');
      if ($invocation !== NULL) {
        $lines[] = sprintf(
          "# %s (%s)\n%s\n",
          self::text($latest, 'name') ?? '',
          self::text($latest, 'phase') ?? '',
          $invocation,
        );
      }
    }
    if ($lines === []) {
      return "\n**No gate recorded an invocation.** Nothing in this section can\n"
        . "be re-run by the reader, and nothing corroborates that a tool was\n"
        . "spawned other than its own duration.\n";
    }

    return "\n### Invocations, as recorded\n\n```\n" . implode("\n", $lines) . "```\n";
  }

  /**
   * The checks of a run that are not gates.
   *
   * Listed rather than filtered away: a declaration or a spec check that
   * blocked a phase is part of the round's verdict, and a §4 that silently
   * dropped it would read as a phase with nothing left to satisfy.
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
      if (self::text($check, 'kind') === 'gate') {
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
      return "\n### Checks that are not gates\n\nNone recorded.\n";
    }

    return "\n### Checks that are not gates\n\n"
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
      ['Total calls', (string) $total, ''],
      ['Distinct tools', (string) count($perTool), ''],
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
        '',
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
        in_array($tool, self::KNOWLEDGE_TOOLS, TRUE) ? 'knowledge' : ($tool === self::ROUTER_TOOL ? 'router' : ''),
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

    $tiers = [];
    foreach ($rows as $row) {
      $tier = (string) (self::text($row, 'tier') ?? '');
      $tiers[$tier]['rows'] = ($tiers[$tier]['rows'] ?? 0) + 1;
      $tiers[$tier]['cited'] = ($tiers[$tier]['cited'] ?? 0)
        + (self::text($row, 'citation') === NULL ? 0 : 1);
      $tiers[$tier]['resolved'] = ($tiers[$tier]['resolved'] ?? 0)
        + ((self::number($row, 'resolved') ?? 0) === 1 ? 1 : 0);
      $store = self::text($row, 'store');
      if ($store !== NULL) {
        $tiers[$tier]['stores'][$store] = $store;
      }
    }

    $summary = [];
    foreach ($tiers as $tier => $facts) {
      $stores = $facts['stores'] ?? [];
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
    return "## 5. Build verdict\n\n"
      . "**Not generated, and it must not be.** This section checks the spec's\n"
      . "own EARS acceptance criteria **verified live, not from the run\n"
      . "record** — and generating it from the run record would be exactly the\n"
      . "circularity the template exists to prevent. The run record is the\n"
      . "subject's account of itself; a verdict derived from it would agree\n"
      . "with the subject by construction, every time, including the times the\n"
      . "subject was wrong.\n\n"
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
      . self::table(['', 'Score', 'Basis'], [
        ['**S** setup', '`/10`', ''],
        ['**V** verification', '`/10`', ''],
        ['**B** build', '`/10`', ''],
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
      . "### 8a. Gate findings — what the gates found in the code under test\n\n";

    if ($rows === []) {
      $out .= "No gate recorded a structured finding in this run. Read that\n"
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
   *
   * @return string
   *   The cell.
   */
  private static function measurement(?CheckState $state, ?int $durationMs): string {
    if ($state === NULL) {
      return 'no — the stored state is not one this build knows';
    }
    if (!$state->measured()) {
      return 'no — ' . $state->label();
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
   * One cell, safe to sit between two pipes.
   *
   * Newlines and unescaped pipes both break a markdown table silently — a row
   * carrying a raw `|` renders as two short columns and a lost value, which a
   * live round found in the seeker report before it was found here.
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
    $flat = preg_replace('/\s+/u', ' ', $value) ?? $value;

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
