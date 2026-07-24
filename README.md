# WP Fleet Cron

Reliable `wp-cron` for WordPress running on more than one server.

The usual cron fix, `DISABLE_WP_CRON` plus a request to `wp-cron.php`, quietly assumes a single machine. Behind a load balancer it breaks in two directions: pin the job to one instance and it dies when that instance recycles (scheduled posts miss, silently), or run it on every instance and the same event fires on each one (posts publish twice, emails send twice).

WP Fleet Cron fixes both. It runs cron in-process through WP-CLI on every node, so there is no single box to lose, and it puts a shared database lock in front of the run, so only one node executes the due events each minute. When cron stalls, it says so out loud.

> 📖 **Full write-up:** [WordPress cron behind a load balancer: fixing "Missed schedule"](https://www.mantek.io/insights/wordpress-cron-load-balancer)

## Why in-process

Triggering cron with an HTTP request (a `wget` crontab, a Lambda, the loopback) sends that request through everything in front of your origin: a CDN that can serve the same fixed URL from cache without ever running PHP, a load balancer that can hand it to a draining node, and a web timeout that kills a long job half done. Running cron with WP-CLI makes no HTTP request at all. It boots WordPress in a normal PHP process and runs the due events straight against the database. None of that path is in the way.

## Install

Must-use plugin. Either drop the file in place:

```
wp-content/mu-plugins/wp-fleet-cron.php
```

or install with Composer:

```
composer require mantekio/wp-fleet-cron
```

## Set up

1. Turn off the loopback in `wp-config.php`:

   ```php
   define( 'DISABLE_WP_CRON', true );
   ```

2. Run the fleet runner every minute from system cron on **every** node (bake it into your AMI or launch template, do not pin it to one box):

   ```
   * * * * * cd /var/www/html && wp fleet-cron run --quiet
   ```

3. Watch it. This exits non-zero the moment cron stalls, so it composes with any alerting:

   ```
   wp fleet-cron status || send-me-an-alert
   ```

## How it works

Every node runs `wp fleet-cron run` each minute. The first thing it does is ask MySQL for an advisory lock with a zero-second timeout:

```sql
SELECT GET_LOCK('wp_fleet_cron', 0);
```

Exactly one node wins. It runs the due events (delegated to core's own `wp cron event run --due-now`, so nothing about cron execution is reimplemented) and records the run. Every other node fails the lock in a millisecond and exits having done nothing, so the database sees one real cron pass plus a handful of trivial lock checks, not one pass per node.

The lock lives on the database connection, so if a runner is killed mid-job the connection closes and the lock releases itself. The stuck-lock failure that wedges WordPress's own transient cron lock for sixty seconds cannot happen here.

## Health check

`wp fleet-cron status` prints the last successful run and the node that made it, and exits non-zero once that run is older than the stale threshold. Put it in your monitoring:

```
wp fleet-cron status || send-me-an-alert
```

The dashboard also shows a warning to admins when cron has gone stale, so a stall is visible even if nobody wired the alert.

## Configuration

- `wp_fleet_cron_stale_after` (filter): seconds before a missing run counts as stalled. Default `300`.

## Known limits

- The advisory lock assumes a single primary database, which is the normal case. On a multi-primary topology, or through some connection poolers that reuse connections, use a Redis lock instead (a `SET key value NX PX` key across the fleet gives the same guarantee).
- It assumes you can run system cron on the nodes. If your host forbids that, keep the loopback, but the watchdog still tells you when cron stops.
- Some managed hosts already run cron correctly for a fleet. Check what yours does before adding this.

## The write-up

The full reasoning (why triggering cron over HTTP inherits every cache and load balancer in front of the origin, why the lock has to be shared rather than per-node, and the production story of the 6am story that never published) is in the write-up:

**→ [WordPress cron behind a load balancer: fixing "Missed schedule"](https://www.mantek.io/insights/wordpress-cron-load-balancer)**

## Changelog

Every release is documented in the [changelog](https://github.com/mantekio/wp-fleet-cron/blob/main/CHANGELOG.md), and the notes for each version are on the [releases page](https://github.com/mantekio/wp-fleet-cron/releases).

## License

GPL-2.0-or-later.

---

Built and maintained by **[ManTek Technologies](https://www.mantek.io)**: WordPress + AWS at scale, for newsrooms and beyond.
