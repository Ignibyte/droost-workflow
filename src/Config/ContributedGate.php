<?php

declare(strict_types=1);

namespace Droost\Workflow\Config;

/**
 * A gate a module contributes, declared in code rather than the lever file.
 *
 * D72: a `droost_<tool>` module (droost_snyk, a semgrep integration) adds a
 * gate the way it adds an MCP tool — enable the module and the gate joins the
 * run at the phases it names. The module owns what the gate DOES: the command
 * (or, later, a PHP check the site driver dispatches), the phases, the
 * default mode, and — required — a plain sentence saying what its verdict
 * means, so a report never shows a number nobody can read. The SITE owns
 * whether it blocks: the lever file's `gates.contributed.<id>` overrides `on`
 * and `mode`, and only those, because everything else is the module's
 * contract, not the repo's to rewrite.
 *
 * This is a value object the engine consumes; the Drupal side (a plugin type
 * and a catalog) builds one per enabled provider and hands the list to
 * WorkflowConfig::load. The engine knows nothing of Drupal plugins, only of
 * this shape — the same boundary work_item and the lifecycle listener keep.
 */
final class ContributedGate {

  /**
   * What a contributed gate's id may look like — the custom-gate grammar.
   */
  private const ID = '#^[a-z0-9][a-z0-9_-]*$#';

  /**
   * The phases a contributed gate may attach to (as custom gates).
   */
  private const PHASES = ['code', 'test'];

  /**
   * Constructs a ContributedGate.
   *
   * @param string $id
   *   The bare gate id (e.g. 'snyk'); becomes 'module:snyk' when resolved.
   * @param string $provider
   *   The module that declared it, for provenance in every report.
   * @param list<string> $phases
   *   The phases it runs at — one or more of code, test. It always re-runs at
   *   complete, like every other gate.
   * @param string $command
   *   The shell command the gate runs; exit zero passes. A single printable
   *   line, the same trust boundary as a lever-file custom gate's cmd.
   * @param string $defaultMode
   *   Either 'block' or 'report': what the gate does with a blocking result
   *   unless the site's lever overrides it.
   * @param string $verdict
   *   A plain sentence: what exit zero means, and what a failure means. Shown
   *   in status and the bill so a contributed gate is never a mystery number.
   */
  public function __construct(
    public readonly string $id,
    public readonly string $provider,
    public readonly array $phases,
    public readonly string $command,
    public readonly string $defaultMode = GateSettings::DEFAULT_MODE,
    public readonly string $verdict = '',
  ) {
    if (preg_match(self::ID, $id) !== 1) {
      throw new \InvalidArgumentException(sprintf(
        'A contributed gate id must match %s (lowercase letters, digits, "_", "-"); "%s" (from %s) does not.',
        self::ID,
        $id,
        $provider,
      ));
    }
    if ($provider === '') {
      throw new \InvalidArgumentException(sprintf('The contributed gate "%s" names no provider module.', $id));
    }
    $unknown = array_diff($phases, self::PHASES);
    if ($phases === [] || $unknown !== []) {
      throw new \InvalidArgumentException(sprintf(
        'The contributed gate "%s" (from %s) must name at least one phase of: %s; got: %s.',
        $id,
        $provider,
        implode(', ', self::PHASES),
        $phases === [] ? '(none)' : implode(', ', $phases),
      ));
    }
    if (trim($command) === '' || str_contains($command, "\n") || str_contains($command, "\0")) {
      throw new \InvalidArgumentException(sprintf(
        'The contributed gate "%s" (from %s) must carry a non-empty single-line command.',
        $id,
        $provider,
      ));
    }
    if (!in_array($defaultMode, GateSettings::MODES, TRUE)) {
      throw new \InvalidArgumentException(sprintf(
        'The contributed gate "%s" (from %s) declares mode "%s"; it must be one of: %s.',
        $id,
        $provider,
        $defaultMode,
        implode(', ', GateSettings::MODES),
      ));
    }
    if (trim($verdict) === '') {
      throw new \InvalidArgumentException(sprintf(
        'The contributed gate "%s" (from %s) must state what its verdict means — a report may not show a number no one can read.',
        $id,
        $provider,
      ));
    }
  }

  /**
   * The resolved gate name: 'module:<id>'.
   *
   * @return string
   *   The name carried through the resolved set, the run and every report.
   */
  public function name(): string {
    return GateSettings::MODULE_PREFIX . $this->id;
  }

  /**
   * This declaration as a transportable row.
   *
   * The shape `drush droost:workflow:catalog` prints and the standalone
   * surface reads back (R31-F3): a run begun with no booted site must be held
   * to the same contributed gates as one begun through drush or the MCP tool.
   *
   * @return array{id: string, provider: string, phases: list<string>, command: string, mode: string, verdict: string}
   *   The row.
   */
  public function toArray(): array {
    return [
      'id' => $this->id,
      'provider' => $this->provider,
      'phases' => $this->phases,
      'command' => $this->command,
      'mode' => $this->defaultMode,
      'verdict' => $this->verdict,
    ];
  }

  /**
   * A declaration read back from a row, refused by name when malformed.
   *
   * @param array<array-key, mixed> $row
   *   A row as toArray() writes it (`default_mode` is accepted for `mode`).
   *
   * @return self
   *   The declaration, validated exactly as a constructed one.
   *
   * @throws \InvalidArgumentException
   *   When a field is missing or of the wrong type — the row's id is named
   *   where one is present.
   */
  public static function fromArray(array $row): self {
    $id = $row['id'] ?? NULL;
    $label = is_string($id) && $id !== '' ? $id : '(no id)';
    foreach (['id', 'provider', 'command', 'verdict'] as $key) {
      if (!isset($row[$key]) || !is_string($row[$key])) {
        throw new \InvalidArgumentException(sprintf('Contributed gate row %s: "%s" must be a string.', $label, $key));
      }
    }
    $phases = $row['phases'] ?? NULL;
    if (!is_array($phases)) {
      throw new \InvalidArgumentException(sprintf('Contributed gate row %s: "phases" must be a list of phase names.', $label));
    }
    $names = [];
    foreach ($phases as $phase) {
      if (!is_string($phase)) {
        throw new \InvalidArgumentException(sprintf('Contributed gate row %s: every phase must be a string.', $label));
      }
      $names[] = $phase;
    }
    $mode = $row['mode'] ?? $row['default_mode'] ?? GateSettings::DEFAULT_MODE;
    if (!is_string($mode)) {
      throw new \InvalidArgumentException(sprintf('Contributed gate row %s: "mode" must be a string.', $label));
    }
    return new self((string) $row['id'], (string) $row['provider'], $names, (string) $row['command'], $mode, (string) $row['verdict']);
  }

  /**
   * This gate as resolved levers, before any lever-file override.
   *
   * @return \Droost\Workflow\Config\GateSettings
   *   On by default (enabling the module is the opt-in), carrying its command,
   *   phases, mode, provider and verdict as options.
   */
  public function toSettings(): GateSettings {
    $options = [
      'cmd' => $this->command,
      'phase' => implode(',', $this->phases),
      'provider' => $this->provider,
      'verdict' => $this->verdict,
    ];
    if ($this->defaultMode === 'report') {
      $options['mode'] = 'report';
    }
    return new GateSettings($this->name(), TRUE, $options);
  }

}
