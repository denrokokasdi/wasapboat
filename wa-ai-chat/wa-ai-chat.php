<?php
/**
 * Plugin Name: WA AI Chat
 * Description: Chatbot AI WhatsApp + portal chat dalam WordPress. Sambung terus ke WhatsApp Cloud API, jawab guna AI (ServerasAI / API serasi OpenAI), label pelanggan & semak status bayaran WooCommerce.
 * Version: 1.4.0
 * Update URI: https://github.com/denrokokasdi/wasapboat
 * Requires PHP: 7.4
 * License: GPLv2 or later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WAAI {

	const OPT     = 'waai_settings';
	const LOG     = 'waai_log';
	const DBV     = '4';
	const GRAPH   = 'https://graph.facebook.com/v23.0';
	const SECRETS = array( 'wa_token', 'app_secret', 'ai_key' );
	const REPO    = 'denrokokasdi/wasapboat';

	const STATUSES = array(
		'belum-beli' => 'Belum beli',
		'unpaid'     => 'Belum bayar',
		'partial'    => 'Bayar separuh',
		'paid'       => 'Dah bayar',
	);
	const LABELS = array( 'lead-baru', 'minta-quotation', 'nak-order', 'komplen', 'site-visit' );

	private static $finished = false;

	/** Token usage of the last AI call: [input, output]. */
	private static $last_usage = array( 0, 0 );

	public static function init() {
		register_activation_hook( __FILE__, array( __CLASS__, 'install' ) );
		add_action( 'plugins_loaded', array( __CLASS__, 'maybe_install' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_post_waai_test', array( __CLASS__, 'handle_test' ) );
		add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'check_update' ) );
		add_filter( 'plugins_api', array( __CLASS__, 'plugin_info' ), 20, 3 );
		foreach ( array( 'list', 'thread', 'send', 'update' ) as $a ) {
			add_action( "wp_ajax_waai_{$a}", array( __CLASS__, "ajax_{$a}" ) );
		}
	}

	/* ================================================================== */
	/* Database                                                            */
	/* ================================================================== */

	private static function t( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'waai_' . $name;
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$c = $wpdb->get_charset_collate();

		dbDelta(
			'CREATE TABLE ' . self::t( 'contacts' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			phone varchar(32) NOT NULL,
			name varchar(191) NOT NULL DEFAULT '',
			status varchar(20) NOT NULL DEFAULT 'belum-beli',
			status_manual tinyint(1) NOT NULL DEFAULT 0,
			labels varchar(255) NOT NULL DEFAULT '',
			notes text NULL,
			memory text NULL,
			bot_on tinyint(1) NOT NULL DEFAULT 1,
			needs_staff tinyint(1) NOT NULL DEFAULT 0,
			unread int(11) NOT NULL DEFAULT 0,
			last_msg varchar(255) NOT NULL DEFAULT '',
			last_msg_at datetime NULL,
			last_in_at datetime NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY phone (phone),
			KEY last_msg_at (last_msg_at)
			) $c;"
		);

		dbDelta(
			'CREATE TABLE ' . self::t( 'messages' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			contact_id bigint(20) unsigned NOT NULL,
			direction varchar(4) NOT NULL,
			author varchar(10) NOT NULL,
			body text NOT NULL,
			wa_id varchar(128) NOT NULL DEFAULT '',
			tokens_in int(11) NOT NULL DEFAULT 0,
			tokens_out int(11) NOT NULL DEFAULT 0,
			media_url varchar(255) NOT NULL DEFAULT '',
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY contact_id (contact_id,id),
			KEY wa_id (wa_id)
			) $c;"
		);

		update_option( 'waai_dbv', self::DBV );
	}

	public static function maybe_install() {
		if ( get_option( 'waai_dbv' ) !== self::DBV ) {
			self::install();
		}
	}

	/* ================================================================== */
	/* Settings                                                            */
	/* ================================================================== */

	public static function defaults() {
		return array(
			'enabled'         => 0,
			'phone_number_id' => '',
			'wa_token'        => '',
			'app_secret'      => '',
			'verify_token'    => '',
			'ai_base_url'     => 'https://serveras.click/v1',
			'ai_key'          => '',
			'ai_model'        => 'asai/claude-haiku-5.5',
			'history_limit'   => 20,
			'human_style'     => 1,
			'handoff_message' => 'Baik, saya sambungkan anda dengan staff kami. Sila tunggu sebentar 🙏',
			'media_message'   => 'Maaf, buat masa ini saya hanya boleh baca mesej teks. Boleh taip soalan anda? 😊',
			'images'          => self::default_images(),
			'system_prompt'   => self::default_prompt(),
		);
	}

	public static function default_images() {
		$u = 'https://solarexpo.my/wp-content/uploads/2026/11/';
		return implode(
			"\n",
			array(
				"pakej-solar | {$u}pakej199-lampu.webp | Pakej Solar RM199: pam + timer + panel solar + 9 free gift, postage & COD percuma",
				"pakej-pam | {$u}pakej170-640.jpg | Pakej Pam Sahaja RM170 (RM150 + postage RM10 + COD RM10)",
				"free-gift | {$u}free-gift-9.webp | 9 free gift untuk setiap Pakej Solar",
				"cara-pasang | {$u}cara-pemasangan-1254.jpg | Cara pasang: mudah, siap dalam beberapa minit",
			)
		);
	}

	/** @return array code => [url, caption] */
	public static function images() {
		$out = array();
		foreach ( preg_split( '/\r?\n/', (string) self::opt( 'images' ) ) as $line ) {
			$parts = array_map( 'trim', explode( '|', $line, 3 ) );
			if ( count( $parts ) >= 2 && '' !== $parts[0] && 0 === strpos( $parts[1], 'http' ) ) {
				$out[ sanitize_key( $parts[0] ) ] = array( $parts[1], $parts[2] ?? '' );
			}
		}
		return $out;
	}

	/** WhatsApp only accepts JPG/PNG: make a JPG copy of WebP/GIF files from this site's uploads. */
	public static function wa_image_url( $url ) {
		if ( ! preg_match( '/\.(webp|gif|avif)$/i', $url ) ) {
			return $url;
		}
		$up = wp_get_upload_dir();
		if ( 0 !== strpos( $url, $up['baseurl'] ) ) {
			return $url; // external file: send as-is
		}
		$rel  = substr( $url, strlen( $up['baseurl'] ) );
		$src  = $up['basedir'] . $rel;
		$jrel = preg_replace( '/\.(webp|gif|avif)$/i', '-wa.jpg', $rel );
		$dst  = $up['basedir'] . $jrel;
		if ( ! file_exists( $dst ) ) {
			if ( ! file_exists( $src ) ) {
				return $url;
			}
			$ed = wp_get_image_editor( $src );
			if ( is_wp_error( $ed ) || is_wp_error( $ed->save( $dst, 'image/jpeg' ) ) ) {
				self::log( "Gambar: gagal tukar ke JPG ({$rel}). Upload versi JPG/PNG." );
				return $url;
			}
		}
		return $up['baseurl'] . $jrel;
	}

	public static function default_prompt() {
		return <<<TXT
Anda ialah pembantu jualan WhatsApp untuk Solar Expo Malaysia (solarexpo.my).
Jawab dalam bahasa yang pelanggan guna (Bahasa Melayu atau English). Mesra, ringkas, sesuai untuk WhatsApp (2-5 ayat, emoji sedikit).

MAKLUMAT SYARIKAT (tukar kepada maklumat sebenar):
- Pakej 5kW: RM xx,xxx
- Pakej 10kW: RM xx,xxx
- Kawasan servis: ...
- Waktu operasi: Isnin-Jumaat 9pg-6ptg
- Cara bayar: ...

CARA BERBUAL:
- Untuk anggar saiz sistem, tanya lokasi (negeri/bandar) dan purata bil elektrik bulanan.
- Jika pelanggan berminat, ajak tempah site visit percuma dan minta nama + alamat.

PERATURAN:
- JANGAN reka harga, promosi atau maklumat yang tiada di atas.
- Jika pelanggan minta bercakap dengan manusia, marah/komplen, tanya status bayaran/pemasangan yang spesifik, atau soalan di luar maklumat di atas: tulis [HANDOFF] sahaja.
- Tag perbualan dengan menulis tag di hujung jawapan (pelanggan tidak nampak):
  [LABEL:lead-baru] pelanggan baru bertanya
  [LABEL:minta-quotation] pelanggan minta sebut harga
  [LABEL:site-visit] pelanggan setuju site visit
  [LABEL:komplen] pelanggan ada aduan
TXT;
	}

	public static function opts() {
		$o = wp_parse_args( (array) get_option( self::OPT, array() ), self::defaults() );
		// Kept in its own option: saving it must not go through sanitize() (which calls opts()).
		$vt = (string) get_option( 'waai_verify_token', '' );
		if ( '' === $vt ) {
			$vt = wp_generate_password( 24, false );
			add_option( 'waai_verify_token', $vt, '', false );
		}
		$o['verify_token'] = $vt;
		return $o;
	}

	public static function opt( $k ) {
		$o = self::opts();
		return isset( $o[ $k ] ) ? $o[ $k ] : '';
	}

	public static function register_settings() {
		register_setting( 'waai', self::OPT, array( 'sanitize_callback' => array( __CLASS__, 'sanitize' ) ) );
	}

	public static function sanitize( $in ) {
		$out = self::opts();
		$in  = is_array( $in ) ? $in : array();

		$out['enabled']         = empty( $in['enabled'] ) ? 0 : 1;
		$out['phone_number_id'] = preg_replace( '/\D/', '', (string) ( $in['phone_number_id'] ?? '' ) );
		$out['ai_base_url']     = untrailingslashit( esc_url_raw( trim( (string) ( $in['ai_base_url'] ?? '' ) ) ) );
		$out['ai_model']        = sanitize_text_field( $in['ai_model'] ?? '' );
		$out['history_limit']   = max( 2, min( 50, (int) ( $in['history_limit'] ?? 20 ) ) );
		$out['human_style']     = empty( $in['human_style'] ) ? 0 : 1;
		$out['handoff_message'] = sanitize_textarea_field( $in['handoff_message'] ?? '' );
		$out['media_message']   = sanitize_textarea_field( $in['media_message'] ?? '' );
		$out['images']          = sanitize_textarea_field( $in['images'] ?? '' );
		$out['system_prompt']   = sanitize_textarea_field( $in['system_prompt'] ?? '' );

		foreach ( self::SECRETS as $f ) {
			$v = trim( (string) ( $in[ $f ] ?? '' ) );
			if ( '' !== $v ) {
				$out[ $f ] = sanitize_text_field( $v );
			}
		}
		return $out;
	}

	public static function log( $msg ) {
		$log = (array) get_option( self::LOG, array() );
		array_unshift( $log, wp_date( 'Y-m-d H:i:s' ) . '  ' . $msg );
		update_option( self::LOG, array_slice( $log, 0, 100 ), false );
	}

	private static function cap() {
		return 'edit_others_posts'; // Admin & Editor can use the chat portal.
	}

	/* ================================================================== */
	/* WhatsApp webhook                                                    */
	/* ================================================================== */

	public static function webhook_url() {
		return rest_url( 'waai/v1/webhook' );
	}

	public static function routes() {
		register_rest_route(
			'waai/v1',
			'/webhook',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'verify' ),
					'permission_callback' => '__return_true',
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'receive' ),
					'permission_callback' => '__return_true',
				),
			)
		);
	}

	/** Meta's one-time webhook verification. */
	public static function verify( WP_REST_Request $req ) {
		if ( 'subscribe' === $req->get_param( 'hub_mode' )
			&& hash_equals( (string) self::opt( 'verify_token' ), (string) $req->get_param( 'hub_verify_token' ) ) ) {
			header( 'Content-Type: text/plain' );
			echo preg_replace( '/[^0-9A-Za-z_\-]/', '', (string) $req->get_param( 'hub_challenge' ) ); // phpcs:ignore
			exit;
		}
		return new WP_REST_Response( 'forbidden', 403 );
	}

	public static function receive( WP_REST_Request $req ) {
		$ok  = new WP_REST_Response( array( 'ok' => true ), 200 );
		$raw = $req->get_body();

		$secret = self::opt( 'app_secret' );
		if ( $secret ) {
			$sig = (string) $req->get_header( 'x-hub-signature-256' );
			if ( ! hash_equals( 'sha256=' . hash_hmac( 'sha256', $raw, $secret ), $sig ) ) {
				self::log( 'Webhook: tandatangan tidak sah (semak App Secret)' );
				return new WP_REST_Response( array( 'error' => 'bad signature' ), 401 );
			}
		}

		$data     = json_decode( $raw, true );
		$incoming = array();
		foreach ( (array) ( $data['entry'] ?? array() ) as $entry ) {
			foreach ( (array) ( $entry['changes'] ?? array() ) as $ch ) {
				$v     = (array) ( $ch['value'] ?? array() );
				$names = array();
				foreach ( (array) ( $v['contacts'] ?? array() ) as $c ) {
					$names[ (string) ( $c['wa_id'] ?? '' ) ] = (string) ( $c['profile']['name'] ?? '' );
				}
				foreach ( (array) ( $v['messages'] ?? array() ) as $m ) {
					$m['_name'] = $names[ (string) ( $m['from'] ?? '' ) ] ?? '';
					$incoming[] = $m;
				}
			}
		}

		$todo = array();
		foreach ( $incoming as $m ) {
			$r = self::store_incoming( $m );
			if ( $r ) {
				$todo[] = $r;
			}
		}
		if ( ! $todo || ! self::opt( 'enabled' ) ) {
			return $ok;
		}

		self::finish_early();
		foreach ( $todo as $r ) {
			self::maybe_reply( $r[0], $r[1], $r[2] );
		}
		if ( self::$finished ) {
			exit;
		}
		return $ok;
	}

	/** Answer Meta now and keep working in the background (AI can be slow). */
	private static function finish_early() {
		ignore_user_abort( true );
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 180 ); // phpcs:ignore
		}
		if ( function_exists( 'fastcgi_finish_request' ) || function_exists( 'litespeed_finish_request' ) ) {
			status_header( 200 );
			header( 'Content-Type: application/json; charset=utf-8' );
			echo '{"ok":true}';
			if ( function_exists( 'fastcgi_finish_request' ) ) {
				fastcgi_finish_request();
			} else {
				litespeed_finish_request();
			}
			self::$finished = true;
		}
	}

	/** @return array|null [contact_id, message_id, is_text] */
	private static function store_incoming( array $m ) {
		global $wpdb;
		$tc = self::t( 'contacts' );
		$tm = self::t( 'messages' );

		$wa_id = (string) ( $m['id'] ?? '' );
		$from  = preg_replace( '/\D/', '', (string) ( $m['from'] ?? '' ) );
		if ( '' === $wa_id || '' === $from ) {
			return null;
		}
		if ( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$tm} WHERE wa_id = %s", $wa_id ) ) ) {
			return null; // duplicate delivery
		}

		$type    = (string) ( $m['type'] ?? '' );
		$is_text = true;
		switch ( $type ) {
			case 'text':
				$body = (string) ( $m['text']['body'] ?? '' );
				break;
			case 'button':
				$body = (string) ( $m['button']['text'] ?? '' );
				break;
			case 'interactive':
				$body = (string) ( $m['interactive']['button_reply']['title'] ?? $m['interactive']['list_reply']['title'] ?? '' );
				break;
			default:
				$is_text = false;
				$map     = array(
					'image'    => '📷 [gambar]',
					'audio'    => '🎤 [voice note]',
					'video'    => '🎬 [video]',
					'document' => '📄 [dokumen]',
					'sticker'  => '[sticker]',
					'location' => '📍 [lokasi]',
				);
				$body    = $map[ $type ] ?? "[{$type}]";
				$caption = (string) ( $m[ $type ]['caption'] ?? '' );
				if ( '' !== $caption ) {
					$body .= ' ' . $caption;
				}
		}
		if ( '' === trim( $body ) ) {
			$body    = '[mesej kosong]';
			$is_text = false;
		}

		$now  = current_time( 'mysql', true );
		$name = sanitize_text_field( (string) ( $m['_name'] ?? '' ) );

		$cid = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$tc} WHERE phone = %s", $from ) );
		if ( ! $cid ) {
			$wpdb->insert( $tc, array( 'phone' => $from, 'name' => $name, 'created_at' => $now ) );
			$cid = (int) $wpdb->insert_id;
			if ( ! $cid ) { // inserted by a parallel request
				$cid = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$tc} WHERE phone = %s", $from ) );
			}
		}
		if ( ! $cid ) {
			return null;
		}

		$wpdb->insert(
			$tm,
			array(
				'contact_id' => $cid,
				'direction'  => 'in',
				'author'     => 'customer',
				'body'       => $body,
				'wa_id'      => $wa_id,
				'created_at' => $now,
			)
		);
		$mid = (int) $wpdb->insert_id;

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$tc} SET unread = unread + 1, last_msg = %s, last_msg_at = %s, last_in_at = %s, name = IF(%s <> '', %s, name) WHERE id = %d",
				mb_substr( $body, 0, 250 ),
				$now,
				$now,
				$name,
				$name,
				$cid
			)
		);

		self::refresh_status( $cid );
		return array( $cid, $mid, $is_text );
	}

	private static function contact( $cid ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'contacts' ) . ' WHERE id = %d', $cid ) );
	}

	private static function update_contact( $cid, array $fields ) {
		global $wpdb;
		$wpdb->update( self::t( 'contacts' ), $fields, array( 'id' => (int) $cid ) );
	}

	/* ================================================================== */
	/* Bot                                                                 */
	/* ================================================================== */

	private static function maybe_reply( $cid, $mid, $is_text ) {
		global $wpdb;
		try {
			// Customers often send several short messages: wait, then answer once.
			sleep( 4 );
			$latest = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT MAX(id) FROM ' . self::t( 'messages' ) . " WHERE contact_id = %d AND direction = 'in'", $cid ) );
			if ( $latest !== (int) $mid ) {
				return;
			}

			$c = self::contact( $cid );
			if ( ! $c || ! (int) $c->bot_on ) {
				return;
			}

			if ( self::opt( 'human_style' ) ) {
				// Blue ticks + "typing..." while the AI thinks.
				self::typing( (string) $wpdb->get_var( $wpdb->prepare( 'SELECT wa_id FROM ' . self::t( 'messages' ) . ' WHERE id = %d', $mid ) ) );
			}

			if ( ! $is_text ) {
				self::send_and_store( $c, self::opt( 'media_message' ), 'bot' );
				return;
			}

			$status_label = self::STATUSES[ $c->status ] ?? $c->status;
			$system       = self::opt( 'system_prompt' ) . sprintf(
				"\n\nMAKLUMAT PELANGGAN INI:\n- Nama: %s\n- Status bayaran (dari sistem): %s",
				$c->name ? $c->name : '-',
				$status_label
			);
			$memory = trim( (string) $c->memory );
			$system .= "\n\nMEMORI TENTANG PELANGGAN INI (dari perbualan lepas, guna untuk faham konteks, jangan tanya semula benda yang dah tahu):\n" . ( '' !== $memory ? $memory : '(tiada lagi)' );
			if ( '' !== trim( (string) $c->notes ) ) {
				$system .= "\n\nNOTA STAFF TENTANG PELANGGAN INI:\n" . trim( (string) $c->notes );
			}
			$orders = self::orders_context( $c->phone );
			if ( '' !== $orders ) {
				$system .= "\n\nORDER PELANGGAN INI (dari sistem kedai, terbaru dahulu):\n{$orders}\nJika pelanggan tanya pasal order, boleh beritahu status di atas secara ringkas. Untuk nombor tracking, tarikh sampai tepat atau masalah order, tulis [HANDOFF].";
			}
			$system .= "\n\nINGAT FAKTA: Bila pelanggan beritahu maklumat yang berguna untuk perbualan akan datang (nama, lokasi, produk diminati, kuantiti, bajet, tujuan guna, alamat, cara bayar pilihan), tulis tag [INGAT:kunci=nilai] di hujung jawapan, satu tag setiap fakta. Contoh: [INGAT:lokasi=Ipoh] [INGAT:minat=Pakej Solar x2]. Guna kunci yang sama untuk kemas kini fakta lama. Pelanggan tidak nampak tag ini.";
			if ( self::opt( 'human_style' ) ) {
				$system .= "\n\nGAYA MESEJ (WAJIB): Tulis macam staff kedai sebenar balas WhatsApp, bukan macam AI. Pecahkan jawapan kepada 1-3 mesej pendek, setiap mesej dipisahkan dengan SATU BARIS KOSONG. Setiap mesej 1-2 ayat sahaja. Jangan guna tanda — atau –, jangan guna senarai berbutir kecuali senarai harga/free gift.";
			}
			$imgs = self::images();
			if ( $imgs ) {
				$system .= "\n\nGAMBAR YANG BOLEH DIHANTAR (tulis tag di hujung jawapan, maksimum 2 gambar; pelanggan nampak gambar, bukan tag):";
				foreach ( $imgs as $code => $im ) {
					$system .= "\n[IMAGE:{$code}] {$im[1]}";
				}
				$system .= "\nBila pelanggan tanya harga atau pakej, sertakan gambar pakej yang berkaitan. Jangan hantar gambar yang sama berulang kali dalam perbualan yang sama.";
			}

			self::$last_usage = array( 0, 0 );
			$reply = self::ask_ai( self::history( $cid ), $system );
			$usage = self::$last_usage;
			if ( null === $reply ) {
				$reply = '[HANDOFF]';
			}

			if ( preg_match_all( '/\[LABEL:([a-z0-9_\-]+)\]/i', $reply, $m ) ) {
				$labels = array_filter( explode( ',', (string) $c->labels ) );
				foreach ( $m[1] as $l ) {
					$labels[] = strtolower( $l );
				}
				self::update_contact( $cid, array( 'labels' => implode( ',', array_unique( $labels ) ) ) );
				$reply = trim( preg_replace( '/\[LABEL:[^\]]*\]/i', '', $reply ) );
			}

			if ( preg_match_all( '/\[INGAT:([^\]=]{1,40})=([^\]]{1,200})\]/iu', $reply, $m, PREG_SET_ORDER ) ) {
				self::remember( $c, $m );
			}
			$reply = trim( preg_replace( '/\[INGAT:[^\]]*\]/iu', '', $reply ) );

			$want = array();
			if ( preg_match_all( '/\[IMAGE:([a-z0-9_\-]+)\]/i', $reply, $m ) ) {
				$want  = array_slice( array_unique( array_map( 'strtolower', $m[1] ) ), 0, 2 );
				$reply = trim( preg_replace( '/\[IMAGE:[^\]]*\]/i', '', $reply ) );
			}

			if ( false !== stripos( $reply, '[HANDOFF]' ) || ( '' === $reply && ! $want ) ) {
				self::send_and_store( $c, self::opt( 'handoff_message' ), 'bot', $usage );
				self::update_contact( $cid, array( 'bot_on' => 0, 'needs_staff' => 1 ) );
				self::log( "Pelanggan {$c->phone}: diserahkan kepada staff" );
			} else {
				if ( '' !== $reply ) {
					self::send_reply( $c, $reply, $usage );
				}
				self::send_images( $c, $want );
			}
		} catch ( \Throwable $e ) {
			self::log( 'RALAT: ' . $e->getMessage() );
		}
	}

	/** Merge [INGAT:key=value] facts into the customer's long-term memory ("key: value" lines). */
	private static function remember( $c, array $matches ) {
		$mem = array();
		foreach ( preg_split( '/\r?\n/', (string) $c->memory ) as $line ) {
			$kv = array_map( 'trim', explode( ':', $line, 2 ) );
			if ( 2 === count( $kv ) && '' !== $kv[0] ) {
				$mem[ mb_strtolower( $kv[0] ) ] = $kv[1];
			}
		}
		foreach ( $matches as $m ) {
			$k = mb_strtolower( trim( sanitize_text_field( $m[1] ) ) );
			$v = trim( sanitize_text_field( $m[2] ) );
			if ( '' !== $k && '' !== $v ) {
				unset( $mem[ $k ] );
				$mem[ $k ] = $v; // newest last
			}
		}
		$mem   = array_slice( $mem, -30, null, true );
		$lines = array();
		foreach ( $mem as $k => $v ) {
			$lines[] = "{$k}: {$v}";
		}
		$c->memory = implode( "\n", $lines );
		self::update_contact( $c->id, array( 'memory' => $c->memory ) );
	}

	private static function history( $cid ) {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT direction, body FROM ' . self::t( 'messages' ) . ' WHERE contact_id = %d ORDER BY id DESC LIMIT %d',
				$cid,
				(int) self::opt( 'history_limit' ) * 2
			)
		);
		$turns = array();
		foreach ( array_reverse( (array) $rows ) as $r ) {
			$role = 'in' === $r->direction ? 'user' : 'assistant';
			$last = count( $turns ) - 1;
			if ( $last >= 0 && $turns[ $last ]['role'] === $role ) {
				$turns[ $last ]['content'] .= "\n" . $r->body;
			} else {
				$turns[] = array( 'role' => $role, 'content' => $r->body );
			}
		}
		$turns = array_slice( $turns, -1 * (int) self::opt( 'history_limit' ) );
		while ( $turns && 'user' !== $turns[0]['role'] ) {
			array_shift( $turns );
		}
		return $turns;
	}

	public static function ask_ai( array $history, $system ) {
		if ( ! $history ) {
			return null;
		}
		$res  = null;
		$code = 0;
		for ( $attempt = 1; $attempt <= 3; $attempt++ ) {
			$res  = wp_remote_post(
				self::opt( 'ai_base_url' ) . '/chat/completions',
				array(
					'timeout' => 90,
					'headers' => array(
						'Content-Type'  => 'application/json',
						'Authorization' => 'Bearer ' . self::opt( 'ai_key' ),
					),
					'body'    => wp_json_encode(
						array(
							'model'       => self::opt( 'ai_model' ),
							'messages'    => array_merge( array( array( 'role' => 'system', 'content' => $system ) ), $history ),
							'temperature' => 0.4,
							'max_tokens'  => 600,
							'stream'      => false,
						)
					),
				)
			);
			$code = is_wp_error( $res ) ? 0 : (int) wp_remote_retrieve_response_code( $res );
			if ( in_array( $code, array( 429, 502, 503 ), true ) && $attempt < 3 ) {
				self::log( "AI: HTTP {$code}, cuba semula ({$attempt})" );
				sleep( 4 * $attempt );
				continue;
			}
			break;
		}
		if ( is_wp_error( $res ) ) {
			self::log( 'AI: ' . $res->get_error_message() );
			return null;
		}
		$raw = wp_remote_retrieve_body( $res );
		if ( $code >= 300 ) {
			self::log( "AI: HTTP {$code} " . substr( $raw, 0, 300 ) );
			return null;
		}
		$d    = json_decode( $raw, true );
		$u    = (array) ( $d['usage'] ?? array() );
		self::$last_usage = array(
			(int) ( $u['prompt_tokens'] ?? $u['input_tokens'] ?? 0 ),
			(int) ( $u['completion_tokens'] ?? $u['output_tokens'] ?? 0 ),
		);
		$text = $d['choices'][0]['message']['content'] ?? null;
		if ( ! is_string( $text ) ) {
			self::log( 'AI: jawapan tidak dijangka ' . substr( $raw, 0, 300 ) );
			return null;
		}
		return trim( preg_replace( '/<think>.*?<\/think>/is', '', $text ) );
	}

	/* ================================================================== */
	/* Sending                                                             */
	/* ================================================================== */

	/** @return array [ok(bool), error(string), wa_id(string)] */
	public static function send_wa( $phone, $text ) {
		return self::send_payload( $phone, array( 'type' => 'text', 'text' => array( 'body' => $text ) ) );
	}

	public static function send_wa_image( $phone, $link, $caption = '' ) {
		$img = array( 'link' => $link );
		if ( '' !== $caption ) {
			$img['caption'] = $caption;
		}
		return self::send_payload( $phone, array( 'type' => 'image', 'image' => $img ) );
	}

	/** Mark the customer's message as read and show "typing..." (up to ~25s or until we reply). */
	private static function typing( $wa_id ) {
		if ( '' === $wa_id ) {
			return;
		}
		wp_remote_post(
			self::GRAPH . '/' . self::opt( 'phone_number_id' ) . '/messages',
			array(
				'timeout'  => 10,
				'blocking' => false,
				'headers'  => array(
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . self::opt( 'wa_token' ),
				),
				'body'     => wp_json_encode(
					array(
						'messaging_product' => 'whatsapp',
						'status'            => 'read',
						'message_id'        => $wa_id,
						'typing_indicator'  => array( 'type' => 'text' ),
					)
				),
			)
		);
	}

	/** Clean AI-looking punctuation. */
	private static function humanize( $text ) {
		$text = preg_replace( '/\s*[—–]\s*/u', ', ', $text );
		$text = preg_replace( '/\*\*(.+?)\*\*/u', '*$1*', $text ); // markdown bold -> WhatsApp bold
		return trim( $text );
	}

	/** Send the AI reply, as several short bubbles with a small pause when human style is on. */
	private static function send_reply( $c, $reply, $usage ) {
		if ( ! self::opt( 'human_style' ) ) {
			self::send_and_store( $c, $reply, 'bot', $usage );
			return;
		}
		$parts = array_values( array_filter( array_map( 'trim', preg_split( '/\n\s*\n/u', self::humanize( $reply ) ) ) ) );
		if ( count( $parts ) > 4 ) { // keep it to 4 bubbles
			$parts = array_merge( array_slice( $parts, 0, 3 ), array( implode( "\n\n", array_slice( $parts, 3 ) ) ) );
		}
		foreach ( $parts as $i => $part ) {
			if ( $i > 0 ) {
				sleep( (int) min( 4, 1 + floor( mb_strlen( $part ) / 60 ) ) ); // "typing" time
			}
			self::send_and_store( $c, $part, 'bot', 0 === $i ? $usage : array( 0, 0 ) );
		}
	}

	private static function send_payload( $phone, array $payload ) {
		$res = wp_remote_post(
			self::GRAPH . '/' . self::opt( 'phone_number_id' ) . '/messages',
			array(
				'timeout' => 20,
				'headers' => array(
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . self::opt( 'wa_token' ),
				),
				'body'    => wp_json_encode(
					array_merge(
						array(
							'messaging_product' => 'whatsapp',
							'to'                => $phone,
						),
						$payload
					)
				),
			)
		);
		if ( is_wp_error( $res ) ) {
			self::log( 'WhatsApp: ' . $res->get_error_message() );
			return array( false, $res->get_error_message(), '' );
		}
		$d = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( wp_remote_retrieve_response_code( $res ) >= 300 ) {
			$err  = (array) ( $d['error'] ?? array() );
			$code = (int) ( $err['code'] ?? 0 );
			$msg  = (string) ( $err['message'] ?? 'Ralat tidak diketahui' );
			if ( 131047 === $code ) {
				$msg = 'Lebih 24 jam sejak pelanggan mesej. WhatsApp tak benarkan mesej biasa — tunggu pelanggan mesej dulu.';
			} elseif ( 190 === $code ) {
				$msg = 'Token WhatsApp tamat tempoh / salah. Kemas kini di Tetapan.';
			}
			self::log( "WhatsApp ke {$phone}: {$code} {$msg}" );
			return array( false, $msg, '' );
		}
		return array( true, '', (string) ( $d['messages'][0]['id'] ?? '' ) );
	}

	/** Send catalogue images by code, skipping ones this customer got in the last 24 hours. */
	private static function send_images( $c, array $codes ) {
		global $wpdb;
		$imgs = self::images();
		foreach ( $codes as $code ) {
			if ( ! isset( $imgs[ $code ] ) ) {
				self::log( "Gambar: kod '{$code}' tiada dalam senarai" );
				continue;
			}
			list( $url, $caption ) = $imgs[ $code ];
			$link = self::wa_image_url( $url );
			$sent = $wpdb->get_var(
				$wpdb->prepare(
					'SELECT id FROM ' . self::t( 'messages' ) . ' WHERE contact_id = %d AND media_url = %s AND created_at >= %s LIMIT 1',
					$c->id,
					$link,
					gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS )
				)
			);
			if ( $sent ) {
				continue;
			}
			$r = self::send_wa_image( $c->phone, $link, $caption );
			if ( $r[0] ) {
				$now = current_time( 'mysql', true );
				$wpdb->insert(
					self::t( 'messages' ),
					array(
						'contact_id' => $c->id,
						'direction'  => 'out',
						'author'     => 'bot',
						'body'       => '📷 ' . ( '' !== $caption ? $caption : $code ),
						'wa_id'      => $r[2],
						'media_url'  => $link,
						'created_at' => $now,
					)
				);
				self::update_contact( $c->id, array( 'last_msg' => '📷 Gambar', 'last_msg_at' => $now ) );
			}
		}
	}

	private static function send_and_store( $c, $text, $author, $usage = array( 0, 0 ) ) {
		global $wpdb;
		$text = trim( (string) $text );
		if ( '' === $text ) {
			return array( false, 'Mesej kosong', '' );
		}
		$r = self::send_wa( $c->phone, $text );
		if ( $r[0] ) {
			$now = current_time( 'mysql', true );
			$wpdb->insert(
				self::t( 'messages' ),
				array(
					'contact_id' => $c->id,
					'direction'  => 'out',
					'author'     => $author,
					'body'       => $text,
					'wa_id'      => $r[2],
					'tokens_in'  => (int) $usage[0],
					'tokens_out' => (int) $usage[1],
					'created_at' => $now,
				)
			);
			self::update_contact( $c->id, array( 'last_msg' => mb_substr( $text, 0, 250 ), 'last_msg_at' => $now ) );
		}
		return $r;
	}

	/* ================================================================== */
	/* WooCommerce                                                         */
	/* ================================================================== */

	private static function refresh_status( $cid ) {
		$c = self::contact( $cid );
		if ( ! $c || (int) $c->status_manual || ! function_exists( 'wc_order_search' ) ) {
			return;
		}
		$s = self::payment_status( $c->phone );
		if ( $s !== $c->status ) {
			self::update_contact( $cid, array( 'status' => $s ) );
		}
	}

	/** Orders whose billing phone matches (last 9 digits), newest first. */
	private static function matching_orders( $phone ) {
		$digits = preg_replace( '/\D/', '', (string) $phone );
		if ( strlen( $digits ) < 7 || ! function_exists( 'wc_order_search' ) ) {
			return array();
		}
		$tail = substr( $digits, -9 );
		$out  = array();
		foreach ( array_slice( (array) wc_order_search( substr( $digits, -4 ) ), 0, 300 ) as $id ) {
			$o = wc_get_order( $id );
			if ( ! $o || ! method_exists( $o, 'get_billing_phone' ) ) {
				continue;
			}
			$p = preg_replace( '/\D/', '', (string) $o->get_billing_phone() );
			if ( '' !== $p && substr( $p, -9 ) === $tail && ! in_array( $o->get_status(), array( 'trash', 'checkout-draft' ), true ) ) {
				$out[] = $o;
			}
		}
		usort(
			$out,
			function ( $a, $b ) {
				return $b->get_id() <=> $a->get_id();
			}
		);
		return $out;
	}

	private static function is_cod( $o ) {
		return false !== strpos( (string) $o->get_payment_method(), 'cod' );
	}

	/**
	 * paid | unpaid | belum-beli.
	 * COD orders count as paid only once Completed (cash is collected on delivery).
	 */
	public static function payment_status( $phone ) {
		$found = false;
		foreach ( self::matching_orders( $phone ) as $o ) {
			$st = $o->get_status();
			if ( in_array( $st, array( 'cancelled', 'refunded', 'failed' ), true ) ) {
				continue;
			}
			if ( 'completed' === $st || ( 'processing' === $st && ! self::is_cod( $o ) ) ) {
				return 'paid';
			}
			$found = true;
		}
		return $found ? 'unpaid' : 'belum-beli';
	}

	/** Short text summary of the customer's 3 latest orders for the AI. */
	private static function orders_context( $phone ) {
		$names = array(
			'pending'    => 'Menunggu bayaran',
			'processing' => 'Sedang diproses / belum pos',
			'on-hold'    => 'Ditahan (tunggu pengesahan)',
			'completed'  => 'Selesai',
			'cancelled'  => 'Dibatalkan',
			'refunded'   => 'Dipulangkan wang',
			'failed'     => 'Bayaran gagal',
		);
		$lines = array();
		foreach ( array_slice( self::matching_orders( $phone ), 0, 3 ) as $o ) {
			$items = array();
			foreach ( $o->get_items() as $it ) {
				$items[] = $it->get_name() . ' x' . $it->get_quantity();
			}
			$date    = $o->get_date_created();
			$st      = $o->get_status();
			$lines[] = sprintf(
				'- Order #%s (%s): %s, jumlah RM%s, bayaran: %s, status: %s',
				$o->get_order_number(),
				$date ? $date->date_i18n( 'd/m/Y' ) : '-',
				implode( ', ', $items ),
				number_format( (float) $o->get_total(), 2 ),
				self::is_cod( $o ) ? 'COD' : (string) $o->get_payment_method_title(),
				$names[ $st ] ?? $st
			);
		}
		return implode( "\n", $lines );
	}

	/* ================================================================== */
	/* Auto-update from GitHub releases                                    */
	/* ================================================================== */

	public static function version() {
		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$d = get_plugin_data( __FILE__, false, false );
		return (string) $d['Version'];
	}

	/** Latest GitHub release with a .zip asset, cached 6 hours. */
	public static function latest_release( $force = false ) {
		$r = $force ? false : get_transient( 'waai_gh_release' );
		if ( false === $r ) {
			$r   = array();
			$res = wp_remote_get(
				'https://api.github.com/repos/' . self::REPO . '/releases/latest',
				array(
					'timeout' => 10,
					'headers' => array(
						'Accept'     => 'application/vnd.github+json',
						'User-Agent' => 'wa-ai-chat-updater',
					),
				)
			);
			if ( ! is_wp_error( $res ) && 200 === (int) wp_remote_retrieve_response_code( $res ) ) {
				$d = json_decode( wp_remote_retrieve_body( $res ), true );
				foreach ( (array) ( $d['assets'] ?? array() ) as $a ) {
					if ( 'wa-ai-chat.zip' === ( $a['name'] ?? '' ) ) {
						$r = array(
							'version' => ltrim( (string) ( $d['tag_name'] ?? '' ), 'vV' ),
							'package' => (string) $a['browser_download_url'],
							'url'     => (string) ( $d['html_url'] ?? '' ),
							'notes'   => (string) ( $d['body'] ?? '' ),
						);
						break;
					}
				}
			}
			set_transient( 'waai_gh_release', $r, 6 * HOUR_IN_SECONDS );
		}
		return $r;
	}

	public static function check_update( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}
		$base = plugin_basename( __FILE__ );
		$cur  = self::version();
		$r    = self::latest_release();
		$item = (object) array(
			'id'           => self::REPO,
			'slug'         => 'wa-ai-chat',
			'plugin'       => $base,
			'new_version'  => $cur,
			'url'          => 'https://github.com/' . self::REPO,
			'package'      => '',
			'requires_php' => '7.4',
		);
		if ( $r && version_compare( $r['version'], $cur, '>' ) ) {
			$item->new_version         = $r['version'];
			$item->package             = $r['package'];
			$transient->response[ $base ] = $item;
		} else {
			unset( $transient->response[ $base ] );
			$transient->no_update[ $base ] = $item;
		}
		return $transient;
	}

	public static function plugin_info( $res, $action, $args ) {
		if ( 'plugin_information' !== $action || 'wa-ai-chat' !== ( $args->slug ?? '' ) ) {
			return $res;
		}
		$r = self::latest_release();
		return (object) array(
			'name'          => 'WA AI Chat',
			'slug'          => 'wa-ai-chat',
			'version'       => $r['version'] ?? self::version(),
			'author'        => 'Solarex MY',
			'homepage'      => 'https://github.com/' . self::REPO,
			'download_link' => $r['package'] ?? '',
			'sections'      => array( 'changelog' => wpautop( esc_html( $r['notes'] ?? '' ) ) ),
		);
	}

	/* ================================================================== */
	/* Portal AJAX                                                         */
	/* ================================================================== */

	private static function guard() {
		check_ajax_referer( 'waai', 'nonce' );
		if ( ! current_user_can( self::cap() ) ) {
			wp_send_json_error( array( 'message' => 'Tiada akses' ), 403 );
		}
	}

	private static function contact_json( $c ) {
		return array(
			'id'          => (int) $c->id,
			'phone'       => $c->phone,
			'name'        => $c->name,
			'status'      => $c->status,
			'status_auto' => ! (int) $c->status_manual,
			'labels'      => array_values( array_filter( explode( ',', (string) $c->labels ) ) ),
			'notes'       => (string) $c->notes,
			'memory'      => (string) $c->memory,
			'bot_on'      => (bool) (int) $c->bot_on,
			'needs_staff' => (bool) (int) $c->needs_staff,
			'unread'      => (int) $c->unread,
			'last_msg'    => $c->last_msg,
			'last_msg_at' => $c->last_msg_at,
		);
	}

	public static function ajax_list() {
		global $wpdb;
		self::guard();
		$tc = self::t( 'contacts' );

		$where = array( '1=1' );
		$args  = array();
		$q     = sanitize_text_field( wp_unslash( $_POST['q'] ?? '' ) );
		if ( '' !== $q ) {
			$like    = '%' . $wpdb->esc_like( $q ) . '%';
			$where[] = '(name LIKE %s OR phone LIKE %s OR notes LIKE %s)';
			array_push( $args, $like, $like, $like );
		}
		$status = sanitize_key( $_POST['status'] ?? '' );
		if ( isset( self::STATUSES[ $status ] ) ) {
			$where[] = 'status = %s';
			$args[]  = $status;
		}
		$label = sanitize_key( $_POST['label'] ?? '' );
		if ( '' !== $label ) {
			$where[] = 'FIND_IN_SET(%s, labels)';
			$args[]  = $label;
		}
		if ( ! empty( $_POST['staff'] ) ) {
			$where[] = 'needs_staff = 1';
		}

		$sql  = "SELECT * FROM {$tc} WHERE " . implode( ' AND ', $where ) . ' ORDER BY last_msg_at DESC LIMIT 300';
		$rows = $args ? $wpdb->get_results( $wpdb->prepare( $sql, $args ) ) : $wpdb->get_results( $sql ); // phpcs:ignore

		$stats = array( 'total' => 0, 'needs_staff' => 0 );
		foreach ( (array) $wpdb->get_results( "SELECT status, COUNT(*) n, SUM(needs_staff) s FROM {$tc} GROUP BY status" ) as $r ) {
			$stats[ $r->status ]   = (int) $r->n;
			$stats['total']       += (int) $r->n;
			$stats['needs_staff'] += (int) $r->s;
		}
		$tok = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT COALESCE(SUM(tokens_in),0) ti, COALESCE(SUM(tokens_out),0) tout, COUNT(*) n FROM ' . self::t( 'messages' ) . " WHERE author = 'bot' AND tokens_in > 0 AND created_at >= %s",
				get_gmt_from_date( wp_date( 'Y-m-d 00:00:00' ) )
			)
		);
		$stats['tokens_today'] = $tok ? (int) $tok->ti + (int) $tok->tout : 0;
		$stats['ai_today']     = $tok ? (int) $tok->n : 0;
		$stats['today'] = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(DISTINCT contact_id) FROM ' . self::t( 'messages' ) . " WHERE direction = 'in' AND created_at >= %s",
				get_gmt_from_date( wp_date( 'Y-m-d 00:00:00' ) )
			)
		);

		wp_send_json_success(
			array(
				'contacts' => array_map( array( __CLASS__, 'contact_json' ), (array) $rows ),
				'stats'    => $stats,
			)
		);
	}

	public static function ajax_thread() {
		global $wpdb;
		self::guard();
		$cid   = (int) ( $_POST['contact_id'] ?? 0 );
		$after = (int) ( $_POST['after'] ?? 0 );
		$c     = self::contact( $cid );
		if ( ! $c ) {
			wp_send_json_error( array( 'message' => 'Tiada' ), 404 );
		}
		$tm = self::t( 'messages' );
		if ( $after ) {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, direction, author, body, created_at, tokens_in, tokens_out, media_url FROM {$tm} WHERE contact_id = %d AND id > %d ORDER BY id ASC LIMIT 500", $cid, $after ) );
		} else {
			$rows = array_reverse( (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, direction, author, body, created_at, tokens_in, tokens_out, media_url FROM {$tm} WHERE contact_id = %d ORDER BY id DESC LIMIT 300", $cid ) ) );
		}
		if ( (int) $c->unread ) {
			self::update_contact( $cid, array( 'unread' => 0 ) );
		}
		wp_send_json_success(
			array(
				'contact'  => self::contact_json( $c ),
				'messages' => $rows,
			)
		);
	}

	public static function ajax_send() {
		self::guard();
		$c    = self::contact( (int) ( $_POST['contact_id'] ?? 0 ) );
		$text = sanitize_textarea_field( wp_unslash( $_POST['text'] ?? '' ) );
		if ( ! $c || '' === trim( $text ) ) {
			wp_send_json_error( array( 'message' => 'Mesej kosong' ) );
		}
		$r = self::send_and_store( $c, $text, 'staff' );
		if ( ! $r[0] ) {
			wp_send_json_error( array( 'message' => $r[1] ) );
		}
		// Staff took over: pause the bot for this customer.
		self::update_contact( $c->id, array( 'bot_on' => 0, 'needs_staff' => 0 ) );
		wp_send_json_success();
	}

	public static function ajax_update() {
		self::guard();
		$cid = (int) ( $_POST['contact_id'] ?? 0 );
		if ( ! self::contact( $cid ) ) {
			wp_send_json_error( array( 'message' => 'Tiada' ), 404 );
		}
		$f      = array();
		$status = sanitize_key( $_POST['status'] ?? '' );
		if ( 'auto' === $status ) {
			$f['status_manual'] = 0;
		} elseif ( isset( self::STATUSES[ $status ] ) ) {
			$f['status']        = $status;
			$f['status_manual'] = 1;
		}
		if ( isset( $_POST['labels_sent'] ) ) {
			$labels      = array_map( 'sanitize_key', (array) wp_unslash( $_POST['labels'] ?? array() ) );
			$f['labels'] = implode( ',', array_unique( array_filter( $labels ) ) );
		}
		if ( isset( $_POST['memory'] ) ) {
			$f['memory'] = sanitize_textarea_field( wp_unslash( $_POST['memory'] ) );
		}
		if ( isset( $_POST['notes'] ) ) {
			$f['notes'] = sanitize_textarea_field( wp_unslash( $_POST['notes'] ) );
		}
		if ( isset( $_POST['bot_on'] ) ) {
			$f['bot_on'] = (int) ! empty( $_POST['bot_on'] );
			if ( $f['bot_on'] ) {
				$f['needs_staff'] = 0;
			}
		}
		if ( isset( $_POST['needs_staff'] ) ) {
			$f['needs_staff'] = (int) ! empty( $_POST['needs_staff'] );
		}
		if ( $f ) {
			self::update_contact( $cid, $f );
		}
		if ( 'auto' === $status ) {
			self::refresh_status( $cid );
		}
		wp_send_json_success( array( 'contact' => self::contact_json( self::contact( $cid ) ) ) );
	}

	/* ================================================================== */
	/* Admin pages                                                         */
	/* ================================================================== */

	public static function menu() {
		add_menu_page( 'WA Chat', 'WA Chat', self::cap(), 'waai', array( __CLASS__, 'portal' ), 'dashicons-format-chat', 3 );
		add_submenu_page( 'waai', 'Chat', 'Chat', self::cap(), 'waai', array( __CLASS__, 'portal' ) );
		add_submenu_page( 'waai', 'Tetapan WA Chat', 'Tetapan', 'manage_options', 'waai-settings', array( __CLASS__, 'settings_page' ) );
	}

	public static function handle_test() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'forbidden' );
		}
		check_admin_referer( 'waai_test' );
		$what = sanitize_key( $_POST['what'] ?? '' );
		$msg  = '';

		if ( 'ai' === $what ) {
			$q     = sanitize_textarea_field( wp_unslash( $_POST['q'] ?? '' ) );
			$start = microtime( true );
			$a     = self::ask_ai( array( array( 'role' => 'user', 'content' => $q ? $q : 'Hai' ) ), self::opt( 'system_prompt' ) );
			$ms    = round( ( microtime( true ) - $start ) * 1000 );
			$msg   = null === $a ? '❌ AI gagal. Lihat Log di bawah.' : "✅ AI jawab ({$ms} ms · " . number_format( self::$last_usage[0] ) . " token in / " . number_format( self::$last_usage[1] ) . " token out):\n\n{$a}";
		} elseif ( 'wa' === $what ) {
			$to  = preg_replace( '/\D/', '', (string) ( $_POST['to'] ?? '' ) );
			$r   = self::send_wa( $to, 'Ujian dari WA AI Chat ✅' );
			$msg = $r[0] ? "✅ Mesej ujian dihantar ke {$to}." : '❌ Gagal: ' . $r[1];
		} elseif ( 'img' === $what ) {
			$to   = preg_replace( '/\D/', '', (string) ( $_POST['to'] ?? '' ) );
			$imgs = self::images();
			$code = sanitize_key( $_POST['code'] ?? '' );
			if ( ! isset( $imgs[ $code ] ) ) {
				$msg = '❌ Kod gambar tiada.';
			} else {
				$link = self::wa_image_url( $imgs[ $code ][0] );
				$r    = self::send_wa_image( $to, $link, $imgs[ $code ][1] );
				$msg  = $r[0] ? "✅ Gambar '{$code}' dihantar ke {$to}.\nURL: {$link}" : '❌ Gagal: ' . $r[1] . "\nURL: {$link}";
			}
		} elseif ( 'woo' === $what ) {
			$p   = sanitize_text_field( wp_unslash( $_POST['phone'] ?? '' ) );
			$s   = self::payment_status( $p );
			$msg = "Status bayaran {$p}: " . ( self::STATUSES[ $s ] ?? $s );
		} elseif ( 'update' === $what ) {
			delete_site_transient( 'update_plugins' );
			$r = self::latest_release( true );
			wp_update_plugins();
			if ( ! $r ) {
				$msg = '❌ Tak dapat semak GitHub sekarang. Cuba lagi kemudian.';
			} elseif ( version_compare( $r['version'], self::version(), '>' ) ) {
				wp_safe_redirect( admin_url( 'plugins.php' ) );
				exit;
			} else {
				$msg = '✅ Dah versi terkini (' . self::version() . ').';
			}
		} elseif ( 'clear' === $what ) {
			delete_option( self::LOG );
		}

		set_transient( 'waai_test_' . get_current_user_id(), $msg, 120 );
		wp_safe_redirect( admin_url( 'admin.php?page=waai-settings' ) );
		exit;
	}

	public static function settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$o    = self::opts();
		$n    = self::OPT;
		$test = get_transient( 'waai_test_' . get_current_user_id() );
		delete_transient( 'waai_test_' . get_current_user_id() );

		$field = function ( $key, $label, $help = '', $type = 'text' ) use ( $o, $n ) {
			$secret = in_array( $key, self::SECRETS, true );
			printf(
				'<tr><th scope="row"><label for="%1$s">%2$s</label></th><td><input type="%3$s" class="regular-text" id="%1$s" name="%4$s[%1$s]" value="%5$s" placeholder="%6$s" autocomplete="off">%7$s</td></tr>',
				esc_attr( $key ),
				esc_html( $label ),
				esc_attr( $secret ? 'password' : $type ),
				esc_attr( $n ),
				esc_attr( $secret ? '' : $o[ $key ] ),
				esc_attr( $secret && ! empty( $o[ $key ] ) ? '•••••••• tersimpan (biar kosong untuk kekalkan)' : '' ),
				$help ? '<p class="description">' . wp_kses_post( $help ) . '</p>' : ''
			);
		};
		$test_form = function ( $what, $inner ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin:0 0 10px">';
			echo '<input type="hidden" name="action" value="waai_test"><input type="hidden" name="what" value="' . esc_attr( $what ) . '">';
			wp_nonce_field( 'waai_test' );
			echo $inner; // phpcs:ignore -- static markup
			echo '</form>';
		};
		?>
		<div class="wrap">
			<h1>Tetapan WA Chat <span style="font-size:13px;color:#646970">versi <?php echo esc_html( self::version() ); ?></span></h1>
			<?php $test_form( 'update', '<button class="button">🔄 Semak update sekarang</button>' ); ?>
			<?php if ( $test ) : ?>
				<div class="notice notice-info"><pre style="white-space:pre-wrap"><?php echo esc_html( $test ); ?></pre></div>
			<?php endif; ?>

			<div class="card" style="max-width:none">
				<h2>Salin 2 benda ini ke Meta (WhatsApp → Configuration → Webhook)</h2>
				<p><strong>Callback URL</strong><br><input type="text" readonly class="large-text code" value="<?php echo esc_attr( self::webhook_url() ); ?>" onclick="this.select()"></p>
				<p><strong>Verify token</strong><br><input type="text" readonly class="regular-text code" value="<?php echo esc_attr( $o['verify_token'] ); ?>" onclick="this.select()"></p>
				<p class="description">Lepas Verify, tekan <strong>Subscribe</strong> pada field <code>messages</code>.</p>
			</div>

			<form method="post" action="options.php">
				<?php settings_fields( 'waai' ); ?>
				<table class="form-table" role="presentation">
					<tr><th scope="row">Bot aktif</th><td><label><input type="checkbox" name="<?php echo esc_attr( $n ); ?>[enabled]" value="1" <?php checked( $o['enabled'] ); ?>> AI balas mesej WhatsApp secara automatik</label></td></tr>
				</table>

				<h2>WhatsApp (dari Meta)</h2>
				<table class="form-table" role="presentation">
					<?php
					$field( 'phone_number_id', 'Phone number ID', 'Meta → WhatsApp → API Setup' );
					$field( 'wa_token', 'Access token', 'Token kekal dari System User (lihat panduan)' );
					$field( 'app_secret', 'App Secret (pilihan)', 'App settings → Basic → App secret. Tambah keselamatan, boleh isi kemudian.' );
					?>
				</table>

				<h2>AI (ServerasAI)</h2>
				<table class="form-table" role="presentation">
					<?php
					$field( 'ai_key', 'API key', 'serverasai.com → API keys (mula dengan <code>asai_sk_</code>)' );
					$field( 'ai_model', 'Model', '<code>asai/claude-haiku-5.5</code> laju & jimat · <code>asai/claude-sonnet-5.5</code> lebih bijak' );
					$field( 'ai_base_url', 'API URL', 'Biar default.' );
					$field( 'history_limit', 'Ingat berapa mesej', '2–50', 'number' );
					?>
					<tr><th scope="row">Gaya manusia</th><td><label><input type="checkbox" name="<?php echo esc_attr( $n ); ?>[human_style]" value="1" <?php checked( $o['human_style'] ); ?>> Pecah jawapan jadi beberapa mesej pendek, tunjuk "typing...", buang gaya tulisan AI</label></td></tr>
					<tr><th scope="row"><label for="system_prompt">Arahan AI</label></th>
						<td><textarea id="system_prompt" class="large-text code" rows="22" name="<?php echo esc_attr( $n ); ?>[system_prompt]"><?php echo esc_textarea( $o['system_prompt'] ); ?></textarea>
						<p class="description"><strong>Tukar harga, FAQ & maklumat syarikat kepada yang sebenar.</strong> AI hanya tahu apa yang ditulis di sini.</p></td></tr>
					<tr><th scope="row"><label for="handoff_message">Mesej bila serah kepada staff</label></th>
						<td><textarea id="handoff_message" class="large-text" rows="2" name="<?php echo esc_attr( $n ); ?>[handoff_message]"><?php echo esc_textarea( $o['handoff_message'] ); ?></textarea></td></tr>
					<tr><th scope="row"><label for="media_message">Mesej bila dapat gambar/voice</label></th>
						<td><textarea id="media_message" class="large-text" rows="2" name="<?php echo esc_attr( $n ); ?>[media_message]"><?php echo esc_textarea( $o['media_message'] ); ?></textarea></td></tr>
					<tr><th scope="row"><label for="images">Gambar produk</label></th>
						<td><textarea id="images" class="large-text code" rows="6" name="<?php echo esc_attr( $n ); ?>[images]"><?php echo esc_textarea( $o['images'] ); ?></textarea>
						<p class="description">Satu gambar satu baris: <code>kod | URL gambar | caption</code>. AI hantar gambar bila sesuai (cth: bila bagi harga). URL ambil dari <strong>Media → klik gambar → Copy URL</strong>. Gambar WebP ditukar ke JPG secara automatik.</p></td></tr>
				</table>
				<?php submit_button( 'Simpan' ); ?>
			</form>

			<hr><h2>Ujian</h2>
			<?php
			$test_form( 'ai', '<input type="text" name="q" class="regular-text" value="Hai, berapa harga solar 5kW?"> <button class="button">Uji AI</button>' );
			$test_form( 'wa', '<input type="text" name="to" class="regular-text" placeholder="60123456789 (nombor anda)"> <button class="button">Uji hantar WhatsApp</button><p class="description">Mesej dulu nombor bisnes dari telefon anda, kemudian tekan butang ini dalam masa 24 jam.</p>' );
			$opts_html = '';
			foreach ( array_keys( self::images() ) as $code ) {
				$opts_html .= '<option value="' . esc_attr( $code ) . '">' . esc_html( $code ) . '</option>';
			}
			$test_form( 'img', '<input type="text" name="to" class="regular-text" placeholder="60123456789 (nombor anda)"> <select name="code">' . $opts_html . '</select> <button class="button">Uji hantar gambar</button>' );
			$test_form( 'woo', '<input type="text" name="phone" class="regular-text" placeholder="0123456789"> <button class="button">Uji status bayaran WooCommerce</button>' );
			?>

			<hr><h2>Log</h2>
			<textarea readonly class="large-text code" rows="10"><?php echo esc_textarea( implode( "\n", (array) get_option( self::LOG, array() ) ) ); ?></textarea>
			<?php $test_form( 'clear', '<button class="button">Kosongkan log</button>' ); ?>
		</div>
		<?php
	}

	public static function portal() {
		$cfg = array(
			'ajax'     => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( 'waai' ),
			'statuses' => self::STATUSES,
			'labels'   => self::LABELS,
			'ready'    => (bool) ( self::opt( 'phone_number_id' ) && self::opt( 'wa_token' ) ),
			'settings' => current_user_can( 'manage_options' ) ? admin_url( 'admin.php?page=waai-settings' ) : '',
		);
		?>
		<div class="wrap waai">
			<h1>WA Chat</h1>
			<?php if ( ! $cfg['ready'] ) : ?>
				<div class="notice notice-warning"><p>WhatsApp belum disambung. <?php if ( $cfg['settings'] ) : ?><a href="<?php echo esc_url( $cfg['settings'] ); ?>">Buka Tetapan</a><?php endif; ?></p></div>
			<?php endif; ?>

			<div class="waai-stats" id="waai-stats"></div>

			<div class="waai-main">
				<div class="waai-side">
					<div class="waai-filters">
						<input type="search" id="waai-q" placeholder="Cari nama / nombor / nota">
						<select id="waai-status"><option value="">Semua status</option></select>
						<select id="waai-label"><option value="">Semua label</option></select>
						<label><input type="checkbox" id="waai-staff"> Perlu staff sahaja</label>
					</div>
					<div class="waai-list" id="waai-list"></div>
				</div>

				<div class="waai-chat" id="waai-chat">
					<div class="waai-empty">← Pilih pelanggan</div>
				</div>
			</div>
		</div>

		<style>
			.waai-stats{display:flex;gap:10px;flex-wrap:wrap;margin:12px 0}
			.waai-stat{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:10px 14px;min-width:110px;cursor:pointer}
			.waai-stat b{display:block;font-size:22px;line-height:1.2}
			.waai-stat span{color:#646970;font-size:12px}
			.waai-main{display:flex;gap:12px;height:calc(100vh - 260px);min-height:480px}
			.waai-side{width:340px;display:flex;flex-direction:column;background:#fff;border:1px solid #dcdcde;border-radius:8px;overflow:hidden}
			.waai-filters{padding:10px;display:flex;flex-direction:column;gap:6px;border-bottom:1px solid #dcdcde}
			.waai-filters input[type=search],.waai-filters select{width:100%;max-width:none}
			.waai-list{overflow-y:auto;flex:1}
			.waai-item{padding:10px 12px;border-bottom:1px solid #f0f0f1;cursor:pointer}
			.waai-item:hover{background:#f6f7f7}.waai-item.active{background:#e7f1fb}
			.waai-item .top{display:flex;justify-content:space-between;gap:6px}
			.waai-item .nm{font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
			.waai-item .tm{color:#646970;font-size:11px;white-space:nowrap}
			.waai-item .lm{color:#50575e;font-size:12px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;margin:2px 0 4px}
			.waai-b{display:inline-block;font-size:11px;padding:1px 7px;border-radius:10px;margin:0 3px 2px 0;background:#f0f0f1;color:#3c434a}
			.waai-b.paid{background:#d1f2d9;color:#0a5c1f}.waai-b.unpaid{background:#fbd5d5;color:#8a1f1f}
			.waai-b.partial{background:#fdecc8;color:#7a4b00}.waai-b.staff{background:#d63638;color:#fff}
			.waai-b.unread{background:#2271b1;color:#fff}
			.waai-chat{flex:1;display:flex;flex-direction:column;background:#fff;border:1px solid #dcdcde;border-radius:8px;overflow:hidden;min-width:0}
			.waai-empty{margin:auto;color:#646970}
			.waai-head{padding:10px 14px;border-bottom:1px solid #dcdcde;display:flex;flex-wrap:wrap;gap:10px;align-items:center}
			.waai-head h2{margin:0;font-size:16px}
			.waai-head .ph{color:#646970}
			.waai-head .sp{flex:1}
			.waai-tools{padding:8px 14px;border-bottom:1px solid #f0f0f1;display:flex;flex-wrap:wrap;gap:12px;align-items:flex-start;background:#fbfbfc}
			.waai-tools textarea{width:260px;height:34px}
			.waai-msgs{flex:1;overflow-y:auto;padding:14px;background:#efeae2;display:flex;flex-direction:column;gap:6px}
			.waai-m{max-width:72%;padding:7px 10px;border-radius:8px;white-space:pre-wrap;word-wrap:break-word;box-shadow:0 1px 1px rgba(0,0,0,.08)}
			.waai-m.in{background:#fff;align-self:flex-start}
			.waai-m.out{background:#d9fdd3;align-self:flex-end}
			.waai-m.out.staff{background:#cfe9ff}
			.waai-m small{display:block;color:#667781;font-size:10px;margin-top:3px;text-align:right}
			.waai-reply{display:flex;gap:8px;padding:10px;border-top:1px solid #dcdcde}
			.waai-reply textarea{flex:1;height:44px;resize:vertical}
			@media (max-width:900px){.waai-main{flex-direction:column;height:auto}.waai-side{width:auto;max-height:340px}.waai-chat{height:70vh}}
		</style>

		<script>
		(function () {
			var A = <?php echo wp_json_encode( $cfg ); ?>;
			var cur = null, lastId = 0, loadingThread = false;
			var $ = function (id) { return document.getElementById(id); };

			function el(tag, cls, text) {
				var e = document.createElement(tag);
				if (cls) e.className = cls;
				if (text != null) e.textContent = text; // always textContent: customer text is untrusted
				return e;
			}
			function post(action, data) {
				var f = new FormData();
				f.append('action', 'waai_' + action);
				f.append('nonce', A.nonce);
				Object.keys(data || {}).forEach(function (k) {
					var v = data[k];
					if (Array.isArray(v)) v.forEach(function (x) { f.append(k + '[]', x); });
					else f.append(k, v);
				});
				return fetch(A.ajax, { method: 'POST', body: f, credentials: 'same-origin' }).then(function (r) { return r.json(); });
			}
			function fmt(t) {
				if (!t) return '';
				var d = new Date(t.replace(' ', 'T') + 'Z');
				return d.toLocaleString('ms-MY', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' });
			}
			function statusBadge(s) { return el('span', 'waai-b ' + s, A.statuses[s] || s); }

			// Filters
			Object.keys(A.statuses).forEach(function (k) { var o = el('option', null, A.statuses[k]); o.value = k; $('waai-status').appendChild(o); });
			A.labels.forEach(function (l) { var o = el('option', null, l); o.value = l; $('waai-label').appendChild(o); });
			['waai-status', 'waai-label', 'waai-staff'].forEach(function (id) { $(id).addEventListener('change', loadList); });
			var qt; $('waai-q').addEventListener('input', function () { clearTimeout(qt); qt = setTimeout(loadList, 300); });

			function renderStats(s) {
				var box = $('waai-stats'); box.innerHTML = '';
				[
					['total', 'Jumlah pelanggan', ''],
					['today', 'Chat hari ini', ''],
					['ai_today', 'Jawapan AI hari ini', ''],
					['tokens_today', 'Token hari ini', ''],
					['needs_staff', '⚠ Perlu staff', 'staff'],
					['paid', 'Dah bayar', 'paid'],
					['unpaid', 'Belum bayar', 'unpaid'],
					['partial', 'Bayar separuh', 'partial'],
					['belum-beli', 'Belum beli', 'belum-beli']
				].forEach(function (x) {
					var d = el('div', 'waai-stat');
					d.appendChild(el('b', null, (+s[x[0]] || 0).toLocaleString()));
					d.appendChild(el('span', null, x[1]));
					d.onclick = function () {
						$('waai-staff').checked = x[2] === 'staff';
						$('waai-status').value = (x[2] && x[2] !== 'staff') ? x[2] : '';
						loadList();
					};
					box.appendChild(d);
				});
			}

			function renderList(list) {
				var box = $('waai-list'); box.innerHTML = '';
				if (!list.length) { box.appendChild(el('p', 'waai-empty', 'Tiada pelanggan')).style.padding = '20px'; return; }
				list.forEach(function (c) {
					var it = el('div', 'waai-item' + (c.id === cur ? ' active' : ''));
					var top = el('div', 'top');
					top.appendChild(el('span', 'nm', c.name || ('+' + c.phone)));
					top.appendChild(el('span', 'tm', fmt(c.last_msg_at)));
					it.appendChild(top);
					it.appendChild(el('div', 'lm', c.last_msg));
					it.appendChild(statusBadge(c.status));
					if (c.needs_staff) it.appendChild(el('span', 'waai-b staff', '⚠ perlu staff'));
					if (c.unread) it.appendChild(el('span', 'waai-b unread', c.unread + ' baru'));
					it.appendChild(el('span', 'waai-b', c.bot_on ? '🤖 bot' : '👤 staff'));
					c.labels.forEach(function (l) { it.appendChild(el('span', 'waai-b', l)); });
					it.onclick = function () { open(c.id); };
					box.appendChild(it);
				});
			}

			function loadList() {
				post('list', { q: $('waai-q').value, status: $('waai-status').value, label: $('waai-label').value, staff: $('waai-staff').checked ? 1 : 0 })
					.then(function (r) { if (r.success) { renderStats(r.data.stats); renderList(r.data.contacts); } });
			}

			function buildChat(c) {
				var box = $('waai-chat'); box.innerHTML = '';

				var head = el('div', 'waai-head');
				head.appendChild(el('h2', null, c.name || 'Tanpa nama'));
				head.appendChild(el('span', 'ph', '+' + c.phone));
				var wa = el('a', null, 'Buka di WhatsApp ↗'); wa.href = 'https://wa.me/' + c.phone; wa.target = '_blank'; wa.rel = 'noopener';
				head.appendChild(wa);
				head.appendChild(el('span', 'sp'));
				var botLbl = el('label'); var bot = el('input'); bot.type = 'checkbox'; bot.id = 'waai-bot'; bot.checked = c.bot_on;
				botLbl.appendChild(bot); botLbl.appendChild(document.createTextNode(' 🤖 Bot jawab pelanggan ini'));
				bot.onchange = function () { save({ bot_on: bot.checked ? 1 : 0 }); };
				head.appendChild(botLbl);
				box.appendChild(head);

				var tools = el('div', 'waai-tools');
				var stWrap = el('label', null, 'Status: ');
				var st = el('select'); st.id = 'waai-st';
				var oa = el('option', null, 'Auto (ikut WooCommerce)'); oa.value = 'auto'; st.appendChild(oa);
				Object.keys(A.statuses).forEach(function (k) { var o = el('option', null, A.statuses[k]); o.value = k; st.appendChild(o); });
				st.value = c.status_auto ? 'auto' : c.status;
				st.onchange = function () { save({ status: st.value }); };
				stWrap.appendChild(st);
				stWrap.appendChild(document.createTextNode(' '));
				stWrap.appendChild(statusBadge(c.status));
				tools.appendChild(stWrap);

				var lbWrap = el('div');
				lbWrap.appendChild(document.createTextNode('Label: '));
				A.labels.concat(c.labels.filter(function (l) { return A.labels.indexOf(l) < 0; })).forEach(function (l) {
					var lab = el('label'); lab.style.marginRight = '8px';
					var cb = el('input'); cb.type = 'checkbox'; cb.value = l; cb.className = 'waai-lb'; cb.checked = c.labels.indexOf(l) >= 0;
					cb.onchange = function () {
						var ls = Array.prototype.map.call(document.querySelectorAll('.waai-lb:checked'), function (x) { return x.value; });
						save({ labels: ls, labels_sent: 1 });
					};
					lab.appendChild(cb); lab.appendChild(document.createTextNode(' ' + l));
					lbWrap.appendChild(lab);
				});
				tools.appendChild(lbWrap);

				var notes = el('textarea'); notes.placeholder = 'Nota (cth: alamat, bil elektrik, tarikh site visit)'; notes.value = c.notes;
				var nt; notes.oninput = function () { clearTimeout(nt); nt = setTimeout(function () { save({ notes: notes.value }, true); }, 800); };
				tools.appendChild(notes);
				var mem = el('textarea'); mem.placeholder = '🧠 Memori AI (diisi sendiri oleh AI, boleh edit)'; mem.value = c.memory; mem.title = 'Memori AI: fakta yang AI ingat tentang pelanggan ini';
				var mt; mem.oninput = function () { clearTimeout(mt); mt = setTimeout(function () { save({ memory: mem.value }, true); }, 800); };
				tools.appendChild(mem);
				if (c.needs_staff) {
					var done = el('button', 'button', '✓ Selesai (buang tanda perlu staff)');
					done.onclick = function () { save({ needs_staff: 0 }); };
					tools.appendChild(done);
				}
				box.appendChild(tools);

				var msgs = el('div', 'waai-msgs'); msgs.id = 'waai-msgs';
				box.appendChild(msgs);

				var rep = el('div', 'waai-reply');
				var ta = el('textarea'); ta.placeholder = 'Taip balasan… (Enter hantar, Shift+Enter baris baru). Bot akan berhenti untuk pelanggan ini.';
				var btn = el('button', 'button button-primary', 'Hantar');
				function send() {
					var t = ta.value.trim(); if (!t) return;
					btn.disabled = true;
					post('send', { contact_id: cur, text: t }).then(function (r) {
						btn.disabled = false;
						if (!r.success) { alert((r.data && r.data.message) || 'Gagal hantar'); return; }
						ta.value = ''; $('waai-bot').checked = false; loadThread(); loadList();
					}).catch(function () { btn.disabled = false; alert('Gagal hantar'); });
				}
				btn.onclick = send;
				ta.addEventListener('keydown', function (e) { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); send(); } });
				rep.appendChild(ta); rep.appendChild(btn);
				box.appendChild(rep);
			}

			function save(data, quiet) {
				data.contact_id = cur;
				post('update', data).then(function (r) {
					if (!r.success) return;
					if (quiet) loadList(); else open(cur);
				});
			}

			function addMsgs(list) {
				var box = $('waai-msgs'); if (!box) return;
				var atBottom = box.scrollHeight - box.scrollTop - box.clientHeight < 80;
				list.forEach(function (m) {
					var who = m.direction === 'in' ? '' : (m.author === 'bot' ? '🤖 Bot · ' : '👤 Staff · ');
					var d = el('div', 'waai-m ' + m.direction + ' ' + m.author, m.body);
					if (m.media_url) {
						var im = el('img'); im.src = m.media_url; im.style.cssText = 'display:block;max-width:220px;border-radius:6px;margin-bottom:4px';
						d.insertBefore(im, d.firstChild);
					}
					var tk = (+m.tokens_in || +m.tokens_out) ? ' · ' + (+m.tokens_in).toLocaleString() + ' in / ' + (+m.tokens_out).toLocaleString() + ' out' : '';
					d.appendChild(el('small', null, who + fmt(m.created_at) + tk));
					box.appendChild(d);
					lastId = Math.max(lastId, parseInt(m.id, 10));
				});
				if (list.length && (atBottom || list.length > 20)) box.scrollTop = box.scrollHeight;
			}

			function loadThread() {
				if (!cur || loadingThread) return;
				loadingThread = true;
				var id = cur;
				post('thread', { contact_id: id, after: lastId }).then(function (r) {
					loadingThread = false;
					if (!r.success || id !== cur) return;
					var bot = $('waai-bot'); if (bot && document.activeElement !== bot) bot.checked = r.data.contact.bot_on;
					addMsgs(r.data.messages);
				}).catch(function () { loadingThread = false; });
			}

			function open(id) {
				cur = id; lastId = 0;
				post('thread', { contact_id: id, after: 0 }).then(function (r) {
					if (!r.success || id !== cur) return;
					buildChat(r.data.contact);
					addMsgs(r.data.messages);
					loadList();
				});
			}

			loadList();
			setInterval(function () { if (!document.hidden) { loadList(); loadThread(); } }, 5000);
		})();
		</script>
		<?php
	}
}

WAAI::init();
