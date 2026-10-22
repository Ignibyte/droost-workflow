<?php

declare(strict_types=1);

namespace Droost\Workflow\Panel;

/**
 * The run panel as data: `droost workflow:panel --json`.
 *
 * What the droost mod draws in Claude Code's pane, and what any other
 * harness can draw the same way: the run (ticket, level, lead), its phases,
 * a question it waits on, and tabs. droost's own tabs are Spec (the
 * criteria and their proofs), Activity (what the agent said it is doing),
 * Gates (each gate's verdict, the ones that measured nothing and the ones a
 * level turned off told apart from a pass), Seeker and Files (what the run
 * changed); a project adds its own after them (droost.yml `panel.tabs`).
 *
 * Built from the run record and the evidence store, never from what the
 * agent says, except the Activity tab, which says that it is the agent's.
 *
 * @phpstan-type Summary array{id: string|null, state: string, phase: string|null, preset: string|null, mode: string|null, lead: string|null, work_item: string|null, spec: string|null, started_at: string|null}
 * @phpstan-type Phase array{id: string, label: string, status: string, attempts: int}
 * @phpstan-type Tab array{id: string, title: string, source: string, badge: string|null, sections: list<array<string, mixed>>, error: string|null}
 * @phpstan-type Document array{schema: string, generated_at: string, open: string, run: Summary|null, phases: list<Phase>, hold: array{question: string}|null, tabs: list<Tab>}
 * @phpstan-type Action array{at: string, tool: string, subject: string|null, refused: bool}
 * @phpstan-type Criterion array{ref: string, statement: string, verified_by: string|null}
 * @phpstan-type ChangedFile array{path: string, status: string, added: int|null, removed: int|null}
 */
final class RunPanel {

  /**
   * The document's schema.
   */
  public const SCHEMA = 'droost.panel/1';

  /**
   * The phases' labels, in order.
   */
  private const PHASES = ['plan' => 'Plan', 'code' => 'Code', 'test' => 'Test', 'complete' => 'Complete'];

  /**
   * The panel document.
   *
   * @param array<mixed>|null $run
   *   The facade's status `run` (the run record), or NULL with none open.
   * @param list<Criterion> $criteria
   *   The facade's criteria for the open run.
   * @param list<array<string, mixed>> $findings
   *   The evidence store's seeker findings for the open run.
   * @param list<array{at: string, text: string, detail: string|null}> $activity
   *   What the agent said, for the open run.
   * @param array<string, array{title: string, badge: string|null, sections: list<array<string, mixed>>, error: string|null}> $projectTabs
   *   The project's tabs, already run.
   * @param string $now
   *   The time, ISO 8601.
   * @param string|null $spec
   *   The spec's markdown, or NULL when there is none to read.
   * @param list<Action> $actions
   *   What the agent did, from the guard's diary.
   * @param string $open
   *   When the mod opens the panel by itself: auto, always or never.
   * @param list<ChangedFile>|null $files
   *   The files the run changed (WorkflowFacade::runFiles()), or NULL when
   *   the repository cannot say.
   *
   * @return Document
   *   The document.
   */
  public static function document(
    ?array $run,
    array $criteria,
    array $findings,
    array $activity,
    array $projectTabs,
    string $now,
    ?string $spec = NULL,
    array $actions = [],
    string $open = 'auto',
    ?array $files = NULL,
  ): array {
    $tabs = $run === NULL
      ? []
      : [
        self::specTab($run, $criteria, $spec),
        self::activityTab($activity, $actions),
        self::gatesTab($run),
        self::seekerTab($run, $findings),
        self::filesTab($files),
      ];
    foreach ($projectTabs as $id => $tab) {
      $tabs[] = [
        'id' => $id,
        'title' => $tab['title'],
        'source' => 'project',
        'badge' => $tab['badge'],
        'sections' => $tab['sections'],
        'error' => $tab['error'],
      ];
    }

    return [
      'schema' => self::SCHEMA,
      'generated_at' => $now,
      'open' => $open,
      'run' => $run === NULL ? NULL : self::summary($run),
      'phases' => self::phases($run),
      'hold' => self::hold($run),
      'tabs' => $tabs,
    ];
  }

