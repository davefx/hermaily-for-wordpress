<?php
/**
 * The send log.
 *
 * @package Hermaily
 */

defined( 'ABSPATH' ) || exit;

/**
 * One row per message: date, recipients, subject, Hermaily's id and status, and the error if it failed.
 * Bodies are never stored. Rows older than the retention setting are deleted daily.
 */
class Hermaily_Log {

	/**
	 * Schema version, stored in the hermaily_db_version option.
	 */
	const DB_VERSION = '1';

	/**
	 * Statuses that can no longer change, so there is nothing to ask Hermaily about.
	 */
	const FINAL_STATUSES = array( 'suppressed', 'rejected', 'failed', 'bounced', 'complained', 'error' );

	/**
	 * Table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;

		return $wpdb->prefix . 'hermaily_log';
	}

	/**
	 * Creates the table and schedules the purge.
	 */
	public static function activate() {
		self::install();
		self::schedule();
	}

	/**
	 * Stops the purge. The log stays until the plugin is deleted.
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( 'hermaily_purge_log' );
	}

	/**
	 * Creates or updates the table.
	 */
	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$charset = $wpdb->get_charset_collate();

		dbDelta(
			"CREATE TABLE {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				message_id varchar(64) DEFAULT NULL,
				recipients text NOT NULL,
				subject text NOT NULL,
				status varchar(20) NOT NULL,
				error text,
				PRIMARY KEY  (id),
				KEY created_at (created_at)
			) {$charset};"
		);

		update_option( 'hermaily_db_version', self::DB_VERSION, false );
	}

	/**
	 * Installs the table on sites that got the plugin without the activation hook (updates, multisite).
	 */
	public static function maybe_install() {
		if ( self::DB_VERSION !== get_option( 'hermaily_db_version' ) ) {
			self::activate();
		}
	}

	/**
	 * Schedules the daily purge.
	 */
	public static function schedule() {
		if ( ! wp_next_scheduled( 'hermaily_purge_log' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'hermaily_purge_log' );
		}
	}

	/**
	 * Adds a row, unless the log is off.
	 *
	 * @param string[]    $recipients Recipients.
	 * @param string      $subject    Subject.
	 * @param string      $status     Hermaily status, or "error".
	 * @param string|null $message_id Hermaily message id.
	 * @param string|null $error      Error message.
	 */
	public static function add( array $recipients, $subject, $status, $message_id = null, $error = null ) {
		global $wpdb;

		if ( Hermaily_Settings::get()['log_days'] < 1 ) {
			return;
		}

		$now = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- The plugin's own table, written once per message.
		$wpdb->insert(
			self::table(),
			array(
				'created_at' => $now,
				'updated_at' => $now,
				'message_id' => '' === (string) $message_id ? null : $message_id,
				'recipients' => implode( ', ', $recipients ),
				'subject'    => mb_substr( (string) $subject, 0, 1000 ),
				'status'     => sanitize_key( $status ),
				'error'      => $error,
			)
		);
	}

	/**
	 * A page of rows, newest first.
	 *
	 * @param int $page     Page, from 1.
	 * @param int $per_page Rows per page.
	 * @return array[]
	 */
	public static function rows( $page, $per_page ) {
		global $wpdb;

		$offset = max( 0, ( (int) $page - 1 ) * (int) $per_page );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id DESC LIMIT %d OFFSET %d', self::table(), (int) $per_page, $offset ), ARRAY_A );

		return array_map( array( __CLASS__, 'cast' ), (array) $rows );
	}

	/**
	 * One row.
	 *
	 * @param int $id Row id.
	 * @return array|null
	 */
	public static function get( $id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', self::table(), (int) $id ), ARRAY_A );

		return $row ? self::cast( $row ) : null;
	}

	/**
	 * Number of rows.
	 *
	 * @return int
	 */
	public static function count() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table.
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', self::table() ) );
	}

	/**
	 * Asks Hermaily for a row's status and stores it.
	 *
	 * @param array $row Log row.
	 * @return array|WP_Error The message, with its events.
	 */
	public static function refresh( array $row ) {
		global $wpdb;

		if ( empty( $row['message_id'] ) ) {
			return new WP_Error( 'hermaily_no_id', __( 'This message never reached Hermaily.', 'hermaily' ) );
		}

		$message = ( new Hermaily_Client( Hermaily_Settings::api_key() ) )->message( $row['message_id'] );

		if ( ! is_wp_error( $message ) && isset( $message['status'] ) && $message['status'] !== $row['status'] ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table.
			$wpdb->update(
				self::table(),
				array(
					'status'     => sanitize_key( $message['status'] ),
					'updated_at' => current_time( 'mysql', true ),
				),
				array( 'id' => $row['id'] )
			);
		}

		return $message;
	}

	/**
	 * Refreshes the rows whose status can still change.
	 *
	 * @param array[] $rows Log rows.
	 */
	public static function refresh_rows( array $rows ) {
		foreach ( $rows as $row ) {
			if ( ! empty( $row['message_id'] ) && ! in_array( $row['status'], self::FINAL_STATUSES, true ) ) {
				self::refresh( $row );
			}
		}
	}

	/**
	 * Deletes the rows older than the retention setting.
	 */
	public static function purge() {
		global $wpdb;

		$days = Hermaily_Settings::get()['log_days'];
		if ( $days < 1 ) {
			self::clear();
			return;
		}

		$cutoff = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE created_at < %s', self::table(), $cutoff ) );
	}

	/**
	 * Deletes every row.
	 */
	public static function clear() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', self::table() ) );
	}

	/**
	 * Gives a row its types.
	 *
	 * @param array $row Row as read.
	 * @return array
	 */
	private static function cast( array $row ) {
		$row['id'] = (int) $row['id'];

		return $row;
	}
}
