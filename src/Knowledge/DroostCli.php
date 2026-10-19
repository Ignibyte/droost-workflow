<?php

declare(strict_types=1);

namespace Droost\Workflow\Knowledge;

use Droost\Engine\Wiki\FileHasher;
use Droost\Workflow\Knowledge\Tool\ArchSearchTool;
use Droost\Workflow\Knowledge\Tool\BriefTool;
use Droost\Workflow\Knowledge\Tool\DeprecationsTool;
use Droost\Workflow\Knowledge\Tool\GraphTool;
use Droost\Workflow\Knowledge\Tool\ModuleDocsTool;
use Droost\Workflow\Knowledge\Tool\SearchTool;
use Droost\Workflow\Knowledge\Tool\SymbolTool;
use Droost\Workflow\Knowledge\Tool\TreeTool;
use Droost\Workflow\Knowledge\Tool\WikiTool;
use Droost\Workflow\Knowledge\Wiki\HashRewriter;
use Droost\Workflow\Knowledge\Wiki\WikiStamper;
use Droost\Workflow\Knowledge\Wiki\WikiStatus;

/**
 * The droost command: the code's knowledge and the workflow's run.
 *
 * A bare word fills a knowledge command's main argument, `--name=value` any
 * other, or one JSON object may be passed:
 *
 * @code
 * droost                         lists the commands
 * droost <command> help          explains one
 * droost code:wiki [page]        the wiki and the inventory
 * droost code:wiki status|stamp  the wiki's freshness; re-recording a page
 * droost code:tree [module]      the code's structure
 * droost search:arch "<words>"   the documentation, as text
 * droost code:index [--full]     builds the code index from files
 * droost workflow:<verb> …       the run (what droost-workflow did)
 * droost init | migrate | doctor
 * @endcode
 *
 * Nothing here boots Drupal or calls drush: what the code says is read from
 * files and the index. Every knowledge command is a row in the tool-call
 * ledger, the one plan_grounded reads, under its command's name.
 *
 * A knowledge answer is a JSON envelope {success, message, data}; exit 0,
 * 1 when the command ran and failed, 2 for a call that was not one.
 */
final class DroostCli {

  /**
   * The command answered.
   */
  public const int EXIT_OK = 0;

  /**
   * The command ran and failed.
   */
  public const int EXIT_FAILED = 1;

  /**
   * The call was not one.
   */
  public const int EXIT_USAGE = 2;

  /**
   * The command could not run: what it measures is unknown.
   */
  public const int EXIT_FAULT = 3;

  /**
   * The knowledge commands, by name.
   *
   * @var array<string, \Droost\Workflow\Knowledge\KnowledgeToolInterface>
   */
  private array $tools = [];

  /**
   * Where output goes.
   *
   * @var \Closure(string): void
   */
  private \Closure $out;

  /**
   * Where errors go.
   *
   * @var \Closure(string): void
   */
  private \Closure $err;

  /**
   * The project root, once found.
   */
  private ?string $root = NULL;

  /**
   * Constructs a DroostCli.
   *
   * @param string $cwd
   *   The working directory.
   * @param \Closure(list<string>): int $workflow
   *   Runs one droost-workflow verb line (the verb first) and returns its
   *   exit code: the run's own dispatcher.
   * @param string $packageRoot
   *   This package's root.
   * @param (\Closure(string): void)|null $out
   *   Standard output, a line at a time.
   * @param (\Closure(string): void)|null $err
   *   Standard error, a line at a time.
   * @param string|false $host
   *   The host's project directory (`CLAUDE_PROJECT_DIR`), FALSE when unset.
   */
  public function __construct(
    private readonly string $cwd,
    private readonly \Closure $workflow,
    private readonly string $packageRoot,
    ?\Closure $out = NULL,
    ?\Closure $err = NULL,
    private readonly string|false $host = FALSE,
  ) {
    $this->out = $out ?? static function (string $line): void {
      fwrite(STDOUT, $line . "\n");
    };
    $this->err = $err ?? static function (string $line): void {
      fwrite(STDERR, $line . "\n");
    };
    foreach (self::tools() as $tool) {
      $this->tools[$tool->id()] = $tool;
    }
  }

