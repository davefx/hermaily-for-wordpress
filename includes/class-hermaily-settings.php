<?php
/**
 * Settings page: key, sender, test email, log and more plugins.
 *
 * @package Hermaily
 */

defined( 'ABSPATH' ) || exit;

/**
 * Settings → Hermaily.
 */
class Hermaily_Settings {

	/**
	 * Option holding the settings.
	 */
	const OPTION = 'hermaily_settings';

	/**
	 * Option caching what Hermaily says about the key's domain.
	 */
	const DOMAIN_OPTION = 'hermaily_domain';

	/**
	 * Page slug.
	 */
	const SLUG = 'hermaily';

	/**
	 * Rows per page in the log.
	 */
	const PER_PAGE = 20;

	/**
	 * Registers the hooks.
	 */
	public static function hooks() {
		add_action( 'admin_menu', array( __CLASS__, 'add_page' ) );
		add_action( 'admin_init', array( 'Hermaily_Log', 'maybe_install' ) );
		add_action( 'admin_post_hermaily_save', array( __CLASS__, 'save' ) );
		add_action( 'admin_post_hermaily_test', array( __CLASS__, 'send_test' ) );
		add_action( 'admin_post_hermaily_refresh', array( __CLASS__, 'refresh_log' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( HERMAILY_FILE ), array( __CLASS__, 'action_links' ) );
	}

	/**
	 * Settings with their defaults and types.
	 *
	 * @return array api_key, from_email, from_name, force_from, log_days.
	 */
	public static function get() {
		$saved = get_option( self::OPTION, array() );
		$saved = is_array( $saved ) ? $saved : array();

		return array(
			'api_key'    => (string) ( $saved['api_key'] ?? '' ),
			'from_email' => (string) ( $saved['from_email'] ?? '' ),
			'from_name'  => (string) ( $saved['from_name'] ?? '' ),
			'force_from' => ! empty( $saved['force_from'] ),
			'log_days'   => isset( $saved['log_days'] ) ? (int) $saved['log_days'] : 30,
		);
	}

	/**
	 * The API key: the HERMAILY_API_KEY constant when defined, else the saved one.
	 *
	 * @return string
	 */
	public static function api_key() {
		return self::key_is_constant() ? trim( (string) constant( 'HERMAILY_API_KEY' ) ) : self::get()['api_key'];
	}

	/**
	 * Whether the key comes from wp-config.php.
	 *
	 * @return bool
	 */
	public static function key_is_constant() {
		return defined( 'HERMAILY_API_KEY' ) && '' !== trim( (string) constant( 'HERMAILY_API_KEY' ) );
	}

	/**
	 * What Hermaily says about the key's domain, asked at most once a day.
	 *
	 * @return array|null domain and verified; null when unknown (no key, or no such endpoint yet).
	 */
	public static function domain() {
		$cache = self::domain_cache();

		return $cache['info'] ?? null;
	}

	/**
	 * Why the domain could not be checked, if the API said so.
	 *
	 * @return string
	 */
	public static function domain_error() {
		$cache = self::domain_cache();

		return (string) ( $cache['error'] ?? '' );
	}

	/**
	 * Reads, or refreshes, the domain cache.
	 *
	 * @return array|null
	 */
	private static function domain_cache() {
		$key = self::api_key();
		if ( '' === $key ) {
			return null;
		}

		$hash  = hash( 'sha256', $key );
		$cache = get_option( self::DOMAIN_OPTION );

		if ( is_array( $cache ) && ( $cache['hash'] ?? '' ) === $hash && time() < (int) ( $cache['expires'] ?? 0 ) ) {
			return $cache;
		}

		$answer = ( new Hermaily_Client( $key ) )->domain();
		$cache  = array(
			'hash'    => $hash,
			'info'    => is_array( $answer ) ? $answer : null,
			'error'   => is_wp_error( $answer ) ? $answer->get_error_message() : '',
			// A failure is asked again soon; an answer (or a missing endpoint) a day later.
			'expires' => time() + ( is_wp_error( $answer ) ? 10 * MINUTE_IN_SECONDS : DAY_IN_SECONDS ),
		);

		update_option( self::DOMAIN_OPTION, $cache, false );

		return $cache;
	}

	/**
	 * Whether an address is on a domain or one of its subdomains.
	 *
	 * @param string $email  Address.
	 * @param string $domain Domain.
	 * @return bool
	 */
	public static function sender_on_domain( $email, $domain ) {
		$host   = strtolower( (string) substr( strrchr( (string) $email, '@' ), 1 ) );
		$domain = strtolower( $domain );

		return $host === $domain || substr( $host, -strlen( '.' . $domain ) ) === '.' . $domain;
	}

	/**
	 * Adds Settings → Hermaily.
	 */
	public static function add_page() {
		add_options_page( 'Hermaily', 'Hermaily', 'manage_options', self::SLUG, array( __CLASS__, 'render_page' ) );
	}

	/**
	 * Adds the Settings link in the plugins list.
	 *
	 * @param string[] $links Links.
	 * @return string[]
	 */
	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Settings', 'hermaily' ) . '</a>' );

		return $links;
	}

