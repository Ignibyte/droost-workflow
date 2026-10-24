<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Pack;

use Droost\Workflow\Config\WorkflowConfig;
use Droost\Workflow\Evidence\EvaluationReport;
use Droost\Workflow\Evidence\WorkType;
use Droost\Workflow\Knowledge\DroostCli;
use Droost\Workflow\Pack\PackManifest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Lints the pack itself — the artefact users actually get.
 *
 * Linting the materializer would prove only that copying works. The pack is
 * the product, it is prose, and nothing else in the toolchain reads prose:
 * phpcs does not lint Markdown and no test asserts a paragraph. So what CAN
 * be checked mechanically is checked here.
 *
 * What these tests deliberately do NOT claim: that the prose produces correct
 * agent behaviour. Shape is not truth. That is the fact-check critic's job at
 * inspect, and ultimately the live run at TICKET-133.
 */
class PackContentLintTest extends TestCase {

  /**
   * Files the operating system leaves behind, which pack/ never ships.
   *
   * @var list<string>
   */
  private const OS_ARTIFACTS = ['.DS_Store', 'Thumbs.db', 'desktop.ini'];

  /**
   * REQ-001: the pack and the manifest agree, in both directions.
   *
   * Both directions matters. The manifest is the sole enumerator, so a file
   * present but unlisted silently never ships — druplit's pack array carries
   * the same warning for the same reason.
   */
  public function testPackMatchesTheManifestBothWays(): void {
    foreach (PackManifest::FILES as $source => $unused) {
      $this->assertFileExists(
        $this->packDir() . '/' . $source,
        $source . ' is in the manifest but missing from pack/',
      );
    }

    $onDisk = [];
    $dir = new \RecursiveDirectoryIterator($this->packDir());
    foreach (new \RecursiveIteratorIterator($dir) as $file) {
      if (!$file instanceof \SplFileInfo || !$file->isFile()) {
        continue;
      }
      $relative = substr($file->getPathname(), strlen($this->packDir()) + 1);
      // Finder droppings are not pack content and can never be: this walks
      // the filesystem, so without this skip anyone who merely OPENS pack/ in
      // Finder fails the suite. Named explicitly rather than skipping all
      // dotfiles, because a dotfile the manifest forgot is exactly the defect
      // this test exists to catch.
      if (in_array($file->getFilename(), self::OS_ARTIFACTS, TRUE)) {
        continue;
      }
      // The lever template is written once, and the guard is never copied:
      // each runs from the pack (droost off Drupal, 2026-10-19).
      if ($relative !== PackManifest::CONFIG_TEMPLATE && $relative !== PackManifest::GUARD) {
        $onDisk[] = $relative;
      }
    }

    sort($onDisk);
    $listed = array_keys(PackManifest::FILES);
    sort($listed);
    $this->assertSame($listed, $onDisk, 'pack/ and the manifest disagree');
  }

  /**
   * REQ-005: every skill states its gates and how it degrades without a site.
   *
   * @param string $source
   *   The pack-relative path of a skill file.
   */
  #[DataProvider('phaseSkillFiles')]
  public function testEverySkillHasTheFourSections(string $source): void {
    $body = $this->read($source);

    foreach (['## Entry gate', '## Work', '## Exit gate', '## Without a site'] as $heading) {
      $this->assertStringContainsString(
        $heading,
        $body,
        $source . ' is missing the "' . $heading . '" section',
      );
    }
  }

  /**
   * Every skill carries the frontmatter Claude Code reads.
   *
   * @param string $source
   *   The pack-relative path of a skill file.
   */
  #[DataProvider('skillFiles')]
  public function testEverySkillHasFrontmatter(string $source): void {
    $body = $this->read($source);

    $this->assertStringStartsWith("---\n", $body);
    $this->assertMatchesRegularExpression('/^name: \S+/m', $body);
    $this->assertMatchesRegularExpression('/^description: \S+/m', $body);
  }

