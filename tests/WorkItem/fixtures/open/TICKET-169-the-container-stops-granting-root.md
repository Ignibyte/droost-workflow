---
title: TICKET-169-the-container-stops-granting-root
status: open
ticket_number: 169
type: bug
created: 2026-09-28
intake: docs/planning/intake/INTAKE-druplit-on-drupal.md
pipeline_spec: docs/planning/pipeline/active/pipeline-no-root-in-the-container.spec.md
---

# TICKET-169-the-container-stops-granting-root

## Summary

Today every agent seat in the druplit container can become root. The
container inherits the ddev web image's `ALL ALL=NOPASSWD: ALL` sudo rule and
850 world-writable system directories, among them PHP's `conf.d` and
`/usr/local/bin`. The `claude` binary every seat is launched from is writable
by the seats, and the owner's host ssh-agent socket is reachable by path.

This ticket closes all four:
- sudo is removed from the image;
- a build-time sweep strips world-write from the image's root filesystem;
- `claude` becomes a root-owned regular file;
- the ssh-agent and xhprof mounts are masked.

To make the lock-down possible, the entrypoint runs as root for setup only,
and supervisord (root) starts every program through a new `druplit-run`
helper as the one unprivileged user that runs everything today. TICKET-170
then splits that user in two.

## Why

- **Any seat is root.** Probed live on 2026-09-28 in
  `ddev-druplit-scratch-druplit`: `/etc/sudoers.d/ddev` holds
  `ALL ALL=NOPASSWD: ALL`, and `sudo -n true` succeeds as uid 1793721006, the
  uid every seat runs as.
  - A worker's `Bash` can therefore read or change anything in the container:
    the store, the cockpit token, the bridge, the contract suite's vendor
    (which TICKET-154 made root-owned precisely so seats could not edit the
    grader).
  - The overlay's `Read(//…)` denies (`app/packs/worker/overlay.json`) bind
    only claude's file tools, not `Bash` (TICKET-158:977-979).
- **Root does not even need sudo.** The launcher's PATH starts
  `/usr/local/sbin:/usr/local/bin` (`../ddev-druplit/druplit/bin/druplit-php-fpm:66`),
  and `/usr/local/bin` is world-writable. PHP reads every `conf.d/*.ini` at
  start, and `conf.d` is world-writable. So a seat can plant an
  `auto_prepend_file` that every later app process runs.
- **TICKET-170's user split is theatre without this.** A second uid means
  nothing while any uid can `sudo`, or can write the ini the other uid loads.
- **The owner foresees a public offering (D2).** "if we offer this as a
  server it will use ddev but be public." A container whose agents are root
  cannot be offered.

## The seam, at HEAD `3e57ce3` (ddev-druplit `d3dd48e`) (evidence)

Live probes were run 2026-09-28 in `ddev-druplit-scratch-druplit`, read-only,
as the container user.

**sudo**
- `/etc/sudoers.d/ddev`:
  `ALL ALL=NOPASSWD: ALL` / `Defaults env_keep += "*_proxy *_PROXY DDEV_* IS_DDEV_PROJECT PHP_DEFAULT_VERSION"`.
- `sudo -n true` → success. `/usr/bin/sudo` is present. The `sudo` group is
  empty, and `/etc/sudoers` is 0440 root.
- The rule arrives with the base image: `FROM ${BASE_IMAGE}`
  (`druplit/Dockerfile:38`), the project's own built web image
  (`docker-compose.druplit.yaml:17`).
- Nothing druplit ships calls sudo. A grep for `sudo` over `../ddev-druplit`,
  `app/src`, `app/bin`, `contract/targets`, `contract/bin` and `bridge/src`
  has no hits.

**World-writable paths in the image**
- `find / -xdev -type d -perm -0002 ! -perm -1000` lists 850 directories.
  Among them:
  - `/etc/php/8.4/cli/conf.d`, `/etc/php/8.4/fpm/conf.d`, `/etc/php/8.4/fpm`
    (all 0777 root);
  - `/usr/local/bin` (0777);
  - `/etc/nginx` (0777);
  - `/etc/alternatives`, `/etc/ssl/certs`, `/etc/apache2/*`, `/run/*`.
- World-writable files include `/etc/alternatives/README` and
  `/etc/apache2/sites-available/*.conf`.
- The reason they must stay writable today: the entrypoint copies the
  project's ini files into both `conf.d` dirs **as the container user**
  (`druplit/bin/druplit-entrypoint:38-46`).
