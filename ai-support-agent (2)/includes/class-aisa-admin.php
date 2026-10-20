<?php
defined( 'ABSPATH' ) || exit;

class AISA_Admin {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_action( 'admin_head', array( __CLASS__, 'css' ) );
		foreach ( array( 'agent_reply', 'conv_action', 'reindex', 'add_doc', 'delete_doc', 'export_leads' ) as $a ) {
			add_action( 'admin_post_aisa_' . $a, array( __CLASS__, 'h_' . $a ) );
		}
	}

	public static function register() {
		register_setting( 'aisa_group', AISA_Settings::OPTION, array( 'sanitize_callback' => array( 'AISA_Settings', 'sanitize' ) ) );
	}

	private static function guard() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'ai-support-agent' ), 403 );
		}
	}

	private static function waiting_count() {
		global $wpdb;
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . AISA_DB::t( 'conversations' ) . " WHERE status = 'waiting_human'" ); // phpcs:ignore
	}

	public static function menu() {
		$w     = self::waiting_count();
		$label = 'AI Support' . ( $w ? ' <span class="awaiting-mod">' . (int) $w . '</span>' : '' );
		add_menu_page( 'AI Support', $label, 'manage_options', 'aisa', array( __CLASS__, 'page_analytics' ), 'dashicons-format-chat', 58 );
		add_submenu_page( 'aisa', 'Analytics', 'Analytics', 'manage_options', 'aisa', array( __CLASS__, 'page_analytics' ) );
		add_submenu_page( 'aisa', 'Conversations', 'Conversations', 'manage_options', 'aisa-conversations', array( __CLASS__, 'page_conversations' ) );
		add_submenu_page( 'aisa', 'Leads', 'Leads', 'manage_options', 'aisa-leads', array( __CLASS__, 'page_leads' ) );
		add_submenu_page( 'aisa', 'Knowledge', 'Knowledge', 'manage_options', 'aisa-knowledge', array( __CLASS__, 'page_knowledge' ) );
		add_submenu_page( 'aisa', 'Settings', 'Settings', 'manage_options', 'aisa-settings', array( __CLASS__, 'page_settings' ) );
	}

	public static function css() {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore
		if ( 0 !== strpos( $page, 'aisa' ) ) {
			return;
		}
		echo '<style>
		.aisa-cards{display:flex;flex-wrap:wrap;gap:12px;margin:16px 0}
		.aisa-card{background:#fff;border:1px solid #c3c4c7;border-radius:6px;padding:14px 18px;min-width:150px}
		.aisa-card b{display:block;font-size:26px;line-height:1.2}
		.aisa-bar{background:#2271b1;height:14px;border-radius:2px;min-width:2px}
		.aisa-thread{max-width:760px}
		.aisa-b{margin:8px 0;padding:10px 12px;border-radius:6px;background:#fff;border:1px solid #dcdcde;white-space:pre-wrap}
		.aisa-b.user{background:#f0f6fc}.aisa-b.agent{background:#edfaef}
		.aisa-b small{display:block;color:#646970;margin-bottom:2px}
		</style>';
	}

	private static function notice() {
		$msgs = array(
			'reindex'    => array( 'success', 'Reindexing started in the background.' ),
			'doc_added'  => array( 'success', 'Document added to the knowledge base.' ),
			'doc_gone'   => array( 'success', 'Document removed.' ),
			'err_type'   => array( 'error', 'Unsupported file type. Use .txt, .md, .csv or .pdf.' ),
			'err_empty'  => array( 'error', 'Nothing to add: no text was found.' ),
			'err_size'   => array( 'error', 'File is too large (5 MB max).' ),
			'err_pdf'    => array( 'error', 'PDF support needs smalot/pdfparser installed in the plugin folder (see README).' ),
			'replied'    => array( 'success', 'Reply sent.' ),
			'updated'    => array( 'success', 'Conversation updated.' ),
		);
		$code = isset( $_GET['aisa_msg'] ) ? sanitize_key( wp_unslash( $_GET['aisa_msg'] ) ) : ''; // phpcs:ignore
		if ( isset( $msgs[ $code ] ) ) {
			printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $msgs[ $code ][0] ), esc_html( $msgs[ $code ][1] ) );
		}
	}

	private static function local( $utc ) {
		return esc_html( get_date_from_gmt( $utc, 'M j, H:i' ) );
	}

	/* ====================== Analytics ====================== */

	public static function page_analytics() {
		self::guard();
		global $wpdb;
		$c     = AISA_DB::t( 'conversations' );
		$m     = AISA_DB::t( 'messages' );
		$l     = AISA_DB::t( 'leads' );
		$e     = AISA_DB::t( 'events' );
		$since = gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS );

		// phpcs:disable WordPress.DB.PreparedSQL
		$convs    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $c WHERE created_at >= %s", $since ) );
		$handoffs = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $c WHERE created_at >= %s AND handoff = 1", $since ) );
		$msgs     = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $m WHERE created_at >= %s AND role = 'user'", $since ) );
		$leads    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $l WHERE created_at >= %s", $since ) );
		$tok      = $wpdb->get_row( $wpdb->prepare( "SELECT COALESCE(SUM(tokens_in),0) i, COALESCE(SUM(tokens_out),0) o FROM $m WHERE created_at >= %s", $since ) );
		$unans    = $wpdb->get_results( $wpdb->prepare( "SELECT meta, COUNT(*) n FROM $e WHERE type = 'no_kb_hit' AND created_at >= %s GROUP BY meta ORDER BY n DESC LIMIT 10", $since ) );
		$tools    = $wpdb->get_results( $wpdb->prepare( "SELECT meta, COUNT(*) n FROM $e WHERE type = 'tool' AND created_at >= %s GROUP BY meta ORDER BY n DESC", $since ) );
		$errors   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $e WHERE type = 'error' AND created_at >= %s", $since ) );
		$daily    = $wpdb->get_results( $wpdb->prepare( "SELECT DATE(created_at) d, COUNT(*) n FROM $c WHERE created_at >= %s GROUP BY d ORDER BY d", $since ) );
		// phpcs:enable

		$deflect = $convs ? round( ( $convs - $handoffs ) / $convs * 100 ) . '%' : '–';
		$avg     = $convs ? round( $msgs / $convs, 1 ) : '–';
		$max     = 1;
		foreach ( $daily as $d ) {
			$max = max( $max, (int) $d->n );
		}

		echo '<div class="wrap"><h1>AI Support: Analytics <small>(last 30 days)</small></h1>';
		echo '<div class="aisa-cards">';
		$cards = array(
			'Conversations'        => $convs,
			'Visitor messages'     => $msgs,
			'AI-resolved (no handoff)' => $deflect,
			'Human handoffs'       => $handoffs,
			'Leads'                => $leads,
			'Avg messages / chat'  => $avg,
			'Tokens in / out'      => number_format_i18n( (int) $tok->i ) . ' / ' . number_format_i18n( (int) $tok->o ),
			'Errors'               => $errors,
		);
		foreach ( $cards as $k => $v ) {
			printf( '<div class="aisa-card"><b>%s</b>%s</div>', esc_html( (string) $v ), esc_html( $k ) );
		}
		echo '</div>';

		echo '<h2>Top unanswered questions</h2><p class="description">Searches that found nothing in the knowledge base. Add content that covers these.</p>';
		if ( $unans ) {
			echo '<table class="widefat striped" style="max-width:700px"><thead><tr><th>Search</th><th style="width:80px">Count</th></tr></thead><tbody>';
			foreach ( $unans as $u ) {
				printf( '<tr><td>%s</td><td>%d</td></tr>', esc_html( $u->meta ), (int) $u->n );
			}
			echo '</tbody></table>';
		} else {
			echo '<p>None yet.</p>';
		}

		echo '<h2>Tool usage</h2>';
		if ( $tools ) {
			echo '<table class="widefat striped" style="max-width:700px"><tbody>';
			foreach ( $tools as $t ) {
				printf( '<tr><td>%s</td><td style="width:80px">%d</td></tr>', esc_html( $t->meta ), (int) $t->n );
			}
			echo '</tbody></table>';
		} else {
			echo '<p>None yet.</p>';
		}

		echo '<h2>Conversations per day</h2>';
		if ( $daily ) {
			echo '<table class="widefat striped" style="max-width:700px"><tbody>';
			foreach ( $daily as $d ) {
				printf(
					'<tr><td style="width:110px">%s</td><td><div class="aisa-bar" style="width:%d%%"></div></td><td style="width:50px">%d</td></tr>',
					esc_html( $d->d ),
					(int) round( $d->n / $max * 100 ),
					(int) $d->n
				);
			}
			echo '</tbody></table>';
		} else {
			echo '<p>None yet.</p>';
		}
		echo '</div>';
	}

	/* ====================== Conversations ====================== */

	public static function page_conversations() {
		self::guard();
		global $wpdb;
		$c  = AISA_DB::t( 'conversations' );
		$m  = AISA_DB::t( 'messages' );
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0; // phpcs:ignore

		echo '<div class="wrap"><h1>Conversations</h1>';
		self::notice();

		if ( $id ) {
			$conv = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $c WHERE id = %d", $id ) ); // phpcs:ignore
			if ( ! $conv ) {
				echo '<p>Not found.</p></div>';
				return;
			}
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $m WHERE conversation_id = %d ORDER BY id ASC", $id ) ); // phpcs:ignore
			$user = $conv->user_id ? get_userdata( $conv->user_id ) : false;
			printf(
				'<p><a href="%s">&larr; All conversations</a> &nbsp; Status: <strong>%s</strong> &nbsp; Visitor: %s &nbsp; Page: %s &nbsp; <a href="">Refresh</a></p>',
				esc_url( admin_url( 'admin.php?page=aisa-conversations' ) ),
				esc_html( $conv->status ),
				$user ? esc_html( $user->display_name ) : 'anonymous',
				$conv->page_url ? '<a href="' . esc_url( $conv->page_url ) . '" target="_blank" rel="noopener">' . esc_html( $conv->page_url ) . '</a>' : '–'
			);
			echo '<div class="aisa-thread">';
			foreach ( $rows as $r ) {
				printf(
					'<div class="aisa-b %1$s"><small>%2$s &middot; %3$s</small>%4$s</div>',
					esc_attr( $r->role ),
					esc_html( ucfirst( $r->role ) ),
					self::local( $r->created_at ), // phpcs:ignore
					esc_html( $r->content )
				);
			}
			echo '</div>';

			echo '<h2>Reply as a human</h2><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="max-width:760px">';
			wp_nonce_field( 'aisa_agent_reply' );
			echo '<input type="hidden" name="action" value="aisa_agent_reply"><input type="hidden" name="id" value="' . (int) $id . '">';
			echo '<textarea name="message" rows="4" class="large-text" required></textarea>';
			submit_button( 'Send reply', 'primary', 'submit', false );
			echo '</form><p>';
			foreach ( array( 'ai' => 'Return to AI', 'closed' => 'Close conversation', 'delete' => 'Delete conversation' ) as $op => $label ) {
				$url = wp_nonce_url( admin_url( 'admin-post.php?action=aisa_conv_action&op=' . $op . '&id=' . $id ), 'aisa_conv_action' );
				printf( '<a class="button" href="%s"%s>%s</a> ', esc_url( $url ), 'delete' === $op ? ' onclick="return confirm(\'Delete this conversation?\')"' : '', esc_html( $label ) );
			}
			echo '</p></div>';
			return;
		}

		$allowed = array( 'ai', 'waiting_human', 'human', 'closed' );
		$status  = isset( $_GET['status'] ) && in_array( $_GET['status'], $allowed, true ) ? $_GET['status'] : ''; // phpcs:ignore
		$where   = $status ? $wpdb->prepare( 'WHERE c.status = %s', $status ) : '';
		$rows    = $wpdb->get_results(
			"SELECT c.*,
				(SELECT content FROM $m WHERE conversation_id = c.id AND role = 'user' ORDER BY id DESC LIMIT 1) AS last_user,
				(SELECT COUNT(*) FROM $m WHERE conversation_id = c.id) AS msg_count
			FROM $c c $where ORDER BY (c.status = 'waiting_human') DESC, c.updated_at DESC LIMIT 50" // phpcs:ignore
		);

		echo '<p>';
		foreach ( array( '' => 'All', 'waiting_human' => 'Waiting for human', 'human' => 'With human', 'ai' => 'AI', 'closed' => 'Closed' ) as $k => $label ) {
			printf( '<a class="button%s" href="%s">%s</a> ', $status === $k ? ' button-primary' : '', esc_url( admin_url( 'admin.php?page=aisa-conversations' . ( $k ? '&status=' . $k : '' ) ) ), esc_html( $label ) );
		}
		echo '</p><table class="widefat striped"><thead><tr><th>#</th><th>Status</th><th>Last visitor message</th><th>Msgs</th><th>Updated</th></tr></thead><tbody>';
		if ( ! $rows ) {
			echo '<tr><td colspan="5">No conversations yet.</td></tr>';
		}
		foreach ( $rows as $r ) {
			printf(
				'<tr><td><a href="%s">%d</a></td><td>%s</td><td>%s</td><td>%d</td><td>%s</td></tr>',
				esc_url( admin_url( 'admin.php?page=aisa-conversations&id=' . $r->id ) ),
				(int) $r->id,
				'waiting_human' === $r->status ? '<strong style="color:#b32d2e">waiting_human</strong>' : esc_html( $r->status ),
				esc_html( mb_strimwidth( (string) $r->last_user, 0, 90, '…' ) ),
				(int) $r->msg_count,
				self::local( $r->updated_at ) // phpcs:ignore
			);
		}
		echo '</tbody></table></div>';
	}

	public static function h_agent_reply() {
		self::guard();
		check_admin_referer( 'aisa_agent_reply' );
		global $wpdb;
		$id  = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$msg = isset( $_POST['message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['message'] ) ) : '';
		if ( $id && '' !== $msg ) {
			AISA_Chat::add_message( $id, 'agent', $msg );
			$wpdb->update( AISA_DB::t( 'conversations' ), array( 'status' => 'human', 'handoff' => 1 ), array( 'id' => $id ) );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=aisa-conversations&id=' . $id . '&aisa_msg=replied' ) );
		exit;
	}

	public static function h_conv_action() {
		self::guard();
		check_admin_referer( 'aisa_conv_action' );
		global $wpdb;
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		$op = isset( $_GET['op'] ) ? sanitize_key( $_GET['op'] ) : '';
		if ( $id && in_array( $op, array( 'ai', 'closed' ), true ) ) {
			$wpdb->update( AISA_DB::t( 'conversations' ), array( 'status' => $op ), array( 'id' => $id ) );
		} elseif ( $id && 'delete' === $op ) {
			$wpdb->delete( AISA_DB::t( 'messages' ), array( 'conversation_id' => $id ) );
			$wpdb->delete( AISA_DB::t( 'conversations' ), array( 'id' => $id ) );
			wp_safe_redirect( admin_url( 'admin.php?page=aisa-conversations&aisa_msg=updated' ) );
			exit;
		}
		wp_safe_redirect( admin_url( 'admin.php?page=aisa-conversations&id=' . $id . '&aisa_msg=updated' ) );
		exit;
	}

	/* ====================== Leads ====================== */

	public static function page_leads() {
		self::guard();
		global $wpdb;
		$rows = $wpdb->get_results( 'SELECT * FROM ' . AISA_DB::t( 'leads' ) . ' ORDER BY id DESC LIMIT 200' ); // phpcs:ignore
		echo '<div class="wrap"><h1>Leads <a class="page-title-action" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=aisa_export_leads' ), 'aisa_export_leads' ) ) . '">Export CSV</a></h1>';
		echo '<table class="widefat striped"><thead><tr><th>Date</th><th>Name</th><th>Email</th><th>Phone</th><th>Interest</th><th>Chat</th></tr></thead><tbody>';
		if ( ! $rows ) {
			echo '<tr><td colspan="6">No leads yet.</td></tr>';
		}
		foreach ( $rows as $r ) {
			printf(
				'<tr><td>%s</td><td>%s</td><td><a href="mailto:%s">%s</a></td><td>%s</td><td>%s</td><td><a href="%s">#%d</a></td></tr>',
				self::local( $r->created_at ), // phpcs:ignore
				esc_html( $r->name ),
				esc_attr( $r->email ),
				esc_html( $r->email ),
				esc_html( $r->phone ),
				esc_html( $r->note ),
				esc_url( admin_url( 'admin.php?page=aisa-conversations&id=' . $r->conversation_id ) ),
				(int) $r->conversation_id
			);
		}
		echo '</tbody></table></div>';
	}

	public static function h_export_leads() {
		self::guard();
		check_admin_referer( 'aisa_export_leads' );
		global $wpdb;
		$rows = $wpdb->get_results( 'SELECT created_at, name, email, phone, note FROM ' . AISA_DB::t( 'leads' ) . ' ORDER BY id DESC', ARRAY_A ); // phpcs:ignore
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=leads-' . gmdate( 'Y-m-d' ) . '.csv' );
		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, array( 'date_utc', 'name', 'email', 'phone', 'note' ) );
		foreach ( $rows as $r ) {
			// Neutralize spreadsheet formula injection.
			foreach ( $r as $k => $v ) {
				if ( isset( $v[0] ) && in_array( $v[0], array( '=', '+', '-', '@' ), true ) ) {
					$r[ $k ] = "'" . $v;
				}
			}
			fputcsv( $out, $r );
		}
		fclose( $out ); // phpcs:ignore
		exit;
	}

	/* ====================== Knowledge ====================== */

	public static function page_knowledge() {
		self::guard();
		global $wpdb;
		$t      = AISA_DB::t( 'chunks' );
		$posts  = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT source_id) FROM $t WHERE source_type = 'post'" ); // phpcs:ignore
		$chunks = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $t" ); // phpcs:ignore
		$docs   = $wpdb->get_results( "SELECT source_id, source_title, COUNT(*) n FROM $t WHERE source_type = 'doc' GROUP BY source_id, source_title ORDER BY source_title" ); // phpcs:ignore
		$status = get_option( 'aisa_reindex_status', '' );

		echo '<div class="wrap"><h1>Knowledge base</h1>';
		self::notice();
		printf( '<p>%d site items and %d chunks indexed.%s</p>', $posts, $chunks, $status ? ' Last reindex: <strong>' . esc_html( $status ) . '</strong>.' : '' );
		echo '<p>Posts, pages and products (see Settings) are indexed automatically when saved. Reindex to pick up existing content:</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'aisa_reindex' );
		echo '<input type="hidden" name="action" value="aisa_reindex">';
		submit_button( 'Reindex site content', 'secondary', 'submit', false );
		echo '</form>';

		echo '<h2>Add a document</h2><form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'aisa_add_doc' );
		echo '<input type="hidden" name="action" value="aisa_add_doc">';
		echo '<table class="form-table"><tr><th>Upload file</th><td><input type="file" name="aisa_file" accept=".txt,.md,.csv,.pdf"> <span class="description">.txt, .md, .csv, .pdf (5 MB max)</span></td></tr>';
		echo '<tr><th>…or paste text</th><td><input type="text" name="aisa_title" class="regular-text" placeholder="Title (e.g. Return policy)"><br><textarea name="aisa_text" rows="6" class="large-text" style="max-width:600px"></textarea></td></tr></table>';
		submit_button( 'Add to knowledge base' );
		echo '</form>';

		echo '<h2>Documents</h2>';
		if ( $docs ) {
			echo '<table class="widefat striped" style="max-width:700px"><thead><tr><th>Title</th><th>Chunks</th><th></th></tr></thead><tbody>';
			foreach ( $docs as $d ) {
				$url = wp_nonce_url( admin_url( 'admin-post.php?action=aisa_delete_doc&id=' . (int) $d->source_id ), 'aisa_delete_doc' );
				printf( '<tr><td>%s</td><td>%d</td><td><a href="%s">Delete</a></td></tr>', esc_html( $d->source_title ), (int) $d->n, esc_url( $url ) );
			}
			echo '</tbody></table>';
		} else {
			echo '<p>No documents yet.</p>';
		}
		echo '</div>';
	}

	private static function back( $code ) {
		wp_safe_redirect( admin_url( 'admin.php?page=aisa-knowledge&aisa_msg=' . $code ) );
		exit;
	}

	public static function h_reindex() {
		self::guard();
		check_admin_referer( 'aisa_reindex' );
		AISA_KB::reindex_all();
		self::back( 'reindex' );
	}

	public static function h_add_doc() {
		self::guard();
		check_admin_referer( 'aisa_add_doc' );

		$title = isset( $_POST['aisa_title'] ) ? sanitize_text_field( wp_unslash( $_POST['aisa_title'] ) ) : '';
		$text  = isset( $_POST['aisa_text'] ) ? sanitize_textarea_field( wp_unslash( $_POST['aisa_text'] ) ) : '';

		if ( ! empty( $_FILES['aisa_file']['tmp_name'] ) && is_uploaded_file( $_FILES['aisa_file']['tmp_name'] ) ) { // phpcs:ignore
			$name = sanitize_file_name( wp_unslash( $_FILES['aisa_file']['name'] ) ); // phpcs:ignore
			$ext  = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
			if ( ! in_array( $ext, array( 'txt', 'md', 'csv', 'pdf' ), true ) ) {
				self::back( 'err_type' );
			}
			if ( (int) $_FILES['aisa_file']['size'] > 5 * MB_IN_BYTES ) { // phpcs:ignore
				self::back( 'err_size' );
			}
			$extracted = AISA_KB::extract_text( $_FILES['aisa_file']['tmp_name'], $ext ); // phpcs:ignore
			if ( is_wp_error( $extracted ) ) {
				self::back( 'pdf' === $ext ? 'err_pdf' : 'err_empty' );
			}
			$text  = (string) $extracted;
			$title = '' !== $title ? $title : $name;
		}

		$text = trim( $text );
		if ( '' === $text || '' === $title ) {
			self::back( 'err_empty' );
		}
		AISA_KB::add_document( $title, $text );
		self::back( 'doc_added' );
	}

	public static function h_delete_doc() {
		self::guard();
		check_admin_referer( 'aisa_delete_doc' );
		AISA_KB::delete_source( 'doc', isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0 );
		self::back( 'doc_gone' );
	}

	/* ====================== Settings ====================== */

	public static function page_settings() {
		self::guard();
		$s    = AISA_Settings::all();
		$name = AISA_Settings::OPTION;
		$row  = function ( $label, $html, $desc = '' ) {
			echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . $html . ( $desc ? '<p class="description">' . esc_html( $desc ) . '</p>' : '' ) . '</td></tr>'; // phpcs:ignore
		};
		$text = function ( $key, $type = 'text', $extra = '' ) use ( $s, $name ) {
			return sprintf( '<input type="%s" class="regular-text" name="%s[%s]" value="%s" %s>', esc_attr( $type ), esc_attr( $name ), esc_attr( $key ), esc_attr( $s[ $key ] ), $extra );
		};
		$check = function ( $key, $label ) use ( $s, $name ) {
			return sprintf( '<label><input type="checkbox" name="%s[%s]" value="1" %s> %s</label>', esc_attr( $name ), esc_attr( $key ), checked( ! empty( $s[ $key ] ), true, false ), esc_html( $label ) );
		};

		echo '<div class="wrap"><h1>AI Support: Settings</h1><form method="post" action="options.php">';
		settings_fields( 'aisa_group' );
		echo '<table class="form-table">';

		$row( 'Chat widget', $check( 'enabled', 'Show the chat widget on the site' ) );

		$prov  = '<select name="' . esc_attr( $name ) . '[provider]">';
		$prov .= '<option value="anthropic"' . selected( $s['provider'], 'anthropic', false ) . '>Anthropic (Claude)</option>';
		$prov .= '<option value="openai"' . selected( $s['provider'], 'openai', false ) . '>OpenAI-compatible</option></select>';
		$row( 'AI provider', $prov );

		$has_key = '' !== AISA_Settings::api_key();
		$row(
			'API key',
			'<input type="password" class="regular-text" autocomplete="new-password" name="' . esc_attr( $name ) . '[api_key]" value="" placeholder="' . esc_attr( $has_key ? 'Saved. Enter a new key to replace it' : '' ) . '">',
			'Better: define AISA_API_KEY in wp-config.php so the key is not stored in the database.'
		);
		$row( 'Model', $text( 'model' ), 'e.g. claude-sonnet-5-5, or gpt-4o-mini for OpenAI.' );
		$row( 'OpenAI base URL', $text( 'openai_base', 'url' ), 'Only used for the OpenAI-compatible provider (also works with other compatible endpoints).' );
		$row( 'Business name', $text( 'business_name' ) );
		$row( 'Extra instructions', '<textarea class="large-text" rows="5" name="' . esc_attr( $name ) . '[system_prompt]">' . esc_textarea( $s['system_prompt'] ) . '</textarea>', 'Tone, escalation rules, things to avoid. Appended to the built-in system prompt.' );

		$pts = '';
		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $pt ) {
			if ( 'attachment' === $pt->name ) {
				continue;
			}
			$pts .= sprintf( '<label style="margin-right:14px"><input type="checkbox" name="%s[post_types][]" value="%s" %s> %s</label>', esc_attr( $name ), esc_attr( $pt->name ), checked( in_array( $pt->name, (array) $s['post_types'], true ), true, false ), esc_html( $pt->labels->name ) );
		}
		$row( 'Index these content types', $pts, 'Save, then use Knowledge → Reindex to index existing content.' );

		if ( class_exists( 'WooCommerce' ) ) {
			$row( 'WooCommerce', $check( 'woocommerce', 'Let the assistant search products and look up orders (needs order number + billing email)' ) );
		}
		$row( 'Lead collection', $check( 'collect_leads', 'Let the assistant save contact details when visitors agree' ) );
		$row( 'Handoff email', $text( 'handoff_email', 'email' ), 'Notified when a visitor needs a human.' );

		$row( 'Widget title', $text( 'title' ) );
		$row( 'Greeting', $text( 'greeting' ) );
		$row( 'Privacy notice', $text( 'consent_text' ), 'Shown under the messages. Update your privacy policy to mention stored chats and the AI provider.' );
		$row( 'Color', $text( 'color', 'text', 'placeholder="#2563eb"' ) );
		$pos  = '<select name="' . esc_attr( $name ) . '[position]"><option value="right"' . selected( $s['position'], 'right', false ) . '>Bottom right</option>';
		$pos .= '<option value="left"' . selected( $s['position'], 'left', false ) . '>Bottom left</option></select>';
		$row( 'Position', $pos );

		$row( 'Rate limit', '<input type="number" min="1" max="120" name="' . esc_attr( $name ) . '[rate_limit]" value="' . (int) $s['rate_limit'] . '"> messages / minute / IP' );
		$row( 'Retention', '<input type="number" min="0" max="3650" name="' . esc_attr( $name ) . '[retention_days]" value="' . (int) $s['retention_days'] . '"> days', 'Conversations older than this are deleted daily. 0 keeps everything. Leads are kept.' );

		echo '</table>';
		submit_button();
		echo '</form></div>';
	}
}
