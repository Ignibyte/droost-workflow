<?php

declare(strict_types=1);

namespace Droost\Workflow\Config;

/**
 * Resolves the contributed-gate catalog for a surface with no booted site.
 *
 * R31-F3 (the first live D72 round): the subject began its run with the
 * standalone `droost-workflow` binary, which boots no Drupal and so saw none
 * of the gates the site's enabled modules declared — the run was held to a
 * smaller set than the same run through drush or the MCP tool, and nothing
 * said so. The lifecycle-hook lesson again: what a run is held to must not
 * depend on which door it came through.
 *
 * The standalone surface therefore ASKS the site, the way the wiki gate and
 * the render probe already do: `vendor/bin/drush droost:workflow:catalog`
 * prints the declarations as JSON, and this reads them back. When there is
 * no drush, or the site cannot answer, the result is an EMPTY catalog with a
 * `source` that says why — status prints it as `levers.contributed_source` —
 * never a silent shortfall, and never a refusal: a repo with no site is
 * exactly what the standalone surface exists for.
 */
final class DrushCatalogResolver {

  /**
   * The drush command that prints the catalog.
   */
  public const COMMAND = 'droost:workflow:catalog';

  /**
   * Where a Composer-managed Drupal repository keeps drush.
   */
  public const DRUSH = 'vendor/bin/drush';

  /**
   * Constructs a DrushCatalogResolver.
   *
   * @param callable(list<string>, string, int): array{int, string, string} $runner
   *   Runs argv in a cwd with a timeout; returns [exit, stdout, stderr].
   * @param int $timeout
   *   Seconds to allow a drush boot.
   */
  public function __construct(
    private readonly mixed $runner,
    private readonly int $timeout = 120,
  ) {}

  /**
   * The site's contributed gates, and where the answer came from.
   *
   * @param string $projectRoot
   *   The repository.
   *
   * @return array{gates: list<\Droost\Workflow\Config\ContributedGate>|null, source: string}
   *   The declarations — an empty list when the site answered that it has
   *   none, NULL when nothing could be resolved (so a lever file's
   *   `gates.contributed` block is left alone rather than refused as naming a
   *   gate "no module declares") — and a sentence naming the source or the
   *   reason.
   */
  public function resolve(string $projectRoot): array {
    $drush = $projectRoot . '/' . self::DRUSH;
    if (!is_file($drush)) {
      return [
        'gates' => NULL,
        'source' => sprintf(
          'unresolved — no %s in this repository, so gates enabled modules contribute cannot be seen from this surface; a run begun here is held to the lever file\'s gates only',
          self::DRUSH,
        ),
      ];
    }
    [$exit, $stdout, $stderr] = ($this->runner)([$drush, self::COMMAND, '--no-interaction'], $projectRoot, $this->timeout);
    $invocation = self::DRUSH . ' ' . self::COMMAND;
    if ($exit !== 0) {
      $line = self::firstLine($stderr !== '' ? $stderr : $stdout);
      return [
        'gates' => NULL,
        'source' => sprintf(
          'unresolved — `%s` exited %d%s; a run begun here is held to the lever file\'s gates only',
          $invocation,
          $exit,
          $line === NULL ? '' : ' (' . $line . ')',
        ),
      ];
    }
    $rows = self::decode($stdout);
    if ($rows === NULL) {
      return [
        'gates' => NULL,
        'source' => sprintf('unresolved — `%s` printed no JSON list; a run begun here is held to the lever file\'s gates only', $invocation),
      ];
    }
    $gates = [];
    foreach ($rows as $row) {
      if (!is_array($row)) {
        return [
          'gates' => NULL,
          'source' => sprintf('unresolved — `%s` printed a row that is not an object; a run begun here is held to the lever file\'s gates only', $invocation),
        ];
      }
      try {
        $gates[] = ContributedGate::fromArray($row);
      }
      catch (\InvalidArgumentException $e) {
        return [
          'gates' => NULL,
          'source' => sprintf('unresolved — `%s`: %s; a run begun here is held to the lever file\'s gates only', $invocation, $e->getMessage()),
        ];
      }
    }
    return [
      'gates' => $gates,
      'source' => sprintf(
        'the booted site, asked through `%s` (%d contributed gate%s)',
        $invocation,
        count($gates),
        count($gates) === 1 ? '' : 's',
      ),
    ];
  }

  /**
   * The JSON list in drush's output, tolerating chatter before it.
   *
   * @param string $stdout
   *   Standard output.
   *
   * @return list<mixed>|null
   *   The decoded list, or NULL when there is none.
   */
  private static function decode(string $stdout): ?array {
    $start = strpos($stdout, '[');
    if ($start === FALSE) {
      return NULL;
    }
    try {
      $decoded = json_decode(substr($stdout, $start), TRUE, 16, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException) {
      return NULL;
    }
    return is_array($decoded) ? array_values($decoded) : NULL;
  }

  /**
   * The first line of output that says something.
   *
   * @param string $output
   *   Tool output.
   *
   * @return string|null
   *   The line, or NULL when nothing was said.
   */
  private static function firstLine(string $output): ?string {
    foreach (preg_split('/\R/', $output) ?: [] as $line) {
      $line = trim($line, " \t[]");
      if ($line !== '' && !str_starts_with($line, 'Note:')) {
        return $line;
      }
    }
    return NULL;
  }

}
