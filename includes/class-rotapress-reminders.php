<?php
declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Email reminders via WP Cron with customisable templates.
 *
 * Available placeholders in subject and body:
 *   {title}           – Event title
 *   {assignee}        – Display name of the assigned user
 *   {date}            – Event date (YYYY-MM-DD)
 *   {notes}           – Event notes (or empty)
 *   {days}            – Number of days before the event
 *   {site}            – Site name
 *   {calendar_url}    – Direct link to the RotaPress calendar
 *   {no_reminder_url} – One-click link to disable reminders for this event
 *
 * @package RotaPress
 */
class RotaPress_Reminders {

	public function __construct() {
		add_action( 'rotapress_daily_reminders', array( $this, 'process' ) );
		add_action( 'template_redirect', array( $this, 'handle_no_reminder_token' ) );
	}

	/**
	 * Default email subject template.
	 */
	public static function default_subject(): string {
		/* translators: Email reminder subject. {site}=site name, {title}=event title, {days}=number of days before the event. Keep placeholders unchanged. */
		return __( '[{site}] Reminder: "{title}" in {days} day(s)', 'rotapress' );
	}

	/**
	 * Default email body template.
	 */
	public static function default_body(): string {
		/* translators: Email reminder body. Placeholders — {assignee}: user display name, {days}: days before the event, {title}: event title, {date}: event date (YYYY-MM-DD), {notes}: event notes, {site}: site name, {calendar_url}: calendar URL, {no_reminder_url}: opt-out URL. Keep all placeholder names unchanged. */
		return __(
			"Hello {assignee},\n\nThis is a reminder that you have an event in {days} day(s):\n\nTitle: {title}\nDate: {date}\nNotes: {notes}\n\nView the calendar: {calendar_url}\n\nTo stop reminders for this event: {no_reminder_url}\n\n—\n{site}",
			'rotapress'
		);
	}

	/**
	 * Process daily reminders — called by WP Cron.
	 */
	public function process(): void {
		$days_str = get_option( 'rotapress_reminder_days', '7,3,1' );
		$offsets  = array_filter( array_map( 'intval', explode( ',', (string) $days_str ) ) );

		if ( empty( $offsets ) ) {
			return;
		}

		$subject_tpl = get_option( 'rotapress_email_subject', self::default_subject() );
		$body_tpl    = get_option( 'rotapress_email_body', self::default_body() );

		foreach ( $offsets as $n ) {
			$target_date = gmdate( 'Y-m-d', strtotime( "+{$n} days" ) );

			/* 1. Non-recurring events on this date. */
			$events = get_posts( array(
				'post_type'      => 'rp_event',
				'posts_per_page' => -1,
				'post_status'    => 'publish',
				'meta_query'     => array(
					array(
						'key'     => '_rp_event_date',
						'value'   => $target_date,
						'compare' => '=',
					),
				),
			) );

			foreach ( $events as $event ) {
				if ( 1 === (int) get_post_meta( $event->ID, '_rp_no_reminder', true ) ) {
					continue;
				}

				$rrule = get_post_meta( $event->ID, '_rp_rrule', true );
				if ( '' !== (string) $rrule ) {
					/* Recurring parent — check if target_date is a valid occurrence. */
					$rrule_arr = json_decode( (string) $rrule, true );
					if ( ! is_array( $rrule_arr ) ) {
						continue;
					}
					$parent_date = (string) get_post_meta( $event->ID, '_rp_event_date', true );
					$exdates     = RotaPress_Recurrence::get_exdates( $event->ID );
					$dates       = RotaPress_Recurrence::expand_in_range( $rrule_arr, $parent_date, $target_date, $target_date, $exdates );
					if ( empty( $dates ) ) {
						continue;
					}
				}

				$flag_key = '_rp_reminder_sent_' . $n . '_' . $target_date;
				if ( 1 === (int) get_post_meta( $event->ID, $flag_key, true ) ) {
					continue;
				}

				$this->send_reminder( $event, $n, $target_date, $subject_tpl, $body_tpl );
				update_post_meta( $event->ID, $flag_key, 1 );
			}

			/* 2. Recurring parents that have a virtual occurrence on target_date. */
			$parents = get_posts( array(
				'post_type'      => 'rp_event',
				'posts_per_page' => -1,
				'post_status'    => 'publish',
				'meta_query'     => array(
					array( 'key' => '_rp_rrule', 'compare' => 'EXISTS' ),
					array( 'key' => '_rp_rrule', 'value' => '', 'compare' => '!=' ),
				),
			) );

			foreach ( $parents as $parent ) {
				if ( 1 === (int) get_post_meta( $parent->ID, '_rp_no_reminder', true ) ) {
					continue;
				}

				$parent_date = (string) get_post_meta( $parent->ID, '_rp_event_date', true );
				$rrule_arr   = json_decode( (string) get_post_meta( $parent->ID, '_rp_rrule', true ), true );
				if ( ! is_array( $rrule_arr ) ) { continue; }

				$exdates = RotaPress_Recurrence::get_exdates( $parent->ID );
				$dates   = RotaPress_Recurrence::expand_in_range( $rrule_arr, $parent_date, $target_date, $target_date, $exdates );
				if ( empty( $dates ) ) { continue; }

				$flag_key = '_rp_reminder_sent_' . $n . '_' . $target_date;
				if ( 1 === (int) get_post_meta( $parent->ID, $flag_key, true ) ) {
					continue;
				}

				$this->send_reminder( $parent, $n, $target_date, $subject_tpl, $body_tpl );
				update_post_meta( $parent->ID, $flag_key, 1 );
			}
		}
	}