  /**
   * The open run in a few fields, as a project's tab is handed it too.
   *
   * @param array<mixed> $run
   *   The run record.
   *
   * @return Summary
   *   The summary.
   */
  public static function summary(array $run): array {
    $phases = is_array($run['phases'] ?? NULL) ? $run['phases'] : [];
    $current = is_string($run['current_phase'] ?? NULL) ? $run['current_phase'] : NULL;
    $state = match (TRUE) {
      $current === NULL => 'completed',
      ($phases[$current] ?? NULL) === 'failed' => 'failed',
      is_array($run['awaiting'] ?? NULL) => 'waiting',
      default => 'running',
    };

    return [
      'id' => self::str($run['run_id'] ?? NULL),
      'state' => $state,
      'phase' => $current,
      'preset' => self::str($run['preset'] ?? NULL),
      'mode' => self::str($run['effective_mode'] ?? NULL),
      'lead' => self::str($run['lead'] ?? NULL),
      // The ticket as the run holds it ({id, source, …}), or a bare id.
      'work_item' => self::str(is_array($run['work_item'] ?? NULL) ? ($run['work_item']['id'] ?? NULL) : ($run['work_item'] ?? NULL)),
      'spec' => self::str($run['spec'] ?? NULL),
      'started_at' => self::str($run['started_at'] ?? NULL),
    ];
  }

  /**
   * A string from the run record, or NULL for anything else.
   *
   * @param mixed $value
   *   The value.
   *
   * @return string|null
   *   The string, or NULL.
   */
  private static function str(mixed $value): ?string {
    return is_string($value) && $value !== '' ? $value : NULL;
  }

  /**
   * The four phases and where each stands.
   *
   * @param array<mixed>|null $run
   *   The run record, or NULL.
   *
   * @return list<Phase>
   *   The phases, in order.
   */
  private static function phases(?array $run): array {
    $phases = is_array($run['phases'] ?? NULL) ? $run['phases'] : [];
    $reports = is_array($run['gate_reports'] ?? NULL) ? $run['gate_reports'] : [];
    $out = [];
    foreach (self::PHASES as $id => $label) {
      $status = is_string($phases[$id] ?? NULL) ? $phases[$id] : 'pending';
      $attempts = is_array($reports[$id] ?? NULL) && is_int($reports[$id]['attempt'] ?? NULL) ? $reports[$id]['attempt'] : 0;
      $out[] = ['id' => $id, 'label' => $label, 'status' => $status, 'attempts' => $attempts];
    }

    return $out;
  }

  /**
   * The question the run waits on, or NULL.
   *
   * @param array<mixed>|null $run
   *   The run record, or NULL.
   *
   * @return array{question: string}|null
   *   The hold.
   */
  private static function hold(?array $run): ?array {
    $awaiting = is_array($run['awaiting'] ?? NULL) ? $run['awaiting'] : NULL;
    if ($awaiting === NULL) {
      return NULL;
    }
    foreach (['question', 'prompt', 'summary'] as $key) {
      if (is_string($awaiting[$key] ?? NULL) && trim($awaiting[$key]) !== '') {
        return ['question' => trim($awaiting[$key])];
      }
    }

    return ['question' => 'The run is waiting on a person.'];
  }

  /**
   * Spec: the spec's file and its criteria, each with its proof.
   *
   * @param array<mixed> $run
   *   The run record.
   * @param list<Criterion> $criteria
   *   The criteria.
   * @param string|null $spec
   *   The spec's markdown, or NULL.
   *
   * @return Tab
   *   The tab.
   */
  private static function specTab(array $run, array $criteria, ?string $spec = NULL): array {
    $items = [];
    $proven = 0;
    foreach ($criteria as $criterion) {
      $proof = $criterion['verified_by'];
      $proven += $proof === NULL ? 0 : 1;
      $items[] = [
        'text' => sprintf('%s · %s', $criterion['ref'], $criterion['statement']),
        'done' => $proof !== NULL,
        'note' => $proof ?? 'not proven yet',
      ];
    }
    $sections = [];
    if ($spec !== NULL && trim($spec) !== '') {
      $sections[] = [
        'kind' => 'markdown',
        'title' => NULL,
        'text' => mb_strlen($spec) > PanelSections::MAX_MARKDOWN
          ? mb_substr($spec, 0, PanelSections::MAX_MARKDOWN - 60) . "\n\n… (the rest is in the spec file)"
          : $spec,
      ];
    }
    elseif (is_string($run['spec'] ?? NULL)) {
      $sections[] = self::text(NULL, 'The spec is not written yet: plan writes it.');
    }
    // A finished run is archived, and its criteria are read from the open run
    // only: its record still says how many there were and how many a test
    // proved, which is not "none declared".
    $recorded = is_array($run['criteria'] ?? NULL) ? $run['criteria'] : [];
    $total = is_int($recorded['total'] ?? NULL) ? $recorded['total'] : 0;
    $verified = is_array($recorded['verified'] ?? NULL) ? count($recorded['verified']) : 0;
    if ($items === [] && $total > 0) {
      $sections[] = self::text('Proofs', sprintf(
        '%d of %d proven by a test. The run is archived; `droost workflow:evidence` has each criterion and its proof.',
        $verified,
        $total,
      ));
      return self::tab('spec', 'Spec', $verified . '/' . $total . ' ✓', $sections);
    }
    $sections[] = $items === []
      ? self::text('Proofs', 'No criterion declared yet: plan declares them with `droost workflow:declare-criterion`.')
      : [
        'kind' => 'checklist',
        'title' => sprintf('Proofs: %d of %d criteria proven', $proven, count($items)),
        'items' => $items,
      ];

    return self::tab('spec', 'Spec', $items === [] ? NULL : $proven . '/' . count($items) . ' ✓', $sections);
  }

