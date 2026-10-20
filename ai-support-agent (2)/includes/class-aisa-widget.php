<?php
defined( 'ABSPATH' ) || exit;

class AISA_Widget {

	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	public static function enqueue() {
		if ( is_admin() || ! AISA_Settings::get( 'enabled' ) || '' === AISA_Settings::api_key() ) {
			return;
		}
		wp_enqueue_style( 'aisa-widget', AISA_URL . 'assets/widget.css', array(), AISA_VERSION );
		wp_enqueue_script( 'aisa-widget', AISA_URL . 'assets/widget.js', array(), AISA_VERSION, true );
		wp_localize_script(
			'aisa-widget',
			'AISA',
			array(
				'rest'     => esc_url_raw( rest_url( AISA_REST::NS . '/' ) ),
				'nonce'    => wp_create_nonce( 'wp_rest' ),
				'title'    => AISA_Settings::get( 'title' ),
				'greeting' => AISA_Settings::get( 'greeting' ),
				'consent'  => AISA_Settings::get( 'consent_text' ),
				'color'    => AISA_Settings::get( 'color' ),
				'position' => AISA_Settings::get( 'position' ),
				'i18n'     => array(
					'open'        => __( 'Open chat', 'ai-support-agent' ),
					'close'       => __( 'Close chat', 'ai-support-agent' ),
					'placeholder' => __( 'Type your message…', 'ai-support-agent' ),
					'send'        => __( 'Send', 'ai-support-agent' ),
					'waiting'     => __( 'A team member has been notified and will reply here shortly.', 'ai-support-agent' ),
					'error'       => __( 'Something went wrong. Please try again.', 'ai-support-agent' ),
					'agent'       => __( 'Team member', 'ai-support-agent' ),
				),
			)
		);
	}
}
