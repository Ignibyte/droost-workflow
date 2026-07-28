<?php

declare(strict_types=1);

namespace Drupal\droost_workflow_mcp\Plugin\Tool;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\droost\Plugin\Tool\DestructiveToolBase;
use Drupal\droost\ProjectRoot;
use Drupal\droost_workflow\Config\Mode;
use Drupal\droost_workflow_mcp\WorkflowFacadeTrait;
use Drupal\mcp_server\Attribute\Tool;
use Mcp\Server\ClientGateway;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * MCP tool that DRIVES a Droost Workflow run: advance, answer, or swap mode.
 *
 * The write half of the workflow's MCP surface, and the fourth front onto the
 * same WorkflowFacade. One destructive tool with `answer` and `swap` as
 * ARGUMENTS rather than three separately gated tools: answering and swapping
 * are sub-operations of driving a run, not independent capabilities, and every
 * extra destructive tool is another separately allow-listed, separately
 * audited surface.
 *
 * STDIO/Drush-only, gated on the TRANSPORT alone and taking no `allow_*` flag —
 * the same posture droost_verify has for the same risk class: it spawns the
 * project's own analysis binaries. droost's config vocabulary belongs to
 * droost, and a submodule minting a flag invents config another module owns.
 *
 * The whole body runs inside the base's Fiber shield. That is not caution: a
 * run can reach the booted-site driver's rendered check, Drupal's renderer
 * suspends the fiber, and the MCP SDK misreads that and silently drops the
 * JSON-RPC response — a call that appears to hang with no error anywhere.
 */
#[Tool(
  id: 'droost_workflow_run',
  label: new TranslatableMarkup('Droost Workflow: Run'),
  description: new TranslatableMarkup('Drives a Droost Workflow run in the current phase: runs that phase\'s gates and advances when they pass. Args: "project" (absolute path to the repository; omit for this site\'s own root), "answer" (answer the question a paused run is waiting on, then resume), "swap" (change mode: "automated" or "pair"). Pass at most one of "answer"/"swap". Returns the outcome, the current phase, the gate report and whether the run is awaiting an answer. STDIO/Drush-only; it writes run state and spawns the project\'s analysis binaries.'),
  inputSchema: [
    'type' => 'object',
    'properties' => [
      'project' => [
        'type' => 'string',
        'description' => 'Absolute path to the repository to drive. Omit to use this site\'s own root.',
      ],
      'answer' => [
        'type' => 'string',
        'description' => 'The answer to the question a paused run is waiting on. Resumes the run.',
      ],
      'swap' => [
        'type' => 'string',
        'description' => 'Change the run\'s mode: "automated" or "pair". Mutually exclusive with "answer".',
      ],
    ],
  ],
  outputSchema: [
    'type' => 'object',
    'properties' => [
      'success' => [
        'type' => 'boolean',
        'description' => 'Whether the tool ran (NOT whether the gates passed — read data.report for that).',
      ],
      'message' => ['type' => 'string', 'description' => 'Human-readable summary.'],
      'data' => ['description' => '{outcome, current_phase, report, awaiting}.'],
    ],
    'required' => ['success', 'message'],
  ],
  readOnly: FALSE,
  destructive: TRUE,
  idempotent: FALSE,
  openWorld: FALSE,
)]
final class WorkflowRun extends DestructiveToolBase {

  use WorkflowFacadeTrait;

  /**
   * Constructs a WorkflowRun tool plugin.
   *
   * @param array<string, mixed> $configuration
   *   The plugin configuration.
   * @param string $plugin_id
   *   The plugin ID.
   * @param mixed $plugin_definition
   *   The typed plugin definition.
   * @param \Drupal\Core\Session\AccountProxyInterface $currentUser
   *   The current user service.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory (base injection; no `allow_*` gate is taken here — the
   *   transport gate is the whole gate, per droost_verify's precedent).
   * @param \Drupal\droost\ProjectRoot $projectRoot
   *   Resolves this site's own repository root, the default target.
   * @param \Symfony\Component\HttpKernel\HttpKernelInterface $kernel
   *   The HTTP kernel the facade's site driver issues its sub-requests through.
   */
  public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    AccountProxyInterface $currentUser,
    ConfigFactoryInterface $configFactory,
    protected ProjectRoot $projectRoot,
    protected HttpKernelInterface $kernel,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $currentUser, $configFactory);
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
      $container->get('config.factory'),
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
    $blocked = $this->requireCliTransport();
    if ($blocked !== NULL) {
      return $blocked;
    }
    $root = self::resolveRoot($this->stringArg($arguments, 'project'), $this->projectRoot->path());
    if (!self::rootIsUsable($root)) {
      return $this->fail(self::unusableRootMessage($root));
    }
    $answer = $this->stringArg($arguments, 'answer');
    $swap = $this->stringArg($arguments, 'swap');
    if ($answer !== '' && $swap !== '') {
      return $this->fail('Pass either "answer" or "swap", not both: answering resumes the run in its current mode, swapping changes the mode without answering.');
    }
    if ($swap !== '') {
      $mode = Mode::tryFrom($swap);
      if ($mode === NULL) {
        // Refused BY NAME, the package's vocabulary rule: an unknown lever
        // value is told to the caller, never coerced into a default.
        return $this->fail(sprintf('Unknown mode "%s". The modes are "automated" and "pair".', $swap));
      }
    }
    else {
      $mode = NULL;
    }

    // The ENTIRE body is shielded, not just the render-adjacent part: `run()`
    // reaches the booted-site driver's rendered check, and that is exactly
    // where the Fiber collision lives.
    return self::shielded(fn (): array => $this->drive($root, $answer, $mode));
  }

  /**
   * Drives the run and builds the response envelope.
   *
   * @param string $root
   *   The repository, already validated.
   * @param string $answer
   *   The answer to a pending question, or '' for none.
   * @param \Drupal\droost_workflow\Config\Mode|null $mode
   *   The mode to swap to, or NULL for none.
   *
   * @return array<string, mixed>
   *   The success envelope.
   */
  private function drive(string $root, string $answer, ?Mode $mode): array {
    $facade = $this->facade();
    if ($answer !== '') {
      $state = $facade->answer($root, $answer);
      return $this->succeed(
        sprintf('Answered the pending question; the run is in %s.', $state->currentPhase->value ?? 'no phase'),
        [
          'outcome' => 'answered',
          'current_phase' => $state->currentPhase?->value,
          'report' => NULL,
          'awaiting' => $state->awaiting !== NULL,
        ],
      );
    }
    if ($mode !== NULL) {
      $state = $facade->swap($root, $mode);
      return $this->succeed(
        sprintf('Swapped the run to %s mode.', $mode->value),
        [
          'outcome' => 'swapped',
          'current_phase' => $state->currentPhase?->value,
          'report' => NULL,
          'awaiting' => $state->awaiting !== NULL,
        ],
      );
    }
    $outcome = $facade->run($root);
    return $this->succeed(
      sprintf('Workflow run: %s.', $outcome->outcome->value),
      [
        'outcome' => $outcome->outcome->value,
        'current_phase' => $outcome->state->currentPhase?->value,
        'report' => $outcome->report?->toArray(),
        'awaiting' => $outcome->question !== NULL,
      ],
    );
  }

}