	/**
	 * Build and send a test reminder for a real event, routing delivery to an
	 * override address instead of the actual assignee.
	 *
	 * A genuine one-click no-reminder token is generated so that {no_reminder_url}
	 * in the test email is fully functional end-to-end.
	 *
	 * @param \WP_Post $event        The event post.
	 * @param string   $event_date   Event date (YYYY-MM-DD).
	 * @param int      $days_before  Days-before value shown in the email.
	 * @param string   $subject_tpl  Subject template.
	 * @param string   $body_tpl     Body template.
	 * @param string   $override_to  Recipient address that receives the test email.
	 * @return bool True when wp_mail() accepted the message.
	 */
	public static function dispatch_test(
		\WP_Post $event,
		string $event_date,
		int $days_before,
		string $subject_tpl,
		string $body_tpl,
		string $override_to
	): bool {
		$uid = (int) get_post_meta( $event->ID, '_rp_assigned_user', true );
		if ( 0 === $uid ) { return false; }

		$user = get_userdata( $uid );
		if ( ! $user ) { return false; }

		$token = wp_generate_password( 32, false, false );
		update_post_meta( $event->ID, '_rp_no_reminder_token', $token );
		$no_reminder_url = add_query_arg(
			array(
				'rp_no_reminder' => '1',
				'rp_event'       => $event->ID,
				'rp_token'       => $token,
			),
			home_url( '/' )
		);

		$replacements = array(
			'{title}'           => get_the_title( $event->ID ) ?: __( '(untitled)', 'rotapress' ),
			'{assignee}'        => $user->display_name,
			'{date}'            => $event_date,
			'{notes}'           => (string) get_post_meta( $event->ID, '_rp_notes', true ),
			'{days}'            => (string) $days_before,
			'{site}'            => get_bloginfo( 'name' ),
			'{calendar_url}'    => admin_url( 'admin.php?page=rotapress' ),
			'{no_reminder_url}' => $no_reminder_url,
		);

		$subject = str_replace( array_keys( $replacements ), array_values( $replacements ), $subject_tpl );
		$body    = str_replace( array_keys( $replacements ), array_values( $replacements ), $body_tpl );

		return wp_mail( $override_to, $subject, $body );
	}

	/**
	 * Send a single reminder email using the template.
	 */
	private function send_reminder( \WP_Post $event, int $days_before, string $event_date, string $subject_tpl, string $body_tpl ): void {
		$uid = (int) get_post_meta( $event->ID, '_rp_assigned_user', true );
		if ( 0 === $uid ) { return; }

		$user = get_userdata( $uid );
		if ( ! $user ) { return; }

		/* Generate a single-use token for the one-click no-reminder link. */
		$token = wp_generate_password( 32, false, false );
		update_post_meta( $event->ID, '_rp_no_reminder_token', $token );
		$no_reminder_url = add_query_arg(
			array(
				'rp_no_reminder' => '1',
				'rp_event'       => $event->ID,
				'rp_token'       => $token,
			),
			home_url( '/' )
		);

		$replacements = array(
			'{title}'           => get_the_title( $event->ID ) ?: __( '(untitled)', 'rotapress' ),
			'{assignee}'        => $user->display_name,
			'{date}'            => $event_date,
			'{notes}'           => (string) get_post_meta( $event->ID, '_rp_notes', true ),
			'{days}'            => (string) $days_before,
			'{site}'            => get_bloginfo( 'name' ),
			'{calendar_url}'    => admin_url( 'admin.php?page=rotapress' ),
			'{no_reminder_url}' => $no_reminder_url,
		);

		$subject = str_replace( array_keys( $replacements ), array_values( $replacements ), $subject_tpl );
		$body    = str_replace( array_keys( $replacements ), array_values( $replacements ), $body_tpl );

		wp_mail( $user->user_email, $subject, $body );
	}

	/**
	 * Handle the one-click no-reminder link from emails.
	 * URL: ?rp_no_reminder=1&rp_event=ID&rp_token=TOKEN
	 */
	public function handle_no_reminder_token(): void {
		if ( empty( $_GET['rp_no_reminder'] ) ) {
			return;
		}

		$event_id = (int) ( $_GET['rp_event'] ?? 0 );
		$token    = sanitize_text_field( (string) ( $_GET['rp_token'] ?? '' ) );

		if ( ! $event_id || ! $token ) {
			wp_die( esc_html__( 'Invalid link.', 'rotapress' ), esc_html__( 'Invalid link', 'rotapress' ), array( 'response' => 400 ) );
		}

		$post = get_post( $event_id );
		if ( ! $post || 'rp_event' !== $post->post_type ) {
			wp_die( esc_html__( 'Event not found.', 'rotapress' ), esc_html__( 'Not found', 'rotapress' ), array( 'response' => 404 ) );
		}

		$stored = (string) get_post_meta( $event_id, '_rp_no_reminder_token', true );
		if ( '' === $stored || ! hash_equals( $stored, $token ) ) {
			wp_die( esc_html__( 'This link has already been used or is invalid.', 'rotapress' ), esc_html__( 'Link expired', 'rotapress' ), array( 'response' => 400 ) );
		}

		/* Valid — disable reminders and invalidate the token. */
		update_post_meta( $event_id, '_rp_no_reminder', 1 );
		delete_post_meta( $event_id, '_rp_no_reminder_token' );

		$event_date = (string) get_post_meta( $event_id, '_rp_event_date', true );

		wp_die(
			'<p>' . esc_html( sprintf(
				/* translators: %1$s: event title, %2$s: event date (YYYY-MM-DD) */
				__( 'Done. You will no longer receive reminders for "%1$s" planned on %2$s.', 'rotapress' ),
				$post->post_title,
				$event_date
			) ) . '</p>',
			esc_html__( 'Reminder cancelled', 'rotapress' ),
			array( 'response' => 200 )
		);
	}
}
