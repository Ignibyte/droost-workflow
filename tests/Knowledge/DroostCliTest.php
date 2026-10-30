<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Knowledge;

use Droost\Workflow\Config\StoragePaths;
use Droost\Workflow\Config\WorkflowConfig;
use Droost\Workflow\Evidence\EvidenceStore;
use Droost\Workflow\Knowledge\CodeIndex;
use Droost\Workflow\Knowledge\DroostCli;
use Droost\Workflow\Knowledge\HarnessSetup;
use Droost\Workflow\Knowledge\KnowledgeSettings;
use Droost\Workflow\Knowledge\ProjectLocator;
use Droost\Workflow\Knowledge\ToolCallLog;
use PHPUnit\Framework\TestCase;

/**
 * The droost command's knowledge commands, over a project on disk.
 *
 * Every command here reads files only; the project has no database and no
 * Drupal to boot, which is the point.
 */
final class DroostCliTest extends TestCase {

  use KnowledgeFixture;

  /**
   * The project.
   */
  private string $root;

  /**
   * What the commands printed.
   *
   * @var list<string>
   */
  private array $out = [];

  /**
   * What they printed to stderr.
   *
   * @var list<string>
   */
  private array $err = [];

  /**
   * The workflow verb lines handed on.
   *
   * @var list<list<string>>
   */
  private array $workflow = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->root = sys_get_temp_dir() . '/droost-cli-' . bin2hex(random_bytes(4));
    mkdir($this->root, 0755, TRUE);
    $this->project($this->root);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    self::remove($this->root);
    parent::tearDown();
  }

  /**
   * The listing names the three knowledge commands; a stranger is refused.
   */
  public function testListingAndAnUnknownCommand(): void {
    $this->assertSame(0, $this->droost([]));
    $listing = implode("\n", $this->out);
    foreach (['code:wiki', 'code:tree', 'search:arch', 'code:index', 'workflow:<verb>', 'migrate'] as $command) {
      $this->assertStringContainsString($command, $listing);
    }
    $this->assertStringNotContainsString('drush', $listing);

    $this->assertSame(2, $this->droost(['droost_symbol']));
    $this->assertStringContainsString('is not a droost command', implode("\n", $this->err));

    $this->out = [];
    $this->assertSame(0, $this->droost(['code:tree', 'help']));
    $this->assertStringStartsWith('droost code:tree [target]', implode("\n", $this->out));
  }

  /**
   * Bare words, --name=value and a JSON object all become arguments.
   */
  public function testArgumentsParse(): void {
    $this->assertEquals(
      ['query' => 'camp registration', 'limit' => '3'],
      DroostCli::arguments('query', ['camp', 'registration', '--limit=3']),
    );
    $this->assertSame(['name' => 'Camp', 'limit' => 5], DroostCli::arguments('name', ['{"name":"Camp","limit":5}']));
    $this->assertSame(['full' => TRUE], DroostCli::arguments('', ['--full']));
    $this->expectException(\InvalidArgumentException::class);
    DroostCli::arguments('', ['stray']);
  }

  /**
   * A workflow verb is handed to the workflow's dispatcher, the verb first.
   */
  public function testWorkflowVerbsAreHandedOn(): void {
    $this->assertSame(7, $this->droost(['workflow:status', '--json'], 7));
    $this->assertSame([['status', '--json']], $this->workflow);
  }

  /**
   * The index is built from files, and code:tree and code:symbol read it.
   */
  public function testIndexThenTreeAndSymbol(): void {
    $this->assertSame(1, $this->droost(['code:tree']), 'No index yet is a failed answer, not an empty one.');
    $this->assertStringContainsString('droost code:index', $this->str('message'));

    $this->assertSame(0, $this->droost(['code:index']));
    $this->assertGreaterThan(0, $this->at('data.symbols'));
    $this->assertFileExists(CodeIndex::path($this->root));

    $this->assertSame(0, $this->droost(['code:tree']));
    $this->assertArrayHasKey('camps', $this->arr('data.custom'));
    $this->assertArrayHasKey('paragraphs', $this->arr('data.contrib'));

    $this->assertSame(0, $this->droost(['code:tree', 'camps']));
    $files = $this->arr('data.files');
    $this->assertArrayHasKey('modules/custom/camps/src/CampList.php', $files);
    $outline = $files['modules/custom/camps/src/CampList.php'];
    $this->assertIsArray($outline);
    $this->assertContains('  method count:9', $outline);
    $this->assertContains('cron', $this->arr('data.hooks'));
    $this->assertContains('camps.list', $this->arr('data.services'));
    $this->assertContains('camps.page', $this->arr('data.routes'));

    $this->assertSame(0, $this->droost(['code:tree', 'web/modules/custom/camps/src']));
    $this->assertStringContainsString('1 file', $this->str('message'));

    $this->assertSame(0, $this->droost(['code:symbol', 'CampList']));
    $this->assertStringContainsString('CampList', (string) json_encode($this->at('data')));

    $tools = array_column(ToolCallLog::read($this->root), 'tool');
    $this->assertSame(['code:tree', 'code:tree', 'code:tree', 'code:tree', 'code:symbol'], $tools, 'Every knowledge command is a ledger row under its own name; code:index is not one.');
  }

  /**
   * A wrong name form is never read as "no edges" (S1-6).
   *
   * The lookup is exact, so a leading backslash or another case came back
   * as success with 0 edges, which reads as "nothing calls this".
   */
  public function testGraphNameFormIsNeverNoEdges(): void {
    $this->droost(['code:index']);

    $this->assertSame(0, $this->droost(['code:graph', '\\Drupal\\camps\\CampList']), $this->str('message'));
    $this->assertSame(0, $this->droost(['code:graph', 'drupal\\camps\\camplist']), $this->str('message'));
    $this->assertStringContainsString('resolved from', $this->str('message'));
    $this->assertSame(1, $this->droost(['code:graph', 'Drupal\\camps\\NoSuchClass']));
    $this->assertStringContainsString('No symbol', $this->str('message'));
  }

  /**
   * With no deprecation data for the core major, a lookup says so (S1-5).
   */
  public function testDeprecationsWithNoDataSayNothingIsKnown(): void {
    $core = ['name' => 'drupal/core', 'version' => '12.0.0'];
    $this->put($this->root, 'composer.lock', (string) json_encode(['packages' => [$core]]));
    $this->put($this->root, 'web/core/lib/Drupal.php', "<?php\nclass Drupal {\n  const VERSION = '12.0.0';\n}\n");
    $this->assertSame(1, $this->droost(['code:deprecations', 'hide']), $this->str('message'));
    $this->assertStringContainsString('no deprecation data', $this->str('message'));
  }

  /**
   * The arch search reads the files before an index, and the index after.
   */
  public function testArchSearchFromFilesThenFromTheIndex(): void {
    $this->assertSame(0, $this->droost(['search:arch', 'translation', 'support']));
    $this->assertStringStartsWith('files', $this->str('data.source'));
    $this->assertSame('web/modules/contrib/paragraphs/README.md', $this->str('data.results.0.path'));

    $this->droost(['code:index']);
    $this->assertSame(0, $this->droost(['search:arch', 'translation', 'support']));
    $this->assertSame('index', $this->str('data.source'));
    $this->assertSame('web/modules/contrib/paragraphs/README.md', $this->str('data.results.0.path'));

    $this->assertSame(0, $this->droost(['search:arch', 'nothing-says-this']));
    $this->assertSame([], $this->arr('data.results'));
  }

  /**
   * The wiki lists its pages and the inventory, with versions and states.
   */
  public function testWikiAndInventory(): void {
    $this->assertSame(0, $this->droost(['code:wiki']));
    $inventory = [];
    foreach ($this->arr('data.inventory') as $row) {
      $this->assertIsArray($row);
      $this->assertIsString($row['page'] ?? NULL);
      $inventory[$row['page']] = $row;
    }
    $this->assertSame('1.18.0', $inventory['inventory/paragraphs.md']['version'] ?? NULL);
    $this->assertFalse($inventory['inventory/paragraphs.md']['enabled'] ?? NULL);
    $this->assertTrue($inventory['inventory/camps.md']['enabled'] ?? NULL);
    $this->assertSame(1, $this->at('data.total'));

    $this->assertSame(0, $this->droost(['code:wiki', 'inventory/camps']));
    $page = $this->str('data.content');
    $this->assertStringContainsString('Enabled: yes, as of the export of', $page);
    $this->assertStringContainsString('`camps.page` /camps', $page);
    $this->assertStringContainsString('administer camps', $page);

    $this->assertSame(0, $this->droost(['code:wiki', 'overview']));
    $this->assertStringContainsString('# Overview', $this->str('data.content'));

    $this->assertSame(0, $this->droost(['code:wiki', '--query=Paragraphs']));
    $this->assertNotEmpty($this->arr('data.inventory'));
  }

  /**
   * A module named bare is its inventory page before any wiki is written.
   *
   * Every project starts with no wiki, and the plan consult names a module
   * (`droost code:wiki camps`). It failed there, saying the inventory had no
   * such extension (F-304).
   */
  public function testBareModuleNameWithNoWikiIsItsInventoryPage(): void {
    self::remove($this->root . '/droost/wiki');

    $this->assertSame(0, $this->droost(['code:wiki', 'camps']));
    $this->assertSame('inventory/camps.md', $this->at('data.path'));
    $this->assertStringContainsString('administer camps', $this->str('data.content'));
    $this->assertSame(0, $this->droost(['code:wiki', 'camps.md']), 'as the inventory links it');
    $this->assertSame(1, $this->droost(['code:wiki', 'nope']));
  }

  /**
   * The brief is the project's files in one answer.
   */
  public function testBrief(): void {
    $this->assertSame(0, $this->droost(['brief']));
    $this->assertSame('11.2.0', $this->at('data.drupal'));
    $this->assertSame(['camps (module)'], $this->arr('data.custom'));
    $this->assertSame(['HR1: No direct database queries.'], $this->arr('data.house_rules'));
  }

  /**
   * The stores go where droost.yml says, and every reader agrees.
   */
  public function testStoragePathsAreConfigurable(): void {
    $this->put($this->root, 'droost/droost.yml', "storage:\n  index: var/index.sqlite\n");
    $this->assertSame($this->root . '/var/index.sqlite', StoragePaths::index($this->root));
    $this->assertSame(StoragePaths::index($this->root), CodeIndex::path($this->root));
    $this->droost(['code:index']);
    $this->assertFileExists($this->root . '/var/index.sqlite');
    $this->assertSame(0, $this->droost(['code:tree', 'camps']));
  }

  /**
   * The evidence store moves where storage.evidence says, keeping its name.
   *
   * A Cockpit can hold it; the guard walls it by its name wherever it is, so
   * a path whose file is called anything else is not followed, and the
   * configuration says so.
   */
  public function testEvidenceStoreMovesKeepingItsName(): void {
    $this->put($this->root, 'droost/droost.yml', "storage:\n  evidence: var/evidence.sqlite\n");
    $this->assertSame($this->root . '/var/evidence.sqlite', StoragePaths::evidence($this->root));
    $this->assertSame(StoragePaths::evidence($this->root), EvidenceStore::pathFor($this->root));
    $this->assertSame([], array_values(array_filter(
      WorkflowConfig::load($this->root)->deprecations,
      static fn (string $notice): bool => str_contains($notice, 'storage.evidence'),
    )));

    // A real write lands there.
    (new EvidenceStore($this->root))->recordToolCall('run-x', 'plan', 'code:wiki', 'ok');
    $this->assertFileExists($this->root . '/var/evidence.sqlite');
    $this->assertFileDoesNotExist($this->root . '/droost/droost-workflow/evidence.sqlite');

    $this->put($this->root, 'droost/droost.yml', "storage:\n  evidence: var/store.db\n");
    $this->assertSame($this->root . '/droost/droost-workflow/evidence.sqlite', StoragePaths::evidence($this->root));
    $this->assertNotEmpty(array_filter(
      WorkflowConfig::load($this->root)->deprecations,
      static fn (string $notice): bool => str_contains($notice, 'must name a file called evidence.sqlite'),
    ));
  }

  /**
   * Migrating moves the lever file and brings the exported settings in.
   */
  public function testMigrate(): void {
    unlink($this->root . '/droost/droost.yml');
    $this->put($this->root, 'droost.workflow.yml', "# my levers\npreset: low\n");
    $this->put($this->root, 'config/sync/droost.wiki.settings.yml', "path: docs/wiki\n");
    $this->put($this->root, 'config/sync/droost.search.settings.yml', "embedding_endpoint: 'http://ollama:11434'\nembedding_model: nomic\nembedding_api_key: secret\n");

    $this->assertSame(0, $this->droost(['migrate']));
    $this->assertFileDoesNotExist($this->root . '/droost.workflow.yml');
    $moved = (string) file_get_contents($this->root . '/droost/droost.yml');
    $this->assertStringContainsString("# my levers\npreset: low\n", $moved);
    $this->assertStringContainsString("wiki:\n  path: docs/wiki", $moved);
    $this->assertStringContainsString('endpoint: \'http://ollama:11434\'', $moved);
    $this->assertStringNotContainsString('secret', $moved, 'A key is never copied into a committed file.');
    $this->assertSame($this->root . '/docs/wiki', KnowledgeSettings::fromProjectRoot($this->root)->wikiDir());

    $this->put($this->root, 'droost.workflow.yml', "preset: high\n");
    $this->assertSame(1, $this->droost(['migrate']), 'Both files at once is refused, and nothing moves.');
    $this->assertFileExists($this->root . '/droost.workflow.yml');
  }

  /**
   * Help on a project command prints, and never acts (F-310).
   */
  public function testProjectCommandsAnswerHelpBeforeActing(): void {
    unlink($this->root . '/droost/droost.yml');
    $this->put($this->root, 'droost.workflow.yml', "preset: low\n");
    foreach (['--help', 'help', '-h'] as $word) {
      $this->out = [];
      $this->assertSame(0, $this->droost(['migrate', $word]));
      $this->assertStringStartsWith('droost migrate: ', implode("\n", $this->out));
      $this->assertFileExists($this->root . '/droost.workflow.yml', 'help moved nothing');
    }
    $this->assertSame(0, $this->droost(['init', 'help']));
    $this->assertSame(0, $this->droost(['doctor', '--help']));
    $this->assertSame(0, $this->droost(['code:index', '-h']));
    $this->assertSame(2, $this->droost(['migrate', '--dry-run']), 'an argument it does not take is refused, not ignored');
    $this->assertFileExists($this->root . '/droost.workflow.yml');
  }

  /**
   * The AGENTS heading names the site's PHP, never the PHP running init.
   */
  public function testAgentsHeadingNamesTheSitesPhp(): void {
    $this->put($this->root, '.ddev/config.yaml', "name: x\nphp_version: \"8.3\"\n");
    $this->assertSame('8.3', HarnessSetup::sitePhp($this->root));
    $this->put($this->root, 'composer.json', (string) json_encode(['config' => ['platform' => ['php' => '8.3.30']]]));
    $this->assertSame('8.3.30', HarnessSetup::sitePhp($this->root), 'the platform pin first');
    $this->assertSame(0, $this->droost(['init']));
    $this->assertStringContainsString(', PHP 8.3.30', (string) file_get_contents($this->root . '/AGENTS.md'));
  }

  /**
   * Init retires the module's documenting-changes skill unless edited (F-311).
   */
  public function testInitRetiresTheModulesSkillUnlessEdited(): void {
    $skill = "---\nname: documenting-changes\n---\nWrites the wiki through Droost.\n";
    $sentinel = "Droost-authored skill; safe to delete.\nsha256: " . hash('sha256', $skill) . "\n";
    $this->put($this->root, '.claude/skills/documenting-changes/SKILL.md', $skill);
    $this->put($this->root, '.claude/skills/documenting-changes/.droost-skill', $sentinel);
    $this->assertSame(0, $this->droost(['init']));
    $this->assertDirectoryDoesNotExist($this->root . '/.claude/skills/documenting-changes');
    $this->assertStringContainsString('removed .claude/skills/documenting-changes', implode("\n", $this->out));

    $this->put($this->root, '.claude/skills/documenting-changes/SKILL.md', $skill . "My team's own rule.\n");
    $this->put($this->root, '.claude/skills/documenting-changes/.droost-skill', $sentinel);
    $this->assertSame(0, $this->droost(['init']));
    $this->assertFileExists($this->root . '/.claude/skills/documenting-changes/SKILL.md', 'an edited copy stays');
    $this->assertStringContainsString('has been edited, so it stays', implode("\n", $this->err));
  }

  /**
   * Init removes the module's retired commands, and only droost's own (F-305).
   */
  public function testInitRemovesTheModulesRetiredCommandsOnly(): void {
    $this->put($this->root, '.claude/commands/droost/init.md', "---\ntitle: Droost — finish setup on a project someone else wired\npurpose: x\n---\n\nRun drush droost:install.\n");
    $this->put($this->root, '.claude/commands/droost/upgrade.md', "---\ntitle: Droost — guided upgrade\n---\n");
    $this->put($this->root, '.claude/commands/droost/configure.md', "---\ntitle: My own configure\n---\n");
    $this->put($this->root, '.claude/commands/droost/work.md', "---\ntitle: Work a ticket\n---\n");

    $this->assertSame(0, $this->droost(['init']));

    $this->assertFileDoesNotExist($this->root . '/.claude/commands/droost/init.md');
    $this->assertFileDoesNotExist($this->root . '/.claude/commands/droost/upgrade.md');
    $this->assertFileExists($this->root . '/.claude/commands/droost/configure.md', 'a command a person wrote at that path stays');
    $this->assertFileExists($this->root . '/.claude/commands/droost/work.md', 'another package\'s command stays');
    $this->assertStringContainsString('removed .claude/commands/droost/init.md', implode("\n", $this->out));
  }

  /**
   * Init writes droost's block and removes an old droost MCP entry.
   */
  public function testInitWritesTheBlockAndNoMcp(): void {
    $servers = [
      'droost' => ['command' => 'vendor/bin/droost-mcp', 'args' => []],
      'atlassian' => ['command' => 'npx', 'args' => ['atlassian']],
    ];
    $this->put($this->root, '.mcp.json', (string) json_encode(['mcpServers' => $servers]));
    $this->assertSame(0, $this->droost(['init']));
    $this->assertSame([['init']], $this->workflow, 'The workflow\'s own init installs the pack first.');
    $agents = (string) file_get_contents($this->root . '/AGENTS.md');
    $this->assertStringContainsString('droost code:wiki', $agents);
    $this->assertStringNotContainsString('drush', $agents);
    $mcp = json_decode((string) file_get_contents($this->root . '/.mcp.json'), TRUE);
    $this->assertIsArray($mcp);
    $this->assertIsArray($mcp['mcpServers'] ?? NULL);
    $this->assertArrayNotHasKey('droost', $mcp['mcpServers']);
    $this->assertArrayHasKey('atlassian', $mcp['mcpServers']);
  }

  /**
   * The doctor reads each row, and an absent index is a failure.
   */
  public function testDoctor(): void {
    $this->assertSame(1, $this->droost(['doctor']));
    $rows = array_column($this->arr('data.rows'), 'status', 'check');
    $this->assertSame('fail', $rows['code index']);
    $this->assertSame('ok', $rows['config export']);
    $this->assertSame('warn', $rows['baseline'], 'no baseline is said before any run (F-378)');
    $this->droost(['code:index']);
    $this->assertSame(0, $this->droost(['doctor']));
  }

  /**
   * Each gate turned on and each file plugged in answers for itself.
   *
   * A built-in gate is ready when its tool is installed; a gate, hook or wall
   * file is asked with DROOST_CHECK=1 (owner, 2026-10-30). One that does not
   * answer it is not run, since that would run the real thing.
   */
  public function testDoctorAsksWhatIsPluggedIn(): void {
    $this->put($this->root, 'droost/droost.yml', "preset: custom\ngates:\n  phpcs: { on: false }\n  phpstan: { on: true }\n"
      . "  phpunit: { on: false }\n  playwright: { on: false }\n  parity: { on: false }\n"
      . "  ready: { run: droost/gates/ready.php, phase: code }\n  broken: { run: droost/gates/broken.php, phase: code }\n"
      . "hooks:\n  run_start: droost/hooks/start.php\n"
      . "walls:\n  w: { tools: '^mcp__x__', run: droost/walls/gone.php }\n");
    $this->put($this->root, 'droost/gates/ready.php', "<?php\nif (getenv('DROOST_CHECK') === '1') { echo \"tool 1.2 installed\\n\"; exit(0); }\nexit(1);\n");
    $this->put($this->root, 'droost/gates/broken.php', "<?php\nif (getenv('DROOST_CHECK') === '1') { echo \"tool missing: install it\\n\"; exit(1); }\nexit(0);\n");
    $this->put($this->root, 'droost/hooks/start.php', "<?php\nfile_put_contents('ran', 'x');\n");
    $this->droost(['code:index']);

    $this->assertSame(1, $this->droost(['doctor']));
    $status = array_column($this->arr('data.rows'), 'status', 'check');
    $detail = array_column($this->arr('data.rows'), 'detail', 'check');
    $this->assertSame('ok', $status['gate ready']);
    $this->assertSame('tool 1.2 installed', $detail['gate ready']);
    $this->assertSame('fail', $status['gate broken']);
    $this->assertSame('tool missing: install it', $detail['gate broken'], 'the line is the fix');
    $this->assertSame('warn', $status['hook run_start'], 'no check of its own');
    $this->assertFileDoesNotExist($this->root . '/ran', 'a file with no check is not run');
    $this->assertSame('fail', $status['wall w'], 'a wall file that is not there');
    $this->assertSame('warn', $status['gate phpstan'], 'a gate turned on with its tool not installed');
    $this->assertArrayNotHasKey('gate phpcs', $status, 'a gate turned off is not asked');
  }

  /**
   * The project is found from a subdirectory, and the host's word wins.
   */
  public function testTheProjectIsFoundAboveTheWorkingDirectory(): void {
    mkdir($this->root . '/droost/droost-workflow', 0755, TRUE);
    $this->assertSame(realpath($this->root), ProjectLocator::locate($this->root . '/web/modules/custom/camps'));
    $this->assertSame(realpath($this->root . '/web'), ProjectLocator::locate($this->root, $this->root . '/web'));
  }

  /**
   * Runs droost in the project.
   *
   * @param list<string> $argv
   *   The arguments.
   * @param int $workflowExit
   *   What the workflow's dispatcher returns.
   *
   * @return int
   *   The exit code.
   */
  private function droost(array $argv, int $workflowExit = 0): int {
    $this->out = [];
    $cli = new DroostCli(
      $this->root,
      function (array $line) use ($workflowExit): int {
        $this->workflow[] = $line;
        return $workflowExit;
      },
      dirname(__DIR__, 2),
      function (string $line): void {
        $this->out[] = $line;
      },
      function (string $line): void {
        $this->err[] = $line;
      },
    );
    return $cli->run($argv);
  }

  /**
   * One value of the last answer, by its dotted path.
   *
   * @param string $path
   *   E.g. `data.results.0.path`.
   *
   * @return mixed
   *   The value.
   */
  private function at(string $path): mixed {
    $value = json_decode(implode("\n", $this->out), TRUE);
    foreach (explode('.', $path) as $key) {
      $this->assertIsArray($value, sprintf('No "%s" in: %s', $path, implode("\n", $this->out)));
      $this->assertArrayHasKey($key, $value, sprintf('No "%s" in: %s', $path, implode("\n", $this->out)));
      $value = $value[$key];
    }
    return $value;
  }

  /**
   * An array of the last answer.
   *
   * @param string $path
   *   Its dotted path.
   *
   * @return array<array-key, mixed>
   *   The array.
   */
  private function arr(string $path): array {
    $value = $this->at($path);
    $this->assertIsArray($value);
    return $value;
  }

  /**
   * A string of the last answer.
   *
   * @param string $path
   *   Its dotted path.
   *
   * @return string
   *   The string.
   */
  private function str(string $path): string {
    $value = $this->at($path);
    $this->assertIsString($value);
    return $value;
  }

  /**
   * A Drupal-shaped project: core, a custom module, a contrib module, a wiki.
   *
   * @param string $root
   *   The project.
   */
  private function project(string $root): void {
    $this->put($root, 'composer.json', (string) json_encode(['extra' => ['drupal-scaffold' => ['locations' => ['web-root' => 'web/']]]]));
    $packages = [
      ['name' => 'drupal/core', 'version' => '11.2.0'],
      ['name' => 'drupal/paragraphs', 'version' => '1.18.0'],
    ];
    $this->put($root, 'composer.lock', (string) json_encode(['packages' => $packages]));
    $this->put($root, 'web/core/lib/Drupal.php', "<?php\nclass Drupal {\n  const VERSION = '11.2.0';\n}\n");
    $this->put($root, 'web/core/core.api.php', "<?php\nfunction hook_cron() {\n}\n");
    $this->put($root, 'web/modules/custom/camps/camps.info.yml', "name: Camps\ntype: module\ndescription: 'Summer camps and their sessions.'\npackage: Custom\n");
    $this->put($root, 'web/modules/custom/camps/camps.module', "<?php\n\nfunction camps_cron() {\n}\n");
    $this->put($root, 'web/modules/custom/camps/camps.services.yml', "services:\n  camps.list:\n    class: Drupal\\camps\\CampList\n");
    $this->put($root, 'web/modules/custom/camps/camps.routing.yml', "camps.page:\n  path: /camps\n  defaults:\n    _controller: '\\Drupal\\camps\\CampList::count'\n");
    $this->put($root, 'web/modules/custom/camps/camps.permissions.yml', "administer camps:\n  title: 'Administer camps'\n");
    $this->put($root, 'web/modules/custom/camps/README.md', "# Camps\n\nRegistration for summer camps.\n");
    $this->put($root, 'web/modules/custom/camps/src/CampList.php', "<?php\n\nnamespace Drupal\\camps;\n\n/**\n * Lists camps.\n */\nclass CampList {\n  public function count(): int {\n    return 0;\n  }\n}\n");
    $this->put($root, 'web/modules/contrib/paragraphs/paragraphs.info.yml', "name: Paragraphs\ntype: module\ndescription: 'Paragraphs types.'\n");
    $this->put($root, 'web/modules/contrib/paragraphs/src/Entity/Paragraph.php', "<?php\n\nnamespace Drupal\\paragraphs\\Entity;\n\nclass Paragraph {\n}\n");
    $this->put($root, 'web/modules/contrib/paragraphs/README.md', "# Paragraphs\n\nTranslation support for nested paragraphs.\n");
    $this->put($root, 'config/sync/core.extension.yml', "module:\n  camps: 0\n  system: 0\ntheme:\n  olivero: 0\n");
    $this->put($root, 'droost/wiki/overview.md', "# Overview\n\nThe site sells camps.\n");
    $this->put($root, 'droost/droost.yml', "rules:\n  house:\n    - { id: HR1, rule: 'No direct database queries.', judge: seeker }\n");
  }

  /**
   * Removes a directory tree.
   *
   * @param string $dir
   *   The directory.
   */
  private static function remove(string $dir): void {
    if (!is_dir($dir)) {
      return;
    }
    $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $file) {
      if ($file instanceof \SplFileInfo) {
        $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
      }
    }
    rmdir($dir);
  }

}
