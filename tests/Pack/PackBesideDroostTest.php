<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Pack;

use Droost\Workflow\Pack\PackManifest;
use Droost\Workflow\Pack\PackMaterializer;
use Droost\Workflow\Tests\WorkflowTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * The pack installs beside droost's own commands and skills.
 *
 * On a Drupal site droost's installer writes first: its commands into
 * `.claude/commands/droost/` (configure, init, upgrade) and its skills into
 * `.claude/skills/`, with no pack sentinel. A pack file put directly in one
 * of those (the intake's pointer at `commands/droost/intake.md`, 0.14's
 * first cut) made the whole directory the pack's, the materializer refused
 * to overwrite what it had not created, and NOTHING of the pack was
 * written: no workflow skill, no guard. An empty test project never has
 * droost's files, so every pack test passed.
 */
#[CoversClass(PackMaterializer::class)]
final class PackBesideDroostTest extends WorkflowTestCase {

  /**
   * Droost's directories, as `drush droost:install` leaves them.
   */
  public function testThePackInstallsBesideDroostsFiles(): void {
    $root = $this->makeRoot();
    mkdir($root . '/.claude/commands/droost', 0755, TRUE);
    mkdir($root . '/.claude/skills/documenting-changes', 0755, TRUE);
    foreach (['configure', 'init', 'upgrade'] as $command) {
      file_put_contents($root . '/.claude/commands/droost/' . $command . '.md', "# droost {$command}\n");
    }
    file_put_contents($root . '/.claude/skills/documenting-changes/SKILL.md', "# droost's\n");

    $report = (new PackMaterializer())->init($root);

    foreach (PackManifest::FILES as $destination) {
      $this->assertFileExists($root . '/' . $destination, $destination . ' is written beside droost\'s files');
    }
    $this->assertContains('.claude/skills/intake/SKILL.md', $report->written);
    foreach (['configure', 'init', 'upgrade'] as $command) {
      $this->assertStringEqualsFile($root . '/.claude/commands/droost/' . $command . '.md', "# droost {$command}\n", 'droost\'s own command is left as it was');
    }
  }

  /**
   * No pack file lands directly in a directory droost writes.
   */
  public function testNoPackFileSitsInDroostsDirectories(): void {
    foreach (PackManifest::FILES as $destination) {
      $this->assertNotSame('.claude/commands/droost', dirname($destination), $destination . ' would make droost\'s command directory the pack\'s');
      $this->assertNotSame('.claude/skills', dirname($destination), $destination . ' would make the skills directory the pack\'s');
    }
  }

}