  /**
   * Every skill in the pack: the phase skills and the entry verbs.
   *
   * @return array<string, array{string}>
   *   Case name to pack-relative path.
   */
  public static function skillFiles(): array {
    $cases = [];
    foreach (array_keys(PackManifest::FILES) as $source) {
      if (str_starts_with($source, 'skills/')) {
        $cases[$source] = [$source];
      }
    }
    return $cases;
  }

  /**
   * The phase skills only — the ones that gate work and degrade without a site.
   *
   * The entry verbs (start, continue, status) are procedures, not phases:
   * they have no entry gate or exit gate of their own, and "without a site"
   * is a fact about the phases they drive.
   *
   * @return array<string, array{string}>
   *   Case name to pack-relative path.
   */
  public static function phaseSkillFiles(): array {
    $cases = [];
    foreach (['plan', 'code', 'test', 'complete'] as $phase) {
      $source = 'skills/workflow-' . $phase . '/SKILL.md';
      $cases[$source] = [$source];
    }
    return $cases;
  }

  /**
   * REQ-006: the terminal gate insists on the report.
   */
  public function testCompleteSkillRequiresTheReport(): void {
    $body = $this->read('skills/workflow-complete/SKILL.md');

    $this->assertStringContainsString('gate report', $body);
    $this->assertStringContainsString('skipped', $body);
    // The four outcomes must be distinguished, not summarised.
    $this->assertStringContainsString('tool missing', $body);
  }

  /**
   * No pack file names a droost command that does not exist, or a tool id.
   *
   * The pack is prose served to a coding agent as authoritative, and nothing
   * else lints prose: a confidently wrong command would be acted on. An early
   * draft cited "droost worker-docs", which never existed. droost is one
   * command since 2026-10-19, so every `droost <command>` the pack names must
   * be one it lists, and no pack file may name a Drupal-era tool id
   * (`droost_symbol`, `droost_wiki_write`, …) at all.
   */
  public function testNoPackFileNamesAnUnknownCommandOrRetiredTool(): void {
    $known = self::droostCommands();
    $unknown = [];
    foreach ($this->allPackFiles() as $relative) {
      $body = $this->read($relative);
      if (preg_match_all('/\bdroost_[a-z_]+/', $body, $ids) > 0) {
        foreach (array_unique($ids[0]) as $id) {
          $unknown[] = $relative . ': the tool id ' . $id;
        }
      }
      preg_match_all('/(?<![\w\/:.-])droost ((?:code|search|workflow):[a-z-]+|brief|init|migrate|doctor|guard)\b/', $body, $commands);
      foreach (array_unique($commands[1]) as $command) {
        if (!in_array($command, $known, TRUE)) {
          $unknown[] = $relative . ': droost ' . $command;
        }
      }
    }

    $this->assertSame([], $unknown, 'Pack files name commands droost does not have');
  }

  /**
   * Every command the droost binary answers, read from its own lists.
   *
   * @return list<string>
   *   `code:wiki`, `workflow:run`, `init`, …
   */
  private static function droostCommands(): array {
    $commands = array_map(static fn (array $definition): string => $definition['command'], DroostCli::definitions());
    foreach (array_keys(DroostCli::otherCommands()) as $other) {
      $commands[] = explode(' ', $other)[0];
    }
    $dispatcher = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Cli/ArgvDispatcher.php');
    preg_match_all('/^\s+\'([a-z][a-z-]*)\' => \$this->/m', $dispatcher, $verbs);
    foreach ($verbs[1] as $verb) {
      $commands[] = 'workflow:' . $verb;
    }
    $commands[] = 'guard';

    return array_values(array_unique($commands));
  }

  /**
   * The pack says what droost reads, and what it does not.
   *
   * The droost command reads files and its code index, with no site
   * (2026-10-19). The running site — content, active configuration, rendered
   * pages — is not
   * droost's, and a pack that implied otherwise would have an agent report
   * the site checked when only its files were.
   */
  public function testThePackSaysDroostReadsFilesNotTheRunningSite(): void {
    $partial = $this->read('partials/droost-usage.md');
    $this->assertStringContainsString('boots no Drupal and never calls drush', $partial);
    $this->assertStringContainsString('The running site is not droost\'s', $partial);
    foreach (['droost code:wiki', 'droost code:tree', 'droost search:arch'] as $command) {
      $this->assertStringContainsString($command, $partial, 'the partial names the three knowledge commands plan requires');
    }
  }

