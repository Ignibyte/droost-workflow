<?php

declare(strict_types=1);

namespace Droost\Workflow\Gate;

/**
 * What one gate reported.
 *
 * Carries the invocation as well as the outcome. "phpcs failed" sends someone
 * looking at their code; "phpcs failed, and here is the command that was run"
 * lets them see that the standard was wrong — and when a tool is missing
 * entirely, the attempted invocation is the whole of the useful information.
 */
final class GateResult {

  /**
   * How many findings a result carries before it is truncated.
   */
  public const FINDINGS_CAP = 50;

  /**
   * The most raw output one stream may contribute to the record.
   */
  public const OUTPUT_CAP = 16384;

  /**
   * What the tool wrote to stdout, capped, or '' when nothing was captured.
   */
  public string $stdout = '';

  /**
   * What the tool wrote to stderr, capped, or '' when nothing was captured.
   */
  public string $stderr = '';

  /**
   * Constructs a GateResult.
   *
   * @param string $gate
   *   The gate name.
   * @param \Droost\Workflow\Gate\GateStatus $status
   *   What happened.
   * @param int|null $exitCode
   *   The tool's exit code, or NULL when nothing ran.
   * @param int|null $durationMs
   *   How long it took, supplied by the caller — no value object here reads a
   *   clock, so that every report is reproducible.
   * @param string $summary
   *   One line a human can read.
   * @param list<array<string, mixed>> $findings
   *   Structured findings, already capped.
   * @param bool $truncated
   *   Whether findings were dropped to fit the cap.
   * @param string|null $skipReason
   *   Why the gate did not run, when it did not.
   * @param string|null $invocation
   *   The command that ran, or that would have.
   * @param int|null $inherited
   *   How many findings the adoption baseline already recorded — reported,
   *   counted, never failing — or NULL when the gate consulted no baseline.
   *   Always printed beside a pass when set: `passed — 0 new, 123 inherited`
   *   is the whole point; a bare "passed" over inherited debt would hide it.
   * @param int|null $new
   *   How many findings the baseline does NOT record — the ones the verdict
   *   turns on — or NULL when no baseline was consulted.
   * @param bool $labelledPass
   *   TRUE when the tool ran and examined nothing. Three paths return a
   *   pass over an empty scan; each said so only in prose, which the
   *   evidence boundary discarded.
   * @param string|null $remedy
   *   What an OPERATOR can do about an environment block. Built-in gates
   *   could not carry one at all, so the guidance that tells a reader to
   *   "show the OPERATOR the remedy below" pointed at an empty string.
   * @param string|null $declaredFault
   *   The fault this result knows it is, where the default is ambiguous.
   *   Overrides `Unknown` only, so a gate cannot talk its way out of an
   *   agent fault.
   * @param list<string> $subjects
   *   What the tool was pointed at, project-relative: the `paths` lever
   *   narrowed to what exists, or the default the argv was built from.
   *   Empty when the tool reads its own config and the subject cannot be
   *   known here, or when nothing ran.
   */
  public function __construct(
    public readonly string $gate,
    public readonly GateStatus $status,
    public readonly ?int $exitCode = NULL,
    public readonly ?int $durationMs = NULL,
    public readonly string $summary = '',
    public readonly array $findings = [],
    public readonly bool $truncated = FALSE,
    public readonly ?string $skipReason = NULL,
    public readonly ?string $invocation = NULL,
    public readonly ?int $inherited = NULL,
    public readonly ?int $new = NULL,
    public readonly bool $labelledPass = FALSE,
    // WHAT AN OPERATOR CAN DO ABOUT IT. `Fault::Environment`'s guidance says
    // "Show the OPERATOR the remedy below and ask them to run it", the stop
    // hook prints that remedy only when it is non-empty, and no BUILT-IN gate
    // could ever carry one: `EvidenceRecorder::remedy()` reads the gate's
    // frozen levers, and the only writer of that key is a CONTRIBUTED gate's
    // own declaration. So every environment block droost produced by itself
    // pointed a reader at a remedy that was the empty string.
    //
    // The text already existed — `ShellGateExecutor::toolFailedHint()` writes
    // a per-gate sentence, and it was going into the summary only. This is
    // where it belongs as well, because the summary is what HAPPENED and the
    // remedy is what to DO, and the two surfaces render them differently.
    public readonly ?string $remedy = NULL,
    // WHEN THE EXECUTOR KNOWS. `ErrorToolFailed` maps to `Fault::Unknown`
    // because a crash is genuinely ambiguous in general — phpstan dying on PHP
    // the agent wrote is the agent's, snyk dying unauthenticated is not. But
    // the built-in executor produces `toolFailed()` only for the exit codes
    // that mean "I could not load my configuration", and there is nothing
    // ambiguous about those: the tool named a ruleset or a config file it
    // could not read.
    //
    // `Unknown` is treated as `Agent`, whose guidance is "This is the work,
    // not the setup … There is no waiver for it" — false about a broken
    // ruleset, and the reason a live round lost an hour. A gate that knows
    // says so, the same way a contributed gate's `faults:` map already does.
    public readonly ?string $declaredFault = NULL,
    // WHAT THE GATE WAS POINTED AT, project-relative. A green is meant to
    // expire when the code it was green about moves, and the fingerprint
    // came from the `paths` LEVER alone — which the default levers for the
    // mandatory trio do not carry. So on a stock project nothing was
    // fingerprinted, the evidence document's "Still true?" column read
    // `unknown` for every gate, and its note blamed "config files this
    // record never sees" while the recorded invocation fifteen lines below
    // named `src tests` exactly. The executor knows what it handed over; it
    // says so here, and the recorder hashes it when the lever is silent.
    public readonly array $subjects = [],
  ) {}