- `/opt/druplit`, `/usr/local/share/druplit` and `/usr/share/php` are 0755
  root, and are fine.
- `/mnt/ddev_config` (the add-on's scripts and configs) is a **read-only**
  bind (live `docker inspect`: `rw=false`), so seats cannot edit what
  supervisord runs.

**claude**
- `druplit/Dockerfile:71-80` installs the pinned 2.1.282 as the user into
  `~/.local`, then links `/usr/local/bin/claude` to it.
- Live: the link is root-owned, and its target is writable by the seat user.
- The bridge resolves `DRUPLIT_CLAUDE_BIN` once and keeps symlinks
  (`bridge/src/main.rs:102-104`, `:135-138`), so a replaced target runs at the
  next spawn.
- This is recorded as an owner residual:
  `docs/planning/pipeline/completed/pipeline-druplit-bridge-crate.notes.md:305`
  (row 19) and `:533-536` ("fix = root-owned install").

**The ssh agent and xhprof** (all from `volumes_from: web`,
`docker-compose.druplit.yaml:48-49`)
- `docker-compose.druplit.yaml:42` sets `SSH_AUTH_SOCK=/home/.ssh-agent/socket`
  on the service.
- Live mounts include the volume `ddev-ssh-agent_socket_dir` →
  `/home/.ssh-agent` (rw). Its `proxy-socket` is `srw-rw-rw-` in a 0777
  directory.
- Panes get no `SSH_AUTH_SOCK` (the allow-list, `bridge/src/tmux.rs:59-72`),
  but any seat can use the socket by path, with the owner's GitHub identity.
- Live mounts also include a rw bind of the project's `.ddev/xhprof` →
  `/usr/local/bin/xhprof`: a host-writable directory inside a system path.
- The web service's full mount list (rendered
  `.ddev/.ddev-docker-compose-full.yaml`):
  - `project_mutagen` → `/var/www` and `/tmp/project_mutagen`;
  - `.ddev` → `/mnt/ddev_config` (read-only);
  - `.ddev/xhprof` → `/usr/local/bin/xhprof`;
  - the files dir;
  - `.git`;
  - `ddev-global-cache`;
  - `ddev-ssh-agent_socket_dir`.
- The list depends on the project's performance mode and upload dirs, which
  is why the add-on mirrors web with `volumes_from`
  (`docker-compose.druplit.yaml:2-7`).
- No docker socket is mounted (`/var/run/docker.sock` is absent), so a seat
  cannot reach the web container, whose sudo rule this ticket leaves alone.

**Who runs what**
- `docker-compose.druplit.yaml:22`: `user: "${DDEV_UID}:${DDEV_GID}"`.
- The entrypoint execs supervisord as that user
  (`druplit/bin/druplit-entrypoint:73`).
- No program sets `user=` (`druplit/supervisord.conf:5-24`,
  `druplit/supervisor/nginx.conf:2-9`, `druplit/stacks/php/supervisor/*.conf`).
- php-fpm pools carry no user: "fpm ignores both unless it runs as root"
  (`druplit/php-fpm/php.conf:16-17`).
- `/run/druplit`, `/var/lib/druplit-auth` and `/var/lib/druplit` are
  created and owned by the user uid (`druplit/Dockerfile:53-55`).

**The commands that exec into the container**
- The container command `commands/druplit/druplit` calls `supervisorctl`
  (`:9`), reads `/proc/<pid>/environ` (`:69`, `:76`), and runs php, contract
  and claude (`:240`, `:290`, `:311`).
- The host command `commands/host/druplit-copy-login` uses a bare
  `docker exec` (`:47-48`, `:55`, `:57`). Its user is the service's `user:`,
  so a root service would make it write the shared login as root.

## EARS Requirements