  /**
   * Activity: what the agent said it is doing, newest last.
   *
   * @param list<array{at: string, text: string, detail: string|null}> $activity
   *   The entries.
   * @param list<Action> $actions
   *   What the guard saw the agent do.
   *
   * @return Tab
   *   The tab.
   */
  private static function activityTab(array $activity, array $actions = []): array {
    $items = [];
    $last = count($activity) - 1;
    foreach ($activity as $index => $entry) {
      $items[] = [
        'text' => $entry['text'],
        'detail' => $entry['detail'],
        'tag' => strlen($entry['at']) >= 16 ? substr($entry['at'], 11, 5) : NULL,
        'tone' => $index === $last ? 'info' : 'ok',
      ];
    }
    $did = [];
    foreach ($actions as $action) {
      $did[] = [
        'text' => $action['subject'] === NULL ? $action['tool'] : $action['tool'] . ' · ' . $action['subject'],
        'detail' => NULL,
        'tag' => $action['refused'] ? 'refused' : (strlen($action['at']) >= 16 ? substr($action['at'], 11, 5) : NULL),
        'tone' => $action['refused'] ? 'bad' : 'muted',
      ];
    }
    $sections = [
      $items === []
        ? self::text('It says (its own account)', 'Nothing yet: the agent says what it is doing with `droost workflow:say "<what>"`.')
        : ['kind' => 'list', 'title' => 'It says (its own account)', 'items' => $items],
    ];
    if ($did !== []) {
      $sections[] = ['kind' => 'list', 'title' => 'It did (the guard saw these calls)', 'items' => $did];
    }

    return self::tab('activity', 'Activity', NULL, $sections);
  }

  /**
   * Gates: each phase's latest verdicts, told apart.
   *
   * A pass that examined nothing, a gate a level turned off and a gate that
   * has not run yet each read as what they are, never as a pass.
   *
   * @param array<mixed> $run
   *   The run record.
   *
   * @return Tab
   *   The tab.
   */
  private static function gatesTab(array $run): array {
    $reports = is_array($run['gate_reports'] ?? NULL) ? $run['gate_reports'] : [];
    $sections = [];
    $measured = 0;
    $total = 0;
    foreach (array_keys(self::PHASES) as $phase) {
      $report = is_array($reports[$phase] ?? NULL) ? $reports[$phase] : NULL;
      $gates = is_array($report['gates'] ?? NULL) ? $report['gates'] : [];
      if ($gates === []) {
        continue;
      }
      $items = [];
      foreach ($gates as $gate) {
        if (!is_array($gate)) {
          continue;
        }
        [$tag, $tone] = self::verdict($gate);
        $total += $tag === 'off' ? 0 : 1;
        $measured += $tag === 'passed' ? 1 : 0;
        $items[] = [
          'text' => self::str($gate['gate'] ?? NULL) ?? '?',
          'detail' => is_string($gate['summary'] ?? NULL) ? $gate['summary'] : NULL,
          'tag' => $tag,
          'tone' => $tone,
        ];
      }
      $attempt = is_int($report['attempt'] ?? NULL) ? ' · attempt ' . $report['attempt'] : '';
      $sections[] = ['kind' => 'list', 'title' => self::PHASES[$phase] . $attempt, 'items' => $items];
    }
    if ($sections === []) {
      $sections[] = self::text(NULL, 'No gate has run yet: plan runs none, code runs the first.');
    }

    return self::tab('gates', 'Gates', $total === 0 ? NULL : $measured . '/' . $total, $sections);
  }

  /**
   * A gate's verdict word and tone.
   *
   * @param array<mixed> $gate
   *   The gate's row of a phase report.
   *
   * @return array{string, string}
   *   The word, and the tone.
   */
  private static function verdict(array $gate): array {
    $status = is_string($gate['status'] ?? NULL) ? $gate['status'] : '';
    if ($status === 'passed' && ($gate['labelled'] ?? FALSE) === TRUE) {
      return ['examined nothing', 'warn'];
    }

    return match ($status) {
      'passed' => ['passed', 'ok'],
      'failed' => ['failed', 'bad'],
      'reported' => ['reported', 'warn'],
      'off' => ['off', 'muted'],
      'waived' => ['waived', 'warn'],
      'error-tool-missing' => ['tool missing', 'bad'],
      'error-tool-failed' => ['could not run', 'bad'],
      'skipped-no-site' => ['did not run', 'warn'],
      default => [$status === '' ? '?' : $status, 'muted'],
    };
  }