  /**
   * The complete skill says how a wiki page is written, and that droost reads.
   *
   * `droost code:wiki` reads the wiki and writes nothing; a page is a file the
   * agent writes, and `droost code:wiki stamp` records its sources' hashes.
   * An earlier draft called the read tools "where durable knowledge
   * belongs", which invites an agent to attempt a write, find none, and
   * record the wiki as updated.
   */
  public function testTheWikiIsReadByDroostAndWrittenByTheAgent(): void {
    $body = $this->read('skills/workflow-complete/SKILL.md');

    $this->assertStringContainsString('read-only', strtolower($body));
    $this->assertStringContainsString('droost code:wiki stamp', $body);
    $this->assertStringContainsString('xxh3:0000000000000000', $body, 'the placeholder hash a new page starts from');
    $this->assertStringNotContainsString('where durable knowledge belongs', $body);
  }

  /**
   * The pack describes the real engine, not the pre-engine era.
   *
   * The inversion of a lint that once pointed the other way: before
   * TICKET-131/132 shipped run state and pair mode, the pack correctly said
   * "nothing writes run.json / count your own attempts / pair is not
   * built" — and then the engine landed and the prose was never un-said,
   * leaving the canonical pack contradicting the shipped behaviour. These
   * phrases are now forbidden pack-wide, so the claim cannot regress
   * without this failing.
   */
  public function testPackDescribesTheRealEngine(): void {
    $stale = [
      'nothing writes',
      'has no producer',
      'does not exist yet',
      'count your own attempts',
      'no resume',
      'transport is not built',
      'nothing counts them for you',
    ];

    foreach ($this->allPackFiles() as $relative) {
      $body = strtolower($this->read($relative));
      foreach ($stale as $phrase) {
        $this->assertStringNotContainsString($phrase, $body, sprintf(
          '%s still carries pre-engine prose ("%s")',
          $relative,
          $phrase,
        ));
      }
    }

    // And the positive half: the entry and status commands must send an
    // agent to the engine's record, by name.
    $entry = $this->read('commands/droost/workflow/continue.md');
    $this->assertStringContainsString('droost/droost-workflow/run.json', $entry);
    $this->assertStringContainsString('resolved_gates', $entry);
    $status = $this->read('commands/droost/workflow/status.md');
    $this->assertStringContainsString('droost/droost-workflow/run.json', $status);
    $this->assertStringContainsString('phase_gates', $status);
    // Start opens a run: it guards on run.json (refuse to clobber) and hands
    // off to continue. Its whole reason to exist is that declare-browser
    // records against a run the first `run` creates — so it must not tell an
    // agent to declare the browser BEFORE that first invocation.
    $start = $this->read('commands/droost/workflow/start.md');
    $this->assertStringContainsString('droost/droost-workflow/run.json', $start);
    $this->assertStringContainsString('/droost:workflow:continue', $start);
    $this->assertDoesNotMatchRegularExpression(
      '~before the first `?run`?, .{0,40}declare~i',
      $start,
      'start must not resurrect the impossible declare-before-run order',
    );
  }

  /**
   * The retired command names do not linger anywhere in the pack.
   *
   * 0.4 renamed the entry to /droost:workflow:continue and DELETED the
   * /workflow:run pointer (a pointer file is a second source of truth). A
   * pack file still steering an agent at the old names would send it to
   * "unknown command" — except the entry command's own one-line note saying
   * what it used to be called, which is how muscle memory finds the rename.
   */
  public function testRetiredCommandNamesDoNotLinger(): void {
    foreach ($this->allPackFiles() as $relative) {
      $body = $this->read($relative);
      if ($relative === 'commands/droost/workflow/continue.md') {
        continue;
      }
      // Token-bounded: "/droost-work" must not swallow the legitimate
      // "vendor/bin/droost-workflow", and "/workflow:status" must not
      // swallow "droost:workflow:status".
      $this->assertDoesNotMatchRegularExpression(
        '~/droost-work(?![a-z-])~',
        $body,
        $relative,
      );
      $this->assertDoesNotMatchRegularExpression(
        '~(?<![a-z:])/workflow:(run|status)~',
        $body,
        $relative,
      );
    }
  }