  /**
   * This result with the baseline partition recorded.
   *
   * @param int $inherited
   *   Findings the baseline records.
   * @param int $new
   *   Findings it does not.
   *
   * @return self
   *   A new result carrying both counts.
   */
  public function withBaselineCounts(int $inherited, int $new): self {
    // EVERY FIELD, BY NAME. This rebuilt the result from eleven positional
    // arguments and stopped there, so a baseline-partitioned verdict lost its
    // `remedy`, its `labelledPass` and its `declaredFault` on the way out —
    // the exact "one field, N rebuild sites, the inline ones drop it" trap
    // the run record's own docblock warns about. A field added below the
    // eleventh was silently NULL on every project with an adopted baseline.
    return $this->rebuilt(inherited: $inherited, new: $new);
  }

  /**
   * This result with what the gate was pointed at recorded.
   *
   * @param list<string> $subjects
   *   Project-relative paths the tool was handed.
   *
   * @return self
   *   The result.
   */
  public function withSubjects(array $subjects): self {
    return $this->rebuilt(subjects: $subjects);
  }

  /**
   * A copy of this result with some fields replaced, and every other kept.
   *
   * The one place the constructor is called with the whole field list, so a
   * field added to the class is carried by every `with…()` rather than by the
   * ones somebody remembered to update.
   *
   * @param int|null $inherited
   *   Baseline-inherited count, when replacing it.
   * @param int|null $new
   *   New-finding count, when replacing it.
   * @param list<string>|null $subjects
   *   The subjects, when replacing them.
   *
   * @return self
   *   The copy.
   */
  private function rebuilt(?int $inherited = NULL, ?int $new = NULL, ?array $subjects = NULL): self {
    $copy = new self(
      gate: $this->gate,
      status: $this->status,
      exitCode: $this->exitCode,
      durationMs: $this->durationMs,
      summary: $this->summary,
      findings: $this->findings,
      truncated: $this->truncated,
      skipReason: $this->skipReason,
      invocation: $this->invocation,
      inherited: $inherited ?? $this->inherited,
      new: $new ?? $this->new,
      labelledPass: $this->labelledPass,
      remedy: $this->remedy,
      declaredFault: $this->declaredFault,
      subjects: $subjects ?? $this->subjects,
    );
    $copy->stdout = $this->stdout;
    $copy->stderr = $this->stderr;

    return $copy;
  }

  /**
   * A gate that is configured off.
   *
   * @param string $gate
   *   The gate name.
   * @param string $reason
   *   What turned it off, phrased to follow "off —": the level ("by preset
   *   low") or the file ("by the lever file (preset xhigh turns it on)"). It
   *   rides as the skip reason too, so a renderer that prints a reason beside
   *   every non-pass shows it next to the status word, and "off" is never
   *   left to be read as "skipped".
   *
   * @return self
   *   The result.
   */
  public static function off(
    string $gate,
    string $reason = 'by the lever file',
  ): self {
    return new self(
      $gate,
      GateStatus::Off,
      summary: 'off — ' . $reason,
      skipReason: $reason,
    );
  }

