<?php
/**
 * Plugin Name: AI Support Agent
 * Description: AI customer support chatbot with website knowledge base (RAG), document knowledge, WooCommerce tools, lead collection, conversation history, human handoff and admin analytics.
 * Version: 0.1.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * License: GPL-2.0-or-later
 * Text Domain: ai-support-agent
 */

defined( 'ABSPATH' ) || exit;

define( 'AISA_VERSION', '0.1.0' );
define( 'AISA_FILE', __FILE__ );
define( 'AISA_DIR', plugin_dir_path( __FILE__ ) );
define( 'AISA_URL', plugin_dir_url( __FILE__ ) );

foreach ( array( 'settings', 'db', 'kb', 'tools', 'llm', 'chat', 'rest', 'admin', 'widget' ) as $aisa_file ) {
	require_once AISA_DIR . 'includes/class-aisa-' . $aisa_file . '.php';
}

register_activation_hook( __FILE__, array( 'AISA_DB', 'install' ) );
register_deactivation_hook( __FILE__, array( 'AISA_DB', 'deactivate' ) );

add_action(
	'plugins_loaded',
	function () {
		AISA_DB::init();
		AISA_KB::init();
		AISA_REST::init();
		AISA_Admin::init();
		AISA_Widget::init();
	}
);