	/**
	 * The page URL.
	 *
	 * @param array $args Query arguments.
	 * @return string
	 */
	private static function url( array $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::SLUG ), $args ), admin_url( 'options-general.php' ) );
	}

	/**
	 * Stops unless the current user may manage the plugin and the form carries its nonce.
	 *
	 * @param string $action Nonce action suffix.
	 */
	private static function check( $action ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to manage Hermaily.', 'hermaily' ), 403 );
		}

		check_admin_referer( 'hermaily_' . $action );
	}

	/**
	 * Saves the settings form.
	 */
	public static function save() {
		self::check( 'save' );

		$settings = self::get();
		$old_key  = $settings['api_key'];

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Checked in self::check().
		$api_key    = isset( $_POST['api_key'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) ) : '';
		$from_email = isset( $_POST['from_email'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['from_email'] ) ) ) : $settings['from_email'];
		$from_name  = isset( $_POST['from_name'] ) ? sanitize_text_field( wp_unslash( $_POST['from_name'] ) ) : $settings['from_name'];
		$log_days   = isset( $_POST['log_days'] ) ? absint( $_POST['log_days'] ) : $settings['log_days'];
		$force_from = ! empty( $_POST['force_from'] );
		$remove_key = ! empty( $_POST['remove_key'] );
		// phpcs:enable

		if ( '' !== $from_email && ! is_email( $from_email ) ) {
			self::redirect( array( 'error' => 'from_email' ) );
		}

		if ( '' !== $api_key ) {
			$settings['api_key'] = $api_key;
		} elseif ( $remove_key ) {
			$settings['api_key'] = '';
		}

		$settings['from_email'] = $from_email;
		$settings['from_name']  = trim( $from_name );
		$settings['force_from'] = $force_from;
		$settings['log_days']   = min( 365, $log_days );

		update_option( self::OPTION, $settings, false );

		if ( $settings['api_key'] !== $old_key ) {
			delete_option( self::DOMAIN_OPTION );
		}

		// A new key without a sender: propose one on the key's domain, if Hermaily says which it is.
		if ( '' === $settings['from_email'] ) {
			$domain = self::domain();
			if ( $domain ) {
				$settings['from_email'] = 'wordpress@' . $domain['domain'];
				update_option( self::OPTION, $settings, false );
			}
		}

		self::redirect( array( 'saved' => '1' ) );
	}

	/**
	 * Sends the test email.
	 */
	public static function send_test() {
		self::check( 'test' );

		if ( '' === self::api_key() ) {
			self::redirect( array( 'error' => 'no_key' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked in self::check().
		$to = isset( $_POST['to'] ) ? sanitize_email( wp_unslash( $_POST['to'] ) ) : '';
		if ( ! is_email( $to ) ) {
			$to = wp_get_current_user()->user_email;
		}

		$failure = '';
		$catch   = static function ( $error ) use ( &$failure ) {
			$failure = $error->get_error_message();
		};
		add_action( 'wp_mail_failed', $catch );

		Hermaily_Mailer::$last_message_id = '';
		$sent                             = wp_mail(
			$to,
			__( 'Hermaily test email', 'hermaily' ),
			sprintf(
				/* translators: %s: site name. */
				__( 'This test email from %s was sent through the Hermaily API. If you are reading it, sending works.', 'hermaily' ),
				wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES )
			)
		);

		remove_action( 'wp_mail_failed', $catch );

		if ( $sent && '' !== Hermaily_Mailer::$last_message_id ) {
			/* translators: 1: recipient, 2: Hermaily message id. */
			$notice = array( 'success', sprintf( __( 'Hermaily accepted the test email to %1$s (id %2$s). Its status appears in the Log tab.', 'hermaily' ), $to, Hermaily_Mailer::$last_message_id ) );
		} elseif ( $sent ) {
			/* translators: %s: recipient. */
			$notice = array( 'warning', sprintf( __( 'The test email to %s was sent, but not through Hermaily: another plugin is handling wp_mail().', 'hermaily' ), $to ) );
		} else {
			/* translators: 1: recipient, 2: error message. */
			$notice = array( 'error', sprintf( __( 'The test email to %1$s could not be sent. %2$s', 'hermaily' ), $to, $failure ) );
		}

		set_transient( 'hermaily_notice_' . get_current_user_id(), $notice, 5 * MINUTE_IN_SECONDS );

		self::redirect( array() );
	}

	/**
	 * Refreshes the statuses of a log page.
	 */
	public static function refresh_log() {
		self::check( 'refresh' );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked in self::check().
		$page = isset( $_POST['paged'] ) ? max( 1, absint( $_POST['paged'] ) ) : 1;

		Hermaily_Log::refresh_rows( Hermaily_Log::rows( $page, self::PER_PAGE ) );

		self::redirect(
			array(
				'tab'   => 'log',
				'paged' => $page,
			)
		);
	}

	/**
	 * Redirects back to the page and stops.
	 *
	 * @param array $args Query arguments.
	 */
	private static function redirect( array $args ) {
		wp_safe_redirect( self::url( $args ) );
		exit;
	}

	/**
	 * Renders the page.
	 */
	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only navigation.
		$tab     = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'settings';
		$message = isset( $_GET['message'] ) ? absint( $_GET['message'] ) : 0;
		$paged   = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		// phpcs:enable

		$tabs = array(
			'settings'     => __( 'Settings', 'hermaily' ),
			'log'          => __( 'Log', 'hermaily' ),
			'more-plugins' => dfx_plugin_showcase_label(),
		);
		if ( ! isset( $tabs[ $tab ] ) ) {
			$tab = 'settings';
		}

		echo '<div class="wrap"><h1>Hermaily</h1>';
		echo '<nav class="nav-tab-wrapper">';
		foreach ( $tabs as $id => $label ) {
			$url = self::url( 'settings' === $id ? array() : array( 'tab' => $id ) );
			echo '<a href="' . esc_url( $url ) . '" class="nav-tab' . ( $id === $tab ? ' nav-tab-active' : '' ) . '">' . esc_html( $label ) . '</a>';
		}
		echo '</nav>';

		if ( 'more-plugins' === $tab ) {
			dfx_plugin_showcase_render( array( 'exclude' => 'hermaily' ) ); // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_exclude -- Not a query: the catalog id to leave out.
		} elseif ( 'log' === $tab && $message ) {
			self::render_message( $message );
		} elseif ( 'log' === $tab ) {
			self::render_log( $paged );
		} else {
			self::render_notices();
			self::render_settings();
		}

		echo '</div>';
	}

	/**
	 * Prints a notice.
	 *
	 * @param string $type    success, warning or error.
	 * @param string $message Text.
	 */
	private static function notice( $type, $message ) {
		echo '<div class="notice notice-' . esc_attr( $type ) . '"><p>' . esc_html( $message ) . '</p></div>';
	}

	/**
	 * Notices after saving or sending the test email.
	 */
	private static function render_notices() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Only picks which message to show.
		if ( isset( $_GET['saved'] ) ) {
			self::notice( 'success', __( 'Settings saved.', 'hermaily' ) );
		}

		$error = isset( $_GET['error'] ) ? sanitize_key( wp_unslash( $_GET['error'] ) ) : '';
		// phpcs:enable

		if ( 'from_email' === $error ) {
			self::notice( 'error', __( 'The default sender is not a valid email address. Nothing was saved.', 'hermaily' ) );
		} elseif ( 'no_key' === $error ) {
			self::notice( 'error', __( 'Save an API key before sending the test email.', 'hermaily' ) );
		}

		$transient = 'hermaily_notice_' . get_current_user_id();
		$notice    = get_transient( $transient );
		if ( is_array( $notice ) && 2 === count( $notice ) ) {
			delete_transient( $transient );
			self::notice( $notice[0], $notice[1] );
		}
	}

	/**
	 * The settings and test email forms.
	 */
	private static function render_settings() {
		$settings = self::get();
		$constant = self::key_is_constant();
		$has_key  = '' !== self::api_key();
		$domain   = $has_key ? self::domain() : null;

		if ( ! $has_key ) {
			echo '<p>';
			printf(
				/* translators: %s: link to the API keys page of the Hermaily dashboard. */
				esc_html__( 'Create an API key in %s and paste it below. From then on, every email WordPress sends goes through Hermaily.', 'hermaily' ),
				'<a href="https://app.hermaily.com/api-keys" target="_blank" rel="noopener noreferrer">' . esc_html__( 'your Hermaily dashboard', 'hermaily' ) . '</a>'
			);
			echo '</p>';
		}

		if ( $domain && ! $domain['verified'] ) {
			/* translators: %s: domain. */
			self::notice( 'warning', sprintf( __( 'The domain %s is not verified yet: Hermaily will not send from it until its DNS records are in place. Check it in your Hermaily dashboard.', 'hermaily' ), $domain['domain'] ) );
		}
		if ( $domain && '' !== $settings['from_email'] && ! self::sender_on_domain( $settings['from_email'], $domain['domain'] ) ) {
			/* translators: 1: sender address, 2: domain. */
			self::notice( 'warning', sprintf( __( 'The default sender %1$s is not on %2$s, the domain of this API key: Hermaily will refuse it.', 'hermaily' ), $settings['from_email'], $domain['domain'] ) );
		}
		if ( $has_key && self::domain_error() ) {
			/* translators: %s: error message. */
			self::notice( 'error', sprintf( __( 'The API key could not be checked. %s', 'hermaily' ), self::domain_error() ) );
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="hermaily_save">';
		wp_nonce_field( 'hermaily_save' );
		echo '<table class="form-table" role="presentation">';

		// API key. The saved key is never printed back.
		echo '<tr><th scope="row"><label for="hermaily-api-key">' . esc_html__( 'API key', 'hermaily' ) . '</label></th><td>';
		if ( $constant ) {
			echo '<input type="password" id="hermaily-api-key" name="api_key" class="regular-text" value="" placeholder="••••••••" disabled>';
			echo '<p class="description">' . esc_html__( 'Defined by the HERMAILY_API_KEY constant in wp-config.php.', 'hermaily' ) . '</p>';
		} else {
			$placeholder = '' !== $settings['api_key'] ? __( 'Saved. Paste a new key to replace it.', 'hermaily' ) : 'hm_…';
			echo '<input type="password" id="hermaily-api-key" name="api_key" class="regular-text" value="" autocomplete="off" placeholder="' . esc_attr( $placeholder ) . '">';
			if ( '' !== $settings['api_key'] ) {
				echo '<p><label><input type="checkbox" name="remove_key" value="1"> ' . esc_html__( 'Remove the saved key (WordPress will send email by itself again)', 'hermaily' ) . '</label></p>';
			}
			echo '<p class="description">' . esc_html__( 'You can also define it in wp-config.php:', 'hermaily' ) . ' <code>define( \'HERMAILY_API_KEY\', \'hm_…\' );</code></p>';
		}
		if ( $domain ) {
			echo '<p class="description">';
			echo esc_html(
				$domain['verified']
					/* translators: %s: domain. */
					? sprintf( __( 'This key sends from %s (verified).', 'hermaily' ), $domain['domain'] )
					/* translators: %s: domain. */
					: sprintf( __( 'This key sends from %s (not verified yet).', 'hermaily' ), $domain['domain'] )
			);
			echo '</p>';
		}
		echo '</td></tr>';

		// Default sender.
		echo '<tr><th scope="row"><label for="hermaily-from-email">' . esc_html__( 'Default sender email', 'hermaily' ) . '</label></th><td>';
		echo '<input type="email" id="hermaily-from-email" name="from_email" class="regular-text" value="' . esc_attr( $settings['from_email'] ) . '" placeholder="' . esc_attr( $domain ? 'wordpress@' . $domain['domain'] : 'wordpress@example.com' ) . '">';
		echo '<p class="description">' . esc_html__( 'Used when a message does not say who it is from. It must be on the domain of your API key.', 'hermaily' ) . '</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="hermaily-from-name">' . esc_html__( 'Default sender name', 'hermaily' ) . '</label></th><td>';
		echo '<input type="text" id="hermaily-from-name" name="from_name" class="regular-text" value="' . esc_attr( $settings['from_name'] ) . '" placeholder="' . esc_attr( wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ) . '">';
		echo '</td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Force sender', 'hermaily' ) . '</th><td>';
		echo '<label><input type="checkbox" name="force_from" value="1"' . checked( $settings['force_from'], true, false ) . '> ' . esc_html__( 'Always send from the default sender', 'hermaily' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'For plugins that set their own sender, such as contact forms that send "from" the visitor. The original sender becomes the Reply-To, so replies still reach them.', 'hermaily' ) . '</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="hermaily-log-days">' . esc_html__( 'Keep the log for', 'hermaily' ) . '</label></th><td>';
		echo '<input type="number" id="hermaily-log-days" name="log_days" class="small-text" min="0" max="365" value="' . esc_attr( (string) $settings['log_days'] ) . '"> ' . esc_html__( 'days', 'hermaily' );
		echo '<p class="description">' . esc_html__( 'The log keeps date, recipients, subject and status, never the content. 0 turns it off.', 'hermaily' ) . '</p>';
		echo '</td></tr>';

		echo '</table>';
		submit_button();
		echo '</form>';

		// Test email.
		echo '<h2>' . esc_html__( 'Send a test email', 'hermaily' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="hermaily_test">';
		wp_nonce_field( 'hermaily_test' );
		echo '<p><label for="hermaily-test-to">' . esc_html__( 'Send to', 'hermaily' ) . '</label> ';
		echo '<input type="email" id="hermaily-test-to" name="to" class="regular-text" value="' . esc_attr( wp_get_current_user()->user_email ) . '"> ';
		submit_button( __( 'Send test email', 'hermaily' ), 'secondary', 'submit', false, $has_key ? array() : array( 'disabled' => 'disabled' ) );
		echo '</p></form>';
	}

	/**
	 * The log list.
	 *
	 * @param int $paged Page.
	 */
	private static function render_log( $paged ) {
		$settings = self::get();
		$total    = Hermaily_Log::count();
		$rows     = Hermaily_Log::rows( $paged, self::PER_PAGE );

		if ( $settings['log_days'] < 1 ) {
			self::notice( 'warning', __( 'The log is turned off in the settings.', 'hermaily' ) );
		}

		echo '<p>';
		printf(
			/* translators: %d: number of days. */
			esc_html( _n( 'Messages of the last %d day. Statuses are as of the last check: open a message or refresh to ask Hermaily again.', 'Messages of the last %d days. Statuses are as of the last check: open a message or refresh to ask Hermaily again.', max( 1, $settings['log_days'] ), 'hermaily' ) ),
			(int) max( 1, $settings['log_days'] )
		);
		echo '</p>';

		if ( ! $rows ) {
			echo '<p>' . esc_html__( 'No messages yet.', 'hermaily' ) . '</p>';
			return;
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="hermaily_refresh"><input type="hidden" name="paged" value="' . esc_attr( (string) $paged ) . '">';
		wp_nonce_field( 'hermaily_refresh' );
		submit_button( __( 'Refresh statuses', 'hermaily' ), 'secondary', 'submit', false );
		echo '</form>';

		echo '<table class="widefat striped" style="margin-top:1em"><thead><tr>';
		foreach ( array( __( 'Date', 'hermaily' ), __( 'To', 'hermaily' ), __( 'Subject', 'hermaily' ), __( 'Status', 'hermaily' ) ) as $heading ) {
			echo '<th scope="col">' . esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		foreach ( $rows as $row ) {
			$link = self::url(
				array(
					'tab'     => 'log',
					'message' => $row['id'],
				)
			);

			echo '<tr>';
			echo '<td><a href="' . esc_url( $link ) . '">' . esc_html( self::local_date( $row['created_at'] ) ) . '</a></td>';
			echo '<td>' . esc_html( $row['recipients'] ) . '</td>';
			echo '<td>' . esc_html( $row['subject'] ) . '</td>';
			echo '<td><strong>' . esc_html( self::status_label( $row['status'] ) ) . '</strong>';
			if ( ! empty( $row['error'] ) ) {
				echo '<br>' . esc_html( $row['error'] );
			}
			echo '</td></tr>';
		}

		echo '</tbody></table>';

		$pages = (int) ceil( $total / self::PER_PAGE );
		if ( $pages > 1 ) {
			echo '<p>';
			if ( $paged > 1 ) {
				echo '<a class="button" href="' . esc_url( self::url( array( 'tab' => 'log', 'paged' => $paged - 1 ) ) ) . '">‹ ' . esc_html__( 'Newer', 'hermaily' ) . '</a> '; // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			}
			/* translators: 1: current page, 2: number of pages. */
			echo esc_html( sprintf( __( 'Page %1$d of %2$d', 'hermaily' ), $paged, $pages ) );
			if ( $paged < $pages ) {
				echo ' <a class="button" href="' . esc_url( self::url( array( 'tab' => 'log', 'paged' => $paged + 1 ) ) ) . '">' . esc_html__( 'Older', 'hermaily' ) . ' ›</a>'; // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			}
			echo '</p>';
		}
	}

	/**
	 * One message, with its live status and events.
	 *
	 * @param int $id Log row id.
	 */
	private static function render_message( $id ) {
		echo '<p><a href="' . esc_url( self::url( array( 'tab' => 'log' ) ) ) . '">‹ ' . esc_html__( 'Back to the log', 'hermaily' ) . '</a></p>';

		$row = Hermaily_Log::get( $id );
		if ( ! $row ) {
			self::notice( 'error', __( 'This message is no longer in the log.', 'hermaily' ) );
			return;
		}

		$message = empty( $row['message_id'] ) ? null : Hermaily_Log::refresh( $row );
		$status  = is_array( $message ) && isset( $message['status'] ) ? $message['status'] : $row['status'];

		echo '<table class="form-table" role="presentation">';
		$fields = array(
			__( 'Date', 'hermaily' )        => self::local_date( $row['created_at'] ),
			__( 'To', 'hermaily' )          => $row['recipients'],
			__( 'Subject', 'hermaily' )     => $row['subject'],
			__( 'Status', 'hermaily' )      => self::status_label( $status ),
			__( 'Hermaily id', 'hermaily' ) => (string) $row['message_id'],
		);
		if ( ! empty( $row['error'] ) ) {
			$fields[ __( 'Error', 'hermaily' ) ] = $row['error'];
		}
		foreach ( $fields as $label => $value ) {
			echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . esc_html( $value ) . '</td></tr>';
		}
		echo '</table>';

		if ( is_wp_error( $message ) ) {
			/* translators: %s: error message. */
			self::notice( 'error', sprintf( __( 'Hermaily could not be asked for the current status. %s', 'hermaily' ), $message->get_error_message() ) );
			return;
		}
		if ( ! is_array( $message ) ) {
			return;
		}

		echo '<h2>' . esc_html__( 'Events', 'hermaily' ) . '</h2>';
		$events = isset( $message['events'] ) && is_array( $message['events'] ) ? $message['events'] : array();
		if ( ! $events ) {
			echo '<p>' . esc_html__( 'No events yet.', 'hermaily' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped"><thead><tr>';
		foreach ( array( __( 'Date', 'hermaily' ), __( 'Event', 'hermaily' ), __( 'Recipient', 'hermaily' ), __( 'Details', 'hermaily' ) ) as $heading ) {
			echo '<th scope="col">' . esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $events as $event ) {
			$details = array();
			foreach ( (array) ( $event['data'] ?? array() ) as $key => $value ) {
				if ( is_scalar( $value ) ) {
					$details[] = $key . ': ' . ( is_bool( $value ) ? ( $value ? 'true' : 'false' ) : $value );
				}
			}
			$when = isset( $event['occurred_at'] ) ? strtotime( (string) $event['occurred_at'] ) : false;

			echo '<tr>';
			echo '<td>' . esc_html( $when ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $when ) : '' ) . '</td>';
			echo '<td>' . esc_html( self::event_label( (string) ( $event['type'] ?? '' ) ) ) . '</td>';
			echo '<td>' . esc_html( (string) ( $event['recipient'] ?? '' ) ) . '</td>';
			echo '<td>' . esc_html( implode( ' · ', $details ) ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * A GMT database date in the site's time zone and format.
	 *
	 * @param string $gmt Date.
	 * @return string
	 */
	private static function local_date( $gmt ) {
		$time = strtotime( $gmt . ' UTC' );

		return $time ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $time ) : $gmt;
	}

	/**
	 * Human name of a message status.
	 *
	 * @param string $status Status.
	 * @return string
	 */
	private static function status_label( $status ) {
		$labels = array(
			'queued'     => __( 'Queued', 'hermaily' ),
			'sending'    => __( 'Sending', 'hermaily' ),
			'sent'       => __( 'Sent', 'hermaily' ),
			'deferred'   => __( 'Deferred, retrying', 'hermaily' ),
			'delivered'  => __( 'Delivered', 'hermaily' ),
			'held'       => __( 'Held for review', 'hermaily' ),
			'suppressed' => __( 'Suppressed', 'hermaily' ),
			'rejected'   => __( 'Rejected', 'hermaily' ),
			'failed'     => __( 'Failed', 'hermaily' ),
			'bounced'    => __( 'Bounced', 'hermaily' ),
			'complained' => __( 'Marked as spam', 'hermaily' ),
			'error'      => __( 'Not sent', 'hermaily' ),
		);

		return $labels[ $status ] ?? $status;
	}

	/**
	 * Human name of an event type.
	 *
	 * @param string $type Event type.
	 * @return string
	 */
	private static function event_label( $type ) {
		$labels = array(
			'send'              => __( 'Sent', 'hermaily' ),
			'delivery'          => __( 'Delivered', 'hermaily' ),
			'delivery_delay'    => __( 'Delayed', 'hermaily' ),
			'bounce'            => __( 'Bounce', 'hermaily' ),
			'complaint'         => __( 'Complaint', 'hermaily' ),
			'reject'            => __( 'Rejected', 'hermaily' ),
			'open'              => __( 'Opened', 'hermaily' ),
			'click'             => __( 'Clicked', 'hermaily' ),
			'rendering_failure' => __( 'Rendering failure', 'hermaily' ),
			'unsubscribe'       => __( 'Unsubscribed', 'hermaily' ),
		);

		return $labels[ $type ] ?? $type;
	}
}
