<?php
defined( 'ABSPATH' ) || exit;

class AISA_Settings {

	const OPTION = 'aisa_settings';

	public static function defaults() {
		return array(
			'enabled'        => 1,
			'provider'       => 'anthropic',
			'api_key'        => '',
			'model'          => 'claude-sonnet-5-5',
			'openai_base'    => 'https://api.openai.com/v1',
			'business_name'  => get_bloginfo( 'name' ),
			'system_prompt'  => '',
			'title'          => 'Support',
			'greeting'       => 'Hi! How can I help you today?',
			'consent_text'   => 'This chat is answered by AI and may be stored to improve support.',
			'color'          => '#2563eb',
			'position'       => 'right',
			'handoff_email'  => get_option( 'admin_email' ),
			'post_types'     => array( 'post', 'page', 'product' ),
			'woocommerce'    => 1,
			'collect_leads'  => 1,
			'rate_limit'     => 10,
			'retention_days' => 90,
		);
	}

	public static function all() {
		$saved = get_option( self::OPTION, array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
	}

	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	/** An AISA_API_KEY constant in wp-config.php overrides the saved key. */
	public static function api_key() {
		if ( defined( 'AISA_API_KEY' ) && AISA_API_KEY ) {
			return AISA_API_KEY;
		}
		return (string) self::get( 'api_key' );
	}

	public static function sanitize( $in ) {
		$old = self::all();
		$in  = is_array( $in ) ? $in : array();
		$out = array();

		$out['enabled']       = empty( $in['enabled'] ) ? 0 : 1;
		$out['woocommerce']   = empty( $in['woocommerce'] ) ? 0 : 1;
		$out['collect_leads'] = empty( $in['collect_leads'] ) ? 0 : 1;

		$provider        = isset( $in['provider'] ) ? $in['provider'] : '';
		$out['provider'] = in_array( $provider, array( 'anthropic', 'openai' ), true ) ? $provider : 'anthropic';

		$key            = isset( $in['api_key'] ) ? trim( (string) $in['api_key'] ) : '';
		$out['api_key'] = '' !== $key ? sanitize_text_field( $key ) : $old['api_key'];

		$out['model']       = sanitize_text_field( isset( $in['model'] ) ? $in['model'] : $old['model'] );
		$out['openai_base'] = esc_url_raw( isset( $in['openai_base'] ) ? $in['openai_base'] : $old['openai_base'] );
		if ( '' === $out['openai_base'] ) {
			$out['openai_base'] = 'https://api.openai.com/v1';
		}

		$out['business_name'] = sanitize_text_field( isset( $in['business_name'] ) ? $in['business_name'] : '' );
		$out['system_prompt'] = sanitize_textarea_field( isset( $in['system_prompt'] ) ? $in['system_prompt'] : '' );
		$out['title']         = sanitize_text_field( isset( $in['title'] ) ? $in['title'] : 'Support' );
		$out['greeting']      = sanitize_text_field( isset( $in['greeting'] ) ? $in['greeting'] : '' );
		$out['consent_text']  = sanitize_text_field( isset( $in['consent_text'] ) ? $in['consent_text'] : '' );

		$color           = sanitize_hex_color( isset( $in['color'] ) ? $in['color'] : '' );
		$out['color']    = $color ? $color : '#2563eb';
		$out['position'] = ( isset( $in['position'] ) && 'left' === $in['position'] ) ? 'left' : 'right';

		$email                = sanitize_email( isset( $in['handoff_email'] ) ? $in['handoff_email'] : '' );
		$out['handoff_email'] = $email ? $email : get_option( 'admin_email' );

		$types             = isset( $in['post_types'] ) && is_array( $in['post_types'] ) ? $in['post_types'] : array();
		$out['post_types'] = array_values( array_filter( array_map( 'sanitize_key', $types ) ) );

		$out['rate_limit']     = max( 1, min( 120, (int) ( isset( $in['rate_limit'] ) ? $in['rate_limit'] : 10 ) ) );
		$out['retention_days'] = max( 0, min( 3650, (int) ( isset( $in['retention_days'] ) ? $in['retention_days'] : 90 ) ) );

		return $out;
	}
}
