<?php
/**
 * Sends wp_mail() through Hermaily.
 *
 * @package Hermaily
 */

defined( 'ABSPATH' ) || exit;

/**
 * Hooks pre_wp_mail and turns the message wp_mail() received into a POST /v1/send, reading the
 * headers and applying the core filters the way wp_mail() itself does.
 */
class Hermaily_Mailer {

	/**
	 * Most recipients (to, cc and bcc together) one message may have.
	 */
	const MAX_RECIPIENTS = 50;

	/**
	 * Headers that describe the MIME structure, which Hermaily builds itself.
	 */
	const SKIPPED_HEADERS = array( 'mime-version', 'content-transfer-encoding', 'x-mailer' );

	/**
	 * Hermaily id of the last message accepted in this request, for the test email notice.
	 *
	 * @var string
	 */
	public static $last_message_id = '';

	/**
	 * Sends the message, or lets WordPress send it when there is no key.
	 *
	 * @param null|bool $result Short-circuit value from an earlier filter.
	 * @param array     $atts   wp_mail() arguments: to, subject, message, headers, attachments.
	 * @return null|bool Null to let WordPress send it; true or false otherwise.
	 */
	public static function pre_wp_mail( $result, $atts ) {
		if ( null !== $result ) {
			return $result;
		}

		$settings = Hermaily_Settings::get();
		$api_key  = Hermaily_Settings::api_key();

		if ( '' === $api_key ) {
			return null;
		}

		$payload = self::build_payload( (array) $atts, $settings );

		if ( null === $payload ) {
			return null;
		}

		$mail_data = array(
			'to'          => isset( $atts['to'] ) ? self::addresses( $atts['to'] ) : array(),
			'subject'     => $atts['subject'] ?? '',
			'message'     => $atts['message'] ?? '',
			'headers'     => $atts['headers'] ?? array(),
			'attachments' => $atts['attachments'] ?? array(),
		);

		$answer = is_wp_error( $payload ) ? $payload : ( new Hermaily_Client( $api_key ) )->send( $payload );

		$recipients = is_wp_error( $payload ) ? $mail_data['to'] : array_merge( $payload['to'], $payload['cc'] ?? array(), $payload['bcc'] ?? array() );

		if ( is_wp_error( $answer ) ) {
			Hermaily_Log::add( $recipients, (string) $mail_data['subject'], 'error', null, $answer->get_error_message() );

			$mail_data['phpmailer_exception_code'] = $answer->get_error_code();
			/** This action is documented in wp-includes/pluggable.php */
			do_action(
				'wp_mail_failed', // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook, fired as wp_mail() does.
				new WP_Error( 'wp_mail_failed', $answer->get_error_message(), $mail_data )
			);

			return false;
		}

		self::$last_message_id = (string) ( $answer['id'] ?? '' );
		Hermaily_Log::add( $recipients, (string) $mail_data['subject'], (string) ( $answer['status'] ?? 'queued' ), self::$last_message_id );

		/** This action is documented in wp-includes/pluggable.php */
		do_action( 'wp_mail_succeeded', $mail_data ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook, fired as wp_mail() does.

		return true;
	}

	/**
	 * Builds the POST /v1/send body.
	 *
	 * @param array $atts     wp_mail() arguments.
	 * @param array $settings Plugin settings.
	 * @return array|WP_Error|null The payload; null for multipart bodies, which are left to WordPress.
	 */
	public static function build_payload( array $atts, array $settings ) {
		$headers = self::parse_headers( $atts['headers'] ?? array() );

		// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core filters, applied as wp_mail() does.
		$content_type = apply_filters( 'wp_mail_content_type', $headers['content_type'] );
		// phpcs:enable
		if ( 0 === stripos( (string) $content_type, 'multipart/' ) ) {
			return null;
		}

		// Sender: the From header, else the default one, and then the core filters, as wp_mail() does.
		$from_email = $headers['from_email'];
		$from_name  = $headers['from_name'];

		if ( '' === $from_email ) {
			$from_email = '' !== $settings['from_email'] ? $settings['from_email'] : self::wordpress_default_from();
		}
		if ( '' === $from_name ) {
			$from_name = '' !== $settings['from_name'] ? $settings['from_name'] : 'WordPress';
		}

		// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core filters, applied as wp_mail() does.
		$from_email = (string) apply_filters( 'wp_mail_from', $from_email );
		$from_name  = (string) apply_filters( 'wp_mail_from_name', $from_name );
		// phpcs:enable
		$reply_to = $headers['reply_to'];

		if ( $settings['force_from'] && '' !== $settings['from_email'] && strtolower( $from_email ) !== strtolower( $settings['from_email'] ) ) {
			// Replies still reach whoever the message was really from (a contact form's visitor, say).
			if ( empty( $reply_to ) ) {
				$reply_to = array( self::address( $from_email, $from_name ) );
			}
			$from_email = $settings['from_email'];
			$from_name  = '' !== $settings['from_name'] ? $settings['from_name'] : $from_name;
		}

		$payload = array(
			'from'    => self::address( $from_email, $from_name ),
			'to'      => self::addresses( $atts['to'] ?? array() ),
			'subject' => (string) ( $atts['subject'] ?? '' ),
		);

		foreach ( array( 'cc', 'bcc' ) as $field ) {
			if ( ! empty( $headers[ $field ] ) ) {
				$payload[ $field ] = $headers[ $field ];
			}
		}
		if ( ! empty( $reply_to ) ) {
			$payload['reply_to'] = $reply_to;
		}

		$total = count( $payload['to'] ) + count( $payload['cc'] ?? array() ) + count( $payload['bcc'] ?? array() );
		if ( $total > self::MAX_RECIPIENTS ) {
			/* translators: %d: the most recipients a message may have. */
			return new WP_Error( 'hermaily_too_many_recipients', sprintf( __( 'Hermaily accepts at most %d recipients per message.', 'hermaily' ), self::MAX_RECIPIENTS ) );
		}

		$body = (string) ( $atts['message'] ?? '' );
		if ( 0 === stripos( (string) $content_type, 'text/html' ) ) {
			$payload['html'] = $body;
		} else {
			$payload['text'] = $body;
		}

		if ( ! empty( $headers['custom'] ) ) {
			$payload['headers'] = $headers['custom'];
		}

		$attachments = self::attachments( $atts['attachments'] ?? array() );
		if ( is_wp_error( $attachments ) ) {
			return $attachments;
		}
		if ( $attachments ) {
			$payload['attachments'] = $attachments;
		}

		/**
		 * Filters the message sent to Hermaily's POST /v1/send, for example to add a tag or metadata.
		 *
		 * @param array $payload Request body.
		 * @param array $atts    wp_mail() arguments.
		 */
		return apply_filters( 'hermaily_payload', $payload, $atts );
	}

	/**
	 * Reads wp_mail() headers.
	 *
	 * @param string|array $headers Headers, as a string or a list of lines.
	 * @return array from_email, from_name, content_type, cc, bcc, reply_to and custom.
	 */
	private static function parse_headers( $headers ) {
		$parsed = array(
			'from_email'   => '',
			'from_name'    => '',
			'content_type' => 'text/plain',
			'cc'           => array(),
			'bcc'          => array(),
			'reply_to'     => array(),
			'custom'       => array(),
		);

		$lines = is_array( $headers ) ? $headers : explode( "\n", str_replace( "\r\n", "\n", (string) $headers ) );

		foreach ( $lines as $line ) {
			if ( false === strpos( (string) $line, ':' ) ) {
				continue;
			}

			list( $name, $value ) = explode( ':', $line, 2 );
			$name                 = trim( $name );
			$value                = trim( $value );
			$lower                = strtolower( $name );

			switch ( $lower ) {
				case 'from':
					list( $parsed['from_email'], $parsed['from_name'] ) = self::split_address( $value );
					break;
				case 'content-type':
					$parsed['content_type'] = trim( explode( ';', $value )[0] );
					if ( false !== stripos( $value, 'boundary' ) ) {
						$parsed['content_type'] = 'multipart/mixed';
					}
					break;
				case 'cc':
				case 'bcc':
				case 'reply-to':
					$field            = str_replace( '-', '_', $lower );
					$parsed[ $field ] = array_merge( $parsed[ $field ], self::addresses( $value ) );
					break;
				default:
					if ( '' !== $name && ! in_array( $lower, self::SKIPPED_HEADERS, true ) ) {
						$parsed['custom'][ $name ] = $value;
					}
			}
		}

		return $parsed;
	}

	/**
	 * Splits a list of addresses, given as a comma-separated string or an array.
	 *
	 * @param string|array $addresses Addresses.
	 * @return string[]
	 */
	private static function addresses( $addresses ) {
		$list = is_array( $addresses ) ? $addresses : explode( ',', (string) $addresses );

		return array_values( array_filter( array_map( 'trim', array_map( 'strval', $list ) ), 'strlen' ) );
	}

	/**
	 * Splits "Name <email>" into email and name.
	 *
	 * @param string $value Address.
	 * @return string[] Email and name.
	 */
	private static function split_address( $value ) {
		if ( preg_match( '/^(.*)<([^>]+)>\s*$/', $value, $match ) ) {
			return array( trim( $match[2] ), trim( trim( $match[1] ), '"' ) );
		}

		return array( trim( $value ), '' );
	}

	/**
	 * Joins email and name into "Name <email>".
	 *
	 * @param string $email Email.
	 * @param string $name  Name.
	 * @return string
	 */
	private static function address( $email, $name ) {
		$name = trim( str_replace( array( '<', '>', "\r", "\n" ), '', (string) $name ) );

		return '' === $name ? $email : $name . ' <' . $email . '>';
	}

	/**
	 * The sender wp_mail() uses when there is none: wordpress@ the site's domain.
	 *
	 * @return string
	 */
	private static function wordpress_default_from() {
		$host = strtolower( (string) wp_parse_url( network_home_url(), PHP_URL_HOST ) );
		if ( 0 === strpos( $host, 'www.' ) ) {
			$host = substr( $host, 4 );
		}

		return 'wordpress@' . $host;
	}

	/**
	 * Reads attachments into base64.
	 *
	 * @param string|array $attachments Paths; a string key is used as the file name.
	 * @return array|WP_Error
	 */
	private static function attachments( $attachments ) {
		if ( ! is_array( $attachments ) ) {
			$attachments = explode( "\n", str_replace( "\r\n", "\n", (string) $attachments ) );
		}

		$files = array();

		foreach ( $attachments as $name => $path ) {
			$path = trim( (string) $path );
			if ( '' === $path ) {
				continue;
			}

			if ( ! is_readable( $path ) || ! is_file( $path ) ) {
				/* translators: %s: file path. */
				return new WP_Error( 'hermaily_attachment', sprintf( __( 'Could not read the attachment %s.', 'hermaily' ), $path ) );
			}

			$filename = is_string( $name ) && '' !== $name ? $name : wp_basename( $path );
			$type     = wp_check_filetype( $filename );

			$attachment = array(
				'filename' => $filename,
				// The API takes file contents in base64; nothing is obfuscated.
				'content'  => base64_encode( (string) file_get_contents( $path ) ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- A local file read for the API, which takes it in base64.
			);
			if ( ! empty( $type['type'] ) ) {
				$attachment['content_type'] = $type['type'];
			}

			$files[] = $attachment;
		}

		return $files;
	}
}
