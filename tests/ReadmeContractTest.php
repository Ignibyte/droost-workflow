<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests;

use Droost\Workflow\Config\ConfigError;
use Droost\Workflow\Config\Enforcement;
use Droost\Workflow\Config\Mode;
use Droost\Workflow\Config\Phase;
use Droost\Workflow\Config\PhaseGateMap;
use Droost\Workflow\Config\WorkflowConfig;
use Droost\Workflow\Config\WorkItemSettings;
use Droost\Workflow\Event\RunEvent;
use Droost\Workflow\WorkItem\CockpitWorkItemSource;
use Droost\Workflow\WorkItem\MarkdownWorkItemSource;
use Droost\Workflow\WorkItem\WorkItemSources;

/**
 * The README is served to people and to agents as authoritative.
 *
 * Nothing lints prose and no test asserts a paragraph, so a confident wrong
 * sentence in a README outlives every other kind of defect. These tests take
 * the examples out of the file at run time and put them through the real
 * loader, so the documentation cannot drift away from the code it describes.
 */
class ReadmeContractTest extends WorkflowTestCase {

  /**
   * The README's own sample lever file parses, and means what it says.
   */
  public function testTheReadmeSampleParses(): void {
    $sample = $this->extractBlock('yaml');
    $root = $this->makeRootWithConfig($sample);

    $config = WorkflowConfig::load($root);

    $this->assertSame(Mode::Agentic, $config->mode);
    $this->assertSame('custom', $config->preset);
    $this->assertSame(2, $config->maxGateRetries);
    $this->assertSame(Phase::names(), $config->phaseNames());
    $this->assertSame(
      Enforcement::Hard,
      $config->requireRun,
      'The README sample documents the require_run lever the guard enforces.',
    );
    $this->assertSame(
      WorkflowConfig::fromArray(['preset' => 'custom'], 'x')->resolvedGates(),
      $config->resolvedGates(),
      'The README sample no longer matches the custom preset it documents.',
    );
  }

  /**
   * The error message the README quotes is the one the code produces.
   */
  public function testTheReadmeErrorMessageIsExact(): void {
    $quoted = $this->extractBlock('unknown gate');
    // The README wraps the message across lines to stay readable.
    $expected = preg_replace('/\s+/', ' ', trim($quoted));

    try {
      WorkflowConfig::fromArray(
        ['gates' => ['phpstain' => ['on' => TRUE]]],
        'droost.workflow.yml',
      );
      $this->fail('Expected a ConfigError.');
    }
    catch (ConfigError $e) {
      $this->assertSame($expected, $e->getMessage());
    }
  }

  /**
   * The README's phase map is the engine's, line for line.
   *
   * The map is prose in the README and a constant in the engine; nothing
   * else holds the two together. Parsed from the fenced block rather than
   * quoted here, so editing either side alone fails the build.
   */
  public function testTheReadmePhaseMapMatchesTheEngine(): void {
    $block = $this->extractBlock('text');
    $this->assertStringStartsWith('plan:', trim($block));

    $documented = [];
    foreach (explode("\n", trim($block)) as $line) {
      [$phase, $gates] = explode(':', $line, 2);
      $gates = trim($gates);
      $documented[trim($phase)] = $gates === 'none'
        ? []
        : array_map(trim(...), explode(',', $gates));
    }

    $this->assertSame(PhaseGateMap::DEFAULT, $documented);
  }

  /**
   * The README's run-state example is a document the store can read.
   */
  public function testTheReadmeStateExampleIsShapedLikeRealState(): void {
    $sample = $this->extractBlock('json');
    /** @var array<string, mixed>|null $decoded */
    $decoded = json_decode($sample, TRUE);

    $this->assertIsArray($decoded);
    $this->assertSame(1, $decoded['v'] ?? NULL, 'The documented schema '
      . 'version must match the one this build writes.');
  }

