<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Baseline;

use Droost\Workflow\Baseline\FindingKey;
use Droost\Workflow\Tests\WorkflowTestCase;

/**
 * D71 §11.1: shifted lines stay inherited, edited lines are new.
 */
final class FindingKeyTest extends WorkflowTestCase {

  /**
   * Inserting lines above a finding does not change its key.
   */
  public function testShiftedLineKeepsItsKey(): void {
    $root = $this->makeRoot();
    file_put_contents($root . '/a.php', "<?php\n\n\$x = 1;\n\$y = \$x;\n");
    $before = FindingKey::of($root, 'a.php', 'Rule.One', 'Bad thing', 3);

    // Two lines inserted above: the offending line is now line 5.
    file_put_contents($root . '/a.php', "<?php\n\n// note\n// note\n\$x = 1;\n\$y = \$x;\n");
    $after = FindingKey::of($root, 'a.php', 'Rule.One', 'Bad thing', 5);

    $this->assertSame($before, $after, 'the same text on a different line is the same finding');
  }

  /**
   * Editing the offending line changes the key — the finding is new.
   */
  public function testEditedLineHasNewKey(): void {
    $root = $this->makeRoot();
    file_put_contents($root . '/a.php', "<?php\n\n\$x = 1;\n");
    $before = FindingKey::of($root, 'a.php', 'Rule.One', 'Bad thing', 3);

    file_put_contents($root . '/a.php', "<?php\n\n\$x = 2;\n");
    $after = FindingKey::of($root, 'a.php', 'Rule.One', 'Bad thing', 3);

    $this->assertNotSame($before, $after, 'a changed line is a changed finding');
  }

  /**
   * Re-indenting alone is not an edit: the text is trimmed before hashing.
   */
  public function testIndentationDoesNotChangeTheKey(): void {
    $root = $this->makeRoot();
    file_put_contents($root . '/a.php', "<?php\n\$x = 1;\n");
    $before = FindingKey::of($root, 'a.php', 'R', 'm', 2);
    file_put_contents($root . '/a.php', "<?php\n    \$x = 1;\n");
    $this->assertSame($before, FindingKey::of($root, 'a.php', 'R', 'm', 2));
  }

  /**
   * The key includes the file, the rule and the message.
   */
  public function testFileRuleAndMessageAllMatter(): void {
    $root = $this->makeRoot();
    file_put_contents($root . '/a.php', "<?php\n\$x = 1;\n");
    file_put_contents($root . '/b.php', "<?php\n\$x = 1;\n");
    $base = FindingKey::of($root, 'a.php', 'R', 'm', 2);
    $this->assertNotSame($base, FindingKey::of($root, 'b.php', 'R', 'm', 2));
    $this->assertNotSame($base, FindingKey::of($root, 'a.php', 'R2', 'm', 2));
    $this->assertNotSame($base, FindingKey::of($root, 'a.php', 'R', 'm2', 2));
  }

  /**
   * An unreadable file or line hashes as empty text, never throws.
   */
  public function testUnreadableLineIsEmptyText(): void {
    $root = $this->makeRoot();
    $this->assertSame('', FindingKey::lineText($root, 'missing.php', 3));
    file_put_contents($root . '/a.php', "<?php\n");
    $this->assertSame('', FindingKey::lineText($root, 'a.php', 99));
    $this->assertSame('', FindingKey::lineText($root, 'a.php', 0));
  }

  /**
   * Tool paths become project-relative so a clone elsewhere is the same debt.
   */
  public function testRelativeStripsTheRootAndDotSlash(): void {
    $this->assertSame('web/a.php', FindingKey::relative('/repo', '/repo/web/a.php'));
    $this->assertSame('web/a.php', FindingKey::relative('/repo/', './web/a.php'));
    $this->assertSame('/elsewhere/a.php', FindingKey::relative('/repo', '/elsewhere/a.php'));
  }

}
