<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Evidence;

use Droost\Workflow\Evidence\SubjectHasher;
use PHPUnit\Framework\TestCase;

/**
 * A subtree reached through a symlink is part of what a gate examined.
 *
 * It contributed NOTHING. `RecursiveDirectoryIterator` does not follow links,
 * and `skippedShape()` only shapes the directories it names — so a whole tree
 * behind a link was invisible to the fingerprint: not its content, not its
 * shape, not even its existence.
 *
 * A reviewer proved the consequence end to end. A gate's `paths` lever pointed
 * at `src`, where `src/Payments` linked to `../lib`; they recorded a green,
 * rewrote `authorise()` to `return TRUE;`, and `stillGreen()` answered yes over
 * a byte-identical fingerprint. The authorisation bound was gone and the record
 * said the verdict still described the code.
 *
 * That is the exact failure the whole mechanism exists to prevent — the file's
 * own docblock opens with "a green recorded at 03:00 was still a green at 03:05
 * after three files changed". The >2MB middle-edit hole is stated honestly in
 * that same docblock; this one was stated nowhere.
 */
final class SubjectHasherSymlinkTest extends TestCase {

  /**
   * Scratch roots to remove.
   *
   * @var list<string>
   */
  private array $roots = [];

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    foreach ($this->roots as $root) {
      exec('rm -rf ' . escapeshellarg($root));
    }
  }

  /**
   * A scratch directory.
   *
   * @return string
   *   Its path.
   */
  private function scratch(): string {
    $path = sys_get_temp_dir() . '/droost-hash-' . bin2hex(random_bytes(6));
    mkdir($path, 0775, TRUE);
    $this->roots[] = $path;

    return $path;
  }

  /**
   * A link inside the project is followed, so every edit behind it counts.
   *
   * The edit here is the same LENGTH as what it replaces, so nothing but
   * hashing the contents can see it — a shape descriptor of "how many files,
   * how many bytes" would report the tree unchanged.
   */
  public function testLinksInsideTheProjectAreFollowed(): void {
    $root = $this->scratch();
    mkdir($root . '/src', 0775, TRUE);
    mkdir($root . '/lib', 0775, TRUE);
    file_put_contents($root . '/lib/Charge.php', "<?php\nfunction ok(int \$c): bool { return \$c < 100; }\n");
    symlink($root . '/lib', $root . '/src/Payments');

    $before = SubjectHasher::hash($root, ['src']);
    $this->assertIsString($before, 'there is something to fingerprint');

    // Same number of bytes, opposite meaning.
    $was = "return \$c < 100;";
    $now = "return TRUE     ;";
    $this->assertSame(strlen($was), strlen($now) - 1, 'the edit is the same length bar one');
    file_put_contents($root . '/lib/Charge.php', "<?php\nfunction ok(int \$c): bool { return TRUE    ; }\n");

    $this->assertNotSame(
      $before,
      SubjectHasher::hash($root, ['src']),
      'a rewrite behind a link inside the project moves the fingerprint',
    );
  }

  /**
   * A link OUT of the project is recorded by its shape, and that is stated.
   *
   * Following it would make the fingerprint depend on bytes nobody in this
   * repository can change, which is the same objection `insideRoot()` already
   * raises against `paths: ../outside` — a green that can never expire is
   * worse than one that expires too eagerly.
   *
   * So the honest claim is narrower: the link itself, where it points, and the
   * shape of what is on the other side. An addition, a removal or a size
   * change moves the digest; a same-size rewrite out there does not, and that
   * is the trade rather than an oversight.
   */
  public function testLinksOutOfTheProjectAreRecordedByShape(): void {
    $root = $this->scratch();
    $outside = $this->scratch();
    mkdir($root . '/src', 0775, TRUE);
    file_put_contents($outside . '/Charge.php', "<?php\n");
    symlink($outside, $root . '/src/Vendored');

    $before = SubjectHasher::hash($root, ['src']);
    $this->assertIsString($before, 'the link itself is part of the subject');

    // The shape moves on an addition.
    file_put_contents($outside . '/More.php', "<?php\n");
    $grown = SubjectHasher::hash($root, ['src']);
    $this->assertNotSame($before, $grown, 'a file appearing behind the link is visible');

    // And on a size change.
    file_put_contents($outside . '/More.php', "<?php\n// and more\n");
    $this->assertNotSame($grown, SubjectHasher::hash($root, ['src']), 'so is a size change');

    // Re-pointing the link is visible too, which is the move that would
    // otherwise swap a whole subject out from under a recorded verdict.
    $elsewhere = $this->scratch();
    file_put_contents($elsewhere . '/Charge.php', "<?php\n");
    file_put_contents($elsewhere . '/More.php', "<?php\n// and more\n");
    unlink($root . '/src/Vendored');
    symlink($elsewhere, $root . '/src/Vendored');
    $this->assertNotSame(
      $grown,
      SubjectHasher::hash($root, ['src']),
      'and so is the link pointing somewhere else with identical contents',
    );
  }

  /**
   * A broken link is recorded rather than ignored.
   */
  public function testBrokenLinksAreStillPartOfTheSubject(): void {
    $root = $this->scratch();
    mkdir($root . '/src', 0775, TRUE);
    file_put_contents($root . '/src/Real.php', "<?php\n");
    $plain = SubjectHasher::hash($root, ['src']);

    symlink($root . '/nowhere', $root . '/src/Gone');
    $this->assertNotSame(
      $plain,
      SubjectHasher::hash($root, ['src']),
      'a link appearing in the tree changes what the tree is',
    );
  }

}
