<?php
declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * iCal feed with token-based authentication.
 *
 * Updated for the virtual recurrence model — expands rrules on the fly
 * for the token owner's assigned events.
 *
 * @package RotaPress
 */
class RotaPress_iCal {

	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_route' ) );
		add_action( 'admin_post_rotapress_regen_token', array( $this, 'handle_regen_token' ) );
		add_action( 'admin_post_rotapress_revoke_token', array( $this, 'handle_revoke_token' ) );
	}

	public function register_route(): void {
		register_rest_route( 'rotapress/v1', '/ical', array(
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => array( $this, 'serve_feed' ),
			/* Public — auth via secret token for iCal clients. */
			'permission_callback' => '__return_true',
			'args' => array(
				'token' => array( 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
			),
		) );

		/* User generates their own token. */
		register_rest_route( 'rotapress/v1', '/ical/generate', array(
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'api_generate_token' ),
			'permission_callback' => function (): bool { return current_user_can( 'rotapress_read' ); },
		) );

		/* User revokes their own token. */
		register_rest_route( 'rotapress/v1', '/ical/revoke', array(
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'api_revoke_own_token' ),
			'permission_callback' => function (): bool { return current_user_can( 'rotapress_read' ); },
		) );
	}

	public function serve_feed( \WP_REST_Request $request ) {
		$token = sanitize_text_field( $request->get_param( 'token' ) );

		if ( strlen( $token ) !== 64 ) {
			return $this->empty_response( 403 );
		}

		$users = get_users( array(
			'meta_key'   => '_rp_ical_token', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'meta_value' => $token, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			'number'     => 1,
		) );

		if ( empty( $users ) ) {
			return $this->empty_response( 403 );
		}

		$user      = $users[0];
		$site_name = get_bloginfo( 'name' );
		$timezone  = wp_timezone_string();
		$hostname  = wp_parse_url( home_url(), PHP_URL_HOST ) ?: 'localhost';

		/* Collect events: non-recurring assigned to user. */
		$events = get_posts( array(
			'post_type'      => 'rp_event',
			'posts_per_page' => -1,
			'post_status'    => 'publish',
			'meta_query'     => array( array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'key'     => '_rp_assigned_user',
				'value'   => $user->ID,
				'compare' => '=',
				'type'    => 'NUMERIC',
			) ),
		) );

		$lines   = array();
		$lines[] = 'BEGIN:VCALENDAR';
		$lines[] = 'VERSION:2.0';
		$lines[] = 'PRODID:-//RotaPress//' . self::esc( $site_name ) . '//EN';
		$lines[] = 'CALSCALE:GREGORIAN';
		$lines[] = 'METHOD:PUBLISH';
		$lines[] = 'X-WR-CALNAME:' . self::esc( $site_name ) . ' – RotaPress';
		$lines[] = 'X-WR-TIMEZONE:' . $timezone;

		foreach ( $events as $event ) {
			$rrule_json = (string) get_post_meta( $event->ID, '_rp_rrule', true );

			if ( '' !== $rrule_json ) {
				/* Recurring parent: expand all occurrences. */
				$rrule      = json_decode( $rrule_json, true );
				$parent_date = (string) get_post_meta( $event->ID, '_rp_event_date', true );
				$exdates    = RotaPress_Recurrence::get_exdates( $event->ID );

				if ( is_array( $rrule ) ) {
					$all_dates = RotaPress_Recurrence::expand_all( $rrule, $parent_date );
					$exmap     = array_flip( $exdates );

					foreach ( $all_dates as $d ) {
						if ( isset( $exmap[ $d ] ) ) { continue; }
						$lines = array_merge( $lines, $this->vevent( $event, $d, $hostname ) );
					}
				}
			} else {
				$d = (string) get_post_meta( $event->ID, '_rp_event_date', true );
				if ( $d ) {
					$lines = array_merge( $lines, $this->vevent( $event, $d, $hostname ) );
				}
			}
		}

		$lines[] = 'END:VCALENDAR';

		$output = '';
		foreach ( $lines as $line ) {
			$output .= self::fold( $line ) . "\r\n";
		}

		header( 'Content-Type: text/calendar; charset=UTF-8' );
		header( 'Content-Disposition: inline; filename="rotapress.ics"' );
		header( 'Cache-Control: no-store, no-cache, must-revalidate' );
		echo $output; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- iCal output is not HTML
		exit;
	}

	private function vevent( \WP_Post $event, string $date, string $hostname ): array {
		$notes   = get_post_meta( $event->ID, '_rp_notes', true );
		$start   = str_replace( '-', '', $date );
		$end_dt  = new \DateTime( $date );
		$end_dt->modify( '+1 day' );
		$end     = $end_dt->format( 'Ymd' );
		$now     = gmdate( 'Ymd\THis\Z' );

		$uid_str = 'rotapress-' . $event->ID . '-' . $start . '@' . $hostname;

		$v   = array();
		$v[] = 'BEGIN:VEVENT';
		$v[] = 'UID:' . $uid_str;
		$v[] = 'DTSTAMP:' . $now;
		$v[] = 'DTSTART;VALUE=DATE:' . $start;
		$v[] = 'DTEND;VALUE=DATE:' . $end;
		$v[] = 'SUMMARY:' . self::esc( $event->post_title );
		if ( $notes ) {
			$v[] = 'DESCRIPTION:' . self::esc( (string) $notes );
		}
		$v[] = 'END:VEVENT';
		return $v;
	}

	/* ── Token management ─────────────────────────────────────────── */

	public static function generate_token( int $user_id ): string {
		$token = bin2hex( random_bytes( 32 ) );
		update_user_meta( $user_id, '_rp_ical_token', $token );
		update_user_meta( $user_id, '_rp_ical_token_created', gmdate( 'Y-m-d H:i:s' ) );
		return $token;
	}

	public static function get_token( int $user_id ): string {
		$token = get_user_meta( $user_id, '_rp_ical_token', true );
		if ( $token && strlen( (string) $token ) === 64 ) {
			return (string) $token;
		}
		return '';
	}

	/**
	 * Check if a user has an active token.
	 */
	public static function has_token( int $user_id ): bool {
		return '' !== self::get_token( $user_id );
	}

	public static function get_feed_url( int $user_id ): string {
		$token = self::get_token( $user_id );
		if ( '' === $token ) {
			return '';
		}
		return rest_url( 'rotapress/v1/ical' ) . '?token=' . $token;
	}

	/**
	 * Get all users with an active iCal token.
	 *
	 * @return array[] Each: { user_id, display_name, user_email, created }
	 */
	public static function get_all_active_tokens(): array {
		$users = get_users( array(
			'meta_key'     => '_rp_ical_token', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'meta_compare' => 'EXISTS',
		) );

		$result = array();
		foreach ( $users as $u ) {
			$token = get_user_meta( $u->ID, '_rp_ical_token', true );
			if ( ! $token || strlen( (string) $token ) !== 64 ) {
				continue;
			}
			$result[] = array(
				'user_id'      => $u->ID,
				'display_name' => $u->display_name,
				'user_email'   => $u->user_email,
				'created'      => get_user_meta( $u->ID, '_rp_ical_token_created', true ) ?: __( 'Unknown', 'rotapress' ),
			);
		}
		return $result;
	}

	/**
	 * REST: generate a token for the current user.
	 */
	public function api_generate_token( \WP_REST_Request $request ): \WP_REST_Response {
		$uid = get_current_user_id();
		$token = self::generate_token( $uid );
		return new \WP_REST_Response( array(
			'url' => rest_url( 'rotapress/v1/ical' ) . '?token=' . $token,
		), 200 );
	}

	/**
	 * REST: revoke the current user's own token.
	 */
	public function api_revoke_own_token( \WP_REST_Request $request ): \WP_REST_Response {
		$uid = get_current_user_id();
		delete_user_meta( $uid, '_rp_ical_token' );
		delete_user_meta( $uid, '_rp_ical_token_created' );
		return new \WP_REST_Response( array( 'revoked' => true ), 200 );
	}

	public function handle_regen_token(): void {
		check_admin_referer( 'rotapress_regen_token' );
		$uid = get_current_user_id();
		if ( 0 === $uid ) {
			wp_die( esc_html__( 'Unauthorized.', 'rotapress' ) );
		}
		self::generate_token( $uid );
		wp_safe_redirect( admin_url( 'admin.php?page=rotapress&token_regenerated=1' ) );
		exit;
	}

	/**
	 * Admin revokes a user's token from the settings page.
	 */
	public function handle_revoke_token(): void {
		check_admin_referer( 'rotapress_revoke_token' );
		if ( ! current_user_can( 'rotapress_admin' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'rotapress' ) );
		}
		$uid = absint( wp_unslash( $_GET['user_id'] ?? 0 ) );
		if ( $uid > 0 ) {
			delete_user_meta( $uid, '_rp_ical_token' );
			delete_user_meta( $uid, '_rp_ical_token_created' );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=rotapress-settings&token_revoked=1' ) );
		exit;
	}

	/* ── iCal helpers ─────────────────────────────────────────────── */

	private static function esc( string $t ): string {
		$t = str_replace( '\\', '\\\\', $t );
		$t = str_replace( ';', '\\;', $t );
		$t = str_replace( ',', '\\,', $t );
		$t = str_replace( "\r\n", '\\n', $t );
		$t = str_replace( "\n", '\\n', $t );
		$t = str_replace( "\r", '\\n', $t );
		return $t;
	}

	private static function fold( string $line ): string {
		if ( strlen( $line ) <= 75 ) { return $line; }
		$result = ''; $first = true;
		while ( strlen( $line ) > 0 ) {
			$max = $first ? 75 : 74;
			if ( strlen( $line ) <= $max ) {
				$result .= ( $first ? '' : "\r\n " ) . $line; break;
			}
			$result .= ( $first ? '' : "\r\n " ) . substr( $line, 0, $max );
			$line = substr( $line, $max ); $first = false;
		}
		return $result;
	}

	private function empty_response( int $status ): \WP_REST_Response {
		$r = new \WP_REST_Response( "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//RotaPress//Empty//EN\r\nEND:VCALENDAR\r\n", $status );
		$r->header( 'Content-Type', 'text/calendar; charset=UTF-8' );
		return $r;
	}
}
