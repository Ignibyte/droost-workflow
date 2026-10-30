<?php

declare(strict_types=1);

namespace Droost\Workflow\Knowledge;

use Droost\Workflow\Baseline\BaselineStore;
use Droost\Workflow\Config\ConfigLocator;
use Droost\Workflow\Config\GateSettings;
use Droost\Workflow\Config\StoragePaths;
use Droost\Workflow\Config\WorkflowConfig;
use Droost\Workflow\Gate\ShellGateExecutor;

/**
 * What droost can see of a project, row by row: `droost doctor`.
 *
 * Each row is a fact read from the files, with what to do when it is not
 * as it should be. A row that could not be read says so; it is never
 * reported as fine.
 */
final class Doctor {

  /**
   * How long a plugged-in file's check may take.
   */
  private const CHECK_SECONDS = 30;

  /**
   * Constructs a Doctor.
   *
   * @param \Droost\Workflow\Knowledge\Site $site
   *   The project.
   */
  public function __construct(private readonly Site $site) {}

  /**
   * Every row.
   *
   * @return array{success: bool, message: string, data: mixed}
   *   The envelope: success when every row is ok; data lists the rows.
   */
  public function run(): array {
    $root = $this->site->projectRoot;
    $rows = [];

    $config = StoragePaths::configFile($root);
    $rows[] = match (TRUE) {
      $config === NULL => self::row('config', 'warn', 'No droost/droost.yml: `droost init` writes one.'),
      str_ends_with($config, StoragePaths::LEGACY_CONFIG_FILE) => self::row('config', 'warn', 'The configuration is still the root droost.workflow.yml: `droost migrate` moves it to droost/droost.yml.'),
      default => self::row('config', 'ok', StoragePaths::CONFIG_FILE),
    };

    $rows[] = $this->site->coreVersion === ''
      ? self::row('drupal', 'warn', sprintf('No Drupal core version found (composer.lock, core/lib/Drupal.php) under the docroot %s.', $this->site->docroot))
      : self::row('drupal', 'ok', sprintf('Drupal %s at %s', $this->site->coreVersion, $this->site->docroot));

    $sqlite = in_array('sqlite', \PDO::getAvailableDrivers(), TRUE);
    $rows[] = $sqlite ? self::row('sqlite', 'ok', 'pdo_sqlite is available') : self::row('sqlite', 'fail', 'PHP has no pdo_sqlite: the code index cannot be read or built.');

    $pdo = $this->site->pdo();
    if ($pdo === NULL) {
      $rows[] = self::row('code index', 'fail', sprintf('No code index at %s: `droost code:index` builds it from files.', $this->site->indexPath));
    }
    else {
      $meta = KnowledgeIndexer::meta($pdo);
      $graph = $this->site->graph();
      $counts = $graph !== NULL && $graph->isReady() ? $graph->count() : ['symbols' => 0, 'edges' => 0];
      $docs = (new DocsIndex($pdo))->count();
      $rows[] = $counts['symbols'] === 0
        ? self::row('code index', 'fail', 'The code index holds no symbols: `droost code:index --full`.')
        : self::row('code index', 'ok', sprintf('%d symbols, %d edges, %d documents%s', $counts['symbols'], $counts['edges'], $docs, isset($meta['indexed_at']) ? ', built ' . $meta['indexed_at'] : ', built by an earlier droost (rebuild with `droost code:index --full` to add the documents)'));
    }

    $rows[] = is_dir($this->site->wikiDir)
      ? self::row('wiki', 'ok', sprintf('%d page(s) at %s', $this->site->wiki()->count(), $this->site->wikiDir))
      : self::row('wiki', 'warn', sprintf('No wiki at %s yet; `droost code:wiki` still lists the inventory.', $this->site->wikiDir));

    $exported = $this->site->inventory()->exported();
    $rows[] = $exported === FALSE
      ? self::row('config export', 'warn', 'No exported core.extension.yml found: the inventory cannot say what is enabled.')
      : self::row('config export', 'ok', sprintf('%s, exported %s', $exported['file'], $exported['exported']));

    // THE BASELINE, BEFORE ANY RUN (F-378). Missing, it surfaced only when a
    // run's gates failed on findings older than the ticket, and recording it
    // then is the operator's, in a terminal, mid-run.
    try {
      $lever = WorkflowConfig::load($root)->baseline;
    }
    catch (\Throwable) {
      $lever = NULL;
    }
    $rows[] = match (TRUE) {
      $lever === NULL => self::row('baseline', 'warn', 'The configuration could not be read, so the baseline lever is unknown.'),
      $lever === FALSE => self::row('baseline', 'ok', 'off: every gate judges the whole tree (strict mode)'),
      BaselineStore::exists($root) => self::row('baseline', 'ok', sprintf('recorded at %s', BaselineStore::DIR)),
      default => self::row('baseline', 'warn', 'None recorded: every gate judges the whole tree, so findings that predate a ticket fail it. On an existing codebase the operator records one with `droost workflow:baseline` before the first run.'),
    };

    $rows[] = is_file($root . '/.claude/skills/workflow-start/SKILL.md')
      ? self::row('pack', 'ok', 'the workflow pack is installed (.claude/skills)')
      : self::row('pack', 'warn', 'The workflow pack is not installed: `droost init`.');

    $mcp = @file_get_contents($root . '/.mcp.json');
    $decoded = is_string($mcp) ? json_decode($mcp, TRUE) : NULL;
    $servers = is_array($decoded) && is_array($decoded['mcpServers'] ?? NULL) ? $decoded['mcpServers'] : [];
    $rows[] = isset($servers['droost'])
      ? self::row('mcp', 'warn', '.mcp.json still names a droost MCP server, which droost no longer ships: `droost init` removes it.')
      : self::row('mcp', 'ok', 'no droost MCP entry (droost is reached by command)');

    // WHAT THE PROJECT PLUGS IN (owner, 2026-10-30): every gate it turns on,
    // and every gate, hook and wall file it adds, asked whether it is ready.
    // So the check grows with the project, not with droost.
    array_push($rows, ...$this->plugged($root));

    $bad = array_filter($rows, static fn (array $r): bool => $r['status'] !== 'ok');
    $failed = array_filter($rows, static fn (array $r): bool => $r['status'] === 'fail');
    return [
      'success' => $failed === [],
      'message' => $bad === []
        ? sprintf('All %d rows ok.', count($rows))
        : implode("\n", array_map(static fn (array $r): string => sprintf('%s %s: %s', strtoupper($r['status']), $r['check'], $r['detail']), $bad)),
      'data' => ['project' => $root, 'rows' => $rows],
    ];
  }

