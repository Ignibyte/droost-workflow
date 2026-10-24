<?php

declare(strict_types=1);

namespace Droost\Workflow\Knowledge;

use Droost\Engine\Search\Graph\CodeGraphStorageInterface;

/**
 * What every code-knowledge tool shares: its words, its arguments, its replies.
 *
 * A tool states its DESCRIPTION, ARGUMENTS and POSITIONAL once; `droost`
 * reads them to parse a command line, list the commands and print help.
 */
abstract class ToolBase implements KnowledgeToolInterface {

  /**
   * What the tool does, in full.
   */
  public const string DESCRIPTION = '';

  /**
   * The arguments, as a JSON Schema object's properties.
   *
   * @var array<string, array<string, mixed>>
   */
  public const array ARGUMENTS = [];

  /**
   * The arguments a call must carry.
   *
   * @var list<string>
   */
  public const array REQUIRED = [];

  /**
   * The message when there is no index to read.
   */
  public const string NO_INDEX = 'No code index yet: run `droost code:index` (it reads files only; no site needed).';

  /**
   * The argument a bare word on the command line fills, or '' for none.
   *
   * `droost code:wiki camps` is `{"page":"camps"}`.
   */
  public const string POSITIONAL = '';

  /**
   * {@inheritdoc}
   */
  public function summary(): string {
    $first = preg_split('/(?<=\.)\s/', static::DESCRIPTION, 2);
    return is_array($first) ? $first[0] : static::DESCRIPTION;
  }

  /**
   * {@inheritdoc}
   */
  public function help(): string {
    $usage = 'droost ' . $this->id() . (static::POSITIONAL !== '' ? ' [' . static::POSITIONAL . ']' : '') . (static::ARGUMENTS !== [] ? ' [--<argument>=<value>]' : '');
    $lines = [$usage, '', wordwrap(static::DESCRIPTION, 78), ''];
    if (static::ARGUMENTS === []) {
      $lines[] = 'No arguments.';
    }
    else {
      $lines[] = 'Arguments (as --name=value, or one JSON object):';
      foreach (static::ARGUMENTS as $name => $schema) {
        $type = is_string($schema['type'] ?? NULL) ? $schema['type'] : 'value';
        $enum = is_array($schema['enum'] ?? NULL) ? ' (' . implode('|', array_filter($schema['enum'], 'is_string')) . ')' : '';
        $about = is_string($schema['description'] ?? NULL) ? $schema['description'] : '';
        $lines[] = sprintf('  %s: %s%s. %s', $name, $type, $enum, $about);
      }
    }
    return implode("\n", $lines);
  }

  /**
   * A success envelope.
   *
   * @param string $message
   *   The summary.
   * @param array<array-key, mixed> $data
   *   The answer.
   *
   * @return array{success: bool, message: string, data: mixed}
   *   The envelope.
   */
  protected function succeed(string $message, array $data = []): array {
    return ['success' => TRUE, 'message' => $message, 'data' => $data];
  }

  /**
   * A failure envelope.
   *
   * @param string $message
   *   Why.
   *
   * @return array{success: bool, message: string, data: mixed}
   *   The envelope.
   */
  protected function fail(string $message): array {
    return ['success' => FALSE, 'message' => $message, 'data' => NULL];
  }

  /**
   * A string argument, trimmed; '' when absent or not a value.
   *
   * @param array<string, mixed> $arguments
   *   The arguments.
   * @param string $key
   *   The name.
   *
   * @return string
   *   The value.
   */
  protected static function stringArg(array $arguments, string $key): string {
    $value = $arguments[$key] ?? NULL;
    if (is_scalar($value)) {
      return trim((string) $value);
    }
    return $value instanceof \Stringable ? trim((string) $value) : '';
  }

  /**
   * An integer argument, clamped to a range.
   *
   * @param array<string, mixed> $arguments
   *   The arguments.
   * @param string $key
   *   The name.
   * @param int $default
   *   When it is absent or not a number.
   * @param int $min
   *   The least it may be.
   * @param int $max
   *   The most it may be.
   *
   * @return int
   *   The value.
   */
  protected static function intArg(array $arguments, string $key, int $default, int $min, int $max): int {
    $value = $arguments[$key] ?? NULL;
    $int = is_numeric($value) ? (int) $value : $default;
    return max($min, min($int, $max));
  }

  /**
   * The code graph, when the index is there and built.
   *
   * @param \Droost\Workflow\Knowledge\Site $site
   *   The site.
   *
   * @return \Droost\Engine\Search\Graph\CodeGraphStorageInterface|null
   *   The graph, or NULL when there is nothing indexed to read.
   */
  protected static function readyGraph(Site $site): ?CodeGraphStorageInterface {
    $graph = $site->graph();
    return $graph !== NULL && $graph->isReady() ? $graph : NULL;
  }

}
