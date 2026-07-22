<?php
/**
 * Plugin Name: WP Fleet Cron
 * Plugin URI:  https://github.com/mantekio/wp-fleet-cron
 * Description: Reliable wp-cron for WordPress running on more than one server. Runs cron in-process through WP-CLI, elects a single runner across the fleet with a shared database lock, and fails loudly when cron stalls.
 * Version:     0.9.0
 * Author:      Jaafar Abazid
 * Author URI:  https://www.mantek.io
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * A must-use plugin. Drop this file in wp-content/mu-plugins/, set
 * DISABLE_WP_CRON in wp-config.php, and run `wp fleet-cron run` from system
 * cron on every node. See README.md for the full setup.
 *
 * @package ManTek\FleetCron
 */

namespace ManTek\FleetCron;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const OPTION_LAST_RUN = 'wp_fleet_cron_last_run';
const DEFAULT_STALE   = 300; // Seconds; cron counts as stalled past this.

/**
 * Advisory-lock name, scoped to this install so several sites sharing one
 * MySQL server never contend on the same lock.
 */
function lock_name(): string {
	global $wpdb;
	return 'wpfc_' . substr( md5( DB_NAME . $wpdb->prefix ), 0, 16 );
}

/** Seconds before a missing run is treated as a stall. Filterable. */
function stale_after(): int {
	return (int) apply_filters( 'wp_fleet_cron_stale_after', DEFAULT_STALE );
}

/**
 * Take the fleet lock. Returns true only for the one node that wins it.
 *
 * GET_LOCK is a per-connection advisory lock: 1 = acquired, 0 = held by
 * another connection, null = error. The zero timeout means losers return at
 * once and do no work. Because the lock lives on the connection, a runner
 * killed mid-job drops the lock automatically when its connection closes, so a
 * dead runner can never wedge cron the way the transient lock can. A null
 * (error) counts as not acquired, so no node runs and the watchdog catches it,
 * loud, rather than several nodes running at once.
 */
function acquire_lock(): bool {
	global $wpdb;
	return '1' === (string) $wpdb->get_var(
		$wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', lock_name(), 0 )
	);
}

function release_lock(): void {
	global $wpdb;
	$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', lock_name() ) );
}

/** Record a successful pass, so the watchdog can tell running from stalled. */
function record_run(): void {
	update_option(
		OPTION_LAST_RUN,
		array(
			'time' => time(),
			'node' => gethostname() ?: 'unknown',
		),
		false
	);
}

function last_run(): array {
	$value = get_option( OPTION_LAST_RUN );
	return is_array( $value ) ? $value : array(
		'time' => 0,
		'node' => '',
	);
}

function is_stale(): bool {
	return ( time() - (int) last_run()['time'] ) > stale_after();
}

/**
 * Run due events, but only on the node holding the fleet lock. Every node
 * calls this each minute; all but one return 'skipped' immediately.
 *
 * Event execution is delegated to core's own `cron event run`, so nothing
 * about how WordPress runs cron is reimplemented here. launch=false runs it
 * in-process for speed; switch to launch=true for an isolated subprocess if
 * you ever hit re-entrancy.
 *
 * @return string one of 'ran', 'skipped', 'error'.
 */
function run_due_events(): string {
	// WP-CLI only. Refuse elsewhere rather than take the lock and record a run
	// that executed nothing, which would keep the watchdog falsely green.
	if ( ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
		return 'error';
	}
	if ( ! acquire_lock() ) {
		return 'skipped';
	}
	try {
		// exit_error=false so one failing event neither aborts the pass nor
		// false-alarms the watchdog: "ran" means the runner executed, exactly
		// as core wp-cron treats individual event failures.
		\WP_CLI::runcommand(
			'cron event run --due-now',
			array(
				'launch'     => false,
				'return'     => true,
				'exit_error' => false,
			)
		);
		record_run();
		return 'ran';
	} catch ( \Throwable $e ) {
		return 'error';
	} finally {
		release_lock();
	}
}

/**
 * Warn in the dashboard when cron has not run recently. This is the point of
 * the plugin made visible: a stall is loud, not silent.
 */
function stale_notice(): void {
	if ( ! current_user_can( 'manage_options' ) || ! is_stale() ) {
		return;
	}
	$time = (int) last_run()['time'];
	$msg  = $time
		? sprintf( 'Scheduled tasks last ran %d minutes ago. Scheduled posts and jobs may be missing.', (int) round( ( time() - $time ) / 60 ) )
		: 'Scheduled tasks have never run. Scheduled posts and jobs are not firing.';
	printf( '<div class="notice notice-error"><p><strong>Cron warning:</strong> %s</p></div>', esc_html( $msg ) );
}
add_action( 'admin_notices', __NAMESPACE__ . '\\stale_notice' );

/** Nag if the loopback was never disabled, because this plugin assumes it is off. */
function config_notice(): void {
	if ( ! current_user_can( 'manage_options' ) || ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) ) {
		return;
	}
	printf(
		'<div class="notice notice-warning"><p><strong>WP Fleet Cron:</strong> %s</p></div>',
		esc_html( "Add define('DISABLE_WP_CRON', true); to wp-config.php and run `wp fleet-cron run` from system cron on every node. Until then the loopback is still firing cron." )
	);
}
add_action( 'admin_notices', __NAMESPACE__ . '\\config_notice' );

/*
 * WP-CLI surface. Every node's crontab runs `wp fleet-cron run`; a monitor
 * runs `wp fleet-cron status || alert`.
 */
if ( defined( 'WP_CLI' ) && WP_CLI ) {

	\WP_CLI::add_command(
		'fleet-cron run',
		function () {
			\WP_CLI::log( 'fleet-cron: ' . run_due_events() );
		},
		array( 'shortdesc' => 'Run due cron events if this node wins the fleet lock.' )
	);

	\WP_CLI::add_command(
		'fleet-cron status',
		function () {
			$last = last_run();
			if ( ! $last['time'] ) {
				\WP_CLI::error( 'no successful cron run has ever been recorded' );
			}
			$line = sprintf( 'last run %ds ago on %s', time() - (int) $last['time'], $last['node'] );
			if ( is_stale() ) {
				\WP_CLI::error( 'STALE: ' . $line . ' (threshold ' . stale_after() . 's)' );
			}
			\WP_CLI::success( 'OK: ' . $line );
		},
		array( 'shortdesc' => 'Print cron health; exit non-zero if cron has stalled.' )
	);
}
