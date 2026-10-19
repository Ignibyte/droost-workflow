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
 * approved on the human's recorded yes to "Approve this roadmap?", whoever
 * runs it. Abandoning and recording an answer are the operator's alone:
 * the droost command refuses them without a terminal, and the guard refuses
 * them from the agent's shell. The agent asks through AskUserQuestion, or,
 * on a host without it, puts the questions on record with `ask` for the host
 * to show the human. While an intake is open no run opens, so nothing is
 * built before the human approves the roadmap.
 */
final class IntakeService {

  /**
   * The most questions one `ask` puts to the human: AskUserQuestion's limit.
   */
  public const ASK_MAX = 4;

  /**
   * The fewest and most options a question offers.
   */
  public const OPTIONS_MIN = 2;

  /**
   * The most options a question offers.
   */
  public const OPTIONS_MAX = 6;

  /**
   * The longest option, in bytes.
   */
  public const OPTION_BYTES = 200;

  /**
   * How an answer may have been given by the operator.
   */
  public const ANSWER_VIA = ['terminal', 'cockpit'];

  /**
   * The one question that approves the roadmap, in these words.
   */
  public const APPROVAL_QUESTION = 'Approve this roadmap?';

  /**
   * The approval question's options.
   */
  public const APPROVAL_OPTIONS = ['Approve', 'Not yet'];

  /**
   * The answers to the approval question that are a yes, normalised.
   */
  private const YES = ['approve', 'approved', 'yes'];

