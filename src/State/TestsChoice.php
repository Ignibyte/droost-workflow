<?php

declare(strict_types=1);

namespace Droost\Workflow\State;

/**
 * What plan's hold asks about tests, and how an answer reads (`tests.ask`).
 *
 * The owner's rule (2026-10-30): a ticket gets no unit tests and saves no
 * browser spec unless the human says so at plan. The browser check itself is
 * not optional: the agent checks its work in the browser at code whatever the
 * answer. What the answer decides is whether that check is SAVED as a spec,
 * and whether PHPUnit tests are written and run.
 *
 * The questions are asked word for word beside plan's own, in one dialog, and
 * the guard records the human's choices from its result; the agent's own
 * words are never the answer.
 */
final class TestsChoice {

  /**
   * The unit-test question, word for word.
   */
  public const UNIT_QUESTION = 'Should this ticket get unit tests (PHPUnit)?';

  /**
   * Its choices; the first is the default.
   */
  public const UNIT_OPTIONS = ['No unit tests', 'Yes — write and run unit tests'];

  /**
   * The saved-spec question, word for word.
   */
  public const SPEC_QUESTION = 'Should the Playwright check of this work be saved as a test?';

  /**
   * Its choices; the first is the default.
   */
  public const SPEC_OPTIONS = ['No — check it in the browser, save nothing', 'Yes — save it as a Playwright spec'];

  /**
   * The two questions, as plan's hold carries them beside its own.
   *
   * @return list<array{question: string, options: list<string>}>
   *   Each question and its choices.
   */
  public static function questions(): array {
    return [
      ['question' => self::UNIT_QUESTION, 'options' => self::UNIT_OPTIONS],
      ['question' => self::SPEC_QUESTION, 'options' => self::SPEC_OPTIONS],
    ];
  }

  /**
   * Whether an answer says yes.
   *
   * A choice's label or the words typed under "Other": yes only when it
   * begins with "yes". Anything else, an empty answer included, is no, which
   * is the rule's default.
   *
   * @param string|null $answer
   *   The answer, or NULL when the question was not answered.
   *
   * @return bool
   *   TRUE for yes.
   */
  public static function saysYes(?string $answer): bool {
    return $answer !== NULL && preg_match('/^\s*yes\b/i', $answer) === 1;
  }

  /**
   * The resolved gates once the human has chosen.
   *
   * No unit tests: phpunit is off for this run. Yes: on, and a class the run
   * writes under `src/` must come with a test (`in_diff`). No saved spec: the
   * playwright gate, which runs saved specs, is off; the browser check at
   * code is held by `browser_checked` instead. Yes: on and required, so the
   * run must bring the spec it said it would.
   *
   * @param array<string, array<string, int|string|bool>> $gates
   *   The run's resolved gates.
   * @param bool $unit
   *   Unit tests chosen.
   * @param bool $spec
   *   A saved spec chosen.
   *
   * @return array<string, array<string, int|string|bool>>
   *   The gates, changed.
   */
  public static function applyTo(array $gates, bool $unit, bool $spec): array {
    $gates['phpunit'] = $unit
      ? ['on' => TRUE, 'in_diff' => TRUE] + ($gates['phpunit'] ?? [])
      : ['on' => FALSE] + ($gates['phpunit'] ?? []);
    $gates['playwright'] = $spec
      ? ['on' => TRUE, 'required' => TRUE] + ($gates['playwright'] ?? [])
      : ['on' => FALSE] + ($gates['playwright'] ?? []);
    // `+` keeps the left side's keys, so the choice wins over the levers.
    return $gates;
  }

}
