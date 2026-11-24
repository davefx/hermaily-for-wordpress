<?php
/**
 * Hermaily API client.
 *
 * @package Hermaily
 */

defined( 'ABSPATH' ) || exit;

/**
 * Talks to https://api.hermaily.com. Every method returns the decoded JSON answer, or a WP_Error whose
 * code is "hermaily_<API error code>" and whose data holds the HTTP status.
 */
class Hermaily_Client {

	/**
	 * API base URL.
	 */
	const BASE = 'https://api.hermaily.com';

	/**
	 * API key (hm_...).
	 *
	 * @var string
	 */
	private $api_key;

	/**
	 * Creates a client for a key.
	 *
	 * @param string $api_key API key.
	 */
	public function __construct( $api_key ) {
		$this->api_key = (string) $api_key;
	}

	/**
	 * Sends one message (POST /v1/send).
	 *
	 * @param array $payload Message, as the API expects it.
	 * @return array|WP_Error The accepted message: id, to, submitted_at, status.
	 */
	public function send( array $payload ) {
		return $this->request( 'POST', '/v1/send', $payload );
	}

	/**
	 * Gets a message's status and events (GET /v1/messages/{id}).
	 *
	 * @param string $id Message id.
	 * @return array|WP_Error
	 */
	public function message( $id ) {
		if ( ! preg_match( '/^[A-Za-z0-9-]{1,64}$/', (string) $id ) ) {
			return new WP_Error( 'hermaily_bad_id', __( 'Invalid message id.', 'hermaily' ) );
		}

		return $this->request( 'GET', '/v1/messages/' . rawurlencode( $id ) );
	}

	/**
	 * Gets the key's domain (GET /v1/domain).
	 *
	 * @return array|WP_Error|null domain and verified; null when the API does not offer this endpoint.
	 */
	public function domain() {
		$answer = $this->request( 'GET', '/v1/domain' );

		if ( is_wp_error( $answer ) ) {
			$data = $answer->get_error_data();
			return is_array( $data ) && 404 === ( $data['status'] ?? 0 ) ? null : $answer;
		}

		$domain = $answer['domain'] ?? ( $answer['name'] ?? '' );
		if ( ! is_string( $domain ) || '' === $domain ) {
			return null;
		}

		$verified = $answer['verified'] ?? ( 'verified' === ( $answer['status'] ?? '' ) );

		return array(
			'domain'   => strtolower( $domain ),
			'verified' => (bool) $verified,
		);
	}

	/**
	 * Makes a request.
	 *
	 * @param string     $method HTTP method.
	 * @param string     $path   Path under the base URL.
	 * @param array|null $body   JSON body.
	 * @return array|WP_Error
	 */
	private function request( $method, $path, $body = null ) {
		$args = array(
			'method'     => $method,
			'timeout'    => 'POST' === $method ? 30 : 10,
			// WordPress's default user agent carries the site's URL; this one names only the plugin.
			'user-agent' => 'Hermaily-WordPress/' . HERMAILY_VERSION,
			'headers'    => array(
				'Authorization'   => 'Bearer ' . $this->api_key,
				'Accept'          => 'application/json',
				'Accept-Language' => 0 === strpos( determine_locale(), 'es' ) ? 'es' : 'en',
			),
		);

		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = wp_json_encode( $body );
		}

		$response = wp_safe_remote_request( self::BASE . $path, $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status  = (int) wp_remote_retrieve_response_code( $response );
		$raw     = (string) wp_remote_retrieve_body( $response );
		$decoded = json_decode( $raw, true );

		if ( $status >= 200 && $status < 300 ) {
			return is_array( $decoded ) ? $decoded : new WP_Error( 'hermaily_bad_response', __( 'Hermaily sent an answer that could not be read.', 'hermaily' ), array( 'status' => $status ) );
		}

		if ( is_array( $decoded ) && isset( $decoded['error']['code'] ) ) {
			$code    = sanitize_key( $decoded['error']['code'] );
			$message = (string) ( $decoded['error']['message'] ?? $code );
		} else {
			$code    = 'http_' . $status;
			$message = '' !== trim( $raw ) ? wp_strip_all_tags( substr( $raw, 0, 500 ) ) : '';
		}

		/* translators: 1: HTTP status code, 2: explanation from Hermaily. */
		$text = sprintf( __( 'Hermaily answered %1$d: %2$s', 'hermaily' ), $status, $message );

		return new WP_Error( 'hermaily_' . $code, $text, array( 'status' => $status ) );
	}
}
