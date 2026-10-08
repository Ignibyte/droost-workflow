<?php

declare(strict_types=1);

namespace Droost\Workflow\Intake;

use Droost\Workflow\State\RunStateStore;

/**
 * Where an intake keeps its record: the state directory, and its own files.
 *
 * The state directory holds what the agent must not write (`intake.json`, and
 * `intake-answers.jsonl`, the human's answers, which the guard appends from
 * `AskUserQuestion` and the operator's terminal appends to). The intake's own
 * files, which the agent writes and the human reads, are in `droost/intake/`.
 */
final class IntakeStore {

  /**
   * The intake's own files, project-relative.
   */
  public const DIR = 'droost/intake';

  /**
   * The intake's state, in the state directory.
   */
  public const STATE_FILE = 'intake.json';

  /**
   * The human's answers, in the state directory.
   */
  public const ANSWERS_FILE = 'intake-answers.jsonl';

  /**
   * The ledgers an intake leaves to no run (F-6, for the intake).
   *
   * A row written outside a run is attributed to the next run that opens. The
   * intake's consult of the whole model would then satisfy the first rung's
   * `plan_consulted` for every construct it names, with the rung's own plan
   * never put to droost: closing an intake archives them.
   */
  public const LEDGERS = ['tool-calls.jsonl', 'guard-calls.jsonl', 'scaffolded.jsonl'];

  /**
   * The state directory, absolute.
   */
  private readonly string $stateDir;

  /**
   * Constructs the store.
   *
   * @param string $projectRoot
   *   The repository.
   */
  public function __construct(private readonly string $projectRoot) {
    $this->stateDir = (new RunStateStore($projectRoot))->directory();
  }

  /**
   * The state directory.
   *
   * @return string
   *   Absolute.
   */
  public function stateDir(): string {
    return $this->stateDir;
  }

  /**
   * The intake's own directory.
   *
   * @return string
   *   Absolute.
   */
  public function dir(): string {
    return rtrim($this->projectRoot, '/') . '/' . self::DIR;
  }

  /**
   * The intake, or NULL when none was ever opened.
   *
   * @return \Droost\Workflow\Intake\IntakeState|null
   *   The state.
   *
   * @throws \Droost\Workflow\Intake\IntakeError
   *   When the file is there and is not an intake.
   */
  public function load(): ?IntakeState {
    $path = $this->stateDir . '/' . self::STATE_FILE;
    if (!is_file($path)) {
      return NULL;
    }
    $state = IntakeState::fromArray(json_decode((string) @file_get_contents($path), TRUE));
    if ($state === NULL) {
      throw IntakeError::stateUnreadable($path, 'it is not an intake (' . IntakeState::SCHEMA . ')');
    }
    return $state;
  }

  /**
   * Writes the intake.
   *
   * @param \Droost\Workflow\Intake\IntakeState $state
   *   The state.
   */
  public function save(IntakeState $state): void {
    if (!is_dir($this->stateDir) && !@mkdir($this->stateDir, 0777, TRUE) && !is_dir($this->stateDir)) {
      throw IntakeError::stateUnreadable($this->stateDir, 'the state directory could not be created');
    }
    $path = $this->stateDir . '/' . self::STATE_FILE;
    if (@file_put_contents($path, json_encode($state->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n") === FALSE) {
      throw IntakeError::stateUnreadable($path, 'it could not be written');
    }
  }

  /**
   * The answers on record for an intake, the latest for each question last.
   *
   * @param string $intakeId
   *   The intake.
   *
   * @return list<array{question: string, answer: string}>
   *   Each answer.
   */
  public function answers(string $intakeId): array {
    $out = [];
    foreach (@file($this->stateDir . '/' . self::ANSWERS_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
      $row = json_decode($line, TRUE);
      if (is_array($row) && ($row['intake'] ?? NULL) === $intakeId && is_string($row['question'] ?? NULL) && is_string($row['answer'] ?? NULL)) {
        $out[] = ['question' => $row['question'], 'answer' => $row['answer']];
      }
    }
    return $out;
  }

  /**
   * Records an answer the operator typed.
   *
   * @param string $intakeId
   *   The intake.
   * @param string $question
   *   The question, as asked.
   * @param string $answer
   *   The answer.
   * @param string $via
   *   How it was given: `terminal`.
   * @param string $at
   *   When.
   */
  public function appendAnswer(string $intakeId, string $question, string $answer, string $via, string $at): void {
    $line = json_encode([
      'intake' => $intakeId,
      'question' => $question,
      'answer' => $answer,
      'via' => $via,
      'at' => $at,
    ], JSON_UNESCAPED_SLASHES);
    if (@file_put_contents($this->stateDir . '/' . self::ANSWERS_FILE, $line . "\n", FILE_APPEND | LOCK_EX) === FALSE) {
      throw IntakeError::stateUnreadable($this->stateDir . '/' . self::ANSWERS_FILE, 'the answer could not be recorded');
    }
  }

  /**
   * Makes the answers file, empty, so the guard protects it from the start.
   */
  public function touchAnswers(): void {
    $path = $this->stateDir . '/' . self::ANSWERS_FILE;
    if (!is_file($path)) {
      @touch($path);
    }
  }

  /**
   * The tool-call ledger's rows.
   *
   * @return list<array<mixed>>
   *   Each row.
   */
  public function ledger(): array {
    $rows = [];
    foreach (@file($this->stateDir . '/tool-calls.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
      $row = json_decode($line, TRUE);
      if (is_array($row)) {
        $rows[] = $row;
      }
    }
    return $rows;
  }

  /**
   * Moves the ledgers the intake wrote into history, under its id.
   *
   * @param string $intakeId
   *   The intake.
   *
   * @return list<string>
   *   The files moved, by name.
   */
  public function archiveLedgers(string $intakeId): array {
    $history = $this->stateDir . '/history';
    if (!is_dir($history) && !@mkdir($history, 0777, TRUE) && !is_dir($history)) {
      throw IntakeError::stateUnreadable($history, 'the history directory could not be created');
    }
    $moved = [];
    foreach (self::LEDGERS as $ledger) {
      $source = $this->stateDir . '/' . $ledger;
      if (!is_file($source)) {
        continue;
      }
      $target = $history . '/' . $intakeId . '.' . $ledger;
      for ($n = 2; is_file($target); $n++) {
        $target = $history . '/' . $intakeId . '-' . $n . '.' . $ledger;
      }
      if (@rename($source, $target) === FALSE) {
        throw IntakeError::stateUnreadable($source, 'it could not be moved into history');
      }
      $moved[] = $ledger;
    }
    return $moved;
  }

  /**
   * Moves a closed intake's state and answers into history.
   *
   * @param \Droost\Workflow\Intake\IntakeState $state
   *   The closed intake.
   */
  public function archive(IntakeState $state): void {
    $history = $this->stateDir . '/history';
    if (!is_dir($history) && !@mkdir($history, 0777, TRUE) && !is_dir($history)) {
      throw IntakeError::stateUnreadable($history, 'the history directory could not be created');
    }
    foreach ([self::STATE_FILE, self::ANSWERS_FILE] as $file) {
      $source = $this->stateDir . '/' . $file;
      if (is_file($source) && @rename($source, $history . '/' . $state->id . '.' . $file) === FALSE) {
        throw IntakeError::stateUnreadable($source, 'it could not be moved into history');
      }
    }
  }

}
