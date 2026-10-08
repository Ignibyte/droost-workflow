<?php

declare(strict_types=1);

namespace Droost\Workflow\Intake;

use Droost\Workflow\Event\RunEventLog;
use Droost\Workflow\State\RunStateStore;

/**
 * The intake: droost reads a design and plans the site with the human.
 *
 * Opened with the human's request and the source; audited by droost-source;
 * assessed, asked and planned by the agent in `droost/intake/`; checked; and
 * approved or abandoned by the operator alone, from a terminal (the CLI and
 * drush refuse those two verbs, and `answer`, without one, and the guard
 * refuses them from the agent's shell). While an intake is open no run
 * opens, so nothing is built before the human approves the roadmap.
 */
final class IntakeService {

  /**
   * The time, as ISO-8601.
   *
   * @var \Closure(): string
   */
  private readonly \Closure $clock;

  /**
   * The store.
   */
  private readonly IntakeStore $store;

  /**
   * Constructs the service.
   *
   * @param string $projectRoot
   *   The repository.
   * @param (callable(): string)|null $clock
   *   The time; the system clock when NULL.
   */
  public function __construct(private readonly string $projectRoot, ?callable $clock = NULL) {
    $this->clock = $clock === NULL ? static fn (): string => date('c') : \Closure::fromCallable($clock);
    $this->store = new IntakeStore($projectRoot);
  }

  /**
   * The store.
   *
   * @return \Droost\Workflow\Intake\IntakeStore
   *   The store.
   */
  public function store(): IntakeStore {
    return $this->store;
  }

  /**
   * The open intake, or NULL.
   *
   * @return \Droost\Workflow\Intake\IntakeState|null
   *   The intake while it is open.
   */
  public function open(): ?IntakeState {
    $state = $this->store->load();
    return $state !== NULL && $state->isOpen() ? $state : NULL;
  }

  /**
   * Opens an intake.
   *
   * @param string $request
   *   The human's words.
   * @param string $source
   *   The source: a path or a URL.
   *
   * @return \Droost\Workflow\Intake\IntakeState
   *   The intake.
   */
  public function start(string $request, string $source): IntakeState {
    if (trim($request) === '' || trim($source) === '') {
      throw IntakeError::usage('intake start needs --request="<the human\'s words, verbatim>" and --source=<path or URL>.');
    }
    $run = (new RunStateStore($this->projectRoot))->load();
    if ($run !== NULL && $run->currentPhase !== NULL) {
      throw IntakeError::runOpen($run->runId);
    }
    $previous = $this->store->load();
    if ($previous !== NULL && $previous->isOpen()) {
      throw IntakeError::alreadyOpen($previous->id);
    }
    if ($previous !== NULL) {
      $this->store->archive($previous);
    }
    $state = new IntakeState('intake-' . bin2hex(random_bytes(6)), IntakeState::OPEN, trim($request), trim($source), ($this->clock)());
    $this->store->save($state);
    $this->store->touchAnswers();
    if (!is_dir($this->store->dir() . '/tickets')) {
      @mkdir($this->store->dir() . '/tickets', 0777, TRUE);
    }
    $this->event($state, 'intake.opened', ['request' => $state->request, 'source' => $state->source]);
    return $state;
  }

