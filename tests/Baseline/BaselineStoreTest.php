<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Baseline;

use Droost\Workflow\Baseline\BaselineError;
use Droost\Workflow\Baseline\BaselineManifest;
use Droost\Workflow\Baseline\BaselineStore;
use Droost\Workflow\Tests\WorkflowTestCase;

/**
 * Where a baseline lives, and that its hash moves when anything under it does.
 */
final class BaselineStoreTest extends WorkflowTestCase {

  /**
   * A project without a baseline reads as absent, not as an error.
   */
  public function testAbsentBaselineIsNullEverywhere(): void {
    $root = $this->makeRoot();
    $this->assertFalse(BaselineStore::exists($root));
    $this->assertNull(BaselineStore::load($root));
    $this->assertNull(BaselineStore::hash($root));
    $this->assertSame('none', BaselineStore::short(NULL));
  }

  /**
   * Write, then read back: the manifest and the per-gate files round-trip.
   */
  public function testWriteAndLoadRoundTrip(): void {
    $root = $this->makeRoot();
    $manifest = new BaselineManifest(
      '2026-09-08T10:00:00+00:00',
      'abc1234',
      'max',
      [
        'phpcs' => ['file' => 'phpcs.json', 'count' => 2, 'sha256' => 'x'],
        'prettier' => ['file' => 'prettier.txt', 'count' => 1, 'sha256' => 'y'],
        'coverage' => ['file' => 'metrics.json', 'count' => 34, 'sha256' => 'z'],
        'config_clean' => ['file' => 'config_clean.json', 'count' => 1, 'sha256' => 'w'],
      ],
      [['at' => '2026-09-09T00:00:00+00:00', 'reason' => 'legacy import', 'added' => ['phpcs' => 1]]],
    );
    BaselineStore::write($root, $manifest, [
      'phpcs.json' => json_encode([
        'v' => 1,
        'gate' => 'phpcs',
        'findings' => [
        ['key' => 'k1', 'file' => 'a.php', 'rule' => 'R', 'message' => 'm', 'line' => 3],
        ['key' => 'k2', 'file' => 'b.php', 'rule' => 'R', 'message' => 'm', 'line' => 9],
        ],
      ], JSON_THROW_ON_ERROR),
      'prettier.txt' => "# unformatted at adoption\nweb/themes/custom/t/js/a.js\n",
      'metrics.json' => json_encode(['coverage' => 34.2, 'msi' => NULL], JSON_THROW_ON_ERROR),
      'config_clean.json' => json_encode(['drift' => ['system.site']], JSON_THROW_ON_ERROR),
    ]);

    $this->assertTrue(BaselineStore::exists($root));
    $this->assertFileExists($root . '/droost/baseline/baseline.json');
    $loaded = BaselineStore::load($root);
    $this->assertNotNull($loaded);
    $this->assertSame('abc1234', $loaded->manifest->generatedCommit);
    $this->assertSame('max', $loaded->manifest->preset);
    $this->assertSame(2, $loaded->manifest->count('phpcs'));
    $this->assertNull($loaded->manifest->count('eslint'));
    $this->assertCount(1, $loaded->manifest->grown);
    $this->assertSame(['phpcs' => 1], $loaded->manifest->grown[0]['added']);

    $this->assertTrue($loaded->has('phpcs'));
    $this->assertFalse($loaded->has('eslint'));
    $this->assertTrue($loaded->inherits('phpcs', 'k1'));
    $this->assertFalse($loaded->inherits('phpcs', 'k3'));
    $this->assertSame(['web/themes/custom/t/js/a.js'], $loaded->prettierFiles(), 'comment lines are not files');
    $this->assertSame(34.2, $loaded->metric('coverage'));
    $this->assertNull($loaded->metric('msi'));
    $this->assertSame(['system.site'], $loaded->configDrift());
    $this->assertNull($loaded->phpstanWrapper(), 'no phpstan baseline was written');
    $this->assertSame(0, $loaded->phpstanInheritedCount());
  }

  /**
   * The hash changes when a listed file changes, and is stable otherwise.
   */
  public function testHashCoversTheManifestAndEveryListedFile(): void {
    $root = $this->makeRoot();
    $manifest = new BaselineManifest('2026-09-08T10:00:00+00:00', NULL, 'high', [
      'phpcs' => ['file' => 'phpcs.json', 'count' => 0, 'sha256' => 'x'],
    ]);
    BaselineStore::write($root, $manifest, ['phpcs.json' => '{"v":1,"findings":[]}']);
    $first = BaselineStore::hash($root);
    $this->assertNotNull($first);
    $this->assertSame($first, BaselineStore::hash($root), 'rereading does not move the hash');
    $this->assertSame(12, strlen(BaselineStore::short($first)));

    file_put_contents($root . '/droost/baseline/phpcs.json', '{"v":1,"findings":[{"key":"sneaky"}]}');
    $second = BaselineStore::hash($root);
    $this->assertNotSame($first, $second, 'editing a listed file moves the hash');

    // A file the manifest does not list is outside the record — and the
    // writer removes such files anyway.
    file_put_contents($root . '/droost/baseline/stray.txt', 'x');
    $this->assertSame($second, BaselineStore::hash($root), 'an unlisted file is not part of the baseline');
    BaselineStore::write($root, $manifest, ['phpcs.json' => '{"v":1,"findings":[]}']);
    $this->assertFileDoesNotExist($root . '/droost/baseline/stray.txt', 'a rewrite leaves exactly what the manifest lists');
    $this->assertSame($first, BaselineStore::hash($root), 'the same content hashes the same');
  }

  /**
   * A manifest that is not one is refused by name, and still hashes.
   */
  public function testCorruptManifestIsRefusedByNameButStillHashes(): void {
    $root = $this->makeRoot();
    mkdir($root . '/droost/baseline', 0775, TRUE);
    file_put_contents($root . '/droost/baseline/baseline.json', '{"v": 99}');
    $this->assertNotNull(BaselineStore::hash($root), 'a corrupt manifest is a fact about the disk, never "absent"');
    $this->expectException(BaselineError::class);
    $this->expectExceptionMessageMatches('/schema version 99/');
    BaselineStore::load($root);
  }

  /**
   * Invalid JSON is corrupt too, with the parser's reason.
   */
  public function testInvalidJsonIsCorrupt(): void {
    $root = $this->makeRoot();
    mkdir($root . '/droost/baseline', 0775, TRUE);
    file_put_contents($root . '/droost/baseline/baseline.json', 'not json');
    $this->expectException(BaselineError::class);
    $this->expectExceptionMessageMatches('/invalid JSON/');
    BaselineStore::load($root);
  }

}
