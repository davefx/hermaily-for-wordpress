<?php
/**
 * Tests for the key defined in wp-config.php.
 *
 * The constant cannot be undefined, so this file runs last (PHPUnit reads the folder in order).
 *
 * @package Hermaily
 */

/**
 * The HERMAILY_API_KEY constant.
 */
class Test_ZZ_Constant_Key extends Hermaily_TestCase {

	/**
	 * Defines the key in "wp-config.php".
	 */
	public function set_up() {
		parent::set_up();
		if ( ! defined( 'HERMAILY_API_KEY' ) ) {
			define( 'HERMAILY_API_KEY', 'hm_from_config' );
		}
	}

	/**
	 * The constant wins over the saved key, and the field is locked.
	 */
	public function test_constant_key_wins_and_locks_the_field() {
		$this->configure( array( 'api_key' => 'hm_saved' ) );
		$this->login_admin();
		$this->respond( 'GET /v1/domain', 404, array() );

		$this->assertSame( 'hm_from_config', Hermaily_Settings::api_key() );
		$this->assertTrue( Hermaily_Settings::key_is_constant() );

		$_GET = array();
		ob_start();
		Hermaily_Settings::render_page();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'wp-config.php', $html );
		$this->assertMatchesRegularExpression( '/name="api_key"[^>]*disabled/', $html );
		$this->assertStringNotContainsString( 'hm_from_config', $html );
	}

	/**
	 * Mail goes out with the constant's key.
	 */
	public function test_mail_uses_the_constant_key() {
		$this->configure( array( 'api_key' => '' ) );
		$this->respond_accepted();

		$this->assertTrue( wp_mail( 'lucia@example.com', 'Hi', 'Hello' ) );
		$this->assertSame( 'Bearer hm_from_config', $this->api_requests()[0]['headers']['Authorization'] );
	}
}
