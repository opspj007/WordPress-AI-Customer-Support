<?php
defined( 'ABSPATH' ) || exit;

class AISA_DB {

	public static function t( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'aisa_' . $name;
	}

	public static function now() {
		return current_time( 'mysql', true ); // UTC.
	}

	public static function init() {
		add_action( 'aisa_daily', array( __CLASS__, 'purge' ) );
		if ( get_option( 'aisa_db_version' ) !== AISA_VERSION ) {
			self::install();
		}
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$c = $wpdb->get_charset_collate();

		dbDelta( 'CREATE TABLE ' . self::t( 'conversations' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			token char(32) NOT NULL,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			status varchar(20) NOT NULL DEFAULT 'ai',
			handoff tinyint(1) NOT NULL DEFAULT 0,
			page_url varchar(500) NOT NULL DEFAULT '',
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY token (token),
			KEY status (status),
			KEY updated_at (updated_at)
		) $c;" );

		dbDelta( 'CREATE TABLE ' . self::t( 'messages' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			conversation_id bigint(20) unsigned NOT NULL,
			role varchar(10) NOT NULL,
			content longtext NOT NULL,
			tokens_in int(11) NOT NULL DEFAULT 0,
			tokens_out int(11) NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY conversation_id (conversation_id),
			KEY created_at (created_at)
		) $c;" );

		dbDelta( 'CREATE TABLE ' . self::t( 'leads' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			conversation_id bigint(20) unsigned NOT NULL DEFAULT 0,
			name varchar(190) NOT NULL DEFAULT '',
			email varchar(190) NOT NULL DEFAULT '',
			phone varchar(60) NOT NULL DEFAULT '',
			note text NOT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY conversation_id (conversation_id)
		) $c;" );

		dbDelta( 'CREATE TABLE ' . self::t( 'chunks' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			source_type varchar(20) NOT NULL,
			source_id bigint(20) unsigned NOT NULL DEFAULT 0,
			source_title varchar(255) NOT NULL DEFAULT '',
			source_url varchar(500) NOT NULL DEFAULT '',
			chunk_index int(11) NOT NULL DEFAULT 0,
			content longtext NOT NULL,
			PRIMARY KEY  (id),
			KEY source (source_type,source_id),
			FULLTEXT KEY ft (source_title,content)
		) $c;" );

		dbDelta( 'CREATE TABLE ' . self::t( 'events' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			type varchar(30) NOT NULL,
			conversation_id bigint(20) unsigned NOT NULL DEFAULT 0,
			meta text NOT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY type_date (type,created_at)
		) $c;" );

		update_option( 'aisa_db_version', AISA_VERSION );
		if ( ! wp_next_scheduled( 'aisa_daily' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'aisa_daily' );
		}
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( 'aisa_daily' );
	}

	public static function event( $type, $conversation_id = 0, $meta = '' ) {
		global $wpdb;
		$wpdb->insert(
			self::t( 'events' ),
			array(
				'type'            => $type,
				'conversation_id' => (int) $conversation_id,
				'meta'            => mb_substr( (string) $meta, 0, 500 ),
				'created_at'      => self::now(),
			)
		);
	}

	/** Delete old conversations/messages/events per the retention setting. Leads are kept. */
	public static function purge() {
		global $wpdb;
		$days = (int) AISA_Settings::get( 'retention_days' );
		if ( $days < 1 ) {
			return;
		}
		$cut = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . self::t( 'conversations' ) . ' WHERE updated_at < %s LIMIT 500', $cut ) ); // phpcs:ignore
		if ( $ids ) {
			$in = implode( ',', array_map( 'intval', $ids ) );
			$wpdb->query( 'DELETE FROM ' . self::t( 'messages' ) . " WHERE conversation_id IN ($in)" ); // phpcs:ignore
			$wpdb->query( 'DELETE FROM ' . self::t( 'conversations' ) . " WHERE id IN ($in)" ); // phpcs:ignore
		}
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::t( 'events' ) . ' WHERE created_at < %s', $cut ) ); // phpcs:ignore
	}
}