  /**
   * A gate that needs a booted site when there is none.
   *
   * @param string $gate
   *   The gate name.
   * @param string $reason
   *   Why no site was available.
   *
   * @return self
   *   The result.
   */
  public static function skippedNoSite(
    string $gate,
    string $reason = NullSiteDriver::REASON,
  ): self {
    return new self(
      $gate,
      GateStatus::SkippedNoSite,
      summary: 'not run — ' . $reason,
      skipReason: $reason,
    );
  }

  /**
   * A gate whose tool is not installed.
   *
   * ONE SENTENCE FOR FOUR DIFFERENT FAULTS. This constructor is the only way
   * into `blocked/environment`, so eight call sites reach it, and every one of
   * them printed "install %s — `composer require --dev` or `npm install` it".
   * On four of them that is wrong, and wrong in the expensive direction: it
   * names an action that cannot work, on the one surface whose entire job is
   * to tell an operator what to type.
   *
   * phpunit with no `phpunit.xml` is installed. A contributed gate's `cmd`
   * exiting 127 names a program that has nothing to do with the gate's name —
   * `composer require --dev` on it installs nothing. A suite that passed but
   * measured no coverage needs a PHP EXTENSION, not a composer package. And a
   * gate that reached a site driver which does not implement it is not
   * installable at all.
   *
   * So the remedy is the caller's to supply, the way `toolFailed()` already
   * takes its `$hint` — and the sentence above stays as the default, because
   * for a binary that is genuinely absent it is the right one.
   *
   * @param string $gate
   *   The gate name.
   * @param string $invocation
   *   The command that could not be run.
   * @param string|null $remedy
   *   What to actually do about it, when the caller knows something more
   *   specific than "install it". NULL takes the install-or-turn-it-off text.
   *
   * @return self
   *   The result.
   */
  public static function toolMissing(
    string $gate,
    string $invocation,
    ?string $remedy = NULL,
  ): self {
    return new self(
      $gate,
      GateStatus::ErrorToolMissing,
      summary: 'could not run: ' . $invocation,
      invocation: $invocation,
      remedy: $remedy ?? sprintf(
        'Install %1$s so the gate can run — `composer require --dev` or '
        . '`npm install` it, whichever owns it in this project — or, if this '
        . 'project genuinely does not use %1$s, ask the OPERATOR to turn the '
        . 'gate off in droost.workflow.yml (`gates.%1$s.on: false`). An agent '
        . 'cannot decide either of those.',
        $gate,
      ),
    );
  }

  /**
   * A gate whose tool is installed but could not run.
   *
   * @param string $gate
   *   The gate name.
   * @param int $exitCode
   *   The tool's exit code.
   * @param string $line
   *   The tool's own first line of explanation ('' when it gave none).
   * @param string $hint
   *   What to do about it, phrased for the lever file.
   * @param string $invocation
   *   The command that ran.
   *
   * @return self
   *   The result: fails closed, carries no findings.
   */
  public static function toolFailed(
    string $gate,
    int $exitCode,
    string $line,
    string $hint,
    string $invocation,
  ): self {
    return new self(
      $gate,
      GateStatus::ErrorToolFailed,
      exitCode: $exitCode,
      summary: sprintf(
        '%s could not run (exit %d)%s — %s',
        $gate,
        $exitCode,
        $line === '' ? '' : ': ' . $line,
        $hint,
      ),
      invocation: $invocation,
      remedy: $hint,
      declaredFault: 'environment',
    );
  }

  /**
   * A pass that measured nothing, and says so.
   *
   * Three places return `Passed` over a run that examined no code: a path set
   * that resolves to nothing, phpcs exit 16 ("No files were checked"), and
   * phpunit discovering no tests. Each already labels itself in prose — one
   * literally says "a labeled pass, not a measurement" — and each was then
   * recorded as an ordinary `satisfied` gate, because prose is not a field.
   *
   * The cost was exact: `type_coverage`, whose whole job is to catch a gate
   * that passed over an empty path set, reported "phpcs, phpstan, phpunit all
   * measured something" for a run where phpcs and phpstan analysed zero files
   * and phpunit ran zero tests. The check written to catch this case passed in
   * this case. Meanwhile the report's own column, deriving the same thing a
   * different way, said "1 measured something" about the same store.
   *
   * So the executor states it as a fact instead of implying it in a sentence.
   *
   * @param string $gate
   *   The gate name.
   * @param int $exitCode
   *   The tool's exit code.
   * @param int $durationMs
   *   How long it took — non-zero here, because the tool really did run.
   * @param string $summary
   *   The sentence saying what was NOT measured, and why.
   * @param string $invocation
   *   The command, as run.
   *
   * @return self
   *   The result, flagged as having measured nothing.
   */
  public static function labelledPass(
    string $gate,
    int $exitCode,
    int $durationMs,
    string $summary,
    string $invocation,
  ): self {
    return new self(
      $gate,
      GateStatus::Passed,
      $exitCode,
      $durationMs,
      $summary,
      [],
      FALSE,
      NULL,
      $invocation,
      NULL,
      NULL,
      TRUE,
    );
  }