  /**
   * Installed paths are named as they exist after installation.
   */
  public function testPackReferencesInstalledPaths(): void {
    $body = $this->read('commands/droost/workflow/continue.md');

    if (str_contains($body, 'droost-usage.md')) {
      $this->assertStringContainsString(
        '.claude/partials/droost-usage.md',
        $body,
        'continue.md points at the pack-relative path, not the installed one',
      );
    }
  }

  /**
   * The shipped lever file parses, and resolves to what it claims.
   *
   * It must name its preset explicitly: an unnamed preset resolves to
   * `factory`, so a default file that omitted it would hand every new repo
   * the strict set while its own comments described the gentle one.
   */
  public function testShippedLeverFileResolvesToCustom(): void {
    $root = sys_get_temp_dir() . '/dwf-pack-lint-' . bin2hex(random_bytes(6));
    mkdir($root, 0755, TRUE);
    mkdir($root . '/droost', 0755, TRUE);
    copy(
      $this->packDir() . '/' . PackManifest::CONFIG_TEMPLATE,
      $root . '/' . PackManifest::CONFIG_FILE,
    );

    try {
      $config = WorkflowConfig::load($root);
      $this->assertSame('custom', $config->preset);
      $this->assertSame(
        WorkflowConfig::fromArray(['preset' => 'custom'], 'x')->resolvedGates(),
        $config->resolvedGates(),
        'The shipped lever file no longer matches the custom preset',
      );
      $this->assertSame(2, $config->maxGateRetries);
    }
    finally {
      unlink($root . '/' . PackManifest::CONFIG_FILE);
      rmdir($root . '/droost');
      rmdir($root);
    }
  }

  /**
   * Every pack file, including the lever file.
   *
   * @return list<string>
   *   Pack-relative paths.
   */
  private function allPackFiles(): array {
    return [...array_keys(PackManifest::FILES), PackManifest::CONFIG_TEMPLATE];
  }

  /**
   * The pack source directory.
   *
   * @return string
   *   The absolute path.
   */
  private function packDir(): string {
    return dirname(__DIR__, 2) . '/' . PackManifest::SOURCE_DIR;
  }

  /**
   * Reads a pack file.
   *
   * @param string $source
   *   The pack-relative path.
   *
   * @return string
   *   The contents.
   */
  private function read(string $source): string {
    $body = file_get_contents($this->packDir() . '/' . $source);
    $this->assertIsString($body, $source . ' is unreadable');
    return $body;
  }

  /**
   * The plan brief names every work type, and invents none.
   *
   * A mechanism the briefs do not mention is a trap: `--type` blocks a phase
   * when the gates its kind of work rests on measured nothing, and until this
   * was checked NO brief named the flag or a single one of its values. An agent
   * could not have passed a valid type without guessing it.
   *
   * Pinned in both directions, because both failures are silent. A type the
   * brief omits is one no agent will use; a type the brief invents throws on
   * the command line and teaches the agent to stop passing the flag at all.
   */

