<?php
defined( 'ABSPATH' ) || exit;

class AISA_REST {

	const NS = 'aisa/v1';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes() {
		$token = array(
			'required'          => true,
			'type'              => 'string',
			'validate_callback' => function ( $v ) {
				return (bool) preg_match( '/^[a-f0-9]{32}$/', (string) $v );
			},
		);
		register_rest_route(
			self::NS,
			'/chat',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'chat' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'token'    => $token,
					'message'  => array( 'required' => true, 'type' => 'string' ),
					'page_url' => array( 'type' => 'string' ),
				),
			)
		);
		register_rest_route(
			self::NS,
			'/messages',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'messages' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'token' => $token,
					'after' => array( 'type' => 'integer', 'default' => 0 ),
				),
			)
		);
	}

	private static function ip() {
		return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
	}

	public static function chat( WP_REST_Request $req ) {
		if ( ! AISA_Settings::get( 'enabled' ) || '' === AISA_Settings::api_key() ) {
			return new WP_Error( 'aisa_off', 'Chat is not available right now.', array( 'status' => 503 ) );
		}

		$key   = 'aisa_rl_' . md5( self::ip() );
		$count = (int) get_transient( $key );
		if ( $count >= (int) AISA_Settings::get( 'rate_limit' ) ) {
			return new WP_Error( 'aisa_rate', 'You are sending messages too quickly. Please wait a moment.', array( 'status' => 429 ) );
		}
		set_transient( $key, $count + 1, MINUTE_IN_SECONDS );

		$text = trim( mb_substr( sanitize_textarea_field( (string) $req['message'] ), 0, 1000 ) );
		if ( '' === $text ) {
			return new WP_Error( 'aisa_empty', 'Message is empty.', array( 'status' => 400 ) );
		}

		$out = AISA_Chat::handle( $req['token'], $text, (string) $req['page_url'] );
		return is_wp_error( $out ) ? $out : rest_ensure_response( $out );
	}

	public static function messages( WP_REST_Request $req ) {
		global $wpdb;
		$conv  = AISA_Chat::find( $req['token'] );
		$after = max( 0, (int) $req['after'] );
		if ( ! $conv ) {
			return rest_ensure_response( array( 'messages' => array(), 'status' => 'ai', 'last_id' => 0 ) );
		}
		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT id, role, content FROM ' . AISA_DB::t( 'messages' ) . ' WHERE conversation_id = %d AND id > %d ORDER BY id ASC LIMIT 100', $conv->id, $after ), // phpcs:ignore
			ARRAY_A
		);
		$last = $after;
		foreach ( $rows as &$r ) {
			$r['id'] = (int) $r['id'];
			$last    = max( $last, $r['id'] );
		}
		unset( $r );
		return rest_ensure_response( array( 'messages' => $rows, 'status' => $conv->status, 'last_id' => $last ) );
	}
}
