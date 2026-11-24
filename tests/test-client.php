<?php
/**
 * Tests for the Hermaily API client.
 *
 * @package Hermaily
 */

/**
 * The API client.
 */
class Test_Client extends Hermaily_TestCase {

	/**
	 * Send posts JSON with the key as bearer and returns the accepted message.
	 */
	public function test_send_posts_json_with_the_bearer_key() {
		$this->respond_accepted( 'abc-123' );

		$client = new Hermaily_Client( 'hm_secret' );
		$result = $client->send(
			array(
				'from'    => 'Acme <hello@acme.example>',
				'to'      => array( 'lucia@example.com' ),
				'subject' => 'Hi',
				'text'    => 'Hello',
			)
		);

		$this->assertSame( 'abc-123', $result['id'] );
		$this->assertSame( 'queued', $result['status'] );

		$request = $this->api_requests()[0];
		$this->assertSame( 'POST', $request['method'] );
		$this->assertSame( 'https://api.hermaily.com/v1/send', $request['url'] );
		$this->assertSame( 'Bearer hm_secret', $request['headers']['Authorization'] );
		$this->assertSame( 'application/json', $request['headers']['Content-Type'] );
		$this->assertSame( 'Hi', $request['body']['subject'] );
	}

	/**
	 * The user agent names the plugin, not the site.
	 */
	public function test_user_agent_does_not_carry_the_site_url() {
		$this->respond_accepted();

		( new Hermaily_Client( 'hm_secret' ) )->send( array( 'subject' => 'Hi' ) );

		$agent = $this->api_requests()[0]['args']['user-agent'];
		$this->assertStringStartsWith( 'Hermaily-WordPress/', $agent );
		$this->assertStringNotContainsString( wp_parse_url( home_url(), PHP_URL_HOST ), $agent );
	}

	/**
	 * Errors come back as WP_Error with the API's code and localized message.
	 *
	 * @dataProvider api_errors
	 *
	 * @param int    $status HTTP status.
	 * @param string $code   API error code.
	 */
	public function test_api_errors_become_wp_errors( $status, $code ) {
		$this->respond(
			'POST /v1/send',
			$status,
			array(
				'error' => array(
					'code'    => $code,
					'message' => 'Explained by Hermaily',
				),
			)
		);

		$result = ( new Hermaily_Client( 'hm_secret' ) )->send( array( 'subject' => 'Hi' ) );

		$this->assertWPError( $result );
		$this->assertSame( 'hermaily_' . $code, $result->get_error_code() );
		$this->assertStringContainsString( 'Explained by Hermaily', $result->get_error_message() );
		$this->assertSame( $status, $result->get_error_data()['status'] );
	}

	/**
	 * The errors the API documents for sending.
	 *
	 * @return array
	 */
	public function api_errors() {
		return array(
			'bad key'      => array( 401, 'unauthorized' ),
			'quota'        => array( 402, 'quota_exceeded' ),
			'forbidden'    => array( 403, 'forbidden' ),
			'validation'   => array( 422, 'validation' ),
			'rate limited' => array( 429, 'rate_limited' ),
			'internal'     => array( 500, 'internal' ),
		);
	}

	/**
	 * A plain-text error body (unparseable request) still gives a useful error.
	 */
	public function test_plain_text_errors_are_kept() {
		$this->respond( 'POST /v1/send', 400, 'Failed to parse the request body as JSON' );

		$result = ( new Hermaily_Client( 'hm_secret' ) )->send( array( 'subject' => 'Hi' ) );

		$this->assertWPError( $result );
		$this->assertSame( 'hermaily_http_400', $result->get_error_code() );
		$this->assertStringContainsString( 'Failed to parse', $result->get_error_message() );
	}

	/**
	 * Transport errors are passed through.
	 */
	public function test_transport_errors_are_passed_through() {
		$this->respond( 'POST /v1/send', new WP_Error( 'http_request_failed', 'cURL error 28' ) );

		$result = ( new Hermaily_Client( 'hm_secret' ) )->send( array( 'subject' => 'Hi' ) );

		$this->assertWPError( $result );
		$this->assertSame( 'http_request_failed', $result->get_error_code() );
	}

	/**
	 * Messages are fetched by id, url-encoded.
	 */
	public function test_message_fetches_status_and_events() {
		$this->respond(
			'GET /v1/messages/abc-123',
			200,
			array(
				'id'     => 'abc-123',
				'status' => 'delivered',
				'events' => array( array( 'type' => 'delivery' ) ),
			)
		);

		$message = ( new Hermaily_Client( 'hm_secret' ) )->message( 'abc-123' );

		$this->assertSame( 'delivered', $message['status'] );
		$this->assertSame( 'GET', $this->api_requests()[0]['method'] );
	}

	/**
	 * Ids that are not uuids never reach the URL.
	 */
	public function test_message_rejects_ids_that_are_not_uuid_like() {
		$result = ( new Hermaily_Client( 'hm_secret' ) )->message( '../v1/send' );

		$this->assertWPError( $result );
		$this->assertSame( array(), $this->api_requests() );
	}

	/**
	 * The domain endpoint returns the key's domain.
	 */
	public function test_domain_returns_the_keys_domain() {
		$this->respond(
			'GET /v1/domain',
			200,
			array(
				'domain'   => 'acme.example',
				'verified' => true,
			)
		);

		$this->assertSame(
			array(
				'domain'   => 'acme.example',
				'verified' => true,
			),
			( new Hermaily_Client( 'hm_secret' ) )->domain()
		);
	}

	/**
	 * Without the domain endpoint (404) the client says it does not know.
	 */
	public function test_domain_is_null_when_the_endpoint_does_not_exist() {
		$this->respond( 'GET /v1/domain', 404, array( 'error' => array( 'code' => 'not_found' ) ) );

		$this->assertNull( ( new Hermaily_Client( 'hm_secret' ) )->domain() );
	}

	/**
	 * Error messages are asked for in the admin's language.
	 */
	public function test_errors_are_asked_in_the_users_language() {
		$this->respond_accepted();
		switch_to_locale( 'es_ES' );

		( new Hermaily_Client( 'hm_secret' ) )->send( array( 'subject' => 'Hi' ) );
		restore_previous_locale();

		$this->assertSame( 'es', $this->api_requests()[0]['headers']['Accept-Language'] );
	}
}