  /**
   * The plan brief names every tool the gate counts as a knowledge call.
   *
   * `grounding_check`'s ledger half fails a run whose tool-call ledger holds no
   * KNOWLEDGE_TOOLS call. The brief's "ask the site" list once named only six
   * of the counted tools and omitted the ones it complained, three paragraphs
   * up, were never called — `droost_symbol`, `droost_graph`,
   * `droost_deprecations` — plus `droost_search`. An agent following the list
   * to the letter could satisfy every citation and still fail the gate it was
   * never told existed. The constant is the contract; the brief has to match.
   */
  public function testThePlanBriefNamesEveryKnowledgeTool(): void {
    $brief = (string) file_get_contents(dirname(__DIR__, 2) . '/pack/skills/workflow-plan/SKILL.md');

    foreach (EvaluationReport::KNOWLEDGE_TOOLS as $tool) {
      $this->assertStringContainsString(
        '`' . $tool . '`',
        $brief,
        sprintf('the plan brief names the "%s" knowledge tool the gate counts', $tool),
      );
    }
  }

  /**
   * The plan brief names every work type the store knows, and invents none.
   */
  public function testThePlanBriefNamesEveryWorkTypeAndInventsNone(): void {
    $brief = (string) file_get_contents(dirname(__DIR__, 2) . '/pack/skills/workflow-plan/SKILL.md');

    foreach (WorkType::names() as $type) {
      $this->assertStringContainsString(
        '`' . $type . '`',
        $brief,
        sprintf('the plan brief names the "%s" work type', $type),
      );
    }

    preg_match_all('/\| `([a-z_]+)` \|/', $brief, $matches);
    foreach (array_unique($matches[1]) as $claimed) {
      $this->assertNotNull(
        WorkType::tryFrom($claimed),
        sprintf('the plan brief names "%s" as a work type and no such type exists', $claimed),
      );
    }
  }

  /**
   * The lever file names every tree the wall actually walls (F-8).
   *
   * The guard matches `(modules|themes|profiles)/custom/` and the lever file
   * documented two of the three, so an operator reading it would have believed
   * an install profile — PHP that builds the entire site — could be written
   * with no run, while the module beside it could not. The code was right and
   * the text was wrong, which is the harder kind to notice: nothing fails.
   *
   * Pinned against the guard's own pattern rather than a hardcoded list, so a
   * fourth tree cannot be added to one and forgotten in the other.
   */
  public function testTheLeverFileNamesEveryTreeRequireRunWalls(): void {
    $guard = (string) file_get_contents($this->packDir() . '/hooks/droost-workflow-guard.php');
    $this->assertSame(
      1,
      preg_match('#\(\^\|/\)\(([a-z|]+)\)/custom/#', $guard, $m),
      'the wall pattern moved; this test needs to follow it',
    );
    $trees = explode('|', $m[1]);
    $this->assertContains('profiles', $trees, 'profiles/custom is the one that was missing');

    $levers = (string) file_get_contents($this->packDir() . '/' . PackManifest::CONFIG_TEMPLATE);
    $section = strstr($levers, '# require_run:');
    $this->assertIsString($section, 'the lever file must document require_run');
    $section = substr($section, 0, (int) strpos($section, "\nrequire_run:"));
    foreach ($trees as $tree) {
      $this->assertStringContainsString(
        $tree . '/custom',
        $section,
        $tree . '/custom is walled but the lever file does not say so',
      );
    }
  }

  /**
   * The shipped template carries every section the generator emits (F-9).
   *
   * The template had `1 2 3 4 4a 4b 5 6 7 8` while the generator emitted `4c`,
   * `4d` and `7a` as well — so an operator filling the form by hand produced a
   * document missing the transcripts, the adversarial read and the enforcement
   * evidence, and had no way to know. Three descriptions of this document
   * disagreed at once; this is the one that fails when they drift.
   *
   * Read out of both files rather than listed here, so a new section is caught
   * by adding it to the generator alone.
   */
  public function testTheTemplateCarriesEverySectionTheGeneratorEmits(): void {
    $generator = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Evidence/EvaluationReport.php');
    preg_match_all('/"## ([0-9]+[a-z]?)\. /', $generator, $m);
    $emitted = array_values(array_unique($m[1]));
    $this->assertNotSame([], $emitted, 'no sections found; the generator changed shape');

    $template = (string) file_get_contents($this->packDir() . '/templates/evaluation.md');
    preg_match_all('/^## ([0-9]+[a-z]?)\. /m', $template, $tm);
    $carried = array_values(array_unique($tm[1]));

    $missing = array_values(array_diff($emitted, $carried));
    $this->assertSame(
      [],
      $missing,
      'the template is behind its generator; missing: ' . implode(', ', $missing),
    );
  }

