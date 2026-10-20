<?php
defined( 'ABSPATH' ) || exit;

/**
 * Knowledge base: chunks site content + uploaded documents into a table and
 * retrieves with MySQL FULLTEXT (swap search() for a vector store if you outgrow it).
 */
class AISA_KB {

	const BATCH = 20;

	public static function init() {
		add_action( 'save_post', array( __CLASS__, 'on_save' ), 20, 2 );
		add_action( 'before_delete_post', array( __CLASS__, 'on_delete' ) );
		add_action( 'aisa_reindex_batch', array( __CLASS__, 'reindex_batch' ) );
	}

	/* ---------- indexing ---------- */

	public static function on_save( $post_id, $post ) {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		$types = (array) AISA_Settings::get( 'post_types' );
		if ( ! in_array( $post->post_type, $types, true ) ) {
			return;
		}
		if ( 'publish' !== $post->post_status || ! empty( $post->post_password ) ) {
			self::delete_source( 'post', $post_id );
			return;
		}
		self::index_post( $post );
	}

	public static function on_delete( $post_id ) {
		self::delete_source( 'post', $post_id );
	}

	public static function post_text( $post ) {
		$text = $post->post_title . "\n" . wp_strip_all_tags( strip_shortcodes( $post->post_content ) );
		if ( 'product' === $post->post_type && function_exists( 'wc_get_product' ) ) {
			$p = wc_get_product( $post->ID );
			if ( $p ) {
				$text .= "\n" . wp_strip_all_tags( $p->get_short_description() );
				if ( '' !== $p->get_price() ) {
					$text .= "\nPrice: " . $p->get_price() . ' ' . get_woocommerce_currency();
				}
				$text .= "\nAvailability: " . $p->get_stock_status();
				if ( $p->get_sku() ) {
					$text .= "\nSKU: " . $p->get_sku();
				}
			}
		}
		return trim( $text );
	}

	public static function index_post( $post ) {
		self::store( 'post', $post->ID, get_the_title( $post ), get_permalink( $post ), self::post_text( $post ) );
	}

	public static function chunk( $text, $max = 1200 ) {
		$text   = trim( preg_replace( '/[ \t]+/', ' ', (string) $text ) );
		$paras  = preg_split( '/\n+/', $text );
		$chunks = array();
		$cur    = '';
		foreach ( $paras as $p ) {
			$p = trim( $p );
			if ( '' === $p ) {
				continue;
			}
			while ( mb_strlen( $p ) > $max ) {
				if ( '' !== $cur ) {
					$chunks[] = $cur;
					$cur      = '';
				}
				$chunks[] = mb_substr( $p, 0, $max );
				$p        = mb_substr( $p, $max );
			}
			if ( mb_strlen( $cur ) + mb_strlen( $p ) + 1 > $max ) {
				if ( '' !== $cur ) {
					$chunks[] = $cur;
				}
				$cur = $p;
			} else {
				$cur = ( '' === $cur ? '' : $cur . "\n" ) . $p;
			}
		}
		if ( '' !== $cur ) {
			$chunks[] = $cur;
		}
		return $chunks;
	}

	public static function store( $type, $id, $title, $url, $text ) {
		global $wpdb;
		self::delete_source( $type, $id );
		$i = 0;
		foreach ( array_slice( self::chunk( $text ), 0, 200 ) as $chunk ) {
			$wpdb->insert(
				AISA_DB::t( 'chunks' ),
				array(
					'source_type'  => $type,
					'source_id'    => (int) $id,
					'source_title' => mb_substr( $title, 0, 255 ),
					'source_url'   => mb_substr( (string) $url, 0, 500 ),
					'chunk_index'  => $i++,
					'content'      => $chunk,
				)
			);
		}
		return $i;
	}

	public static function delete_source( $type, $id ) {
		global $wpdb;
		$wpdb->delete( AISA_DB::t( 'chunks' ), array( 'source_type' => $type, 'source_id' => (int) $id ) );
	}

	/* ---------- bulk reindex (background, in batches) ---------- */

