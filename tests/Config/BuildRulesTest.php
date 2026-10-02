<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Config;

use Droost\Workflow\Config\BuildRules;
use Droost\Workflow\Config\ConfigError;
use Droost\Workflow\Config\WorkflowConfig;
use Droost\Workflow\State\RunState;
use Droost\Workflow\State\RunStateStore;
use Droost\Workflow\Tests\WorkflowTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The owner's rules for what builds a page (owner, 2026-10-02).
 *
 * A page is judged by its main content: one entity, the collection itself,
 * or neither. The defaults follow the site's case, Canvas first where Canvas
 * is, and a file names only what it changes.
 */
final class BuildRulesTest extends WorkflowTestCase {

  /**
   * With no rules block, each case keeps its defaults.
   */
  public function testEachCaseHasItsDefaults(): void {
    $rules = WorkflowConfig::fromArray([], 'test')->rules();

    $sdc = $rules->resolve('sdc');
    $this->assertSame(['canvas_page'], $sdc['kinds']['page']['owners']);
    $this->assertSame(['view_page'], $sdc['kinds']['collection']['owners']);
    $this->assertSame(['view_block'], $sdc['kinds']['list_section']['owners']);
    $this->assertSame(['entity_view_display', 'content_template'], $sdc['kinds']['detail']['owners']);
    $this->assertSame(['webform'], $sdc['kinds']['form']['owners']);
    $this->assertSame('report', $sdc['kinds']['page']['mode']);
    $this->assertSame('default', $sdc['kinds']['page']['source']);
    $this->assertSame(['role' => 'content_editor', 'mode' => 'report', 'source' => 'default'], $sdc['editor_proof']);
    $this->assertSame('report', $sdc['code_components']['mode'], 'a code component on an SDC site is reported');

    $none = $rules->resolve('none');
    $this->assertSame(['entity_view_display', 'route'], $none['kinds']['page']['owners'], 'regular Drupal without Canvas');
    $this->assertSame('off', $none['editor_proof']['mode'], 'no Canvas, no Canvas editor to prove');

    $code = $rules->resolve('code');
    $this->assertSame(['canvas_page'], $code['kinds']['page']['owners']);
    $this->assertSame('off', $code['code_components']['mode']);
  }

  /**
   * A file changes only what it names, and the table says which.
   */
  public function testFileChangesOnlyWhatItNames(): void {
    $rules = WorkflowConfig::fromArray([
      'rules' => [
        'page' => ['mode' => 'block'],
        'detail' => ['owner' => 'content_template'],
        'editor_proof' => ['role' => 'site_editor'],
      ],
    ], 'test')->rules()->resolve('sdc');

    $this->assertSame(['canvas_page'], $rules['kinds']['page']['owners']);
    $this->assertSame('block', $rules['kinds']['page']['mode']);
    $this->assertSame('file', $rules['kinds']['page']['source']);
    $this->assertSame(['content_template'], $rules['kinds']['detail']['owners']);
    $this->assertSame('report', $rules['kinds']['detail']['mode']);
    $this->assertSame('default', $rules['kinds']['collection']['source']);
    $this->assertSame('site_editor', $rules['editor_proof']['role']);
    $this->assertSame('report', $rules['editor_proof']['mode']);
  }

  /**
   * Owners may be several, comma-separated, as paths are.
   */
  public function testOwnersAreCommaSeparated(): void {
    $rules = WorkflowConfig::fromArray([
      'rules' => ['page' => ['owner' => 'canvas_page, route, canvas_page']],
    ], 'test')->rules()->resolve('sdc');

    $this->assertSame(['canvas_page', 'route'], $rules['kinds']['page']['owners']);
  }

  /**
   * Every name the vocabulary does not hold is refused by name.
   *
   * @param array<string, mixed> $block
   *   The rules block.
   * @param string $message
   *   What the refusal must say.
   */
  #[DataProvider('refusals')]
  public function testUnknownNamesAreRefused(array $block, string $message): void {
    $this->expectException(ConfigError::class);
    $this->expectExceptionMessage($message);
    WorkflowConfig::fromArray(['rules' => $block], 'droost.workflow.yml');
  }

  /**
   * The refusals.
   *
   * @return array<string, array{array<string, mixed>, string}>
   *   Each case.
   */
  public static function refusals(): array {
    return [
      'a rule that is not one' => [
        ['landing' => ['owner' => 'canvas_page']],
        'rules.landing is not a rule',
      ],
      'an owner that is not one' => [
        ['page' => ['owner' => 'paragraphs']],
        'rules.page owner "paragraphs" is not an owner',
      ],
      'a mode that is not one' => [
        ['page' => ['mode' => 'warn']],
        'rules.page mode "warn" is not a mode',
      ],
      'a key a rule does not take' => [
        ['page' => ['owners' => 'canvas_page']],
        'rules.page takes owner and mode, not "owners"',
      ],
      'an empty owner' => [
        ['page' => ['owner' => ' , ']],
        'rules.page owner is empty',
      ],
      'a role that is not a machine name' => [
        ['editor_proof' => ['role' => 'Content Editor']],
        'rules.editor_proof role "Content Editor" is not a role machine name',
      ],
    ];
  }

  /**
   * A run freezes the rules it began with, through its state file.
   */
  public function testRunFreezesItsRules(): void {
    $root = $this->makeRoot();
    $config = WorkflowConfig::fromArray([
      'rules' => ['page' => ['owner' => 'canvas_page,route', 'mode' => 'block']],
    ], 'test');
    $store = new RunStateStore($root);
    $store->save(RunState::begin('run-1', '2026-10-02T00:00:00Z', $config));

    $loaded = $store->load();
    $this->assertNotNull($loaded);
    $frozen = BuildRules::fromArray($loaded->rules)->resolve('sdc');
    $this->assertSame(['canvas_page', 'route'], $frozen['kinds']['page']['owners']);
    $this->assertSame('block', $frozen['kinds']['page']['mode']);

    $plain = new RunStateStore($this->makeRoot());
    $plain->save(RunState::begin('run-2', '2026-10-02T00:00:00Z', WorkflowConfig::fromArray([], 'test')));
    $none = $plain->load();
    $this->assertNotNull($none);
    $this->assertSame([], $none->rules, 'no rules freeze as none, and read back as none');
    $this->assertSame(['canvas_page'], BuildRules::fromArray($none->rules)->resolve('sdc')['kinds']['page']['owners']);
  }

  /**
   * A case the site cannot be is refused, never defaulted.
   */
  public function testAnUnknownCaseIsRefused(): void {
    $this->expectException(\InvalidArgumentException::class);
    BuildRules::none()->resolve('headless');
  }

}
