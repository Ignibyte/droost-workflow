<?php

declare(strict_types=1);

namespace Droost\Workflow\State;

use Droost\Workflow\WorkflowFacade;

/**
 * An operator step a human approved in the session (owner, 2026-10-30).
 *
 * The operator's commands (bypass, gate-waive, effort, a baseline write, a
 * ticket move, an autonomous lead, a project's operator-only scripts) carry a
 * human's decision. A terminal was the only proof of that, so a developer
 * switched windows for every one. The session has a second proof, the one
 * plan's and complete's holds already rest on: the agent asks with
 * AskUserQuestion, the guard refuses a question that arrives pre-answered,
 * and records the dialog's RESULT in a file the agent cannot write. An
 * approval is that record for one exact command: asked with QUESTION and the
 * command, answered APPROVE, used once, within TTL seconds.
 *
 * The guard (pack/hooks/droost-workflow-guard.php, no autoloader by design)
 * writes the approvals and mirrors command() and the matching rule;
 * OperatorApprovalTest holds the two together.
 */
final class OperatorApproval {

  /**
   * The record, in the state directory.
   */
  public const FILE = 'operator-approvals.jsonl';

  /**
   * The question's start; the command follows it, word for word.
   */
  public const QUESTION = 'Approve running this operator command? ';

  /**
   * The answer that approves.
   */
  public const APPROVE = 'Approve';

  /**
   * The answer that declines.
   */
  public const DECLINE = "Don't run it";

  /**
   * How long an approval stands, in seconds.
   */
  public const TTL = 1800;

  /**
   * A droost command as approvals name it.
   *
   * `droost workflow:<verb> <arguments>`, whatever launched it (`ddev
   * droost`, `tools/droost/vendor/bin/droost`), with `--project=` left out
   * and the spacing normalised. Quoting is the shell's and already gone.
   *
   * @param list<string> $argv
   *   The verb and its arguments, the verb first, as the CLI receives them.
   *
   * @return string
   *   The command.
   */
  public static function command(array $argv): string {
    $words = array_values(array_filter(
      array_map('trim', $argv),
      static fn (string $word): bool => $word !== '' && !str_starts_with($word, '--project='),
    ));
    if ($words !== [] && !str_starts_with($words[0], 'workflow:')) {
      $words[0] = 'workflow:' . $words[0];
    }
    return self::normalise('droost ' . implode(' ', $words));
  }

  /**
   * The question the agent asks, word for word.
   *
   * @param string $command
   *   The command, as command() names it.
   *
   * @return string
   *   The question.
   */
  public static function question(string $command): string {
    return self::QUESTION . self::normalise($command);
  }

  /**
   * How to ask, for a refusal.
   *
   * @param string $command
   *   The command, as command() names it.
   *
   * @return string
   *   The sentences that tell the agent to ask.
   */
  public static function howToAsk(string $command): string {
    return sprintf(
      'Ask the human in this session: AskUserQuestion with the question, word for word, "%s" and the options "%s" / "%s", '
      . 'with a line on what it does and why; then run the command again. droost takes THEIR answer, as the guard recorded '
      . 'it from the dialog, once, within %d minutes. Or they run it in a terminal of their own.',
      self::question($command),
      self::APPROVE,
      self::DECLINE,
      intdiv(self::TTL, 60),
    );
  }

  /**
   * Uses the human's approval of a command, once.
   *
   * Only where the guard refuses pre-answered questions and records answers:
   * without that wiring a row in the record could be the agent's, so nothing
   * is taken from it.
   *
   * @param string $projectRoot
   *   The repository.
   * @param string $command
   *   The command, as command() names it.
   * @param string $by
   *   What used it: `cli` or `script`.
   * @param int $now
   *   The time, in seconds since the epoch.
   *
   * @return bool
   *   TRUE when an approval stood and is now used.
   */
  public static function spend(string $projectRoot, string $command, string $by, int $now): bool {
    if (!WorkflowFacade::guardWired($projectRoot, 'question') || !WorkflowFacade::guardWired($projectRoot, 'answers')) {
      return FALSE;
    }
    $file = rtrim($projectRoot, '/') . '/' . RunStateStore::resolveStateDir($projectRoot) . '/' . self::FILE;
    $id = self::open($file, $command, $now);
    if ($id === NULL) {
      return FALSE;
    }
    $line = json_encode(['spent' => $id, 'by' => $by, 'at' => $now], JSON_UNESCAPED_SLASHES);
    return $line !== FALSE && @file_put_contents($file, $line . "\n", FILE_APPEND | LOCK_EX) !== FALSE;
  }

  /**
   * The id of a standing approval of a command, or NULL.
   *
   * @param string $file
   *   The record.
   * @param string $command
   *   The command, as command() names it.
   * @param int $now
   *   The time, in seconds since the epoch.
   *
   * @return string|null
   *   The newest unspent, unexpired approval's id.
   */
  public static function open(string $file, string $command, int $now): ?string {
    $command = self::normalise($command);
    $approved = [];
    $spent = [];
    foreach (@file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
      $row = json_decode($line, TRUE);
      if (!is_array($row)) {
        continue;
      }
      if (is_string($row['spent'] ?? NULL)) {
        $spent[$row['spent']] = TRUE;
      }
      elseif (is_string($row['id'] ?? NULL) && is_string($row['command'] ?? NULL) && is_int($row['at'] ?? NULL)) {
        if (($row['approved'] ?? FALSE) === TRUE && self::normalise($row['command']) === $command && $row['at'] >= $now - self::TTL) {
          $approved[] = $row['id'];
        }
      }
    }
    foreach (array_reverse($approved) as $id) {
      if (!isset($spent[$id])) {
        return $id;
      }
    }
    return NULL;
  }

  /**
   * A command with its spacing normalised.
   *
   * @param string $command
   *   The command.
   *
   * @return string
   *   One space between words, none at the ends.
   */
  private static function normalise(string $command): string {
    return trim((string) preg_replace('/\s+/', ' ', $command));
  }

}
