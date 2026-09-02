<?php
/**
 * Plugin Name: Intertexere
 * Description: Contextual internal linking assistant for WordPress.
 * Version: 0.3.0
 * Requires at least: 7.1
 * Requires PHP: 7.4
 * Author: Jim Lunsford
 * License: GPL-2.0-or-later
 * Text Domain: intertexere
 */

namespace Intertexere;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'INTERTEXERE_VERSION', '0.3.0' );
define( 'INTERTEXERE_MINIMUM_WP_VERSION', '7.1' );
define( 'INTERTEXERE_FILE', __FILE__ );
define( 'INTERTEXERE_PATH', plugin_dir_path( __FILE__ ) );

require_once INTERTEXERE_PATH . 'includes/class-settings.php';
require_once INTERTEXERE_PATH . 'includes/class-eligibility.php';
require_once INTERTEXERE_PATH . 'includes/class-schema.php';
require_once INTERTEXERE_PATH . 'includes/class-indexer.php';
require_once INTERTEXERE_PATH . 'includes/class-link-resolver.php';
require_once INTERTEXERE_PATH . 'includes/class-link-graph.php';
require_once INTERTEXERE_PATH . 'includes/class-editor-suggestions.php';
require_once INTERTEXERE_PATH . 'includes/class-editor-rest.php';
require_once INTERTEXERE_PATH . 'includes/class-editor-assets.php';
require_once INTERTEXERE_PATH . 'includes/class-admin.php';
require_once INTERTEXERE_PATH . 'includes/class-lifecycle.php';
require_once INTERTEXERE_PATH . 'includes/class-plugin.php';

register_activation_hook( __FILE__, array( Lifecycle::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( Lifecycle::class, 'deactivate' ) );

add_action( 'plugins_loaded', array( Plugin::class, 'boot' ) );