  /**
   * The rows for the gates turned on and the files a project plugs in.
   *
   * A built-in gate is ready when its tool is installed. A gate, hook or wall
   * FILE answers for itself: run with `DROOST_CHECK=1` it judges nothing and
   * prints one line, exit 0 ready, 1 not ready (the line says the fix), 3 it
   * cannot tell. Only a file that names DROOST_CHECK is run that way, since
   * running one that does not would run the real gate; it reads "no check".
   *
   * @param string $root
   *   The repository.
   *
   * @return list<array{check: string, status: string, detail: string}>
   *   The rows.
   */
  private function plugged(string $root): array {
    // Nothing is plugged in before the project has its levers: the pack row
    // already says `droost init`.
    if (ConfigLocator::found($root) === NULL) {
      return [];
    }
    try {
      $config = WorkflowConfig::load($root);
    }
    catch (\Throwable) {
      return [];
    }
    $rows = [];
    $files = [];
    foreach ($config->gates as $name => $gate) {
      if (!$gate->on) {
        continue;
      }
      // The house rules are droost's own judgement: no tool to install.
      if ($name === 'house_rules') {
        continue;
      }
      if (GateSettings::isCustom($name)) {
        $run = $gate->option('run');
        if (is_string($run) && $run !== '') {
          $files['gate ' . preg_replace('/^custom:/', '', $name)] = $run;
        }
        continue;
      }
      $binary = ShellGateExecutor::binaryPathFor($name);
      $present = is_file($root . '/' . $binary);
      if (!$present && ($name === 'parity' || $name === 'wiki_fresh')) {
        $present = is_file($name === 'parity' ? ShellGateExecutor::parityBinary($root) : ShellGateExecutor::droostBinary($root));
      }
      $rows[] = $present
        ? self::row('gate ' . $name, 'ok', $binary)
        // A warning, not a failure: a gate with nothing of its kind to judge
        // (eslint on a PHP-only project) never needs its tool. Where it does,
        // the run says so as a gate that did not run.
        : self::row('gate ' . $name, 'warn', sprintf('%s is not installed, so the gate measures nothing until it is (composer install / npm install).', $binary));
    }
    foreach ($config->hooks as $event => $file) {
      $files['hook ' . $event] = $file;
    }
    foreach ($config->walls as $id => $wall) {
      $files['wall ' . $id] = $wall['run'];
    }
    foreach ($files as $check => $file) {
      $rows[] = self::fileCheck($root, $check, $file);
    }
    return $rows;
  }

