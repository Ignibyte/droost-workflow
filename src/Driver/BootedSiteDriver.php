<?php

declare(strict_types=1);

namespace Drupal\droost_workflow\Driver;

use Drupal\droost_workflow\Config\GateSettings;
use Drupal\droost_workflow\Gate\GateResult;
use Drupal\droost_workflow\Gate\GateStatus;
use Drupal\droost_workflow\Gate\SiteDriverInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Runs the site-dependent gates against a booted Drupal site.
 *
 * The other half of the two-surface requirement. Everything else in this
 * package is identical on both surfaces; this class is the entire difference
 * between "skipped, no site" and an actual answer.
 *
 * Only `rendered_check` today. It exists here rather than in droost because
 * no droost tool renders anything — `droost_verify` runs phpcs, phpstan,
 * phpunit and deprecations, none of which fetch a page. Checking that the
 * site actually renders is precisely the gate that had nothing behind it, so
 * this is where artifacts-are-truth stops being a slogan.
 */
final class BootedSiteDriver implements SiteDriverInterface {

  /**
   * The routes checked when a repo names none.
   *
   * @var list<string>
   */
  public const DEFAULT_ROUTES = ['/'];

  /**
   * Constructs a BootedSiteDriver.
   *
   * @param \Symfony\Component\HttpKernel\HttpKernelInterface $kernel
   *   The site's kernel. Sub-requests go through it in-process rather than
   *   over the network, so the check needs no listening web server and no
   *   guess about which hostname the site answers on.
   * @param callable(): int $clock
   *   Returns milliseconds.
   */
  public function __construct(
    private readonly HttpKernelInterface $kernel,
    private readonly mixed $clock,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function available(): bool {
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function supports(): array {
    return ['rendered_check'];
  }

  /**
   * {@inheritdoc}
   */
  public function run(GateSettings $gate, string $projectRoot): GateResult {
    if ($gate->name !== 'rendered_check') {
      // Refused rather than silently skipped: the runner already asked
      // supports(), so arriving here with anything else is a bug in the
      // caller and should say so.
      return GateResult::toolMissing(
        $gate->name,
        sprintf('%s (this driver only runs rendered_check)', $gate->name),
      );
    }

    $routes = $this->routes($gate);
    $started = $this->tick();
    $findings = [];

    foreach ($routes as $path) {
      $finding = $this->check($path);
      if ($finding !== NULL) {
        $findings[] = $finding;
      }
    }

    $elapsed = $this->tick() - $started;
    $failed = count($findings);

    return GateResult::ran(
      'rendered_check',
      $failed === 0 ? GateStatus::Passed : GateStatus::Failed,
      $failed === 0 ? 0 : 1,
      $elapsed,
      $failed === 0
        ? sprintf('%d route(s) rendered', count($routes))
        : sprintf('%d of %d route(s) did not render', $failed, count($routes)),
      $findings,
      'rendered_check: ' . implode(', ', $routes),
    );
  }

  /**
   * Checks one route, returning a finding when it did not render.
   *
   * @param string $path
   *   The internal path.
   *
   * @return array<string, mixed>|null
   *   The finding, or NULL when the route rendered.
   */
  private function check(string $path): ?array {
    try {
      $response = $this->kernel->handle(
        Request::create($path),
        HttpKernelInterface::SUB_REQUEST,
        FALSE,
      );
    }
    catch (\Throwable $e) {
      // An exception IS the finding. Letting it escape would fail the whole
      // gate run rather than recording that one route is broken.
      return [
        'route' => $path,
        'status' => NULL,
        'problem' => 'threw ' . $e::class . ': ' . $e->getMessage(),
      ];
    }

    $status = $response->getStatusCode();
    $body = (string) $response->getContent();

    if ($status !== 200) {
      return ['route' => $path, 'status' => $status, 'problem' => 'not 200'];
    }
    // A 200 with nothing in it is a rendered page in name only, and is
    // exactly what a broken theme or an empty view produces.
    if (trim($body) === '') {
      return ['route' => $path, 'status' => 200, 'problem' => 'empty body'];
    }
    return NULL;
  }

  /**
   * The routes this gate checks.
   *
   * @param \Drupal\droost_workflow\Config\GateSettings $gate
   *   The gate's levers.
   *
   * @return list<string>
   *   Internal paths.
   */
  private function routes(GateSettings $gate): array {
    $configured = $gate->option('routes');
    if (!is_string($configured) || trim($configured) === '') {
      return self::DEFAULT_ROUTES;
    }
    $paths = array_values(array_filter(
      array_map(trim(...), explode(',', $configured)),
      static fn (string $p): bool => $p !== '',
    ));
    return $paths === [] ? self::DEFAULT_ROUTES : $paths;
  }

  /**
   * The current millisecond count.
   *
   * @return int
   *   Milliseconds.
   */
  private function tick(): int {
    /** @var int $now */
    $now = ($this->clock)();
    return $now;
  }

}