	public static function reindex_all() {
		global $wpdb;
		$wpdb->delete( AISA_DB::t( 'chunks' ), array( 'source_type' => 'post' ) );
		update_option( 'aisa_reindex_status', 'running' );
		wp_schedule_single_event( time(), 'aisa_reindex_batch', array( 0 ) );
		spawn_cron();
	}

	public static function reindex_batch( $offset = 0 ) {
		$offset = (int) $offset;
		$types  = (array) AISA_Settings::get( 'post_types' );
		if ( ! $types ) {
			update_option( 'aisa_reindex_status', 'done' );
			return;
		}
		$posts = get_posts(
			array(
				'post_type'        => $types,
				'post_status'      => 'publish',
				'posts_per_page'   => self::BATCH,
				'offset'           => $offset,
				'orderby'          => 'ID',
				'order'            => 'ASC',
				'has_password'     => false,
				'suppress_filters' => true,
			)
		);
		foreach ( $posts as $post ) {
			self::index_post( $post );
		}
		if ( count( $posts ) < self::BATCH ) {
			update_option( 'aisa_reindex_status', 'done' );
			return;
		}
		wp_schedule_single_event( time() + 2, 'aisa_reindex_batch', array( $offset + self::BATCH ) );
	}

	/* ---------- documents ---------- */

	public static function add_document( $title, $text ) {
		return self::store( 'doc', abs( crc32( $title ) ), $title, '', $text );
	}

	/** @return string|WP_Error */
	public static function extract_text( $path, $ext ) {
		if ( in_array( $ext, array( 'txt', 'md', 'csv' ), true ) ) {
			$raw = file_get_contents( $path ); // phpcs:ignore
			return false === $raw ? new WP_Error( 'read', 'Could not read file.' ) : wp_check_invalid_utf8( $raw );
		}
		if ( 'pdf' === $ext ) {
			if ( ! class_exists( '\Smalot\PdfParser\Parser' ) ) {
				$autoload = AISA_DIR . 'vendor/autoload.php';
				if ( file_exists( $autoload ) ) {
					require_once $autoload;
				}
			}
			if ( ! class_exists( '\Smalot\PdfParser\Parser' ) ) {
				return new WP_Error( 'pdf', 'PDF support needs smalot/pdfparser (run "composer require smalot/pdfparser" in the plugin folder).' );
			}
			try {
				$parser = new \Smalot\PdfParser\Parser();
				return $parser->parseFile( $path )->getText();
			} catch ( \Exception $e ) {
				return new WP_Error( 'pdf', 'Could not read this PDF.' );
			}
		}
		return new WP_Error( 'type', 'Unsupported file type.' );
	}

	/* ---------- retrieval ---------- */

	public static function search( $query, $limit = 5 ) {
		global $wpdb;
		$t = AISA_DB::t( 'chunks' );
		$q = trim( wp_strip_all_tags( (string) $query ) );
		if ( '' === $q ) {
			return array();
		}
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT source_type, source_id, source_title, source_url, content,
					MATCH(source_title, content) AGAINST (%s IN NATURAL LANGUAGE MODE) AS score
				FROM $t
				WHERE MATCH(source_title, content) AGAINST (%s IN NATURAL LANGUAGE MODE)
				ORDER BY score DESC LIMIT %d", // phpcs:ignore
				$q,
				$q,
				$limit
			),
			ARRAY_A
		);

		if ( ! $rows ) { // Fallback for very short words / stopwords.
			$words = array_slice( array_filter( preg_split( '/\W+/u', mb_strtolower( $q ) ), function ( $w ) {
				return mb_strlen( $w ) >= 3;
			} ), 0, 5 );
			if ( $words ) {
				$where = array();
				$args  = array();
				foreach ( $words as $w ) {
					$where[] = '(content LIKE %s OR source_title LIKE %s)';
					$like    = '%' . $wpdb->esc_like( $w ) . '%';
					$args[]  = $like;
					$args[]  = $like;
				}
				$args[] = $limit;
				$rows   = $wpdb->get_results(
					$wpdb->prepare( "SELECT source_type, source_id, source_title, source_url, content FROM $t WHERE " . implode( ' OR ', $where ) . ' LIMIT %d', $args ), // phpcs:ignore
					ARRAY_A
				);
			}
		}
		return $rows ? $rows : array();
	}
}