  /**
   * Runs droost-source over the source, and records the audit it wrote.
   *
   * @param list<string> $arguments
   *   The options droost-source takes: `--url`, `--files`, `--routes`, …
   *   (`--out` is the intake's directory, whatever is given).
   *
   * @return array<string, mixed>
   *   droost-source's summary.
   */
  public function audit(array $arguments): array {
    $state = $this->requireOpen();
    $node = getenv('DROOST_NODE') ?: 'node';
    $bin = dirname(__DIR__, 2) . '/bin/droost-source';
    $kept = [];
    $count = count($arguments);
    for ($i = 0; $i < $count; $i++) {
      if ($arguments[$i] === '--out') {
        $i++;
        continue;
      }
      if (str_starts_with($arguments[$i], '--out=')) {
        continue;
      }
      if (preg_match('/^(--[a-z-]+)=(.*)$/s', $arguments[$i], $m) === 1) {
        $kept[] = $m[1];
        $kept[] = $m[2];
        continue;
      }
      $kept[] = $arguments[$i];
    }
    $command = array_merge([$node, $bin, 'audit'], $kept, ['--out', $this->store->dir()]);
    $process = @proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->projectRoot);
    if (!is_resource($process)) {
      throw IntakeError::auditFailed(sprintf('%s could not be started', $node));
    }
    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    $lines = preg_split('/\R/', trim($stdout)) ?: [];
    $last = json_decode((string) end($lines), TRUE);
    $summary = is_array($last) && is_array($last['source'] ?? NULL) ? $last['source'] : NULL;
    if ($summary === NULL || !in_array($exit, [0, 2], TRUE)) {
      $why = $summary !== NULL && is_string($summary['summary'] ?? NULL) ? $summary['summary'] : trim($stderr . ' ' . $stdout);
      throw IntakeError::auditFailed(sprintf('droost-source exited %d: %s', $exit, $why === '' ? 'it said nothing' : $why));
    }
    $sha = hash_file('sha256', $this->store->dir() . '/' . IntakeCheck::FILES['audit']);
    if ($sha === FALSE) {
      throw IntakeError::auditFailed('droost-source wrote no audit.json');
    }
    $this->store->save($state->withAudit($sha, ($this->clock)()));
    $this->event($state, 'intake.audited', [
      'sha' => $sha,
      'summary' => is_string($summary['summary'] ?? NULL) ? $summary['summary'] : '',
    ]);
    $out = [];
    foreach ($summary as $key => $value) {
      if (is_string($key)) {
        $out[$key] = $value;
      }
    }
    return $out;
  }

  /**
   * What the intake still needs.
   *
   * @return list<\Droost\Workflow\Intake\IntakeFinding>
   *   Findings; empty when it may be approved.
   */
  public function check(): array {
    $state = $this->store->load();
    if ($state === NULL) {
      throw IntakeError::noneOpen();
    }
    return IntakeCheck::run($this->projectRoot, $state, $this->store->answers($state->id), $this->store->ledger());
  }

  /**
   * Records an answer the operator typed.
   *
   * @param string $question
   *   The question, as asked.
   * @param string $answer
   *   The answer.
   */
  public function answer(string $question, string $answer): void {
    $state = $this->requireOpen();
    if (trim($question) === '' || trim($answer) === '') {
      throw IntakeError::usage('intake answer needs the question, as asked, and the answer: `intake answer "<question>" "<answer>"`.');
    }
    $this->store->appendAnswer($state->id, trim($question), trim($answer), 'terminal', ($this->clock)());
    $this->event($state, 'intake.answered', [
      'question' => trim($question),
      'answer' => trim($answer),
      'via' => 'terminal',
    ]);
  }

  /**
   * Approves the roadmap: the operator's act.
   *
   * @return \Droost\Workflow\Intake\IntakeState
   *   The approved intake.
   */
  public function approve(): IntakeState {
    $state = $this->requireOpen();
    $findings = $this->check();
    if ($findings !== []) {
      throw IntakeError::notReady($findings);
    }
    $digest = [];
    foreach (IntakeCheck::FILES as $file) {
      $digest[$file] = (string) hash_file('sha256', $this->store->dir() . '/' . $file);
    }
    $roadmap = (string) @file_get_contents($this->store->dir() . '/' . IntakeCheck::FILES['roadmap']);
    $rungs = array_map(
      static fn (array $row): array => [
        'rung' => $row['rung'] ?? '',
        'ticket' => $row['ticket'] ?? '',
        'builds' => $row['builds'] ?? '',
      ],
      IntakeTables::rows($roadmap),
    );
    $archived = $this->store->archiveLedgers($state->id);
    $approved = $state->approved($digest, ($this->clock)());
    $this->store->save($approved);
    $this->event($approved, 'intake.approved', ['digest' => $digest, 'rungs' => $rungs, 'archived' => $archived]);
    return $approved;
  }

  /**
   * Abandons the intake: the operator's act.
   *
   * @param string $reason
   *   Why.
   *
   * @return \Droost\Workflow\Intake\IntakeState
   *   The abandoned intake.
   */
  public function abandon(string $reason): IntakeState {
    $state = $this->requireOpen();
    if (trim($reason) === '') {
      throw IntakeError::usage('intake abandon needs a reason: `intake abandon "<why>"`; it goes on the record.');
    }
    $archived = $this->store->archiveLedgers($state->id);
    $abandoned = $state->abandoned(trim($reason), ($this->clock)());
    $this->store->save($abandoned);
    $this->event($abandoned, 'intake.abandoned', ['reason' => trim($reason), 'archived' => $archived]);
    return $abandoned;
  }

  /**
   * The intake and what it still needs, for `status`.
   *
   * @return array<string, mixed>
   *   The state and the findings, or `intake: null`.
   */
  public function status(): array {
    $state = $this->store->load();
    if ($state === NULL) {
      return ['intake' => NULL];
    }
    $findings = $state->isOpen() ? $this->check() : [];
    return [
      'intake' => $state->toArray(),
      'answers' => count($this->store->answers($state->id)),
      'ready' => $state->isOpen() && $findings === [],
      'findings' => array_map(static fn (IntakeFinding $f): array => $f->toArray(), $findings),
    ];
  }

  /**
   * The open intake, or the error that there is none.
   */
  private function requireOpen(): IntakeState {
    $state = $this->open();
    if ($state === NULL) {
      throw IntakeError::noneOpen();
    }
    return $state;
  }

  /**
   * Appends one event to the run-event log, under the intake's id.
   *
   * @param \Droost\Workflow\Intake\IntakeState $state
   *   The intake.
   * @param string $type
   *   The event.
   * @param array<string, mixed> $payload
   *   What it carries; the intake's id is added.
   */
  private function event(IntakeState $state, string $type, array $payload): void {
    (new RunEventLog($this->store->stateDir(), NULL, $this->clock))
      ->append($state->id, NULL, $type, ['intake_id' => $state->id] + $payload);
  }

}
