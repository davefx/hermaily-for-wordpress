<?php
/**
 * Base test case: a fake Hermaily API and a clean plugin state for every test.
 *
 * @package Hermaily
 */

/**
 * Every request to api.hermaily.com is answered from $this->responses and recorded in $this->requests.
 * Any other request fails, so no test can reach the network.
 */
abstract class Hermaily_TestCase extends WP_UnitTestCase {

	/**
	 * Requests made during the test: method, url, headers and decoded body.
	 *
	 * @var array[]
	 */
	protected $requests = array();

	/**
	 * Fake answers, by "METHOD path" (for example "POST /v1/send"). Each one is a list of
	 * array( status, body ) consumed in order; the last one repeats.
	 *
	 * @var array[]
	 */
	protected $responses = array();

	/**
	 * Resets options, log and fake API.
	 */
	public function set_up() {
		parent::set_up();

		delete_option( 'hermaily_settings' );
		delete_option( 'hermaily_domain' );
		Hermaily_Log::install();
		Hermaily_Log::clear();

		$this->requests  = array();
		$this->responses = array();

		add_filter( 'pre_http_request', array( $this, 'fake_http' ), 10, 3 );
	}

	/**
	 * Answers the request from the fake API.
	 *
	 * @param false|array $pre  Short-circuit value.
	 * @param array       $args Request arguments.
	 * @param string      $url  Request URL.
	 * @return array|WP_Error
	 */
	public function fake_http( $pre, $args, $url ) {
		$this->requests[] = array(
			'method'  => $args['method'] ?? 'GET',
			'url'     => $url,
			'headers' => $args['headers'] ?? array(),
			'body'    => isset( $args['body'] ) && is_string( $args['body'] ) ? json_decode( $args['body'], true ) : null,
			'args'    => $args,
		);

		if ( 0 !== strpos( $url, 'https://api.hermaily.com/' ) ) {
			return new WP_Error( 'offline', 'No network in tests.' );
		}

		$key = ( $args['method'] ?? 'GET' ) . ' ' . wp_parse_url( $url, PHP_URL_PATH );

		if ( empty( $this->responses[ $key ] ) ) {
			return new WP_Error( 'http_request_failed', 'No fake response for ' . $key );
		}

		$answer = count( $this->responses[ $key ] ) > 1 ? array_shift( $this->responses[ $key ] ) : $this->responses[ $key ][0];

		if ( $answer instanceof WP_Error ) {
			return $answer;
		}

		return array(
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => is_string( $answer[1] ) ? $answer[1] : wp_json_encode( $answer[1] ),
			'response' => array(
				'code'    => $answer[0],
				'message' => '',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	/**
	 * Queues a fake answer.
	 *
	 * @param string       $key    "METHOD path".
	 * @param int|WP_Error $status HTTP status, or a transport error.
	 * @param array|string $body   JSON body.
	 */
	protected function respond( $key, $status, $body = array() ) {
		$this->responses[ $key ][] = $status instanceof WP_Error ? $status : array( $status, $body );
	}

	/**
	 * Queues a 202 for POST /v1/send.
	 *
	 * @param string $id Message id.
	 */
	protected function respond_accepted( $id = '0b6f2c1e-7d4a-4f4e-9a51-3c2d1e0f9a88' ) {
		$this->respond(
			'POST /v1/send',
			202,
			array(
				'id'           => $id,
				'to'           => array( 'lucia@example.com' ),
				'submitted_at' => '2026-11-24T10:00:00Z',
				'status'       => 'queued',
			)
		);
	}

	/**
	 * Requests to the fake API, in order.
	 *
	 * @return array[]
	 */
	protected function api_requests() {
		return array_values(
			array_filter(
				$this->requests,
				static function ( $request ) {
					return 0 === strpos( $request['url'], 'https://api.hermaily.com/' );
				}
			)
		);
	}

	/**
	 * Saves the plugin settings.
	 *
	 * @param array $settings Settings to merge into the defaults.
	 */
	protected function configure( array $settings = array() ) {
		update_option(
			'hermaily_settings',
			array_merge(
				array(
					'api_key'    => 'hm_test_key',
					'from_email' => 'hello@acme.example',
					'from_name'  => 'Acme',
					'force_from' => false,
					'log_days'   => 30,
				),
				$settings
			)
		);
	}

	/**
	 * Logs in an administrator.
	 *
	 * @return int User id.
	 */
	protected function login_admin() {
		$user_id = self::factory()->user->create(
			array(
				'role'       => 'administrator',
				'user_email' => 'admin@acme.example',
			)
		);
		wp_set_current_user( $user_id );
		if ( is_multisite() ) {
			grant_super_admin( $user_id );
		}

		return $user_id;
	}
}