  /**
   * The plan skill says a low run plans no wiki write.
   *
   * P6 run 19's plan promised a wiki page through droost_wiki_write; at low
   * the complete skill forbade the write, grounding_check held the promise as
   * a tool never called, and the run lost its retry. Only the complete skill
   * said what a low run writes, and the plan is where the promise is made.
   */
  public function testThePlanSkillSaysLowPlansNoWikiWrite(): void {
    $plan = file_get_contents(__DIR__ . '/../../pack/skills/workflow-plan/SKILL.md');
    $complete = file_get_contents(__DIR__ . '/../../pack/skills/workflow-complete/SKILL.md');
    $this->assertIsString($plan);
    $this->assertIsString($complete);

    // The complete skill says a low run writes no wiki; the plan skill must
    // say it too, where the promise is made (F-137).
    $this->assertStringContainsString('there is NO wiki step', $complete, 'the rule the plan must match');
    $this->assertStringContainsString('Plan only the capture your level makes', $plan);
    $this->assertMatchesRegularExpression('/At\s+\*\*`low`\*\*\s+complete writes\s+\*\*no wiki pages\*\*/', $plan, 'the plan skill must say a low run writes no wiki');
    $this->assertMatchesRegularExpression('/planning a wiki page is a\s+promise the level forbids you to keep/', $plan);
  }

  /**
   * The code skill must tell the agent to CLOSE findings it fixed.
   *
   * F-44: a finding's status only ever moves when a later report marks it,
   * and with `seekers: { rounds: 1 }` there is no later inspection — so the
   * evidence store kept every finding at `open` forever and a run that fixed
   * four mediums read, permanently, as a run that shipped four.
   *
   * The remedy is cheap and it is text: marking a row `resolved` costs a
   * table edit, not another subagent pass over the diff. This pins that the
   * instruction says so, because the whole reason F-44 existed is that
   * nothing told the agent the two were different.
   */
  public function testTheCodeSkillSaysToCloseFixedFindings(): void {
    $skill = file_get_contents(__DIR__ . '/../../pack/skills/workflow-code/SKILL.md');
    $this->assertIsString($skill);

    $this->assertStringContainsString(
      'that is not a re-inspection',
      $skill,
      'closing a fixed finding must be distinguished from re-inspecting',
    );
    foreach (['`open`', '`resolved`', '`carried: <reason>`'] as $status) {
      $this->assertStringContainsString(
        $status,
        $skill,
        'the status vocabulary must be stated where the agent reads it',
      );
    }
    $this->assertStringContainsString(
      'No subagent, no second read of the diff',
      $skill,
      'the cost must be stated, or the instruction reads as "run it again"',
    );
  }

  /**
   * The evaluation template's knowledge row lists exactly the counted tools.
   *
   * The row names them without the `droost_` prefix, so the unknown-tool lint,
   * which matches `droost_[a-z_]+`, cannot see it: the row went on listing a
   * tool droost had removed after the constant had already dropped it. The
   * constant is the contract, and the template has to match it both ways.
   */
  public function testTheEvaluationTemplatesKnowledgeRowMatchesTheCountedTools(): void {
    $template = file_get_contents(__DIR__ . '/../../pack/templates/evaluation.md');
    $this->assertIsString($template);
    $this->assertSame(1, preg_match('/^\| \*\*Knowledge calls\*\* \(([^)]*)\)/m', $template, $row), 'the template has a Knowledge calls row');

    preg_match_all('/`([a-z_:]+)`/', $row[1], $names);
    $listed = $names[1];
    sort($listed);
    $counted = EvaluationReport::KNOWLEDGE_TOOLS;
    sort($counted);

    $this->assertSame($counted, $listed, 'the template lists every counted knowledge tool, and nothing else');
  }

}