  /**
   * The longest question, in bytes.
   */
  public const QUESTION_BYTES = 1000;

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
    return IntakeCheck::run($this->projectRoot, $state, $this->store->answers($state->id));
  }

  /**
   * Puts questions to the human on a host with no AskUserQuestion.
   *
   * The batch is a JSON file in the intake's directory, `{"questions": [{
   * "question", "options", "recommended"}]}`, each question worded as a
   * `questions.md` row words it, or the approval question in its own words
   * with its own two options. It replaces what
   * was pending: one batch is in flight at a time, as one AskUserQuestion
   * call is. The host shows them; the operator's `answer` records each reply.
   *
   * @param string $file
   *   The batch file, project-relative or absolute, inside `droost/intake/`.
   *
   * @return list<array{id: string, question: string, options: list<string>, recommended: int, asked_at: string}>
   *   The questions now pending.
   */
  public function ask(string $file): array {
    $state = $this->requireOpen();
    $usage = 'intake ask needs --file=<a JSON file in ' . IntakeStore::DIR . '/> holding {"questions": [{"question": "<as questions.md words it>", "options": ["<recommended first>", "…"], "recommended": 0}]}';
    if (trim($file) === '') {
      throw IntakeError::usage($usage . '.');
    }
    $path = str_starts_with($file, '/') ? $file : rtrim($this->projectRoot, '/') . '/' . $file;
    $real = realpath($path);
    $dir = realpath($this->store->dir());
    if ($real === FALSE || $dir === FALSE || !str_starts_with($real, $dir . '/') || !is_file($real)) {
      throw IntakeError::usage(sprintf('intake ask: %s is not a file in %s/. %s.', $file, IntakeStore::DIR, $usage));
    }
    $batch = json_decode((string) @file_get_contents($real), TRUE);
    $questions = is_array($batch) ? ($batch['questions'] ?? NULL) : NULL;
    if (!is_array($questions) || !array_is_list($questions)) {
      throw IntakeError::usage(sprintf('intake ask: %s is not JSON with a "questions" list. %s.', $file, $usage));
    }
    if ($questions === [] || count($questions) > self::ASK_MAX) {
      throw IntakeError::usage(sprintf('intake ask: ask 1 to %d questions at a time; %s holds %d.', self::ASK_MAX, $file, count($questions)));
    }
    $ids = $this->questionIds();
    $askedAt = ($this->clock)();
    $pending = [];
    $seen = [];
    foreach ($questions as $n => $entry) {
      $pending[] = $this->pendingEntry($entry, $n + 1, $ids, $askedAt);
      $key = IntakeTables::normal(end($pending)['question']);
      if (isset($seen[$key])) {
        throw IntakeError::usage(sprintf('intake ask: question %d repeats question %d.', $n + 1, $seen[$key]));
      }
      $seen[$key] = $n + 1;
    }
    $this->store->save($state->withPending($pending));
    $this->event($state, 'intake.asked', [
      'asked_at' => $askedAt,
      'questions' => array_map(static fn (array $q): array => [
        'id' => $q['id'],
        'question' => $q['question'],
        'options' => $q['options'],
        'recommended' => $q['recommended'],
      ], $pending),
    ]);
    return $pending;
  }

  /**
   * Records an answer the operator gave, from a terminal.
   *
   * @param string $question
   *   The question, as asked.
   * @param string $answer
   *   The answer.
   * @param string $via
   *   How: `terminal`, or `cockpit` (a host relaying the human's answer
   *   through a terminal). A label, not a credential: the terminal is.
   */
  public function answer(string $question, string $answer, string $via = 'terminal'): void {
    $state = $this->requireOpen();
    if (trim($question) === '' || trim($answer) === '') {
      throw IntakeError::usage('intake answer needs the question, as asked, and the answer: `intake answer "<question>" "<answer>"`.');
    }
    if (!in_array($via, self::ANSWER_VIA, TRUE)) {
      throw IntakeError::usage(sprintf('intake answer --via is %s, not "%s".', implode(' or ', self::ANSWER_VIA), $via));
    }
    $this->store->appendAnswer($state->id, trim($question), trim($answer), $via, ($this->clock)());
    $answered = IntakeTables::normal($question);
    $pending = array_values(array_filter($state->pending, static fn (array $q): bool => IntakeTables::normal($q['question']) !== $answered));
    if (count($pending) !== count($state->pending)) {
      $this->store->save($state->withPending($pending));
    }
    $this->event($state, 'intake.answered', [
      'question' => trim($question),
      'answer' => trim($answer),
      'via' => $via,
    ]);
  }

  /**
   * Approves the roadmap, once the human has said yes.
   *
   * The agent runs it (owner, 2026-10-08) once the human's latest answer to
   * "Approve this roadmap?" is a yes on record: the guard records it from
   * AskUserQuestion, a host or the operator through `answer`. A terminal
   * proves nothing here (one can be faked), so the operator records their
   * yes the same way. Refused while that question is pending again. The
   * approval records how it was run and that answer, so the record shows
   * whose yes it was.
   *
   * @param string $via
   *   How it was run: `terminal`, or `agent` (no terminal).
   *
   * @return \Droost\Workflow\Intake\IntakeState
   *   The approved intake.
   */
  public function approve(string $via = 'agent'): IntakeState {
    $state = $this->requireOpen();
    $findings = $this->check();
    if ($findings !== []) {
      throw IntakeError::notReady($findings);
    }
    foreach ($state->pending as $question) {
      if ($question['id'] === 'approval') {
        throw IntakeError::approvalPending();
      }
    }
    $yes = NULL;
    foreach ($this->store->answers($state->id) as $answer) {
      if (self::isApproval($answer['question'])) {
        $yes = $answer;
      }
    }
    $said = $yes === NULL ? '' : trim((string) preg_replace('/\(recommended\)$/', '', IntakeTables::normal($yes['answer'])));
    if (!in_array($said, self::YES, TRUE)) {
      throw IntakeError::noYes($yes['answer'] ?? NULL);
    }
    $digest = [];
    foreach (IntakeCheck::FILES as $file) {
      $digest[$file] = (string) hash_file('sha256', $this->store->dir() . '/' . $file);
    }
    $roadmap = (string) @file_get_contents($this->store->dir() . '/' . IntakeCheck::FILES['roadmap']);
    $dir = (string) realpath($this->store->dir());
    $root = (string) realpath($this->projectRoot);
    foreach (IntakeTables::rows($roadmap) as $row) {
      $ticket = IntakeCheck::ticketFile($row['ticket'] ?? '', $this->store->dir(), $this->projectRoot);
      $real = $ticket === NULL ? FALSE : realpath($ticket);
      // Keyed under the intake's directory, or else the project: never by a
      // path that differs between the host and a container. A ticket outside
      // both goes unhashed, and a host importing it finds no digest to trust.
      $name = match (TRUE) {
        $real === FALSE => NULL,
        str_starts_with($real, $dir . '/') => substr($real, strlen($dir) + 1),
        str_starts_with($real, $root . '/') => substr($real, strlen($root) + 1),
        default => NULL,
      };
      if ($name !== NULL && $real !== FALSE) {
        $digest[$name] = (string) hash_file('sha256', $real);
      }
    }
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
    $this->event($approved, 'intake.approved', [
      'digest' => $digest,
      'rungs' => $rungs,
      'archived' => $archived,
      'via' => $via,
      'human' => $yes,
    ]);
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
      'pending' => $state->pending,
      'recorded' => $this->store->records($state->id),
      'ready' => $state->isOpen() && $findings === [],
      'findings' => array_map(static fn (IntakeFinding $f): array => $f->toArray(), $findings),
    ];
  }

  /**
   * Whether a question is the roadmap's approval, in its own words.
   *
   * @param string $question
   *   The question.
   *
   * @return bool
   *   TRUE for "Approve this roadmap?", however it is cased or punctuated.
   */
  public static function isApproval(string $question): bool {
    return IntakeTables::normal($question) === IntakeTables::normal(self::APPROVAL_QUESTION);
  }

  /**
   * Each `questions.md` question's id, by its normalised words.
   *
   * @return array<string, string>
   *   The row's Id, or `Q<n>` as the check numbers it.
   */
  private function questionIds(): array {
    $ids = [];
    $rows = IntakeTables::rows((string) @file_get_contents($this->store->dir() . '/' . IntakeCheck::FILES['questions']));
    foreach ($rows as $i => $row) {
      if (isset($row['question'])) {
        $ids[IntakeTables::normal($row['question'])] = trim($row['id'] ?? '') !== '' ? trim($row['id']) : 'Q' . ($i + 1);
      }
    }
    return $ids;
  }

  /**
   * One entry of an `ask` batch, checked and made pending.
   *
   * @param mixed $entry
   *   The entry as the file holds it.
   * @param int $n
   *   Its place in the batch, from 1.
   * @param array<string, string> $ids
   *   The questions.md ids by normalised question.
   * @param string $askedAt
   *   When the batch was asked.
   *
   * @return array{id: string, question: string, options: list<string>, recommended: int, asked_at: string}
   *   The pending entry.
   */
  private function pendingEntry(mixed $entry, int $n, array $ids, string $askedAt): array {
    $question = is_array($entry) && is_string($entry['question'] ?? NULL) ? trim($entry['question']) : '';
    if ($question === '' || strlen($question) > self::QUESTION_BYTES) {
      throw IntakeError::usage(sprintf('intake ask: question %d needs "question" text of 1 to %d bytes.', $n, self::QUESTION_BYTES));
    }
    $approval = self::isApproval($question);
    $id = $approval ? 'approval' : ($ids[IntakeTables::normal($question)] ?? NULL);
    if ($id === NULL) {
      throw IntakeError::usage(sprintf('intake ask: question %d ("%s") is no row of questions.md: ask each question in the words questions.md carries, so its answer can be matched to it.', $n, $question));
    }
    $options = is_array($entry) ? ($entry['options'] ?? NULL) : NULL;
    if (!is_array($options) || !array_is_list($options) || count($options) < self::OPTIONS_MIN || count($options) > self::OPTIONS_MAX) {
      throw IntakeError::usage(sprintf('intake ask: question %d needs %d to %d "options", your recommendation first.', $n, self::OPTIONS_MIN, self::OPTIONS_MAX));
    }
    $seen = [];
    foreach ($options as $option) {
      if (!is_string($option) || trim($option) === '' || strlen($option) > self::OPTION_BYTES) {
        throw IntakeError::usage(sprintf('intake ask: question %d has an option that is not text of 1 to %d bytes.', $n, self::OPTION_BYTES));
      }
      if (isset($seen[IntakeTables::normal($option)])) {
        throw IntakeError::usage(sprintf('intake ask: question %d offers "%s" twice.', $n, trim($option)));
      }
      $seen[IntakeTables::normal($option)] = TRUE;
    }
    $plain = array_map(static fn (string $o): string => IntakeTables::normal($o), $options);
    if ($approval && $plain !== array_map(static fn (string $o): string => IntakeTables::normal($o), self::APPROVAL_OPTIONS)) {
      throw IntakeError::usage(sprintf('intake ask: "%s" offers exactly %s, in that order.', self::APPROVAL_QUESTION, implode(' and ', self::APPROVAL_OPTIONS)));
    }
    $recommended = array_key_exists('recommended', $entry) ? $entry['recommended'] : 0;
    if (!is_int($recommended) || $recommended < 0 || $recommended >= count($options)) {
      throw IntakeError::usage(sprintf('intake ask: question %d\'s "recommended" is not the index of one of its options.', $n));
    }
    return [
      'id' => $id,
      'question' => $question,
      'options' => array_map('trim', $options),
      'recommended' => $recommended,
      'asked_at' => $askedAt,
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
