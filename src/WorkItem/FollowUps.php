<?php

declare(strict_types=1);

namespace Droost\Workflow\WorkItem;

use Droost\Workflow\Config\Phase;
use Droost\Workflow\Config\WorkItemSettings;
use Droost\Workflow\State\RunState;

/**
 * Writes the fast flow's follow-up tickets (0.11).
 *
 * The owner, 2026-09-29: a failure at test that is out of the ticket's scope,
 * or still there when the loop budget is spent, becomes a follow-up ticket,
 * in the cockpit when one is configured and as markdown otherwise. A
 * follow-up is never lost to a cockpit that cannot be reached: it is written
 * as markdown instead, and the record says why.
 */
final class FollowUps {

  /**
   * Where markdown follow-ups go when the project keeps no markdown tickets.
   */
  public const DEFAULT_DIR = 'docs/tickets';

  /**
   * Constructs the writer.
   *
   * @param string $projectRoot
   *   The repository.
   * @param \Droost\Workflow\WorkItem\WorkItemSourceInterface|null $configured
   *   The project's own ticket source, when it has one.
   * @param \Droost\Workflow\Config\WorkItemSettings|null $settings
   *   The `work_item` block, when the lever file has one.
   */
  public function __construct(
    private readonly string $projectRoot,
    private readonly ?WorkItemSourceInterface $configured,
    private readonly ?WorkItemSettings $settings,
  ) {}

  /**
   * Writes one follow-up, and says where it went.
   *
   * @param \Droost\Workflow\State\RunState $state
   *   The run the failure came from.
   * @param \Droost\Workflow\Config\Phase $phase
   *   The phase it failed at.
   * @param string $gate
   *   The gate.
   * @param string $summary
   *   What the gate said.
   * @param string $why
   *   Either `spent` or `outside`.
   * @param string $now
   *   When, ISO-8601.
   *
   * @return array<string, string|null>
   *   The record: phase, gate, why, summary, title, id, source, path, at,
   *   and a note when the ticket went somewhere other than asked, or nowhere.
   */
  public function file(RunState $state, Phase $phase, string $gate, string $summary, string $why, string $now): array {
    $title = self::title($state, $phase, $gate, $summary);
    $body = self::body($state, $phase, $gate, $summary, $why);
    $record = [
      'phase' => $phase->value,
      'gate' => $gate,
      'why' => $why,
      'summary' => $summary,
      'title' => $title,
      'id' => NULL,
      'source' => NULL,
      'path' => NULL,
      'at' => $now,
      'note' => NULL,
    ];
    $notes = [];
    foreach ($this->sources($state->loop->followUps) as [$label, $source]) {
      try {
        if ($source === NULL) {
          throw WorkItemError::cockpitUnreachable('the follow-up', 'the cockpit is not configured here');
        }
        $item = $source->create($title, 'bug', $body);
        $record['id'] = $item->id;
        $record['source'] = $item->source;
        $record['path'] = $item->path;
        $record['note'] = $notes === [] ? NULL : implode('; ', $notes);

        return $record;
      }
      catch (\Throwable $e) {
        $notes[] = sprintf('%s refused it: %s', $label, $e->getMessage());
      }
    }
    $record['note'] = 'no follow-up could be written: ' . implode('; ', $notes);

    return $record;
  }

  /**
   * The sources to try, in order: the one asked for, then markdown.
   *
   * @param string $target
   *   The run's frozen `follow_ups`.
   *
   * @return list<array{string, \Droost\Workflow\WorkItem\WorkItemSourceInterface|null}>
   *   Each source with the label a note names it by.
   */
  private function sources(string $target): array {
    $cockpit = $this->settings?->provider === 'droost_cockpit';
    if ($target === 'auto') {
      $target = $cockpit ? 'cockpit' : 'markdown';
    }
    $markdown = $this->configured !== NULL && $this->settings?->provider === 'markdown'
      ? $this->configured
      : new MarkdownWorkItemSource(
        $this->settings?->markdown['dir'] ?? self::DEFAULT_DIR,
        $this->settings?->markdown['prefix'] ?? MarkdownWorkItemSource::DEFAULT_PREFIX,
        $this->projectRoot,
      );
    if ($target !== 'cockpit') {
      return [['markdown', $markdown]];
    }
    $source = $this->configured instanceof CockpitWorkItemSource
      ? $this->configured
      : WorkItemSources::fromSettings($this->settings, $this->projectRoot);

    return [['the cockpit', $source], ['markdown', $markdown]];
  }

  /**
   * The follow-up's title.
   *
   * @param \Droost\Workflow\State\RunState $state
   *   The run.
   * @param \Droost\Workflow\Config\Phase $phase
   *   The phase.
   * @param string $gate
   *   The gate.
   * @param string $summary
   *   What it said.
   *
   * @return string
   *   One line, naming the ticket it follows when there is one.
   */
  private static function title(RunState $state, Phase $phase, string $gate, string $summary): string {
    $parent = is_string($state->workItem['id'] ?? NULL) ? ' to ' . $state->workItem['id'] : '';
    $where = preg_match('/, at (\S+?):\d+/', $summary, $m) === 1 ? ' (' . $m[1] . ')' : '';

    return sprintf('Follow-up%s: %s failed at %s%s', $parent, $gate, $phase->value, $where);
  }

  /**
   * The follow-up's text.
   *
   * @param \Droost\Workflow\State\RunState $state
   *   The run.
   * @param \Droost\Workflow\Config\Phase $phase
   *   The phase.
   * @param string $gate
   *   The gate.
   * @param string $summary
   *   What it said.
   * @param string $why
   *   Either `spent` or `outside`.
   *
   * @return string
   *   Markdown sections, after the title.
   */
  private static function body(RunState $state, Phase $phase, string $gate, string $summary, string $why): string {
    $reason = $why === 'outside'
      ? 'every failure is in a spec this run did not change, so it belongs to earlier work'
      : sprintf(
        'the run went back to code %d time(s), its whole loop budget, and the gate still failed',
        $state->loop->spent(),
      );
    $ticket = is_string($state->workItem['id'] ?? NULL)
      ? sprintf('%s — %s', $state->workItem['id'], is_string($state->workItem['title'] ?? NULL) ? $state->workItem['title'] : '')
      : 'none';

    return sprintf(
      "## Summary\n\n%s failed at %s in run %s, and the run moved on without fixing it: %s.\n\n"
      . "## The failure\n\n```\n%s\n```\n\n"
      . "## Where it came from\n\n- Run: %s (%s, %s flow)\n- Ticket: %s\n- Spec: %s\n- Loops: %d of %d\n\n"
      . "## Notes\n\nWritten by droost/workflow. `droost-workflow evidence` renders the run's full record.\n",
      $gate,
      $phase->value,
      $state->runId,
      $reason,
      str_replace('```', "'''", $summary),
      $state->runId,
      $state->preset,
      $state->loop->flow,
      $ticket,
      $state->specPath ?? 'none',
      $state->loop->spent(),
      $state->loop->maxLoops,
    );
  }

}
