<?php
/**
 * Tests for the wp_mail() replacement.
 *
 * @package Hermaily
 */

/**
 * The pre_wp_mail handler and the payload it builds.
 */
class Test_Mailer extends Hermaily_TestCase {

	/**
	 * Without an API key WordPress sends as usual.
	 */
	public function test_without_a_key_wordpress_sends_as_usual() {
		$this->assertNull( Hermaily_Mailer::pre_wp_mail( null, $this->atts() ) );
		$this->assertSame( array(), $this->api_requests() );
	}

	/**
	 * Another plugin that already short-circuited wp_mail() wins.
	 */
	public function test_an_earlier_short_circuit_is_respected() {
		$this->configure();

		$this->assertTrue( Hermaily_Mailer::pre_wp_mail( true, $this->atts() ) );
		$this->assertSame( array(), $this->api_requests() );
	}

	/**
	 * Wp_mail() goes through the API and reports success.
	 */
	public function test_wp_mail_is_sent_through_the_api() {
		$this->configure();
		$this->respond_accepted( 'id-1' );
		$succeeded = array();
		add_action(
			'wp_mail_succeeded',
			static function ( $data ) use ( &$succeeded ) {
				$succeeded = $data;
			}
		);

		$this->assertTrue( wp_mail( 'lucia@example.com', 'Your order', 'It has shipped.' ) );

		$body = $this->api_requests()[0]['body'];
		$this->assertSame( 'Acme <hello@acme.example>', $body['from'] );
		$this->assertSame( array( 'lucia@example.com' ), $body['to'] );
		$this->assertSame( 'Your order', $body['subject'] );
		$this->assertSame( 'It has shipped.', $body['text'] );
		$this->assertArrayNotHasKey( 'html', $body );
		$this->assertSame( array( 'lucia@example.com' ), $succeeded['to'] );
	}

	/**
	 * An API error makes wp_mail() fail with the API's message.
	 */
	public function test_api_errors_make_wp_mail_fail() {
		$this->configure();
		$this->respond(
			'POST /v1/send',
			403,
			array(
				'error' => array(
					'code'    => 'forbidden',
					'message' => 'The sender is not in the key domain',
				),
			)
		);
		$failure = null;
		add_action(
			'wp_mail_failed',
			static function ( $error ) use ( &$failure ) {
				$failure = $error;
			}
		);

		$this->assertFalse( wp_mail( 'lucia@example.com', 'Hi', 'Hello' ) );
		$this->assertInstanceOf( WP_Error::class, $failure );
		$this->assertStringContainsString( 'not in the key domain', $failure->get_error_message() );
		$this->assertSame( 'lucia@example.com', $failure->get_error_data()['to'][0] );
	}

	/**
	 * Recipients and headers are mapped as wp_mail() reads them.
	 */
	public function test_headers_and_recipients_are_mapped() {
		$payload = Hermaily_Mailer::build_payload(
			$this->atts(
				array(
					'to'      => 'lucia@example.com, Ana Pérez <ana@example.com>',
					'headers' => array(
						'From: Shop <shop@acme.example>',
						'Cc: one@example.com, two@example.com',
						'Cc: three@example.com',
						'Bcc: hidden@example.com',
						'Reply-To: Help <help@acme.example>',
						'X-Order: 48213',
						'MIME-Version: 1.0',
					),
				)
			),
			$this->settings()
		);

		$this->assertSame( array( 'lucia@example.com', 'Ana Pérez <ana@example.com>' ), $payload['to'] );
		$this->assertSame( 'Shop <shop@acme.example>', $payload['from'] );
		$this->assertSame( array( 'one@example.com', 'two@example.com', 'three@example.com' ), $payload['cc'] );
		$this->assertSame( array( 'hidden@example.com' ), $payload['bcc'] );
		$this->assertSame( array( 'Help <help@acme.example>' ), $payload['reply_to'] );
		$this->assertSame( array( 'X-Order' => '48213' ), $payload['headers'] );
	}