  /**
   * The knowledge commands, in the order they are listed.
   *
   * @return list<\Droost\Workflow\Knowledge\KnowledgeToolInterface>
   *   The tools.
   */
  public static function tools(): array {
    return [
      new WikiTool(),
      new TreeTool(),
      new ArchSearchTool(),
      new SymbolTool(),
      new GraphTool(),
      new SearchTool(),
      new ModuleDocsTool(),
      new DeprecationsTool(),
      new BriefTool(),
    ];
  }

  /**
   * The commands that are not knowledge: name => one line.
   *
   * @return array<string, string>
   *   The commands.
   */
  public static function otherCommands(): array {
    return [
      'code:index' => 'Builds or refreshes the code index from files (--full rebuilds it).',
      'code:wiki status' => 'The wiki\'s freshness, page by page (--json, --strict); the wiki_fresh gate runs it.',
      'code:wiki stamp' => 'Re-records a stale page\'s source hashes once re-read (<page> or --all-stale, --dry-run).',
      'workflow:<verb>' => 'The workflow\'s run: workflow:status, workflow:run, workflow:criteria, …',
      'init' => 'Installs the workflow pack and droost\'s block in each agent harness (--harness=auto|all|<ids>).',
      'migrate' => 'Moves a root droost.workflow.yml to droost/droost.yml.',
      'doctor' => 'What droost can see of this project, row by row.',
      'guard <verb>' => 'The guard each agent harness\'s hooks call (the same as bin/droost-guard); not for typing by hand.',
    ];
  }

  /**
   * The knowledge commands as data.
   *
   * @return list<array{command: string, description: string, positional: string, inputSchema: array<string, mixed>}>
   *   One entry per command.
   */
  public static function definitions(): array {
    $out = [];
    foreach (self::tools() as $tool) {
      $schema = ['type' => 'object', 'properties' => new \stdClass()];
      $description = $tool->summary();
      $positional = '';
      if ($tool instanceof ToolBase) {
        $description = $tool::DESCRIPTION;
        $positional = $tool::POSITIONAL;
        if ($tool::ARGUMENTS !== []) {
          $schema['properties'] = $tool::ARGUMENTS;
        }
        if ($tool::REQUIRED !== []) {
          $schema['required'] = $tool::REQUIRED;
        }
      }
      $out[] = [
        'command' => $tool->id(),
        'description' => $description,
        'positional' => $positional,
        'inputSchema' => $schema,
      ];
    }
    return $out;
  }