| ID | EARS Requirement | Verification |
|---|---|---|
| REQ-001 | While the druplit container runs, a process running as the seat user shall not be able to gain root: `sudo -n true` shall fail and the druplit image shall contain no `/etc/sudoers.d/ddev` and no NOPASSWD grant. | live (as the seat uid) + `docker run --rm <image> grep -rn NOPASSWD /etc/sudoers.d` empty |
| REQ-002 | The druplit image's root filesystem shall contain no world-writable directory without the sticky bit and no world-writable regular file. | live `find` (Regression plan) empty |
| REQ-003 | The `claude` the bridge launches (`/usr/local/bin/claude`) shall be a root-owned regular file, not writable by the seat user, at the pinned version. | live `stat` + `--version` |
| REQ-004 | The druplit container shall expose no ssh agent: nothing under `/home/.ssh-agent` shall be a socket, and no supervised program's environment shall carry `SSH_AUTH_SOCK`. | live `find -type s` + `/proc/<pid>/environ` of each program |
| REQ-005 | The druplit container shall mount no host-writable directory into a system path (the `.ddev/xhprof` → `/usr/local/bin/xhprof` bind is masked). | live `docker inspect` mounts |
| REQ-006 | When the container starts, the entrypoint shall do its setup as root and supervisord shall start every program through `druplit-run seat`, so that `ps` shows no root process other than supervisord (and its `druplit-run` exec, which has already become the seat user). | live `ps -eo user,pid,args` |
| REQ-007 | Every `ddev druplit` subcommand (status, logs, url, contract, php, doctor, login, cutover) and `ddev druplit-copy-login` shall keep working, and every file they write shall be owned by the seat user as today. | live, per subcommand + `stat` |
| REQ-008 | After the change, `/api/health`'s `commit` shall equal HEAD, the php contract suite shall be green, `seat:probe --kind=manager` shall spawn and purge, and `ddev druplit doctor` shall report the agent login row as before. | live + contract |

## Scope

- **In (all in `repo: ddev-druplit`):**
  - the Dockerfile's sudo removal, sweep and root-owned claude;
  - the compose `user:` change, the `DRUPLIT_SEAT_USER` env, dropping
    `SSH_AUTH_SOCK`, and the two tmpfs masks;
  - `druplit-run`;
  - the entrypoint as root;
  - every supervisor program through `druplit-run seat`;
  - the container command's privilege handling;
  - `druplit-copy-login`'s `-u`.
- **In (druplit):** the README security note (the short README from
  TICKET-166), and a decisions record at complete.
- **Out:**
  - a second uid, and anything about who may read the store (TICKET-170);
  - the web container's sudo rule (ddev's; seats never run there);
  - a published image.

## Locked decisions

1. **sudo is removed from the druplit image, not narrowed.** Nothing druplit
   ships uses it (grep above). TICKET-170 adds back exactly one rule, a
   drop-privilege rule for the app user. Rejected: keeping ddev's file and
   adding restrictions, because a NOPASSWD rule for `ALL` users cannot be
   narrowed per user without replacing it.
2. **World-write is stripped by a sweep at build time, not by a list.**
   Rejected: a hand-maintained list. There are 850 directories, and the base
   image is ddev's, re-released on ddev's schedule. The build also fails if
   the sweep leaves anything, so a new ddev release cannot silently reopen it.