  /**
   * Caps a finding list, reporting whether anything was dropped.
   *
   * @param string $gate
   *   The gate name.
   * @param \Droost\Workflow\Gate\GateStatus $status
   *   What happened.
   * @param int $exitCode
   *   The tool's exit code.
   * @param int $durationMs
   *   How long it took.
   * @param string $summary
   *   One line a human can read.
   * @param list<array<string, mixed>> $findings
   *   Every finding, before capping.
   * @param string $invocation
   *   The command that ran.
   * @param string|null $remedy
   *   For an environment block, what to type. `Fault::Environment`'s guidance
   *   tells the reader to go and read this, and a caller building such a
   *   result here had no way to write one — so the one gate that does,
   *   `evidence_record`, sent the reader to an empty string.
   *
   * @return self
   *   The result, with findings capped.
   */
  public static function ran(
    string $gate,
    GateStatus $status,
    int $exitCode,
    int $durationMs,
    string $summary,
    array $findings,
    string $invocation,
    ?string $remedy = NULL,
  ): self {
    return new self(
      $gate,
      $status,
      $exitCode,
      $durationMs,
      $summary,
      array_slice($findings, 0, self::FINDINGS_CAP),
      count($findings) > self::FINDINGS_CAP,
      NULL,
      $invocation,
      remedy: $remedy,
    );
  }

  /**
   * This result as plain data.
   *
   * @return array<string, mixed>
   *   The serialized result.
   */
  public function toArray(): array {
    return [
      'gate' => $this->gate,
      'status' => $this->status->value,
      'exit_code' => $this->exitCode,
      'duration_ms' => $this->durationMs,
      'summary' => $this->summary,
      'findings' => $this->findings,
      'truncated' => $this->truncated,
      'skip_reason' => $this->skipReason,
      'invocation' => $this->invocation,
      'inherited' => $this->inherited,
      'new' => $this->new,
    ];
  }

  /**
   * This result, carrying the raw output the tool produced.
   *
   * Kept apart from `findings` because they answer different questions. The
   * findings are what droost PARSED — sortable rows a report can count. The
   * output is what the tool actually said, which is the only thing that helps
   * when the parse was wrong, when the tool crashed before producing anything
   * structured, or when a reader simply does not believe the summary.
   *
   * Capped hard. A phpcs run over a large tree emits hundreds of kilobytes,
   * and an evidence store that grew without bound would recreate the problem
   * it was built to fix: a real run.json reached 113,313 bytes of gate results
   * because one report was stored whole, twice.
   *
   * @param string $stdout
   *   What the tool wrote to stdout.
   * @param string $stderr
   *   What it wrote to stderr.
   *
   * @return self
   *   A new instance.
   */
  public function withOutput(string $stdout, string $stderr): self {
    $clone = clone $this;
    $clone->stdout = self::cap($stdout);
    $clone->stderr = self::cap($stderr);

    return $clone;
  }

  /**
   * One stream, trimmed to something a record can hold.
   *
   * The head and the tail, because a tool says what it is doing at the start
   * and what it concluded at the end, and the middle is the repetition.
   *
   * @param string $text
   *   The stream.
   *
   * @return string
   *   The capped text.
   */
  private static function cap(string $text): string {
    if (strlen($text) <= self::OUTPUT_CAP) {
      return $text;
    }
    $half = intdiv(self::OUTPUT_CAP, 2);

    // mb_strcut, not substr: substr cuts at a byte and a multi-byte character
    // straddling the boundary becomes invalid UTF-8, which SQLite stores
    // happily and then breaks json_encode and preg's /u in whatever renders it
    // later. Three separate surfaces had grown defensive code for malformed
    // bytes that this line was manufacturing. mb_strcut cuts at a byte offset
    // like substr but never mid-character, which is exactly what a cap wants.
    return mb_strcut($text, 0, $half, 'UTF-8')
      . sprintf("\n\n… %d bytes elided …\n\n", strlen($text) - self::OUTPUT_CAP)
      . mb_strcut($text, max(0, strlen($text) - $half), $half, 'UTF-8');
  }

}
