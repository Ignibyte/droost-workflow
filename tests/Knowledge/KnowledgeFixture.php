<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Knowledge;

/**
 * A Drupal-shaped project on disk, for the code knowledge read with no site.
 */
trait KnowledgeFixture {

  /**
   * Writes a file, making its directory.
   *
   * @param string $root
   *   The project.
   * @param string $path
   *   The relative path.
   * @param string $content
   *   The content.
   */
  private function put(string $root, string $path, string $content = ''): void {
    $file = $root . '/' . $path;
    if (!is_dir(dirname($file))) {
      mkdir(dirname($file), 0755, TRUE);
    }
    file_put_contents($file, $content);
  }

  /**
   * A docroot under `web/` with core, one custom module and a wiki page.
   *
   * @param string $root
   *   The project.
   */
  private function drupalTree(string $root): void {
    $this->put($root, 'web/core/lib/Drupal/Core/Entity/EntityBase.php', "<?php\n");
    $this->put($root, 'web/modules/custom/camps/camps.info.yml', "name: Camps\n");
    $this->put($root, 'web/modules/custom/camps/src/CampList.php', "<?php\n");
    $this->put($root, 'droost/wiki/overview.md', "# Overview\n");
  }

  /**
   * Writes droost's code index with the engine's schema, as droost does.
   *
   * @param string $root
   *   The project.
   * @param list<array{0: string, 1: string, 2: string}> $symbols
   *   Each symbol's fqcn, file and module.
   * @param list<string> $files
   *   The manifest's docroot-relative files.
   */
  private function codeIndex(string $root, array $symbols, array $files): void {
    $dir = $root . '/droost/droost-workflow';
    if (!is_dir($dir)) {
      mkdir($dir, 0755, TRUE);
    }
    $pdo = new \PDO('sqlite:' . $dir . '/code-index.sqlite');
    $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    $pdo->exec("CREATE TABLE droost_search_symbol (id INTEGER PRIMARY KEY AUTOINCREMENT, fqcn TEXT NOT NULL DEFAULT '', kind TEXT NOT NULL DEFAULT '', file TEXT NOT NULL DEFAULT '', line INTEGER NOT NULL DEFAULT 0, module TEXT NOT NULL DEFAULT '')");
    $pdo->exec("CREATE TABLE droost_search_edge (id INTEGER PRIMARY KEY AUTOINCREMENT, src TEXT NOT NULL DEFAULT '', dst TEXT NOT NULL DEFAULT '', kind TEXT NOT NULL DEFAULT '', file TEXT NOT NULL DEFAULT '')");
    $pdo->exec("CREATE TABLE droost_search_file (file TEXT NOT NULL PRIMARY KEY, hash TEXT NOT NULL DEFAULT '', scope TEXT NOT NULL DEFAULT '', type TEXT NOT NULL DEFAULT '', indexed_at INTEGER NOT NULL DEFAULT 0)");
    $symbol = $pdo->prepare("INSERT INTO droost_search_symbol (fqcn, kind, file, line, module) VALUES (?, 'class', ?, 1, ?)");
    foreach ($symbols as [$fqcn, $file, $module]) {
      $symbol->execute([$fqcn, $file, $module]);
    }
    $manifest = $pdo->prepare("INSERT INTO droost_search_file (file, hash, scope, type) VALUES (?, 'h', 'custom', 'php')");
    foreach ($files as $file) {
      $manifest->execute([$file]);
    }
  }

  /**
   * The run's spec, with a Grounding table citing what it is given.
   *
   * @param string $root
   *   The project.
   * @param list<array{0: string, 1: string}> $rows
   *   Each row's tier and Evidence cell.
   *
   * @return string
   *   The spec, project-relative.
   */
  private function groundedSpec(string $root, array $rows): string {
    $table = "| Phase | Tier | Asked | Found | Evidence |\n|---|---|---|---|---|\n";
    foreach ($rows as [$tier, $cite]) {
      $table .= sprintf("| plan | %s | a lookup | an answer | %s |\n", $tier, $cite);
    }
    $this->put(
      $root,
      'droost/droost-workflow/spec-test-run.md',
      "# Spec: test run\n\n## Routes\n\nnone — fixture builds no page\n\n## Grounding\n\n" . $table,
    );

    return 'droost/droost-workflow/spec-test-run.md';
  }

  /**
   * Appends rows to the tool-call ledger.
   *
   * @param string $root
   *   The project.
   * @param list<array<string, mixed>> $rows
   *   The rows.
   */
  private function ledger(string $root, array $rows): void {
    $lines = '';
    foreach ($rows as $row) {
      $lines .= json_encode($row, JSON_UNESCAPED_SLASHES) . "\n";
    }
    $this->put($root, 'droost/droost-workflow/tool-calls.jsonl', $lines);
  }

}