3. **PID 1's setup runs as root, and every program runs as the unprivileged
   user through `druplit-run`.** It uses `setpriv --reuid --regid
   --init-groups`, which execs with no fork, so supervisord's TERM reaches the
   program itself.
   - Rejected: staying unprivileged and keeping `conf.d` writable, which
     defeats REQ-002.
   - Rejected: supervisord's `user=` with the host's username. The supervisor
     files are static `#ddev-generated` files, and the seat username differs
     per host (`DDEV_USER`).
   - Rejected: `runuser` (`/usr/sbin/runuser`, present, and itself 0777 in
     the base image until D2's sweep). It forks and relays signals, so TERM
     would hit `runuser`, not the program.
4. **The seat role runs with `--no-new-privs`.** Even a setuid binary a later
   base image brings cannot raise a seat. The app role (TICKET-170) must not
   get it, because its one sudo rule needs setuid.
5. **`claude` is installed by root, as a regular file.** Rejected: chowning
   the per-user `~/.local` install to root. That keeps a symlink the bridge
   follows (`main.rs:135-138`) into a tree whose layout belongs to claude's
   updater. The pin plus `DISABLE_AUTOUPDATER=1` (`druplit/druplit.env:26`)
   already keep the version fixed.
6. **Mask, don't replace, `volumes_from`.** Two long-syntax `type: tmpfs`
   mounts at `/home/.ssh-agent` and `/usr/local/bin/xhprof` override those two
   destinations. Docker lets a `Mounts` entry replace a `volumes_from` mount
   point with the same destination. Everything else stays mirrored from web,
   so it follows web in every performance mode.
   - Rejected: an explicit mount list. It is fragile across performance
     modes and upload dirs (`docker-compose.druplit.yaml:2-7`).
   - Fallback, if Docker refuses the override on this host: the explicit
     list, recorded as an owner-visible change in the notes.
7. **The ini copy stays in the entrypoint, now as root, into the root-owned
   `conf.d`.** Rejected: `PHP_INI_SCAN_DIR` pointing at the read-only
   `/mnt/ddev_config/php`. Seat processes only get allow-listed env
   (`tmux.rs:59-72`), so the seats' PHP would silently lose the project's ini
   (the JIT-off line among them, TICKET-167).
8. **The container command runs as root and drops per step.**
   `supervisorctl`, `/proc/<pid>/environ` reads and nginx signals stay root.
   Everything that runs app or agent code (php CLI, contract, doctor, login)
   goes through `druplit-run seat`. Rejected: dropping to the user at the top,
   because `supervisorctl` would then need a group-readable control socket
   for no gain.

## Design (settled)

All paths in D1–D8 are in `../ddev-druplit`.

- **D1 — Remove sudo (REQ-001).** In `druplit/Dockerfile`'s final stage,
  right after the `tmux` install (`:43`), add
  `RUN rm -f /etc/sudoers.d/ddev && ! grep -rqs NOPASSWD /etc/sudoers.d`.
  The build fails if any NOPASSWD grant is left. Keep the `sudo` binary:
  TICKET-170 adds one rule.

- **D2 — The sweep (REQ-002).** This is the last root `RUN` of the final
  stage, after the claude install (D3) and the vendor bake (`:59-70`):
  ```dockerfile
  RUN find / -xdev \( -path /proc -o -path /sys -o -path /dev -o -path /tmp -o -path /var/tmp \) -prune \
        -o \( -type d -perm -0002 ! -perm -1000 -o -type f -perm -0002 \) -print0 \
      | xargs -0 -r chmod o-w \
   && test -z "$(find / -xdev \( -path /proc -o -path /sys -o -path /dev -o -path /tmp -o -path /var/tmp \) -prune \
        -o \( -type d -perm -0002 ! -perm -1000 -o -type f -perm -0002 \) -print -quit)"
  ```
  Sticky world-writable directories (`/tmp`, `/var/tmp`) keep their mode.
  Volumes mounted at run time are data and are not touched.

- **D3 — Root-owned claude (REQ-003).** Replace `druplit/Dockerfile:71-81`
  with a root step:
  ```dockerfile
  SHELL ["/bin/bash", "-o", "pipefail", "-c"]
  RUN export HOME=/tmp/claude-install \
   && curl -fsSL https://claude.ai/install.sh | bash -s 2.1.282 \
   && install -o root -g root -m 0755 "$(readlink -f /tmp/claude-install/.local/bin/claude)" /usr/local/bin/claude \
   && rm -rf /tmp/claude-install \
   && /usr/local/bin/claude --version | grep -q '^2\.1\.282'
  ```
  - Keep the pipefail + version-check rationale comment (`:72-75`).
  - Remove the trailing `USER ${username}`: the service runs as root (D4),
    and `druplit-run` drops privileges per program.

- **D4 — Compose (REQ-004, REQ-005, REQ-006).** In `docker-compose.druplit.yaml`:
  - `user: "0:0"` (`:22`), with a comment: root is PID 1's setup and
    supervisord only; every program drops through `druplit-run`.
  - Add `DRUPLIT_SEAT_USER=${DDEV_USER}` to `environment:`.
  - Delete `SSH_AUTH_SOCK` (`:42`).
  - Append to `volumes:`:
    ```yaml
    # Masks over two volumes_from mounts (TICKET-169): the host's ssh agent
    # and a host-writable dir in /usr/local/bin. A Mounts entry replaces a
    # volumes_from mount point of the same destination.
    - type: tmpfs
      target: /home/.ssh-agent
      tmpfs: {size: 4096, mode: 0555}
    - type: tmpfs
      target: /usr/local/bin/xhprof
      tmpfs: {size: 4096, mode: 0555}
    ```
  - Update the header comment (`:2-7`), which currently says `volumes_from`
    brings the ssh-agent socket.

- **D5 — `druplit/bin/druplit-run` (new, 0755).**
  ```bash
  #!/usr/bin/env bash
  #ddev-generated
  # druplit-run seat <argv…> — exec <argv> as the seat user (TICKET-169):
  # setpriv, no fork (supervisord's TERM reaches the program), no new
  # privileges, HOME/USER/LOGNAME of that user (supervisord runs as root, and
  # the bridge hands HOME/USER/LOGNAME to every pane).
  set -euo pipefail
  role=${1:-}; shift || true
  case "$role" in
    seat) user=${DRUPLIT_SEAT_USER:?DRUPLIT_SEAT_USER is unset}; extra=(--no-new-privs) ;;
    *) echo "usage: druplit-run seat <argv…>" >&2; exit 2 ;;
  esac
  IFS=: read -r _ _ uid gid _ home _ < <(getent passwd "$user") || { echo "druplit-run: no user $user" >&2; exit 1; }
  [ "$uid" != 0 ] || { echo "druplit-run: refusing uid 0" >&2; exit 1; }
  export HOME="$home" USER="$user" LOGNAME="$user"
  exec setpriv --reuid="$uid" --regid="$gid" --init-groups "${extra[@]}" -- "$@"
  ```
  TICKET-170 adds the `app` role, and nothing else.

- **D6 — Programs (REQ-006).** Prefix every program `command=` with
  `/mnt/ddev_config/druplit/bin/druplit-run seat `:
  - `druplit/supervisor/nginx.conf:4`;
  - `druplit/stacks/php/supervisor/druplit-php.conf:6`;
  - `druplit/stacks/php/supervisor/druplit-php-scheduler.conf:7`;
  - `druplit/stacks/php/supervisor/druplit-bridge.conf:22`.
  - The bridge's `environment=` PATH (`:24`) keeps `%(ENV_PATH)s`, which is
    now the root supervisord's PATH. Check that it has no sbin-first surprise
    and pin it if it does.
  - `druplit/supervisord.conf` stays as is: `[unix_http_server] chmod=0700`
    is now a root-owned socket, and only root drives it (Locked 8).

- **D7 — The entrypoint as root (REQ-006, REQ-007).** In
  `druplit/bin/druplit-entrypoint`:
  - Start by resolving `DRUPLIT_SEAT_USER` with `getent`, and fail closed if
    it is unset, unknown or uid 0.
  - `:14`: `mkdir -p` as today, then `chown` the created state dirs,
    `/run/druplit` and the token to the seat user (existing volumes are
    already seat-owned; this keeps new ones the same).
  - The token block (`:20-30`) writes as root under `umask 077`, then
    `chown` to the seat user.
  - The ini copy (`:38-46`) runs as root. That is now the only way to write
    `conf.d`.
  - `:73` still execs supervisord, now as root.

- **D8 — The commands (REQ-007).**
  - **`commands/druplit/druplit`:**
    - Add `seat=(/mnt/ddev_config/druplit/bin/druplit-run seat)` next to
      `sup` (`:9`).
    - `login` (`:240`): `"${seat[@]}" env CLAUDE_CONFIG_DIR="$DRUPLIT_AUTH_DIR" /usr/local/bin/claude`.
    - `doctor_cmd`: the environ reads (`:69`, `:76`) stay root, and the run
      at `:98` becomes `"${seat[@]}" env -i … php … doctor`.
    - `contract` (`:290`): `exec "${seat[@]}" /opt/druplit/contract/bin/contract "$@"`.
    - `php` (`:311`): `exec "${seat[@]}" "$launcher" …`.
    - The cutover's launcher call (whatever TICKET-166 leaves of it) goes
      through `"${seat[@]}"` too.
    - If the command finds itself not running as root (an older ddev's
      `exec` user), it prints one line and exits 2 rather than half-working.
  - **`commands/host/druplit-copy-login`:** `docker exec -u "$(id -u):$(id -g)"`
    at `:47`, `:55` and `:57`. ddev maps the host uid to the seat uid, so the
    shared login stays seat-owned.

- **D9 — Docs and record.**
  - The druplit README's security note: seats cannot become root; the one
    unprivileged user until TICKET-170.
  - The add-on README: the service's `user:` and why.
  - At complete, record `AD-druplit-no-root-in-the-druplit-container-001` in
    `docs/planning/decisions.md`. Memory: `ddev exec -s druplit` is now root,
    so use `druplit-run seat` or `docker exec -u` for agent-side checks.

- **D10 — Spikes to settle in Phase 1 (record the answers in the notes).**
  - **S1:** a tmpfs `Mounts` entry overrides the `volumes_from` mount at the
    same destination on Docker Desktop. Prove it with `docker inspect` after
    `ddev restart`; if it fails, use the Locked 6 fallback.
  - **S2:** how ddev runs a container command after `user: "0:0"` (as the
    compose user, or as the host uid). D8's not-root guard covers both, but
    record which.

## Regression test plan

Each live check is a script in the session scratchpad, `docker cp`'d and run
with `docker exec -u <seat uid>`. The container's `/tmp` is wiped by a
restart (memory lesson).

| REQ | Check | Kind |
|---|---|---|
| REQ-001 | `sudo -n true` exits non-zero as the seat uid; `ls /etc/sudoers.d` has no `ddev`; `docker exec -u 0 … grep -rn NOPASSWD /etc/sudoers.d` empty | live |
| REQ-002 | `find / -xdev \( -path /proc -o -path /sys -o -path /dev -o -path /tmp -o -path /var/tmp \) -prune -o \( -type d -perm -0002 ! -perm -1000 -o -type f -perm -0002 \) -print` empty; `test -w /etc/php/8.4/fpm/conf.d` false as the seat | live |
| REQ-003 | `stat -c '%U %F %a' /usr/local/bin/claude` = `root regular file 755`; `test -w` false as the seat; `claude --version` = 2.1.282 | live |
| REQ-004 | `find /home/.ssh-agent -type s` empty; no `SSH_AUTH_SOCK=` in any supervised program's `/proc/<pid>/environ` (read as root) | live |
| REQ-005 | `docker inspect` shows tmpfs at `/home/.ssh-agent` and `/usr/local/bin/xhprof`, and no bind from `.ddev/xhprof` | live |
| REQ-006 | `ps -eo user,args` has root only for supervisord; nginx, fpm masters and workers, the scheduler, the bridge, tmux and claude all run as the seat user; `grep NoNewPrivs /proc/<bridge pid>/status` = 1 | live |
| REQ-007 | each of `ddev druplit status\|logs\|url\|contract --target=php\|php seat:probe …\|doctor\|login (to the claude prompt, then /exit)` runs; `ddev druplit-copy-login`, then `stat -c %U /var/lib/druplit-auth/.credentials.json` = the seat user | live |
| REQ-008 | health `commit` == HEAD; `ddev druplit contract --target=php` green (count recorded); `seat:probe --kind=manager --wait=20` spawns and purges; doctor's `claude agent login` row as before | live + contract |

- Mutation surface: DEFERRED (Q12). The change is image, compose and shell
  wiring. Its failure branches (D1's `! grep`, D2's `test -z`, D5's refusals,
  D8's not-root guard) must each be shown to fire once. Record a deliberate
  trip of each (PR-druplit-a-meta-gate-must-be-seen-to-fire-on-this-host-001).
- `browser_testable`: no (no UI change). REQ-008's health check stands in.

## Verification honesty

- **Proven live:** that a seat cannot `sudo`, cannot write the system
  directories this image had open, cannot replace `claude`, and cannot find
  the ssh agent.
- **Not proven:** that no other route to root exists. REQ-002's sweep is the
  broad guard; a kernel or runtime escape is out of scope.
- **The web container keeps ddev's `ALL ALL=NOPASSWD: ALL`.** Seats never run
  there, and no docker socket reaches it from druplit (checked). That is
  stated, not proven against every future mount.
- **Still open after this ticket:** a seat can still read the store, the
  cockpit token and every socket, because it is the same uid as the app.
  TICKET-170 closes that.
- **S1 and S2 are platform questions** (Docker Desktop, the ddev version).
  Their answers hold for this host and ddev release, and the notes record
  both.

## Notes

- **Depends on:** TICKET-166. It edits the same Dockerfile, compose file,
  entrypoint and command; sequencing avoids conflicts.
- **Sequenced:** 2 of 18.
- **Repos:** `repo: ddev-druplit` for D1–D8 (its own commit; its gate is
  shellcheck plus a live scratch restart). druplit for the README note and,
  at complete, the decisions record.
- **Deploying to scratch:** `ddev add-on get /Users/cpeppers/Projects/contrib/droost/ddev-druplit`,
  then `ddev utility rebuild -s druplit && ddev restart` (memory; a rebuild
  alone restarts the old image).
- **Hands to TICKET-170:** `druplit-run` gains the `app` role. The sudo binary
  stays for 170's one rule. Programs switch roles per D6's list.
- **Related:**
  - `docs/planning/pipeline/completed/pipeline-druplit-bridge-crate.notes.md:305`,
    `:533-536` (the claude residual this closes);
  - `docs/planning/tickets/closed/TICKET-155-druplit-bridge-crate.md:236-250`
    (Locked 11, the same-uid acceptance 170 revisits);
  - `docs/planning/decisions.md:102` (AD-druplit-operator-verbs-cockpit-only-001's
    residual, whose root-level half this closes).
