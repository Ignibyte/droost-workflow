<?php

declare(strict_types=1);

namespace Droost\Workflow\Driver;

/**
 * The vocabulary of a declared route: a path, and the status it must answer.
 *
 * A spec declares the pages its ticket touches (`declare-route`, or its
 * `## Routes` section), each optionally with the status it must answer:
 * `/camps`, `/admin/reports@403`, `/portal@302`. The gate that rendered them
 * left with droost's Drupal module (2026-10-19); the declarations stay, for
 * the seeker, the browser suite and the record.
 */
final class RenderedRoutes {

  /**
   * What is rendered when nobody names anything: the front page.
   */
  public const DEFAULT = '/';

  /**
   * The statuses a route may be declared to answer an anonymous visitor with.
   *
   * A route is expected to render (200) unless it says otherwise. An admin
   * page is expected to refuse, and a declared refusal is still measured: a
   * page declared `@403` that answers 200 is public, and fails (F-120).
   */
  public const REFUSALS = [401, 403];

  /**
   * The redirects a route may be declared to answer an anonymous visitor with.
   *
   * A page whose honest answer to an anonymous visitor is to send them
   * elsewhere (a 303 to the login page) could only be declared as the
   * refusal it is not (F-179, druplit-02). A declared redirect is measured
   * like a declared refusal: exactly that status, or a finding.
   */
  public const REDIRECTS = [301, 302, 303, 307, 308];

  /**
   * Whether a route may be declared to answer with a status.
   *
   * @param int $status
   *   The status.
   *
   * @return bool
   *   TRUE for 200, one of REFUSALS or one of REDIRECTS.
   */
  public static function declarable(int $status): bool {
    return $status === 200 || in_array($status, [...self::REFUSALS, ...self::REDIRECTS], TRUE);
  }

  /**
   * A route's path and the status it must answer an anonymous visitor with.
   *
   * @param string $route
   *   A route as declared: `/camps`, or `/admin/content/x@403`.
   *
   * @return array{path: string, status: int}
   *   The path, and 200 unless the route names a refusal.
   */
  public static function expectation(string $route): array {
    if (preg_match('/^(\/.*)@(\d{3})$/', trim($route), $m) === 1 && (int) $m[2] !== 200 && self::declarable((int) $m[2])) {
      return ['path' => $m[1], 'status' => (int) $m[2]];
    }
    return ['path' => trim($route), 'status' => 200];
  }

  /**
   * A route string carrying its expected status, as expectation() reads it.
   *
   * @param string $path
   *   The path.
   * @param int $status
   *   200, or one of REFUSALS or REDIRECTS.
   *
   * @return string
   *   The path alone for 200, else `path@status`.
   */
  public static function withStatus(string $path, int $status): string {
    return $status === 200 ? $path : $path . '@' . $status;
  }

}
