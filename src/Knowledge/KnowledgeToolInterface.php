<?php

declare(strict_types=1);

namespace Droost\Workflow\Knowledge;

/**
 * One of droost's code-knowledge tools, answered from files alone.
 *
 * Each is one `droost <area>:<verb>` command, answered with no Drupal.
 */
interface KnowledgeToolInterface {

  /**
   * The tool's command, which is also its row in the tool-call ledger.
   *
   * @return string
   *   The id, e.g. code:symbol.
   */
  public function id(): string;

  /**
   * What the tool does, in its first sentence.
   *
   * @return string
   *   One line.
   */
  public function summary(): string;

  /**
   * What the tool does and the arguments it takes, as `help` prints it.
   *
   * @return string
   *   The text.
   */
  public function help(): string;

  /**
   * Answers one call.
   *
   * @param array<string, mixed> $arguments
   *   The arguments, from one JSON object.
   * @param \Droost\Workflow\Knowledge\Site $site
   *   What the answer is read from.
   *
   * @return array{success: bool, message: string, data: mixed}
   *   The envelope.
   */
  public function answer(array $arguments, Site $site): array;

}
