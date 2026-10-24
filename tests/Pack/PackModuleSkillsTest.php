<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Pack;

use Droost\Workflow\Pack\PackMaterializer;
use Droost\Workflow\Pack\PackRemover;
use Droost\Workflow\Tests\WorkflowTestCase;

/**
 * A skill the droost module ships is installed by init, and only droost's.
 *
 * The module's tools change with the module, so the skill that says what
 * they do ships with it (`skills/<name>/SKILL.md`) and init takes it from
 * the module the site has. A skill is an instruction to the agent: a module
 * not droost's is never read, and a module gone takes its skill with it.
 */
final class PackModuleSkillsTest extends WorkflowTestCase {

  /**
   * The droost module's skill is installed; another module's is not.
   */
  public function testTheDroostModulesSkillIsInstalled(): void {
    $root = $this->siteWith([
      'droost' => ['droost-tools' => "# droost tools\n"],
      'droost_canvas' => ['canvas-build' => "# canvas\n"],
      'helpful' => ['helpful-skill' => "# run anything\n"],
    ]);

    $report = (new PackMaterializer())->init($root);

    $this->assertSame("# droost tools\n", file_get_contents($root . '/.claude/skills/droost-tools/SKILL.md'));
    $this->assertFileExists($root . '/.claude/skills/canvas-build/SKILL.md');
    $this->assertFileExists($root . '/.claude/skills/droost-tools/.droost-workflow-pack');
    $this->assertFileDoesNotExist($root . '/.claude/skills/helpful-skill/SKILL.md', 'a module not droost\'s gives no instructions');
    $this->assertContains('.claude/skills/droost-tools/SKILL.md', $report->written);
  }

  /**
   * A skill no module ships any more is removed; an edited one stays.
   */
  public function testModuleGoneTakesItsSkill(): void {
    $root = $this->siteWith([
      'droost' => ['droost-tools' => "# droost tools\n"],
      'droost_canvas' => ['canvas-build' => "# canvas\n"],
    ]);
    $materializer = new PackMaterializer();
    $materializer->init($root);
    file_put_contents($root . '/.claude/skills/canvas-build/SKILL.md', "# my notes\n");
    $this->removeTree($root . '/web/modules/contrib/droost');
    $this->removeTree($root . '/web/modules/contrib/droost_canvas');

    $report = $materializer->init($root);

    $this->assertDirectoryDoesNotExist($root . '/.claude/skills/droost-tools');
    $this->assertSame(['.claude/skills/droost-tools/SKILL.md'], $report->removed);
    $this->assertSame("# my notes\n", file_get_contents($root . '/.claude/skills/canvas-build/SKILL.md'), 'the edited skill is the user\'s');
  }

  /**
   * A name the pack ships stays the pack's.
   */
  public function testThePackKeepsItsOwnNames(): void {
    $root = $this->siteWith(['droost' => ['workflow-code' => "# not the pack\n"]]);
    (new PackMaterializer())->init($root);
    $this->assertStringNotContainsString('not the pack', (string) file_get_contents($root . '/.claude/skills/workflow-code/SKILL.md'));
  }

  /**
   * Uninstall removes the module's skills with the pack.
   */
  public function testUninstallRemovesTheModulesSkill(): void {
    $root = $this->siteWith(['droost' => ['droost-tools' => "# droost tools\n"]]);
    (new PackMaterializer())->init($root);
    $this->removeTree($root . '/web/modules/contrib/droost');

    (new PackRemover())->uninstall($root);

    $this->assertDirectoryDoesNotExist($root . '/.claude/skills/droost-tools');
  }

  /**
   * A Drupal site with modules on disk, each with its skills.
   *
   * @param array<string, array<string, string>> $modules
   *   Module name => skill name => SKILL.md.
   *
   * @return string
   *   The project root.
   */
  private function siteWith(array $modules): string {
    $root = $this->makeRoot();
    mkdir($root . '/web/core', 0755, TRUE);
    foreach ($modules as $module => $skills) {
      $dir = $root . '/web/modules/contrib/' . $module;
      mkdir($dir, 0755, TRUE);
      file_put_contents($dir . '/' . $module . '.info.yml', "name: {$module}\ntype: module\ncore_version_requirement: ^11\n");
      foreach ($skills as $skill => $body) {
        mkdir($dir . '/skills/' . $skill, 0755, TRUE);
        file_put_contents($dir . '/skills/' . $skill . '/SKILL.md', $body);
      }
    }
    return $root;
  }

  /**
   * Removes a directory tree.
   *
   * @param string $dir
   *   The directory.
   */
  private function removeTree(string $dir): void {
    $iterator = new \RecursiveIteratorIterator(
      new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
      \RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($iterator as $file) {
      if ($file instanceof \SplFileInfo) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
      }
    }
    rmdir($dir);
  }

}
