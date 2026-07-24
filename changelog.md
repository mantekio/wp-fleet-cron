# Changelog

All notable changes to **WP Fleet Cron** are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project adheres
to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.9.0]

### Added
- Initial release. A WordPress must-use plugin that runs `wp-cron` reliably
  across more than one server, where the usual `DISABLE_WP_CRON` plus a request
  to `wp-cron.php` quietly assumes a single machine and fails in two directions:
  pin the job to one instance and it dies when that instance recycles (scheduled
  posts miss, silently), or run it on every instance and the same event fires on
  each one (posts publish twice, emails send twice).
  - **In-process execution.** Every node runs `wp fleet-cron run` from system
    cron, which makes no HTTP request at all. Triggering cron over HTTP sends the
    request through everything in front of the origin: a CDN that can answer the
    same fixed URL from cache without ever running PHP, a load balancer that can
    hand it to a draining node, and a web timeout that kills a long job half
    done. None of that is in this path. Execution itself is delegated to core's
    `wp cron event run --due-now`, so nothing about running cron is
    reimplemented.
  - **One runner per minute, elected by a shared lock.** Each run first asks
    MySQL for an advisory lock with a zero-second timeout
    (`SELECT GET_LOCK('wp_fleet_cron', 0)`). Exactly one node wins and runs the
    due events; every other node fails the lock in a millisecond and exits having
    done nothing. Because the lock lives on the database connection, a runner
    killed mid-job releases it automatically, so the stuck-lock failure that
    wedges WordPress's own transient cron lock for sixty seconds cannot happen
    here.
  - **A health check that fails loudly.** The runner records every successful
    pass, and `wp fleet-cron status` exits non-zero the moment cron has stalled,
    so it composes with any alerting: `wp fleet-cron status || send-me-an-alert`.
    A scheduler that stops silently is the whole reason this exists, so the
    silence is made audible.
  - **WP-CLI:** `run` and `status`.

[0.9.0]: https://github.com/mantekio/wp-fleet-cron/releases/tag/v0.9.0
