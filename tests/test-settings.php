<?php
/**
 * Tests for the settings page.
 *
 * @package Hermaily
 */

/**
 * Saving, the domain check, the test email and the tabs.
 */
class Test_Settings extends Hermaily_TestCase {

	/**
	 * Defaults apply when nothing is saved.
	 */
	public function test_defaults() {
		$settings = Hermaily_Settings::get();

		$this->assertSame( '', $settings['api_key'] );
		$this->assertFalse( $settings['force_from'] );
		$this->assertSame( 30, $settings['log_days'] );
	}

	/**
	 * Saving needs a valid nonce.
	 */
	public function test_save_needs_a_nonce() {
		$this->login_admin();
		$_POST    = array( 'api_key' => 'hm_new' );
		$_REQUEST = $_POST;

		$this->expectException( WPDieException::class );

		try {
			Hermaily_Settings::save();
		} finally {
			$this->assertFalse( get_option( 'hermaily_settings' ) );
		}
	}

	/**
	 * Saving needs manage_options.
	 */
	public function test_save_needs_the_capability() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$this->expectException( WPDieException::class );

		$this->post( 'save', array( 'api_key' => 'hm_new' ) );
	}

	/**
	 * A valid form is stored and sanitized.
	 */
	public function test_save_stores_sanitized_settings() {
		$this->login_admin();
		$this->respond( 'GET /v1/domain', 404, array() );

		$redirect = $this->post(
			'save',
			array(
				'api_key'    => ' hm_new ',
				'from_email' => 'hello@acme.example',
				'from_name'  => '<b>Acme</b>',
				'force_from' => '1',
				'log_days'   => '400',
			)
		);

		$settings = Hermaily_Settings::get();
		$this->assertStringContainsString( 'saved=1', $redirect );
		$this->assertSame( 'hm_new', $settings['api_key'] );
		$this->assertSame( 'Acme', $settings['from_name'] );
		$this->assertTrue( $settings['force_from'] );
		$this->assertSame( 365, $settings['log_days'] );
	}

	/**
	 * An empty key field keeps the saved key: the page never shows it.
	 */
	public function test_empty_key_field_keeps_the_saved_key() {
		$this->login_admin();
		$this->configure( array( 'api_key' => 'hm_saved' ) );

		$this->post( 'save', array( 'api_key' => '' ) );

		$this->assertSame( 'hm_saved', Hermaily_Settings::get()['api_key'] );
	}

	/**
	 * The remove box deletes the saved key.
	 */
	public function test_the_key_can_be_removed() {
		$this->login_admin();
		$this->configure( array( 'api_key' => 'hm_saved' ) );

		$this->post(
			'save',
			array(
				'api_key'    => '',
				'remove_key' => '1',
			)
		);

		$this->assertSame( '', Hermaily_Settings::get()['api_key'] );
	}

	/**
	 * An invalid sender address is refused and the old one kept.
	 */
	public function test_invalid_sender_is_refused() {
		$this->login_admin();
		$this->configure();

		$redirect = $this->post( 'save', array( 'from_email' => 'not an email' ) );

		$this->assertStringContainsString( 'error=from_email', $redirect );
		$this->assertSame( 'hello@acme.example', Hermaily_Settings::get()['from_email'] );
	}

	/**
	 * A new key with no sender gets one on the key's domain.
	 */
	public function test_new_key_proposes_a_sender_on_its_domain() {
		$this->login_admin();
		$this->respond(
			'GET /v1/domain',
			200,
			array(
				'domain'   => 'acme.example',
				'verified' => true,
			)
		);

		$this->post(
			'save',
			array(
				'api_key'    => 'hm_new',
				'from_email' => '',
			)
		);

		$this->assertSame( 'wordpress@acme.example', Hermaily_Settings::get()['from_email'] );
		$this->assertSame( 'acme.example', Hermaily_Settings::domain()['domain'] );
	}

	/**
	 * Unknown domain endpoint: the plugin works the same, without proposing anything.
	 */
	public function test_without_the_domain_endpoint_nothing_is_proposed() {
		$this->login_admin();
		$this->respond( 'GET /v1/domain', 404, array() );

		$this->post(
			'save',
			array(
				'api_key'    => 'hm_new',
				'from_email' => '',
			)
		);

		$this->assertSame( '', Hermaily_Settings::get()['from_email'] );
		$this->assertNull( Hermaily_Settings::domain() );
	}

	/**
	 * The domain is asked once a day at most.
	 */
	public function test_domain_is_cached() {
		$this->configure();
		$this->respond(
			'GET /v1/domain',
			200,
			array(
				'domain'   => 'acme.example',
				'verified' => false,
			)
		);

		Hermaily_Settings::domain();
		Hermaily_Settings::domain();

		$this->assertCount( 1, $this->api_requests() );
	}

	/**
	 * The page warns when the domain is not verified, or the sender is outside it.
	 */
	public function test_page_warns_about_unverified_domain_and_foreign_sender() {
		$this->login_admin();
		$this->configure( array( 'from_email' => 'hello@other.example' ) );
		$this->respond(
			'GET /v1/domain',
			200,
			array(
				'domain'   => 'acme.example',
				'verified' => false,
			)
		);

		$html = $this->render();

		$this->assertStringContainsString( 'is not verified yet', $html );
		$this->assertStringContainsString( 'is not on acme.example', $html );
	}

	/**
	 * Why the account cannot send, as Hermaily explains it.
	 *
	 * @dataProvider cannot_send_reasons
	 *
	 * @param array  $answer   GET /v1/domain answer.
	 * @param string $expected Text the page must show.
	 */
	public function test_page_explains_why_it_cannot_send( array $answer, $expected ) {
		$this->login_admin();
		$this->configure();
		$this->respond( 'GET /v1/domain', 200, array_merge( array( 'domain' => 'acme.example' ), $answer ) );

		$this->assertStringContainsString( $expected, $this->render() );
	}

	/**
	 * Answers of GET /v1/domain that mean no email will go out, and the explanation for each.
	 *
	 * @return array
	 */
	public function cannot_send_reasons() {
		return array(
			'pending approval' => array(
				array(
					'verified'       => true,
					'account_status' => 'pending',
					'can_send'       => false,
				),
				'pending approval',
			),
			'paused'           => array(
				array(
					'verified'       => true,
					'account_status' => 'paused',
					'can_send'       => false,
				),
				'contact Hermaily support',
			),
			'suspended'        => array(
				array(
					'verified'       => true,
					'account_status' => 'suspended',
					'can_send'       => false,
				),
				'contact Hermaily support',
			),
			'unverified'       => array(
				array(
					'verified'       => false,
					'account_status' => 'active',
					'can_send'       => false,
				),
				'is not verified yet',
			),
		);
	}

	/**
	 * An account that can send gets no warning.
	 */
	public function test_no_warning_when_it_can_send() {
		$this->login_admin();
		$this->configure();
		$this->respond(
			'GET /v1/domain',
			200,
			array(
				'domain'         => 'acme.example',
				'verified'       => true,
				'stream'         => null,
				'account_status' => 'active',
				'can_send'       => true,
			)
		);

		$this->assertStringNotContainsString( 'notice-warning', $this->render() );
	}

	/**
	 * A broadcast-only key is useless for WordPress's transactional email.
	 */
	public function test_page_warns_about_a_broadcast_key() {
		$this->login_admin();
		$this->configure();
		$this->respond(
			'GET /v1/domain',
			200,
			array(
				'domain'         => 'acme.example',
				'verified'       => true,
				'stream'         => 'broadcast',
				'account_status' => 'active',
				'can_send'       => true,
			)
		);

		$this->assertStringContainsString( 'only send through the broadcast stream', $this->render() );
	}

	/**
	 * A rejected key is reported as such.
	 */
	public function test_page_reports_a_rejected_key() {
		$this->login_admin();
		$this->configure();
		$this->respond(
			'GET /v1/domain',
			401,
			array(
				'error' => array(
					'code'    => 'unauthorized',
					'message' => 'Invalid API key',
				),
			)
		);

		$this->assertStringContainsString( 'Hermaily does not accept this API key', $this->render() );
	}

	/**
	 * A subdomain sender is fine.
	 */
	public function test_subdomain_sender_is_on_the_domain() {
		$this->assertTrue( Hermaily_Settings::sender_on_domain( 'news@mail.acme.example', 'acme.example' ) );
		$this->assertTrue( Hermaily_Settings::sender_on_domain( 'a@ACME.example', 'acme.example' ) );
		$this->assertFalse( Hermaily_Settings::sender_on_domain( 'a@notacme.example', 'acme.example' ) );
	}

	/**
	 * The saved key is never printed back.
	 */
	public function test_saved_key_is_never_printed() {
		$this->login_admin();
		$this->configure( array( 'api_key' => 'hm_very_secret_value' ) );
		$this->respond( 'GET /v1/domain', 404, array() );

		$html = $this->render();

		$this->assertStringNotContainsString( 'hm_very_secret_value', $html );
		$this->assertStringContainsString( 'name="api_key"', $html );
	}

	/**
	 * The page is empty for users without the capability.
	 */
	public function test_page_is_hidden_from_other_users() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$this->assertSame( '', $this->render() );
	}

	/**
	 * The test email goes through wp_mail() and its result is shown.
	 */
	public function test_test_email_is_sent_and_reported() {
		$this->login_admin();
		$this->configure();
		$this->respond_accepted( 'id-test' );

		$this->post( 'test', array( 'to' => 'lucia@example.com' ) );

		$body = $this->api_requests()[0]['body'];
		$this->assertSame( array( 'lucia@example.com' ), $body['to'] );
		$this->assertStringContainsString( 'id-test', $this->render() );
	}

	/**
	 * A failed test email shows Hermaily's explanation.
	 */
	public function test_failed_test_email_shows_the_reason() {
		$this->login_admin();
		$this->configure();
		$this->respond(
			'POST /v1/send',
			401,
			array(
				'error' => array(
					'code'    => 'unauthorized',
					'message' => 'Invalid API key',
				),
			)
		);

		$this->post( 'test', array( 'to' => 'lucia@example.com' ) );

		$this->assertStringContainsString( 'Invalid API key', $this->render() );
	}

	/**
	 * The test email needs a key first.
	 */
	public function test_test_email_needs_a_key() {
		$this->login_admin();

		$redirect = $this->post( 'test', array( 'to' => 'lucia@example.com' ) );

		$this->assertStringContainsString( 'error=no_key', $redirect );
		$this->assertSame( array(), $this->api_requests() );
	}

	/**
	 * The log tab lists messages, escaped.
	 */
	public function test_log_tab_lists_messages_escaped() {
		$this->login_admin();
		$this->configure();
		Hermaily_Log::add( array( 'lucia@example.com' ), '<script>x</script>', 'delivered', 'id-1' );

		$html = $this->render( array( 'tab' => 'log' ) );

		$this->assertStringContainsString( 'lucia@example.com', $html );
		$this->assertStringContainsString( '&lt;script&gt;', $html );
		$this->assertStringNotContainsString( '<script>x', $html );
		$this->assertSame( array(), $this->api_requests(), 'Listing the log must not call the API.' );
	}

	/**
	 * The message view shows the live status and events.
	 */
	public function test_message_view_shows_events() {
		$this->login_admin();
		$this->configure();
		Hermaily_Log::add( array( 'lucia@example.com' ), 'Hi', 'sent', 'id-1' );
		$this->respond(
			'GET /v1/messages/id-1',
			200,
			array(
				'id'     => 'id-1',
				'status' => 'complained',
				'events' => array(
					array(
						'type'        => 'complaint',
						'recipient'   => 'lucia@example.com',
						'occurred_at' => '2026-11-24T10:05:00Z',
						'data'        => array( 'feedback_type' => 'abuse' ),
					),
				),
			)
		);
		$row = Hermaily_Log::rows( 1, 1 )[0];

		$html = $this->render(
			array(
				'tab'     => 'log',
				'message' => (string) $row['id'],
			)
		);

		$this->assertStringContainsString( 'Complaint', $html );
		$this->assertStringContainsString( 'abuse', $html );
		$this->assertSame( 'complained', Hermaily_Log::get( $row['id'] )['status'] );
	}

	/**
	 * The refresh button updates the statuses of the page.
	 */
	public function test_refresh_updates_the_statuses() {
		$this->login_admin();
		$this->configure();
		Hermaily_Log::add( array( 'lucia@example.com' ), 'Hi', 'queued', 'id-1' );
		$this->respond(
			'GET /v1/messages/id-1',
			200,
			array(
				'id'     => 'id-1',
				'status' => 'delivered',
				'events' => array(),
			)
		);

		$this->post( 'refresh', array( 'paged' => '1' ) );

		$this->assertSame( 'delivered', Hermaily_Log::rows( 1, 1 )[0]['status'] );
	}

	/**
	 * The more plugins tab shows the catalog without calling Hermaily.
	 */
	public function test_more_plugins_tab() {
		$this->login_admin();

		$html = $this->render( array( 'tab' => 'more-plugins' ) );

		$this->assertStringContainsString( 'dfx-showcase', $html );
		$this->assertStringNotContainsString( 'name="api_key"', $html );
	}

	/**
	 * Settings link in the plugins list.
	 */
	public function test_settings_link() {
		$links = Hermaily_Settings::action_links( array() );

		$this->assertStringContainsString( 'options-general.php?page=hermaily', $links[0] );
	}

	/**
	 * Renders the page with the given query.
	 *
	 * @param array $get Query arguments.
	 * @return string
	 */
	private function render( array $get = array() ) {
		$_GET = $get;
		ob_start();
		Hermaily_Settings::render_page();
		$html = (string) ob_get_clean();
		$_GET = array();

		return $html;
	}

	/**
	 * Posts a form with a valid nonce and returns where it redirects.
	 *
	 * @param string $action save, test or refresh.
	 * @param array  $fields Form fields.
	 * @return string
	 */
	private function post( $action, array $fields ) {
		$_POST    = array_merge( $fields, array( '_wpnonce' => wp_create_nonce( 'hermaily_' . $action ) ) );
		$_REQUEST = $_POST;
		$redirect = '';

		$catch = static function ( $location ) {
			throw new RuntimeException( $location );
		};
		add_filter( 'wp_redirect', $catch );

		try {
			call_user_func( array( 'Hermaily_Settings', 'save' === $action ? 'save' : ( 'test' === $action ? 'send_test' : 'refresh_log' ) ) );
		} catch ( RuntimeException $e ) {
			$redirect = $e->getMessage();
		} finally {
			remove_filter( 'wp_redirect', $catch );
			$_POST    = array();
			$_REQUEST = array();
		}

		return $redirect;
	}
}