	/**
	 * Headers given as a string with CRLF are read too.
	 */
	public function test_string_headers_are_read() {
		$payload = Hermaily_Mailer::build_payload(
			$this->atts( array( 'headers' => "Content-Type: text/html; charset=UTF-8\r\nCc: one@example.com\r\n" ) ),
			$this->settings()
		);

		$this->assertSame( '<p>Hello</p>', $payload['html'] );
		$this->assertArrayNotHasKey( 'text', $payload );
		$this->assertSame( array( 'one@example.com' ), $payload['cc'] );
	}

	/**
	 * The wp_mail_content_type filter turns the body into HTML.
	 */
	public function test_content_type_filter_is_honoured() {
		$html = static function () {
			return 'text/html';
		};
		add_filter( 'wp_mail_content_type', $html );

		$payload = Hermaily_Mailer::build_payload( $this->atts(), $this->settings() );

		remove_filter( 'wp_mail_content_type', $html );
		$this->assertSame( '<p>Hello</p>', $payload['html'] );
	}

	/**
	 * Without a From header the default sender is used, then the core filters apply.
	 */
	public function test_default_sender_then_core_filters() {
		$payload = Hermaily_Mailer::build_payload( $this->atts(), $this->settings() );
		$this->assertSame( 'Acme <hello@acme.example>', $payload['from'] );

		$filter = static function () {
			return 'filtered@acme.example';
		};
		add_filter( 'wp_mail_from', $filter );
		$payload = Hermaily_Mailer::build_payload( $this->atts(), $this->settings() );
		remove_filter( 'wp_mail_from', $filter );

		$this->assertSame( 'Acme <filtered@acme.example>', $payload['from'] );
	}

	/**
	 * Without a default sender, WordPress's own default is used.
	 */
	public function test_without_a_default_sender_wordpress_default_is_used() {
		$payload = Hermaily_Mailer::build_payload(
			$this->atts(),
			$this->settings(
				array(
					'from_email' => '',
					'from_name'  => '',
				)
			)
		);

		$this->assertMatchesRegularExpression( '/^WordPress <wordpress@[^>]+>$/', $payload['from'] );
	}

	/**
	 * Forcing the sender overrides headers and filters, and keeps replies going to the original sender.
	 */
	public function test_forced_sender_keeps_the_original_as_reply_to() {
		$filter = static function () {
			return 'plugin@elsewhere.example';
		};
		add_filter( 'wp_mail_from', $filter );

		$payload = Hermaily_Mailer::build_payload(
			$this->atts( array( 'headers' => array( 'From: Visitor <visitor@gmail.example>' ) ) ),
			$this->settings( array( 'force_from' => true ) )
		);

		remove_filter( 'wp_mail_from', $filter );
		$this->assertSame( 'Acme <hello@acme.example>', $payload['from'] );
		$this->assertSame( array( 'Visitor <plugin@elsewhere.example>' ), $payload['reply_to'] );
	}

	/**
	 * A forced sender does not replace a Reply-To that is already there.
	 */
	public function test_forced_sender_keeps_an_existing_reply_to() {
		$payload = Hermaily_Mailer::build_payload(
			$this->atts( array( 'headers' => array( 'From: visitor@gmail.example', 'Reply-To: desk@acme.example' ) ) ),
			$this->settings( array( 'force_from' => true ) )
		);

		$this->assertSame( array( 'desk@acme.example' ), $payload['reply_to'] );
	}

	/**
	 * Attachments are sent in base64, with their own name or the one given as array key.
	 */
	public function test_attachments_are_sent_in_base64() {
		$file = wp_tempnam( 'invoice.pdf' );
		file_put_contents( $file, '%PDF-1.4 fake' );

		$payload = Hermaily_Mailer::build_payload(
			$this->atts(
				array(
					'attachments' => array(
						$file,
						'factura.pdf' => $file,
					),
				)
			),
			$this->settings()
		);

		unlink( $file );
		$this->assertCount( 2, $payload['attachments'] );
		$this->assertSame( basename( $file ), $payload['attachments'][0]['filename'] );
		$this->assertSame( 'factura.pdf', $payload['attachments'][1]['filename'] );
		$this->assertSame( base64_encode( '%PDF-1.4 fake' ), $payload['attachments'][1]['content'] );
	}

