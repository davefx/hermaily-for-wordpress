<?php
/**
 * Tests for the send log.
 *
 * @package Hermaily
 */

/**
 * The log table, its retention and the status refresh.
 */
class Test_Log extends Hermaily_TestCase {

	/**
	 * The table has no column for bodies.
	 */
	public function test_the_table_stores_no_bodies() {
		global $wpdb;

		$columns = $wpdb->get_col( 'SHOW COLUMNS FROM ' . Hermaily_Log::table() );

		$this->assertEqualsCanonicalizing(
			array( 'id', 'created_at', 'updated_at', 'message_id', 'recipients', 'subject', 'status', 'error' ),
			$columns
		);
	}

	/**
	 * A retention of zero days turns the log off.
	 */
	public function test_zero_days_turns_the_log_off() {
		$this->configure( array( 'log_days' => 0 ) );

		Hermaily_Log::add( array( 'lucia@example.com' ), 'Hi', 'queued', 'id-1' );

		$this->assertSame( 0, Hermaily_Log::count() );
	}

	/**
	 * Purge removes the rows older than the retention.
	 */
	public function test_purge_removes_old_rows() {
		global $wpdb;
		$this->configure( array( 'log_days' => 7 ) );

		Hermaily_Log::add( array( 'old@example.com' ), 'Old', 'delivered', 'id-old' );
		Hermaily_Log::add( array( 'new@example.com' ), 'New', 'queued', 'id-new' );
		$wpdb->update( Hermaily_Log::table(), array( 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 8 * DAY_IN_SECONDS ) ), array( 'message_id' => 'id-old' ) );

		Hermaily_Log::purge();

		$rows = Hermaily_Log::rows( 1, 10 );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'id-new', $rows[0]['message_id'] );
	}

	/**
	 * The purge runs daily.
	 */
	public function test_purge_is_scheduled_daily() {
		Hermaily_Log::schedule();

		$this->assertSame( 'daily', wp_get_schedule( 'hermaily_purge_log' ) );
	}

	/**
	 * Refresh asks Hermaily for the real status and stores it.
	 */
	public function test_refresh_stores_the_real_status() {
		$this->configure();
		Hermaily_Log::add( array( 'lucia@example.com' ), 'Hi', 'queued', 'id-1' );
		$this->respond(
			'GET /v1/messages/id-1',
			200,
			array(
				'id'     => 'id-1',
				'status' => 'bounced',
				'events' => array(
					array(
						'type'        => 'bounce',
						'recipient'   => 'lucia@example.com',
						'occurred_at' => '2026-11-24T10:01:00Z',
						'data'        => array( 'diagnostic' => '550 5.1.1 User unknown' ),
					),
				),
			)
		);
		$row = Hermaily_Log::rows( 1, 10 )[0];

		$message = Hermaily_Log::refresh( $row );

		$this->assertSame( 'bounced', $message['status'] );
		$this->assertSame( 'bounced', Hermaily_Log::get( $row['id'] )['status'] );
	}

	/**
	 * Refreshing the page only asks for messages whose status can still change.
	 */
	public function test_refresh_page_skips_final_statuses() {
		$this->configure();
		Hermaily_Log::add( array( 'a@example.com' ), 'A', 'bounced', 'id-a' );
		Hermaily_Log::add( array( 'b@example.com' ), 'B', 'sent', 'id-b' );
		Hermaily_Log::add( array( 'c@example.com' ), 'C', 'error', null, 'Quota' );
		$this->respond(
			'GET /v1/messages/id-b',
			200,
			array(
				'id'     => 'id-b',
				'status' => 'delivered',
				'events' => array(),
			)
		);

		Hermaily_Log::refresh_rows( Hermaily_Log::rows( 1, 10 ) );

		$this->assertCount( 1, $this->api_requests() );
		$this->assertStringEndsWith( '/v1/messages/id-b', $this->api_requests()[0]['url'] );
	}

	/**
	 * Rows come newest first and paginate.
	 */
	public function test_rows_are_newest_first() {
		$this->configure();
		Hermaily_Log::add( array( 'a@example.com' ), 'First', 'queued', 'id-a' );
		Hermaily_Log::add( array( 'b@example.com' ), 'Second', 'queued', 'id-b' );

		$this->assertSame( 'Second', Hermaily_Log::rows( 1, 1 )[0]['subject'] );
		$this->assertSame( 'First', Hermaily_Log::rows( 2, 1 )[0]['subject'] );
		$this->assertSame( 2, Hermaily_Log::count() );
	}
}