  /**
   * Files: what the run changed, from git, never from what the agent says.
   *
   * @param list<ChangedFile>|null $files
   *   The files, or NULL when the repository cannot say.
   *
   * @return Tab
   *   The tab.
   */
  private static function filesTab(?array $files): array {
    if ($files === NULL) {
      return self::tab('files', 'Files', NULL, [self::text(NULL, 'The repository cannot say what this run changed: no base commit, or a diff git could not read.')]);
    }
    if ($files === []) {
      return self::tab('files', 'Files', NULL, [self::text(NULL, 'Nothing changed yet.')]);
    }
    $items = [];
    foreach (array_slice($files, 0, PanelSections::MAX_ITEMS) as $file) {
      $lines = trim(
        ($file['added'] ? '+' . $file['added'] : '') . ' ' . ($file['removed'] ? '−' . $file['removed'] : ''),
      );
      $items[] = [
        'text' => $file['path'] . ($lines === '' ? '' : '  ' . $lines),
        'detail' => NULL,
        'tag' => $file['status'],
        'tone' => match ($file['status']) {
          'A' => 'ok',
          'D' => 'bad',
          default => 'info',
        },
      ];
    }
    $more = count($files) - count($items);
    $sections = [['kind' => 'list', 'title' => NULL, 'items' => $items]];
    if ($more > 0) {
      $sections[] = self::text(NULL, sprintf('And %d more.', $more));
    }
    return self::tab('files', 'Files', (string) count($files), $sections);
  }

  /**
   * Seeker: what the independent reviewer found, and how each closed.
   *
   * @param array<mixed> $run
   *   The run record.
   * @param list<array<string, mixed>> $findings
   *   The evidence store's findings.
   *
   * @return Tab
   *   The tab.
   */
  private static function seekerTab(array $run, array $findings): array {
    $latest = [];
    foreach ($findings as $finding) {
      $ref = self::str($finding['ref'] ?? NULL);
      $latest[$ref ?? count($latest)] = $finding;
    }
    $items = [];
    $open = 0;
    foreach ($latest as $finding) {
      $status = self::str($finding['status'] ?? NULL) ?? 'open';
      $open += $status === 'open' ? 1 : 0;
      $severity = strtoupper(self::str($finding['severity'] ?? NULL) ?? '');
      $items[] = [
        'text' => self::str($finding['finding'] ?? NULL) ?? '',
        'detail' => trim($status . (is_string($finding['inspector'] ?? NULL) ? ' · ' . $finding['inspector'] : '')),
        'tag' => $severity === '' ? NULL : $severity,
        'tone' => $status === 'open' ? (in_array($severity, ['CRITICAL', 'HIGH'], TRUE) ? 'bad' : 'warn') : 'ok',
      ];
    }
    if ($items === []) {
      $holds = is_string($run['seeker_holds'] ?? NULL) ? $run['seeker_holds'] : 'none';
      $text = ($run['seekers'] ?? FALSE) === TRUE
        ? sprintf('No finding yet. The seeker reads the diff at code; open findings hold the run at: %s.', $holds)
        : 'The seeker is off at this level.';
      return self::tab('seeker', 'Seeker', NULL, [self::text(NULL, $text)]);
    }

    return self::tab('seeker', 'Seeker', (string) $open, [['kind' => 'list', 'title' => NULL, 'items' => $items]]);
  }

  /**
   * One of droost's own tabs.
   *
   * @param string $id
   *   The id.
   * @param string $title
   *   The title.
   * @param string|null $badge
   *   The badge, or NULL.
   * @param list<array<string, mixed>> $sections
   *   The sections.
   *
   * @return Tab
   *   The tab.
   */
  private static function tab(string $id, string $title, ?string $badge, array $sections): array {
    return [
      'id' => $id,
      'title' => $title,
      'source' => 'droost',
      'badge' => $badge,
      'sections' => $sections,
      'error' => NULL,
    ];
  }

  /**
   * A text section.
   *
   * @param string|null $title
   *   Its title, or NULL.
   * @param string $text
   *   The text.
   *
   * @return array<string, mixed>
   *   The section.
   */
  private static function text(?string $title, string $text): array {
    return ['kind' => 'text', 'title' => $title, 'text' => $text];
  }

}
