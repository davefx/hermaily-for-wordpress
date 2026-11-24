<?php
/**
 * Plugin Name:       Hermaily
 * Plugin URI:        https://hermaily.com/en/docs/
 * Description:       Sends WordPress email through the Hermaily HTTPS API, from the European Union. No SMTP ports needed.
 * Version:           1.0.0
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            David Marín Carreño (DaveFX)
 * Author URI:        https://davefx.com
 * License:           GPLv3 or later
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:       hermaily
 * Domain Path:       /languages
 *
 * @package Hermaily
 */

defined( 'ABSPATH' ) || exit;

define( 'HERMAILY_VERSION', '1.0.0' );
define( 'HERMAILY_FILE', __FILE__ );

require_once __DIR__ . '/includes/class-hermaily-client.php';
require_once __DIR__ . '/includes/class-hermaily-mailer.php';
require_once __DIR__ . '/includes/class-hermaily-log.php';
require_once __DIR__ . '/includes/class-hermaily-settings.php';
require_once __DIR__ . '/libraries/dfx-plugin-showcase/load.php';

add_filter( 'pre_wp_mail', array( 'Hermaily_Mailer', 'pre_wp_mail' ), 10, 2 );
add_action( 'hermaily_purge_log', array( 'Hermaily_Log', 'purge' ) );
Hermaily_Settings::hooks();

register_activation_hook( __FILE__, array( 'Hermaily_Log', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Hermaily_Log', 'deactivate' ) );