  /**
   * The README's ticket samples: a lever file and a ticket the source reads.
   */
  public function testTheReadmeTicketSamplesParse(): void {
    $root = $this->makeRootWithConfig("preset: custom\n" . $this->extractBlock('provider: markdown'));
    $settings = WorkflowConfig::load($root)->workItem;
    $this->assertNotNull($settings);
    $this->assertSame('markdown', $settings->provider);
    $this->assertSame(['dir' => MarkdownWorkItemSource::DEFAULT_DIR, 'prefix' => MarkdownWorkItemSource::DEFAULT_PREFIX], $settings->markdown, 'the sample documents the defaults');

    mkdir($root . '/droost/tickets/open', 0755, TRUE);
    file_put_contents($root . '/droost/tickets/open/TICKET-12-camp-news-by-recipe.md', $this->extractBlock('ticket_number:'));
    $source = WorkItemSources::fromSettings($settings, $root);
    $this->assertNotNull($source);
    $ticket = $source->get('TICKET-12');
    $this->assertNotNull($ticket);
    $this->assertSame('ready', $ticket->status);
    $this->assertSame('feature', $ticket->type);
    $this->assertSame(['created' => '2026-09-28'], $ticket->extra);
  }

  /**
   * The README's cockpit sample: names, never values, and the default path.
   */
  public function testTheReadmeCockpitSampleParses(): void {
    $root = $this->makeRootWithConfig("preset: custom\n" . $this->extractBlock('provider: droost_cockpit'));
    $settings = WorkflowConfig::load($root)->workItem;
    $this->assertNotNull($settings);
    $this->assertSame(CockpitWorkItemSource::SOURCE, $settings->provider);
    $this->assertSame(
      [
        'url_env' => 'DRUPLIT_MAILBOX_URL',
        'token_env' => 'DRUPLIT_SEAT_TOKEN',
        'path' => WorkItemSettings::DEFAULT_COCKPIT_PATH,
      ],
      $settings->cockpit,
      'the sample documents the default path',
    );
  }

  /**
   * The README's sample event is an event this build reads, to the schema.
   */
  public function testTheReadmeSampleEventParses(): void {
    $line = trim($this->extractBlock('"schema":"droost.run-event/1"'));
    $event = RunEvent::fromArray(json_decode($line, TRUE));
    $this->assertNotNull($event, 'the sample is a whole event');
    $this->assertSame(RunEvent::SCHEMA, $event->schema);
    $this->assertContains($event->type, RunEvent::TYPES);
    $this->assertMatchesRegularExpression('/^evt-[0-9a-f]{16}$/', $event->eventId);

    $schema = json_decode((string) file_get_contents(dirname(__DIR__) . '/schema/run-event.v1.json'), TRUE);
    $this->assertIsArray($schema);
    $this->assertIsArray($schema['$defs']);
    $this->assertSame(RunEvent::TYPES, array_keys($schema['$defs']), 'the schema describes every type this build writes, and no other');
    $definition = $schema['$defs'][$event->type];
    $this->assertIsArray($definition);
    $this->assertIsArray($definition['required']);
    foreach ($definition['required'] as $key) {
      $this->assertArrayHasKey($key, $event->payload);
    }
    foreach (RunEvent::TYPES as $type) {
      $this->assertStringContainsString('`' . $type . '`', $this->readmeTable(), $type . ' is documented');
    }
  }

  /**
   * The README's run-event table.
   *
   * @return string
   *   The table's text.
   */
  private function readmeTable(): string {
    $readme = (string) file_get_contents(dirname(__DIR__) . '/README.md');
    $table = strstr($readme, '| Type | When | Payload |');
    $this->assertIsString($table, 'the README carries the run-event table');
    return substr($table, 0, (int) strpos($table, "\n\n"));
  }

  /**
   * The first fenced block whose content matches a marker.
   *
   * @param string $marker
   *   Either a fence language ("yaml", "json") or text the block contains.
   *
   * @return string
   *   The block's contents.
   */
  private function extractBlock(string $marker): string {
    $readme = file_get_contents(dirname(__DIR__) . '/README.md');
    $this->assertIsString($readme, 'README.md is unreadable.');

    $matched = preg_match_all(
      '/^```([a-z]*)\n(.*?)^```/ms',
      $readme,
      $blocks,
      PREG_SET_ORDER,
    );
    $this->assertNotFalse($matched);

    foreach ($blocks as $block) {
      if ($block[1] === $marker || str_contains($block[2], $marker)) {
        return $block[2];
      }
    }

    $this->fail('No fenced block in README.md matches: ' . $marker);
  }

}
