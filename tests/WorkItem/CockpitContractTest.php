<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\WorkItem;

use Droost\Workflow\Config\ConfigError;
use Droost\Workflow\Config\WorkflowConfig;
use Droost\Workflow\Config\WorkItemSettings;
use Droost\Workflow\Tests\WorkflowTestCase;
use Droost\Workflow\WorkItem\CockpitWorkItemSource;
use Droost\Workflow\WorkItem\WorkItemSources;

/**
 * The contract document and its stub name the same routes.
 *
 * And the lever file names variables, never values.
 */
final class CockpitContractTest extends WorkflowTestCase {

  /**
   * Every route the document lists, the stub serves, and no other.
   */
  public function testTheDocumentAndTheStubNameTheSameRoutes(): void {
    $doc = (string) file_get_contents(dirname(__DIR__, 2) . '/docs/cockpit-provider-api.md');
    preg_match_all('/^\| (GET|POST) \| `(\/[^`]+)` \|/m', $doc, $rows, PREG_SET_ORDER);
    $documented = array_map(static fn (array $row): string => $row[1] . ' ' . $row[2], $rows);

    $stub = (string) file_get_contents(dirname(__DIR__) . '/fixtures/cockpit-stub.php');
    preg_match_all("/\\['(GET|POST)', '(\\/[^']+)'\\]/", $stub, $routes, PREG_SET_ORDER);
    $served = array_map(static fn (array $route): string => $route[1] . ' ' . $route[2], $routes);

    $this->assertCount(4, $documented, 'the document\'s route table was read');
    $this->assertSame($documented, $served, 'docs/cockpit-provider-api.md and tests/fixtures/cockpit-stub.php list the same routes, in order');
    $terms = [
      '401',
      '403',
      '404',
      '413',
      '{"accepted": n, "duplicates": m}',
      '100 events',
      '256 KiB',
      'Authorization: Bearer',
      '{"omitted_bytes": n}',
    ];
    foreach ($terms as $term) {
      $this->assertStringContainsString($term, $doc);
    }
  }

  /**
   * The cockpit block: variable names required, a value refused unechoed.
   */
  public function testTheCockpitBlockNamesVariablesOnly(): void {
    $ok = WorkflowConfig::load($this->makeRootWithConfig("preset: custom\nwork_item:\n  provider: droost_cockpit\n  cockpit: { url_env: COCKPIT_URL, token_env: COCKPIT_TOKEN }\n"))->workItem;
    $this->assertInstanceOf(WorkItemSettings::class, $ok);
    $this->assertSame(['url_env' => 'COCKPIT_URL', 'token_env' => 'COCKPIT_TOKEN', 'path' => '/work-items/v1'], $ok->cockpit);
    $this->assertInstanceOf(CockpitWorkItemSource::class, WorkItemSources::fromSettings($ok, $this->makeRoot()));

    foreach ([
      "  provider: droost_cockpit\n" => 'cockpit is required',
      "  provider: droost_cockpit\n  cockpit: { token_env: T }\n" => 'cockpit.url_env is required',
      "  provider: droost_cockpit\n  cockpit: { url_env: 'http://127.0.0.1:8123', token_env: T }\n" => 'must be the NAME of an environment variable',
      "  provider: droost_cockpit\n  cockpit: { url_env: U, token_env: 'sk-live-0123456789abcdef' }\n" => 'must be the NAME of an environment variable',
      "  provider: droost_cockpit\n  cockpit: { url_env: U, token_env: T, path: work-items }\n" => 'must start with a slash',
      "  provider: droost_cockpit\n  cockpit: { url_env: U, token_env: T, host: x }\n" => 'cockpit.host',
    ] as $block => $said) {
      try {
        WorkflowConfig::load($this->makeRootWithConfig("preset: custom\nwork_item:\n" . $block));
        $this->fail('accepted: ' . $block);
      }
      catch (ConfigError $e) {
        $this->assertStringContainsString($said, $e->getMessage());
        $this->assertStringNotContainsString('127.0.0.1:8123', $e->getMessage(), 'a value pasted in is never echoed');
        $this->assertStringNotContainsString('sk-live', $e->getMessage(), 'a token pasted in is never echoed');
      }
    }
  }

}
