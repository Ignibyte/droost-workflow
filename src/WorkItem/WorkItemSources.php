<?php

declare(strict_types=1);

namespace Droost\Workflow\WorkItem;

use Droost\Workflow\Config\WorkItemSettings;
use Droost\Workflow\State\RunStateStore;

/**
 * The work-item source a project's lever file asks for, if it is built in.
 *
 * `work_item.provider: markdown` is solo mode's tickets-as-files, and
 * `droost_cockpit` cockpit mode's tickets over the cockpit's HTTP API (with
 * the relay that carries the run's events there). Any other provider (jira,
 * …) keeps today's meaning: metadata for an integration outside the engine,
 * and no source here.
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
    if ($settings !== NULL && $settings->provider === 'droost_cockpit' && $settings->cockpit !== NULL) {
      [$base, $token, $missing] = self::cockpitEnvironment($settings->cockpit);
      return new CockpitWorkItemSource(new CockpitHttp(), $base, $token, (new RunStateStore($projectRoot))->directory(), $missing);
    }
    if ($settings === NULL || $settings->provider !== 'markdown') {
      return NULL;
    }

    return new MarkdownWorkItemSource(
      $settings->markdown['dir'] ?? MarkdownWorkItemSource::DEFAULT_DIR,
      $settings->markdown['prefix'] ?? MarkdownWorkItemSource::DEFAULT_PREFIX,
      $projectRoot,
    );
  }

  /**
   * The relay that carries the run-event log to the cockpit, in cockpit mode.
   *
   * @param \Droost\Workflow\Config\WorkItemSettings|null $settings
   *   The lever file's work_item block.
   * @param string $projectRoot
   *   The project whose log it carries.
   *
   * @return \Droost\Workflow\WorkItem\CockpitEventRelay|null
   *   The relay, or NULL outside cockpit mode.
   */
  public static function relayFor(?WorkItemSettings $settings, string $projectRoot): ?CockpitEventRelay {
    if ($settings === NULL || $settings->provider !== 'droost_cockpit' || $settings->cockpit === NULL) {
      return NULL;
    }
    [$base, $token, $missing] = self::cockpitEnvironment($settings->cockpit);

    return new CockpitEventRelay(new CockpitHttp(), $base, $token, (new RunStateStore($projectRoot))->directory(), $missing);
  }

  /**
   * The cockpit's base URL and token, read from the variables the block names.
   *
   * Read here, when a surface is built, never at config time: an unset
   * variable makes every cockpit call fail as an offline cockpit does,
   * naming the variable, and never the value of anything.
   *
   * @param array{url_env: string, token_env: string, path: string} $cockpit
   *   The cockpit block.
   *
   * @return array{string, string, string|null}
   *   The API base, the token, and why the cockpit cannot be reached before
   *   trying (NULL when both variables are set).
   */
  private static function cockpitEnvironment(array $cockpit): array {
    $url = getenv($cockpit['url_env']);
    $token = getenv($cockpit['token_env']);
    $missing = NULL;
    if (!is_string($url) || trim($url) === '') {
      $missing = sprintf('the environment variable %s (work_item.cockpit.url_env) is not set', $cockpit['url_env']);
    }
    elseif (!is_string($token) || $token === '') {
      $missing = sprintf('the environment variable %s (work_item.cockpit.token_env) is not set', $cockpit['token_env']);
    }

    return [
      is_string($url) ? rtrim(trim($url), '/') . $cockpit['path'] : '',
      is_string($token) ? $token : '',
      $missing,
    ];
  }

}
