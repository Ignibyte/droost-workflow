<?php

declare(strict_types=1);

namespace Droost\Workflow\Knowledge;

use Droost\Engine\Search\Graph\CodeGraphStorageInterface;
use Droost\Engine\Search\Graph\SqliteCodeGraphStorage;
use Droost\Engine\Site\ScopeClassifier;
use Droost\Engine\Support\CoreVersion;
use Droost\Engine\Wiki\BundleReader;
use Droost\Engine\Wiki\Okf\FrontmatterParser;
use Droost\Workflow\Config\StoragePaths;

/**
 * What the knowledge commands read: the project's files and its code index.
 *
 * Built from the project root alone, with no Drupal: the index droost wrote
 * (`droost code:index`), the wiki's pages, the extensions on disk and the
 * configuration last exported.
 */
final class Site {

  /**
   * The index's connection, once opened.
   */
  private ?\PDO $pdo = NULL;

  /**
   * The code graph, once opened.
   */
  private ?CodeGraphStorageInterface $opened = NULL;

  /**
   * The inventory, once built.
   */
  private ?ExtensionInventory $inventory = NULL;

  /**
   * Constructs a Site.
   *
   * @param string $projectRoot
   *   The project root, absolute.
   * @param string $docroot
   *   Drupal's docroot, absolute.
   * @param string $indexPath
   *   The code index file.
   * @param string $wikiDir
   *   The wiki's directory, absolute.
   * @param string $coreMajor
   *   Drupal core's major version, or '' when unknown.
   * @param \Droost\Workflow\Knowledge\ModuleDocReader $docs
   *   The module documentation reader.
   * @param \Droost\Workflow\Knowledge\KnowledgeSettings $settings
   *   What the project configures.
   * @param string $coreVersion
   *   Drupal core's full version, or '' when unknown.
   */
  public function __construct(
    public readonly string $projectRoot,
    public readonly string $docroot,
    public readonly string $indexPath,
    public readonly string $wikiDir,
    public readonly string $coreMajor,
    public readonly ModuleDocReader $docs,
    public readonly KnowledgeSettings $settings,
    public readonly string $coreVersion = '',
  ) {}

  /**
   * The site a project root holds, read from its files.
   *
   * @param string $projectRoot
   *   The project root, absolute.
   *
   * @return self
   *   The site.
   */
  public static function fromProjectRoot(string $projectRoot): self {
    $root = rtrim($projectRoot, '/');
    $settings = KnowledgeSettings::fromProjectRoot($root);
    $docroot = $settings->docroot;
    $version = self::coreVersionOf($root, $docroot);
    return new self(
      $root,
      $docroot,
      StoragePaths::index($root),
      $settings->wikiDir(),
      $version === '' ? '' : CoreVersion::major($version),
      new ModuleDocReader($docroot, new FilesystemModuleLocator($docroot), ScopeClassifier::forAppRoot($docroot)),
      $settings,
      $version,
    );
  }

  /**
   * The index's connection, read-only, or NULL when there is no index.
   *
   * Never created here: a reader that made an empty index would answer
   * "nothing found" where the truth is "nothing indexed".
   *
   * @return \PDO|null
   *   The connection.
   */
  public function pdo(): ?\PDO {
    if ($this->pdo !== NULL) {
      return $this->pdo;
    }
    if (!is_file($this->indexPath) || !in_array('sqlite', \PDO::getAvailableDrivers(), TRUE)) {
      return NULL;
    }
    try {
      $pdo = new \PDO('sqlite:' . $this->indexPath, NULL, NULL, [
        \PDO::SQLITE_ATTR_OPEN_FLAGS => \PDO::SQLITE_OPEN_READONLY,
        \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
      ]);
    }
    catch (\PDOException) {
      return NULL;
    }
    return $this->pdo = $pdo;
  }

  /**
   * The code graph, or NULL when there is no index to read.
   *
   * @return \Droost\Engine\Search\Graph\CodeGraphStorageInterface|null
   *   The graph; check isReady() before trusting an empty answer.
   */
  public function graph(): ?CodeGraphStorageInterface {
    if ($this->opened !== NULL) {
      return $this->opened;
    }
    $pdo = $this->pdo();
    return $pdo === NULL ? NULL : $this->opened = new SqliteCodeGraphStorage($pdo);
  }

  /**
   * The wiki bundle's reader.
   *
   * @return \Droost\Engine\Wiki\BundleReader
   *   The reader.
   */
  public function wiki(): BundleReader {
    return new BundleReader(new WikiBundle($this->wikiDir), new FrontmatterParser());
  }

  /**
   * The installed modules and themes.
   *
   * @return \Droost\Workflow\Knowledge\ExtensionInventory
   *   The inventory.
   */
  public function inventory(): ExtensionInventory {
    return $this->inventory ??= new ExtensionInventory($this->settings);
  }

  /**
   * The deprecation ledger for this site's core.
   *
   * @return \Droost\Workflow\Knowledge\DeprecationLedger
   *   The ledger.
   */
  public function deprecations(): DeprecationLedger {
    return new DeprecationLedger($this->coreMajor);
  }

  /**
   * Drupal core's version, from Composer's record or core itself.
   *
   * @param string $root
   *   The project root.
   * @param string $docroot
   *   The docroot.
   *
   * @return string
   *   The version, or '' when neither says.
   */
  public static function coreVersionOf(string $root, string $docroot): string {
    foreach (['/composer.lock', '/vendor/composer/installed.json'] as $file) {
      $raw = @file_get_contents($root . $file);
      $data = is_string($raw) ? json_decode($raw, TRUE) : NULL;
      $packages = is_array($data) ? ($data['packages'] ?? $data) : [];
      foreach (is_array($packages) ? $packages : [] as $package) {
        if (is_array($package) && in_array($package['name'] ?? NULL, ['drupal/core', 'drupal/core-recommended'], TRUE) && is_string($package['version'] ?? NULL)) {
          return ltrim($package['version'], 'v');
        }
      }
    }
    $core = @file_get_contents($docroot . '/core/lib/Drupal.php');
    if (is_string($core) && preg_match("/const VERSION = '([^']+)'/", $core, $m) === 1) {
      return $m[1];
    }
    return '';
  }

}
