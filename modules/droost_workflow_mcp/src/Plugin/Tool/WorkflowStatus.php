<?php

declare(strict_types=1);

namespace Drupal\droost_workflow_mcp\Plugin\Tool;

use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\droost\Plugin\Tool\DroostToolBase;
use Drupal\droost\ProjectRoot;
use Drupal\droost_workflow_mcp\WorkflowFacadeTrait;
use Drupal\mcp_server\Attribute\Tool;
use Mcp\Server\ClientGateway;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * MCP tool that reports a Droost Workflow run's levers, phases and gate tally.
 *
 * The read half of the workflow's MCP surface, and a third front onto the same
 * WorkflowFacade the CLI and the Drush commands drive: an agent asks what the
 * levers actually say and where the run stands, instead of reconstructing
 * either from memory.
 *
 * Read-only in the strict sense — it loads the config and the run state and
 * returns them. It renders nothing, so unlike the run tool it needs no Fiber
 * shield, and it writes nothing, so it takes no destructive gate and no
 * transport gate: it answers over HTTP and STDIO alike.
 */
#[Tool(
  id: 'droost_workflow_status',
  label: new TranslatableMarkup('Droost Workflow: Status'),
  description: new TranslatableMarkup('Reports a Droost Workflow run: the resolved levers (which preset, which gates, and where that came from), the phase order with each phase\'s status, the latest gate tally, and whether the run is awaiting an answer. Args: "project" (absolute path to the repository; omit for this site\'s own root). Read-only.'),
  inputSchema: [
    'type' => 'object',
    'properties' => [
      'project' => [
        'type' => 'string',
        'description' => 'Absolute path to the repository to report on. Omit to use this site\'s own root.',
      ],
    ],
  ],
  outputSchema: [
    'type' => 'object',
    'properties' => [
      'success' => [
        'type' => 'boolean',
        'description' => 'Whether the status could be read (NOT whether the run is healthy — read data for that).',
      ],
      'message' => ['type' => 'string', 'description' => 'Human-readable summary.'],
      'data' => ['description' => 'The levers, the phase statuses, the tally, and the awaiting flag.'],
    ],
    'required' => ['success', 'message'],
  ],
  readOnly: TRUE,
  destructive: FALSE,
  idempotent: TRUE,
  openWorld: FALSE,
)]
final class WorkflowStatus extends DroostToolBase {

  use WorkflowFacadeTrait;

  /**
   * Constructs a WorkflowStatus tool plugin.
   *
   * @param array<string, mixed> $configuration
   *   The plugin configuration.
   * @param string $plugin_id
   *   The plugin ID.
   * @param mixed $plugin_definition
   *   The typed plugin definition.
   * @param \Drupal\Core\Session\AccountProxyInterface $currentUser
   *   The current user service.
   * @param \Drupal\droost\ProjectRoot $projectRoot
   *   Resolves this site's own repository root, the default target.
   * @param \Symfony\Component\HttpKernel\HttpKernelInterface $kernel
   *   The HTTP kernel the facade's site driver would use. Injected for parity
   *   with the run tool even though a status read never reaches the driver, so
   *   the two tools build the identical facade.
   */
  public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    AccountProxyInterface $currentUser,
    protected ProjectRoot $projectRoot,
    protected HttpKernelInterface $kernel,
  ) {
    // FOUR arguments, not five: DroostToolBase does not take a config factory —
    // that is DestructiveToolBase's, for the gates a read-only tool has none of.
    // PHP silently ignores a surplus argument, so passing one would have looked
    // fine at runtime and only the analyser would ever have said otherwise.
    parent::__construct($configuration, $plugin_id, $plugin_definition, $currentUser);
  }

  /**
   * {@inheritdoc}
   *
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   The service container.
   * @param array<string, mixed> $configuration
   *   The plugin configuration.
   * @param string $plugin_id
   *   The plugin ID.
   * @param mixed $plugin_definition
   *   The plugin definition.
   */
  #[\Override]
  public static function create(
    ContainerInterface $container,
    array $configuration,
    $plugin_id,
    $plugin_definition,
  ): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('current_user'),
      $container->get('droost.project_root'),
      $container->get('http_kernel'),
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function httpKernel(): HttpKernelInterface {
    return $this->kernel;
  }

  /**
   * {@inheritdoc}
   */
  public function execute(array $arguments, ClientGateway $gateway): mixed {
    $root = self::resolveRoot($this->stringArg($arguments, 'project'), $this->projectRoot->path());
    if (!self::rootIsUsable($root)) {
      return $this->fail(self::unusableRootMessage($root));
    }
    return $this->succeed(
      sprintf('Droost Workflow status for %s.', $root),
      $this->facade()->status($root),
    );
  }

}