  /**
   * A plugged-in file's own answer to whether it is ready.
   *
   * @param string $root
   *   The repository.
   * @param string $check
   *   The row's name.
   * @param string $file
   *   The file, project-relative.
   *
   * @return array{check: string, status: string, detail: string}
   *   The row.
   */
  private static function fileCheck(string $root, string $check, string $file): array {
    $path = $root . '/' . ltrim($file, '/');
    $source = @file_get_contents($path);
    if (!is_string($source)) {
      return self::row($check, 'fail', sprintf('%s is missing (composer install scaffolds it, or droost.yml names the wrong file).', $file));
    }
    if (!str_contains($source, 'DROOST_CHECK')) {
      return self::row($check, 'warn', sprintf('%s has no check of its own (it does not answer DROOST_CHECK), so whether it can run is unknown until a run.', $file));
    }
    $command = str_ends_with($path, '.php') ? [PHP_BINARY, $path] : [$path];
    $env = getenv();
    $env['DROOST_CHECK'] = '1';
    $env['DROOST_PROJECT_ROOT'] = $root;
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, $env);
    if (!is_resource($process)) {
      return self::row($check, 'warn', sprintf('%s could not be started to check it.', $file));
    }
    fwrite($pipes[0], '{}');
    fclose($pipes[0]);
    // A check answers in seconds; one that does not is stopped, not waited on.
    stream_set_blocking($pipes[1], FALSE);
    stream_set_blocking($pipes[2], FALSE);
    $out = '';
    $deadline = microtime(TRUE) + self::CHECK_SECONDS;
    $exit = NULL;
    while (microtime(TRUE) < $deadline) {
      $out .= (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
      $status = proc_get_status($process);
      if (!$status['running']) {
        $exit = $status['exitcode'];
        break;
      }
      usleep(50000);
    }
    $out = trim($out . (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]));
    fclose($pipes[1]);
    fclose($pipes[2]);
    if ($exit === NULL) {
      proc_terminate($process);
      proc_close($process);
      return self::row($check, 'warn', sprintf('%s did not answer its check within %d seconds.', $file, self::CHECK_SECONDS));
    }
    proc_close($process);
    $line = strtok($out, "\n") ?: '(no line)';
    return match ($exit) {
      0 => self::row($check, 'ok', $line),
      1 => self::row($check, 'fail', $line),
      default => self::row($check, 'warn', sprintf('cannot tell (exit %d): %s', $exit, $line)),
    };
  }

  /**
   * One row.
   *
   * @param string $check
   *   What was checked.
   * @param string $status
   *   One of ok, warn or fail.
   * @param string $detail
   *   What was found, or what to do.
   *
   * @return array{check: string, status: string, detail: string}
   *   The row.
   */
  private static function row(string $check, string $status, string $detail): array {
    return ['check' => $check, 'status' => $status, 'detail' => $detail];
  }

}