  /**
   * Runs one command line.
   *
   * @param list<string> $argv
   *   The arguments after the program name.
   *
   * @return int
   *   The exit code.
   */
  public function run(array $argv): int {
    $command = $argv[0] ?? '';
    $rest = array_values(array_slice($argv, 1));
    if ($command === '' || in_array($command, ['help', '--help', '-h', 'list'], TRUE)) {
      ($this->out)($this->listing());
      return self::EXIT_OK;
    }
    if ($command === '--list-json') {
      ($this->out)((string) json_encode(self::definitions(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
      return self::EXIT_OK;
    }
    if (str_starts_with($command, 'workflow:')) {
      $verb = substr($command, strlen('workflow:'));
      if ($verb === '') {
        ($this->err)('Name the verb: droost workflow:status, droost workflow:run, … (droost workflow:help lists them).');
        return self::EXIT_USAGE;
      }
      return ($this->workflow)([$verb === 'help' ? '--help' : $verb, ...$rest]);
    }
    if ($command === 'code:wiki' && in_array($rest[0] ?? '', ['status', 'stamp'], TRUE)) {
      return $rest[0] === 'status'
        ? $this->wikiStatus(array_values(array_slice($rest, 1)))
        : $this->wikiStamp(array_values(array_slice($rest, 1)));
    }
    // The project commands answer help, and refuse what they do not take,
    // before they act: `droost migrate --help` once MOVED the lever file
    // (F-307, EMT-422).
    if (isset(self::otherCommands()[$command])) {
      if (array_intersect(array_map(static fn (string $word): string => strtolower(trim($word)), $rest), ['help', '--help', '-h']) !== []) {
        ($this->out)(sprintf('droost %s: %s', $command, self::otherCommands()[$command]));
        return self::EXIT_OK;
      }
      if (in_array($command, ['migrate', 'doctor'], TRUE) && $rest !== []) {
        ($this->err)(sprintf('droost %s takes no arguments (got: %s). `droost %s help` says what it does.', $command, implode(' ', $rest), $command));
        return self::EXIT_USAGE;
      }
    }
    return match ($command) {
      'code:index' => $this->index($rest),
      'init' => $this->init($rest),
      'migrate' => $this->envelope((new Migrate(KnowledgeSettings::fromProjectRoot($this->root())))->run()),
      'doctor' => $this->envelope((new Doctor(Site::fromProjectRoot($this->root())))->run()),
      default => $this->tool($command, $rest),
    };
  }

  /**
   * Runs one knowledge command.
   *
   * @param string $command
   *   Its name.
   * @param list<string> $rest
   *   Its arguments.
   *
   * @return int
   *   The exit code.
   */
  private function tool(string $command, array $rest): int {
    $tool = $this->tools[$command] ?? NULL;
    if ($tool === NULL) {
      ($this->err)(sprintf('"%s" is not a droost command. Run droost with no argument to list them.', $command));
      return self::EXIT_USAGE;
    }
    if (count($rest) === 1 && in_array(strtolower(trim($rest[0])), ['help', '--help', '-h'], TRUE)) {
      ($this->out)($tool->help());
      return self::EXIT_OK;
    }
    try {
      $arguments = self::arguments($tool instanceof ToolBase ? $tool::POSITIONAL : '', $rest);
    }
    catch (\InvalidArgumentException $e) {
      ($this->err)($e->getMessage() . sprintf(' `droost %s help` shows what it takes.', $command));
      return self::EXIT_USAGE;
    }
    $root = $this->root();
    try {
      $answer = $tool->answer($arguments, Site::fromProjectRoot($root));
    }
    catch (\Throwable $e) {
      $answer = [
        'success' => FALSE,
        'message' => sprintf('%s stopped on %s: %s', $command, get_class($e), $e->getMessage()),
        'data' => NULL,
      ];
    }
    ToolCallLog::append($root, $command, $answer['success'] ? 'ok' : 'fail');
    return $this->envelope($answer);
  }

  /**
   * Parses a knowledge command's arguments.
   *
   * A JSON object is merged in; `--name=value` sets one; `--flag` is TRUE;
   * bare words, joined by spaces, fill the positional argument.
   *
   * @param string $positional
   *   The argument bare words fill, or '' when the command takes none.
   * @param list<string> $rest
   *   The words.
   *
   * @return array<string, mixed>
   *   The arguments.
   */
  public static function arguments(string $positional, array $rest): array {
    $arguments = [];
    $words = [];
    foreach ($rest as $word) {
      $trimmed = trim($word);
      if (str_starts_with($trimmed, '{')) {
        try {
          $decoded = json_decode($trimmed, TRUE, 512, JSON_THROW_ON_ERROR);
        }
        catch (\JsonException $e) {
          throw new \InvalidArgumentException(sprintf('The JSON argument does not parse (%s).', $e->getMessage()));
        }
        if (!is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
          throw new \InvalidArgumentException('A JSON argument must be one object.');
        }
        foreach ($decoded as $key => $value) {
          $arguments[(string) $key] = $value;
        }
        continue;
      }
      if (str_starts_with($word, '--') && strlen($word) > 2) {
        $parts = explode('=', substr($word, 2), 2);
        $arguments[$parts[0]] = $parts[1] ?? TRUE;
        continue;
      }
      $words[] = $word;
    }
    if ($words !== []) {
      if ($positional === '') {
        throw new \InvalidArgumentException(sprintf('This command takes no bare argument (got "%s").', implode(' ', $words)));
      }
      $arguments[$positional] ??= implode(' ', $words);
    }
    return $arguments;
  }

  /**
   * Builds the code index.
   *
   * @param list<string> $rest
   *   The arguments: --full or nothing.
   *
   * @return int
   *   The exit code.
   */
  private function index(array $rest): int {
    $unknown = array_values(array_diff($rest, ['--full']));
    if ($unknown !== []) {
      ($this->err)(sprintf('code:index takes --full or nothing (got %s).', implode(' ', $unknown)));
      return self::EXIT_USAGE;
    }
    try {
      $report = (new KnowledgeIndexer(KnowledgeSettings::fromProjectRoot($this->root())))->index(in_array('--full', $rest, TRUE));
    }
    catch (\Throwable $e) {
      return $this->envelope([
        'success' => FALSE,
        'message' => sprintf('code:index stopped on %s: %s', get_class($e), $e->getMessage()),
        'data' => NULL,
      ]);
    }
    return $this->envelope([
      'success' => TRUE,
      'message' => sprintf(
        'Indexed (%s) in %ss: %d files, %d symbols, %d edges, %d documents; %d parsed, %d removed. Semantic layer: %s. %s',
        $report['mode'],
        $report['seconds'],
        $report['files'],
        $report['symbols'],
        $report['edges'],
        $report['documents'],
        $report['parsed'],
        $report['removed'],
        match ($report['semantic']) {
          'built' => sprintf('built, %d vectors (%d file(s) embedded this run)%s', $report['vectors'], $report['embedded_files'], $report['semantic_reason'] !== '' ? '; ' . $report['semantic_reason'] : ''),
          'off' => 'off (lexical only: search.embeddings names no endpoint)',
          default => sprintf('%s: %s', $report['semantic'], $report['semantic_reason']),
        },
        $report['index'],
      ),
      'data' => $report,
    ]);
  }

  /**
   * Reports the wiki's freshness: `code:wiki status [--json] [--strict]`.
   *
   * The `wiki_fresh` gate's measurement. With --json, stdout holds the report
   * and nothing else, so a consumer can decode the whole stream. Exit 0 when
   * no page is stale, orphaned or invalid (and, with --strict, no extension is
   * uncovered); 1 when one is, or when there is no wiki to check; 2 for a call
   * that was not one; 3 when the check itself could not run. Not a ledger
   * row: a gate measuring the wiki is not the agent consulting it.
   *
   * @param list<string> $rest
   *   The options.
   *
   * @return int
   *   The exit code.
   */
  private function wikiStatus(array $rest): int {
    $unknown = array_values(array_diff($rest, ['--json', '--strict', '--format=json']));
    if ($unknown !== []) {
      ($this->err)(sprintf('code:wiki status takes --json and --strict (got %s).', implode(' ', $unknown)));
      return self::EXIT_USAGE;
    }
    $json = in_array('--json', $rest, TRUE) || in_array('--format=json', $rest, TRUE);
    try {
      $site = Site::fromProjectRoot($this->root());
      if (!is_dir($site->wikiDir)) {
        $message = sprintf('No wiki at %s: there is nothing to check. Write OKF pages there (droost code:wiki help), or turn wiki_fresh off.', $site->wikiDir);
        if ($json) {
          ($this->out)((string) json_encode(['ok' => FALSE, 'error' => $message], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }
        ($this->err)($message);
        return self::EXIT_FAILED;
      }
      $report = self::wikiChecker($site)->report(in_array('--strict', $rest, TRUE));
    }
    catch (\Throwable $e) {
      ($this->err)(sprintf('code:wiki status could not run: %s: %s', get_class($e), $e->getMessage()));
      return self::EXIT_FAULT;
    }
    if ($json) {
      ($this->out)((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
    }
    else {
      foreach ($report['pages'] as $page) {
        $detail = match (TRUE) {
          $page['stale_sources'] !== [] => 'changed: ' . implode(', ', array_column($page['stale_sources'], 'path')),
          $page['missing_sources'] !== [] => 'missing: ' . implode(', ', $page['missing_sources']),
          $page['error'] !== NULL => $page['error'],
          default => '',
        };
        ($this->out)(rtrim(sprintf('%-10s %s  %s', $page['status'], $page['file'], $detail)));
      }
      if ($report['uncovered'] !== []) {
        ($this->out)('No page covers: ' . implode(', ', $report['uncovered']));
      }
      ($this->out)(WikiStatus::summaryLine($report));
    }
    return $report['ok'] ? self::EXIT_OK : self::EXIT_FAILED;
  }

  /**
   * Re-records a stale page's hashes: `code:wiki stamp <page>|--all-stale`.
   *
   * @param list<string> $rest
   *   The page, --all-stale, --dry-run.
   *
   * @return int
   *   The exit code: 0 when every targeted page ends fresh.
   */
  private function wikiStamp(array $rest): int {
    $allStale = in_array('--all-stale', $rest, TRUE);
    $dryRun = in_array('--dry-run', $rest, TRUE);
    $pages = array_values(array_filter($rest, static fn (string $w): bool => !str_starts_with($w, '--')));
    $flags = array_filter($rest, static fn (string $w): bool => str_starts_with($w, '--'));
    $unknown = array_values(array_diff($flags, ['--all-stale', '--dry-run']));
    if ($unknown !== [] || count($pages) > 1 || ($pages === []) === !$allStale) {
      ($this->err)('code:wiki stamp takes one page, or --all-stale; and --dry-run to preview.');
      return self::EXIT_USAGE;
    }
    try {
      $site = Site::fromProjectRoot($this->root());
      if (!is_dir($site->wikiDir)) {
        return $this->envelope([
          'success' => FALSE,
          'message' => sprintf('No wiki at %s: there is nothing to stamp.', $site->wikiDir),
          'data' => NULL,
        ]);
      }
      $stamper = new WikiStamper($site->wikiDir, self::wikiChecker($site), new HashRewriter());
      $targets = $stamper->targets($allStale, $pages[0] ?? NULL);
      $rows = [];
      $failed = FALSE;
      $moved = 0;
      foreach ($targets as $target) {
        $row = $stamper->stamp($target, $dryRun);
        $rows[] = [
          'file' => $row->file,
          'action' => $row->action,
          'verdict' => $row->verdict,
          'sources' => $row->sources,
          'message' => $row->message,
        ];
        $failed = $failed || !$row->ok();
        $moved += ($row->ok() && $row->sources !== []) ? 1 : 0;
      }
    }
    catch (\Throwable $e) {
      ($this->err)(sprintf('code:wiki stamp could not run: %s: %s', get_class($e), $e->getMessage()));
      return self::EXIT_FAULT;
    }
    $message = match (TRUE) {
      $targets === [] => 'Nothing to stamp: no managed page is stale.',
      $dryRun => sprintf('Dry run: %d page(s) would be stamped; nothing was written.', $moved),
      default => sprintf('Stamped %d page(s). A stamp asserts the prose is still accurate; if it is not, rewrite the page instead.', $moved),
    };
    return $this->envelope([
      'success' => !$failed,
      'message' => $message,
      'data' => ['dry_run' => $dryRun, 'rows' => $rows],
    ]);
  }

  /**
   * The wiki freshness checker over a site's files and index.
   *
   * @param \Droost\Workflow\Knowledge\Site $site
   *   The site.
   *
   * @return \Droost\Workflow\Knowledge\Wiki\WikiStatus
   *   The checker.
   */
  private static function wikiChecker(Site $site): WikiStatus {
    return new WikiStatus(
      $site->wiki(),
      new FileHasher(),
      $site->projectRoot,
      $site->settings->wikiConfiguredPath(),
      $site->inventory(),
      $site->graph(),
      $site->settings->wikiContribScope(),
    );
  }

  /**
   * Installs the pack and the harness block.
   *
   * @param list<string> $rest
   *   The arguments: --harness=…, and what the workflow's init takes.
   *
   * @return int
   *   The exit code.
   */
  private function init(array $rest): int {
    $harness = 'auto';
    $forward = [];
    foreach ($rest as $word) {
      if (str_starts_with($word, '--harness=')) {
        $harness = substr($word, strlen('--harness='));
        continue;
      }
      $forward[] = $word;
    }
    $code = ($this->workflow)(['init', ...$forward]);
    if ($code !== self::EXIT_OK) {
      return $code;
    }
    $root = $this->root();
    try {
      $result = (new HarnessSetup($this->packageRoot))->install($root, $harness, Site::coreVersionOf($root, CitationResolver::docroot($root)));
    }
    catch (\Throwable $e) {
      ($this->err)(sprintf('The pack is installed, but the harness block was not: %s', $e->getMessage()));
      return self::EXIT_FAILED;
    }
    foreach ($result->written as $path) {
      ($this->out)('wrote ' . $path);
    }
    foreach ([...$result->removed, ...self::removeRetiredCommands($root)] as $path) {
      ($this->out)('removed ' . $path);
    }
    foreach ($result->warnings as $warning) {
      ($this->err)('warning: ' . $warning);
    }
    return self::EXIT_OK;
  }

  /**
   * The slash commands drupal/droost installed, by file and the title it gave.
   *
   * They drove the module's own installers, which went with it. Nothing
   * installs these commands now, and nothing removed them, so a project
   * upgraded from the module kept three commands that called a door droost no
   * longer has (F-305).
   */
  private const array RETIRED_COMMANDS = [
    '.claude/commands/droost/init.md' => 'Droost — finish setup on a project someone else wired',
    '.claude/commands/droost/upgrade.md' => 'Droost — guided upgrade',
    '.claude/commands/droost/configure.md' => 'Droost — guided configuration',
  ];

  /**
   * Removes the retired module commands that are still droost's own.
   *
   * Only a file whose frontmatter title is the one droost wrote: a command a
   * person wrote at the same path is theirs, and stays.
   *
   * @param string $root
   *   The project root.
   *
   * @return list<string>
   *   The project-relative paths removed.
   */
  private static function removeRetiredCommands(string $root): array {
    $removed = [];
    foreach (self::RETIRED_COMMANDS as $relative => $title) {
      $path = $root . '/' . $relative;
      $text = is_file($path) ? (string) @file_get_contents($path, FALSE, NULL, 0, 4096) : '';
      if ($text !== '' && preg_match('/^title:\s*' . preg_quote($title, '/') . '\s*$/m', $text) === 1 && @unlink($path)) {
        $removed[] = $relative;
      }
    }

    return $removed;
  }

  /**
   * Prints an answer and returns its exit code.
   *
   * @param array{success: bool, message: string, data: mixed} $answer
   *   The answer.
   *
   * @return int
   *   The exit code.
   */
  private function envelope(array $answer): int {
    ($this->out)((string) json_encode($answer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
    return $answer['success'] ? self::EXIT_OK : self::EXIT_FAILED;
  }

  /**
   * The project root.
   *
   * @return string
   *   The root.
   */
  private function root(): string {
    return $this->root ??= ProjectLocator::locate($this->cwd, $this->host);
  }

  /**
   * The commands, a line each.
   *
   * @return string
   *   The listing.
   */
  private function listing(): string {
    $lines = ['droost: this codebase\'s knowledge, read from files, and the workflow\'s run.', '', 'Knowledge:'];
    $names = array_merge(array_keys($this->tools), array_keys(self::otherCommands()));
    $width = max([0, ...array_map('strlen', $names)]);
    foreach ($this->tools as $name => $tool) {
      $lines[] = sprintf('  %s  %s', str_pad($name, $width), $tool->summary());
    }
    $lines[] = '';
    $lines[] = 'Project:';
    foreach (self::otherCommands() as $name => $summary) {
      $lines[] = sprintf('  %s  %s', str_pad($name, $width), $summary);
    }
    $lines[] = '';
    $lines[] = 'What one takes: droost <command> help';
    $lines[] = 'Run one: droost code:wiki camps, droost code:tree camps, droost search:arch "camp registration"';
    return implode("\n", $lines);
  }

}