	/**
	 * A missing attachment fails the message instead of sending it without it.
	 */
	public function test_missing_attachment_is_an_error() {
		$payload = Hermaily_Mailer::build_payload(
			$this->atts( array( 'attachments' => array( '/no/such/file.pdf' ) ) ),
			$this->settings()
		);

		$this->assertWPError( $payload );
		$this->assertSame( 'hermaily_attachment', $payload->get_error_code() );
	}

	/**
	 * More than 50 recipients is refused before calling the API.
	 */
	public function test_more_than_fifty_recipients_is_an_error() {
		$to = array();
		for ( $i = 0; $i < 51; $i++ ) {
			$to[] = "user{$i}@example.com";
		}

		$payload = Hermaily_Mailer::build_payload( $this->atts( array( 'to' => $to ) ), $this->settings() );

		$this->assertWPError( $payload );
		$this->assertSame( 'hermaily_too_many_recipients', $payload->get_error_code() );
	}

	/**
	 * Multipart bodies with their own boundary are left to WordPress.
	 */
	public function test_multipart_messages_are_left_to_wordpress() {
		$this->configure();

		$result = Hermaily_Mailer::pre_wp_mail(
			null,
			$this->atts( array( 'headers' => array( 'Content-Type: multipart/alternative; boundary="b1"' ) ) )
		);

		$this->assertNull( $result );
		$this->assertSame( array(), $this->api_requests() );
	}

	/**
	 * Developers can change the payload.
	 */
	public function test_payload_filter() {
		$tag = static function ( $payload ) {
			$payload['tag'] = 'woocommerce';
			return $payload;
		};
		add_filter( 'hermaily_payload', $tag );

		$payload = Hermaily_Mailer::build_payload( $this->atts(), $this->settings() );

		remove_filter( 'hermaily_payload', $tag );
		$this->assertSame( 'woocommerce', $payload['tag'] );
	}

	/**
	 * Sent messages are logged with the Hermaily id, never with the body.
	 */
	public function test_sent_messages_are_logged_without_body() {
		$this->configure();
		$this->respond_accepted( 'id-logged' );

		wp_mail( 'lucia@example.com', 'Logged subject', 'Secret body' );

		$rows = Hermaily_Log::rows( 1, 10 );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'id-logged', $rows[0]['message_id'] );
		$this->assertSame( 'queued', $rows[0]['status'] );
		$this->assertSame( 'Logged subject', $rows[0]['subject'] );
		$this->assertStringNotContainsString( 'Secret body', wp_json_encode( $rows[0] ) );
	}

	/**
	 * Failed messages are logged with the error.
	 */
	public function test_failed_messages_are_logged_with_the_error() {
		$this->configure();
		$this->respond(
			'POST /v1/send',
			402,
			array(
				'error' => array(
					'code'    => 'quota_exceeded',
					'message' => 'Monthly quota used up',
				),
			)
		);

		wp_mail( 'lucia@example.com', 'Hi', 'Hello' );

		$row = Hermaily_Log::rows( 1, 10 )[0];
		$this->assertSame( 'error', $row['status'] );
		$this->assertNull( $row['message_id'] );
		$this->assertStringContainsString( 'Monthly quota used up', $row['error'] );
	}

	/**
	 * The arguments wp_mail() passes to pre_wp_mail.
	 *
	 * @param array $overrides Values to change.
	 * @return array
	 */
	private function atts( array $overrides = array() ) {
		return array_merge(
			array(
				'to'          => 'lucia@example.com',
				'subject'     => 'Hi',
				'message'     => '<p>Hello</p>',
				'headers'     => array(),
				'attachments' => array(),
			),
			$overrides
		);
	}

	/**
	 * Settings for build_payload().
	 *
	 * @param array $overrides Values to change.
	 * @return array
	 */
	private function settings( array $overrides = array() ) {
		return array_merge(
			array(
				'api_key'    => 'hm_test_key',
				'from_email' => 'hello@acme.example',
				'from_name'  => 'Acme',
				'force_from' => false,
				'log_days'   => 30,
			),
			$overrides
		);
	}
}
