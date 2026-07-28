<?php

declare(strict_types=1);

namespace Drupal\Tests\droost_workflow\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Shared scaffolding for tests that need a project directory on disk.
 *
 * The package's whole contract is about files — a lever file at a repo root
 * and run state beside it — so most tests need a real directory rather than a
 * mock filesystem.
 */
abstract class WorkflowTestCase extends TestCase {

  /**
   * Temporary roots created by this test, removed on teardown.
   *
   * @var list<string>
   */
  private array $roots = [];

  /**
   * Creates an empty project root.
   *
   * @return string
   *   The absolute path.
   */
  protected function makeRoot(): string {
    $root = sys_get_temp_dir() . '/dwf-test-' . bin2hex(random_bytes(8));
    if (!mkdir($root, 0755, TRUE) && !is_dir($root)) {
      $this->fail('Could not create a temporary project root.');
    }
    $this->roots[] = $root;
    return $root;
  }

  /**
   * Creates a project root holding a lever file.
   *
   * @param string $yaml
   *   The file's contents.
   *
   * @return string
   *   The absolute path to the root.
   */
  protected function makeRootWithConfig(string $yaml): string {
    $root = $this->makeRoot();
    file_put_contents($root . '/droost.workflow.yml', $yaml);
    return $root;
  }

  /**
   * The names of any temporary files left in a run's state directory.
   *
   * @param string $root
   *   The project root.
   *
   * @return list<string>
   *   Basenames of anything matching *.tmp.
   */
  protected function tempResidue(string $root): array {
    $found = glob($root . '/.droost-workflow/*.tmp');
    return array_map(basename(...), $found === FALSE ? [] : $found);
  }

  /**
   * Removes everything the test created.
   */
  protected function tearDown(): void {
    foreach ($this->roots as $root) {
      $this->removeTree($root);
    }
    $this->roots = [];
    parent::tearDown();
  }

  /**
   * Recursively deletes a directory.
   *
   * @param string $path
   *   The path to remove.
   */
  private function removeTree(string $path): void {
    if (is_link($path)) {
      unlink($path);
      return;
    }
    if (!is_dir($path)) {
      if (is_file($path)) {
        unlink($path);
      }
      return;
    }
    // A test may have made a directory read-only on purpose.
    @chmod($path, 0755);
    $entries = scandir($path);
    foreach ($entries === FALSE ? [] : $entries as $entry) {
      if ($entry !== '.' && $entry !== '..') {
        $this->removeTree($path . '/' . $entry);
      }
    }
    @rmdir($path);
  }

}
