<?php

declare(strict_types=1);

namespace Droost\Workflow\WorkItem;

use Droost\Workflow\Config\WorkItemSettings;

/**
 * The work-item source a project's lever file asks for, if it is built in.
 *
 * `work_item.provider: markdown` is solo mode's tickets-as-files. Any other
 * provider (jira, …) keeps today's meaning: metadata for an integration
 * outside the engine, and no source here.
 */
final class WorkItemSources {

  /**
   * The built-in source the settings name, or NULL.
   *
   * @param \Droost\Workflow\Config\WorkItemSettings|null $settings
   *   The lever file's work_item block, NULL when it has none.
   * @param string $projectRoot
   *   The project the source reads.
   *
   * @return \Droost\Workflow\WorkItem\WorkItemSourceInterface|null
   *   The source.
   *
   * @throws \Droost\Workflow\WorkItem\WorkItemError
   *   When the configured directory or prefix is refused.
   */
  public static function fromSettings(?WorkItemSettings $settings, string $projectRoot): ?WorkItemSourceInterface {
    if ($settings === NULL || $settings->provider !== 'markdown') {
      return NULL;
    }

    return new MarkdownWorkItemSource(
      $settings->markdown['dir'] ?? MarkdownWorkItemSource::DEFAULT_DIR,
      $settings->markdown['prefix'] ?? MarkdownWorkItemSource::DEFAULT_PREFIX,
      $projectRoot,
    );
  }

}
