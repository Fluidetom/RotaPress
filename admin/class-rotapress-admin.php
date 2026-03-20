<?php
declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RotaPress_Admin {

	private string $calendar_hook = '';
	private string $settings_hook = '';

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menus' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_post_rotapress_save_settings', array( $this, 'handle_save_settings' ) );
		add_action( 'admin_post_rotapress_restore_event', array( $this, 'handle_restore_event' ) );
		add_action( 'admin_post_rotapress_purge_event', array( $this, 'handle_purge_event' ) );
	}

	public function register_menus(): void {
		$this->calendar_hook = (string) add_menu_page(
			__( 'RotaPress', 'rotapress' ), __( 'RotaPress', 'rotapress' ),
			'rotapress_read', 'rotapress', array( $this, 'render_calendar_page' ),
			'dashicons-calendar-alt', 25
		);
		$this->settings_hook = (string) add_submenu_page(
			'rotapress', __( 'RotaPress Settings', 'rotapress' ), __( 'Settings', 'rotapress' ),
			'rotapress_admin', 'rotapress-settings', array( $this, 'render_settings_page' )
		);
	}

	public function enqueue_assets( string $hook ): void {
		if ( $hook === $this->calendar_hook ) {
			$this->enqueue_calendar_assets();
		} elseif ( $hook === $this->settings_hook ) {
			$this->enqueue_settings_assets();
		}
	}

	private function enqueue_calendar_assets(): void {
		wp_enqueue_script( 'fullcalendar', ROTAPRESS_URL . 'admin/js/fullcalendar-6.1.15.min.js', array(), '6.1.15', true );
		wp_enqueue_style( 'rotapress-calendar', ROTAPRESS_URL . 'admin/css/calendar.css', array(), ROTAPRESS_VERSION );
		wp_enqueue_script( 'rotapress-calendar', ROTAPRESS_URL . 'admin/js/calendar.js', array( 'fullcalendar' ), ROTAPRESS_VERSION, true );

		$can_edit = current_user_can( 'rotapress_edit' ) ? 1 : 0;
		$user_id  = get_current_user_id();

		wp_localize_script( 'rotapress-calendar', 'rotapress', array(
			'api_base'        => esc_url_raw( rest_url( 'rotapress/v1' ) ),
			'nonce'           => wp_create_nonce( 'wp_rest' ),
			'can_edit'        => $can_edit,
			'current_user_id' => $user_id,
			'ical_url'        => esc_url_raw( RotaPress_iCal::get_feed_url( $user_id ) ),
			'has_ical_token'  => RotaPress_iCal::has_token( $user_id ) ? 1 : 0,
			'regen_url'       => esc_url_raw( wp_nonce_url( admin_url( 'admin-post.php?action=rotapress_regen_token' ), 'rotapress_regen_token' ) ),
			'i18n' => array(
				'new_event'          => __( 'New Event', 'rotapress' ),
				'edit_event'         => __( 'Edit Event', 'rotapress' ),
				'title'              => __( 'Title', 'rotapress' ),
				'date'               => __( 'Date', 'rotapress' ),
				'assigned_to'        => __( 'Assigned to', 'rotapress' ),
				'notes'              => __( 'Notes', 'rotapress' ),
				'save'               => __( 'Save', 'rotapress' ),
				'delete'             => __( 'Delete', 'rotapress' ),
				'close'              => __( 'Close', 'rotapress' ),
				'cancel'             => __( 'Cancel', 'rotapress' ),
				'continue'           => __( 'Continue', 'rotapress' ),
				'recurring_event'    => __( 'Recurring event', 'rotapress' ),
				'repeat'             => __( 'Repeat', 'rotapress' ),
				'daily'              => __( 'Daily', 'rotapress' ),
				'weekly'             => __( 'Weekly', 'rotapress' ),
				'monthly'            => __( 'Monthly', 'rotapress' ),
				'every'              => __( 'Every', 'rotapress' ),
				'days'               => __( 'day(s)', 'rotapress' ),
				'weeks'              => __( 'week(s)', 'rotapress' ),
				'months'             => __( 'month(s)', 'rotapress' ),
				'on_days'            => __( 'On days', 'rotapress' ),
				'until'              => __( 'Until', 'rotapress' ),
				'this_event'         => __( 'Selected event(s) only', 'rotapress' ),
				'this_and_following' => __( 'This and following events', 'rotapress' ),
				'all_events'         => __( 'All events in series', 'rotapress' ),
				'edit_recurring'     => __( 'Edit recurring event', 'rotapress' ),
				'delete_recurring'   => __( 'Delete recurring event', 'rotapress' ),
				'my_events_only'     => __( 'My events only', 'rotapress' ),
				'ical_feed'          => __( 'iCal Feed', 'rotapress' ),
				'copy_url'           => __( 'Copy URL', 'rotapress' ),
				'copied'             => __( 'Copied!', 'rotapress' ),
				'regenerate'         => __( 'Regenerate', 'rotapress' ),
				'regen_confirm'      => __( 'Regenerate your iCal token? The old URL will stop working.', 'rotapress' ),
				'ical_no_feed'       => __( 'You do not have an iCal feed URL yet.', 'rotapress' ),
				'ical_generate'      => __( 'Generate feed URL', 'rotapress' ),
				'ical_revoke'        => __( 'Revoke my feed URL', 'rotapress' ),
				'ical_revoke_confirm' => __( 'Revoke your feed URL? Calendar apps using it will stop receiving updates.', 'rotapress' ),
				'ical_private'       => __( 'This URL contains a secret token — keep it private.', 'rotapress' ),
				'ical_note'          => __( 'Your feed shows only events assigned to you.', 'rotapress' ),
				'no_drag_recurring'  => __( 'Drag & drop is not available for recurring events.', 'rotapress' ),
				'select_user'        => __( '— Select —', 'rotapress' ),
				'nobody_available'   => __( 'Nobody available', 'rotapress' ),
				'view_month'         => __( 'Calendar', 'rotapress' ),
				'view_list'          => __( 'List', 'rotapress' ),
				'view_year'          => __( 'Year', 'rotapress' ),
				'reassign_to'        => __( 'Reassign to…', 'rotapress' ),
				'reassign'           => __( 'Reassign', 'rotapress' ),
				'delete_selected'    => __( 'Delete selected', 'rotapress' ),
				/* translators: %d: number of events selected in bulk actions */
				'n_selected'         => __( '%d selected', 'rotapress' ),
				'confirm_delete'     => __( 'Delete this event? It will be moved to the trash.', 'rotapress' ),
				'title_required'      => __( 'Please enter a title for the event.', 'rotapress' ),
			'confirm_no_assignee' => __( 'Add event without any participant?', 'rotapress' ),
				'confirm_bulk_delete' => __( 'Delete all selected events? They will be moved to the trash and can only be restored by an admin.', 'rotapress' ),
				'bulk_recurring_title' => __( 'Selection includes recurring events', 'rotapress' ),
				'bulk_recurring_desc'  => __( 'Do you want to modify only the selected events (which will become standalone, non-recurring events), or all events in the series of the selected events?', 'rotapress' ),
				'today'              => __( 'Today', 'rotapress' ),
				'no_reminder'        => __( 'Disable email reminders for this event', 'rotapress' ),
				'error'              => __( 'An error occurred. Please try again.', 'rotapress' ),
				'mon' => __( 'MO', 'rotapress' ), 'tue' => __( 'TU', 'rotapress' ),
				'wed' => __( 'WE', 'rotapress' ), 'thu' => __( 'TH', 'rotapress' ),
				'fri' => __( 'FR', 'rotapress' ), 'sat' => __( 'SA', 'rotapress' ),
				'sun' => __( 'SU', 'rotapress' ),
			),
		) );
	}

	private function enqueue_settings_assets(): void {
		wp_enqueue_style( 'rotapress-settings', ROTAPRESS_URL . 'admin/css/settings.css', array(), ROTAPRESS_VERSION );
		wp_enqueue_script( 'rotapress-settings', ROTAPRESS_URL . 'admin/js/settings.js', array(), ROTAPRESS_VERSION, true );
		wp_localize_script( 'rotapress-settings', 'rotapress_settings', array(
			'api_base'     => esc_url_raw( rest_url( 'rotapress/v1' ) ),
			'nonce'        => wp_create_nonce( 'wp_rest' ),
			'participants' => $this->get_participants_for_js(),
			'i18n'         => array(
				'sending'    => __( 'Sending…', 'rotapress' ),
				'sent_ok'    => __( 'Test email sent successfully.', 'rotapress' ),
				/* translators: %d: number of test emails sent */
				'sent_ok_n'  => __( '%d test email(s) sent successfully.', 'rotapress' ),
				'no_events'  => __( 'No upcoming published events with an assigned participant were found.', 'rotapress' ),
				'sent_fail'  => __( 'Failed to send. Check your SMTP settings.', 'rotapress' ),
				'remove_modal_title'    => __( 'Upcoming events affected', 'rotapress' ),
				/* translators: 1: participant display name, 2: number of upcoming events */
				'remove_has_events'     => __( 'You are removing %1$s from the rota participants, but this person has %2$d upcoming event(s). What do you want to do?', 'rotapress' ),
				'remove_reassign_label' => __( 'Reassign to:', 'rotapress' ),
				'remove_reassign_btn'   => __( 'Reassign', 'rotapress' ),
				/* translators: %s: participant display name */
				'remove_delete_btn'     => __( 'Delete all future events for %s', 'rotapress' ),
				/* translators: %s: participant display name */
				'remove_delete_confirm' => __( 'Are you sure? This will move all future events for %s to trash.', 'rotapress' ),
				'remove_clear_btn'      => __( 'Clear assignee for all future events', 'rotapress' ),
				/* translators: %s: participant display name */
				'remove_clear_confirm'  => __( 'Are you sure? This will remove the assignee from all future events for %s.', 'rotapress' ),
				'remove_keep_btn'       => __( 'Keep events as-is', 'rotapress' ),
				'remove_series_note'    => __( 'Note: recurring series will be fully affected (all occurrences).', 'rotapress' ),
				'remove_processing'     => __( 'Processing…', 'rotapress' ),
			),
		) );
	}

	private function get_participants_for_js(): array {
		$participants = get_option( 'rotapress_participants', array() );
		if ( ! is_array( $participants ) ) { return array(); }
		$result = array();
		foreach ( array_keys( array_filter( $participants ) ) as $uid ) {
			$uid  = (int) $uid;
			$user = get_userdata( $uid );
			if ( $user ) { $result[] = array( 'id' => $uid, 'name' => $user->display_name ); }
		}
		return $result;
	}

	/* ── Calendar page ─────────────────────────────────────────────── */

	public function render_calendar_page(): void {
		?>
		<div class="wrap rotapress-wrap">
			<div class="rp-header">
				<h1><?php esc_html_e( 'RotaPress', 'rotapress' ); ?></h1>
				<div class="rp-toolbar">
					<?php if ( current_user_can( 'rotapress_edit' ) ) : ?>
						<button type="button" class="button button-primary" id="rp-add-event">
							<?php esc_html_e( 'New Event', 'rotapress' ); ?>
						</button>
					<?php endif; ?>
					<select id="rp-filter-participant" class="rp-filter-select">
						<option value="all"><?php esc_html_e( 'All events', 'rotapress' ); ?></option>
						<option value="mine"><?php esc_html_e( 'My events only', 'rotapress' ); ?></option>
						<!-- participant options injected by JS after loadUsers() -->
						<option value="unassigned"><?php esc_html_e( 'Unassigned events', 'rotapress' ); ?></option>
					</select>
					<button type="button" class="button rp-ical-toggle" id="rp-ical-toggle" aria-label="<?php esc_attr_e( 'iCal Feed', 'rotapress' ); ?>">
						<span class="dashicons dashicons-rss"></span>
					</button>
				</div>
			</div>

			<div id="rp-list-scope-toggle" class="rp-list-scope-toggle" style="display:none">
				<button type="button" class="button rp-scope-btn" id="rp-scope-year"><?php esc_html_e( 'Year', 'rotapress' ); ?></button>
				<button type="button" class="button rp-scope-btn rp-scope-active" id="rp-scope-month"><?php esc_html_e( 'Month', 'rotapress' ); ?></button>
				<button type="button" class="button rp-scope-btn" id="rp-scope-today"><?php esc_html_e( 'Today', 'rotapress' ); ?></button>
			</div>

			<?php if ( current_user_can( 'rotapress_edit' ) ) : ?>
			<div id="rp-bulk-bar" class="rp-bulk-bar" style="display:none">
				<label class="rp-select-all-label"><input type="checkbox" id="rp-select-all"> <?php esc_html_e( 'All', 'rotapress' ); ?></label>
				<span id="rp-bulk-count"></span>
				<select id="rp-bulk-user"><option value=""><?php esc_html_e( 'Reassign to…', 'rotapress' ); ?></option></select>
				<button type="button" class="button" id="rp-bulk-reassign"><?php esc_html_e( 'Reassign', 'rotapress' ); ?></button>
				<button type="button" class="button rp-btn-delete" id="rp-bulk-delete"><?php esc_html_e( 'Delete selected', 'rotapress' ); ?></button>
				<button type="button" class="button" id="rp-bulk-cancel"><?php esc_html_e( 'Cancel', 'rotapress' ); ?></button>
			</div>
			<?php endif; ?>

			<div id="rotapress-calendar"></div>

			<!-- Event modal -->
			<div id="rp-modal" class="rp-modal-overlay" style="display:none" role="dialog" aria-modal="true" aria-labelledby="rp-modal-title">
				<div class="rp-modal-box">
					<h2 id="rp-modal-title"></h2>
					<input type="hidden" id="rp-event-id" value="">
					<input type="hidden" id="rp-is-recurring" value="0">
					<p><label for="rp-title"><?php esc_html_e( 'Title', 'rotapress' ); ?></label><br>
					<input type="text" id="rp-title" class="regular-text" style="width:100%" required></p>
					<p><label for="rp-date"><?php esc_html_e( 'Date', 'rotapress' ); ?></label><br>
					<input type="date" id="rp-date" class="regular-text"></p>
					<p><label for="rp-user"><?php esc_html_e( 'Assigned to', 'rotapress' ); ?></label><br>
					<select id="rp-user" style="width:100%"></select></p>
					<p><label for="rp-notes"><?php esc_html_e( 'Notes', 'rotapress' ); ?></label><br>
					<textarea id="rp-notes" rows="2" style="width:100%"></textarea></p>

					<div id="rp-recurrence-section" style="display:none">
						<hr>
						<p><label><input type="checkbox" id="rp-recurring-check"> <?php esc_html_e( 'Recurring event', 'rotapress' ); ?></label></p>
						<div id="rp-recurrence-fields" style="display:none">
							<p><label for="rp-freq"><?php esc_html_e( 'Repeat', 'rotapress' ); ?></label>
							<select id="rp-freq">
								<option value="daily"><?php esc_html_e( 'Daily', 'rotapress' ); ?></option>
								<option value="weekly"><?php esc_html_e( 'Weekly', 'rotapress' ); ?></option>
								<option value="monthly"><?php esc_html_e( 'Monthly', 'rotapress' ); ?></option>
							</select></p>
							<p><label for="rp-interval"><?php esc_html_e( 'Every', 'rotapress' ); ?></label>
							<input type="number" id="rp-interval" min="1" value="1" style="width:60px">
							<span id="rp-interval-label"><?php esc_html_e( 'day(s)', 'rotapress' ); ?></span></p>
							<div id="rp-byday-row" style="display:none">
								<label><?php esc_html_e( 'On days', 'rotapress' ); ?></label>
								<div class="rp-day-checkboxes">
									<?php foreach ( array( 'MO', 'TU', 'WE', 'TH', 'FR', 'SA', 'SU' ) as $code ) : ?>
										<label class="rp-day-chip"><input type="checkbox" name="rp-byday" value="<?php echo esc_attr( $code ); ?>"> <?php echo esc_html( $code ); ?></label>
									<?php endforeach; ?>
								</div>
							</div>
							<p><label for="rp-until"><?php esc_html_e( 'Until', 'rotapress' ); ?></label><br>
							<input type="date" id="rp-until"></p>
						</div>
					</div>

					<p id="rp-no-reminder-row" style="display:none"><label>
						<input type="checkbox" id="rp-no-reminder">
						<?php esc_html_e( 'Disable email reminders for this event', 'rotapress' ); ?>
					</label></p>

					<div class="rp-modal-buttons">
						<button type="button" class="button button-primary" id="rp-save"><?php esc_html_e( 'Save', 'rotapress' ); ?></button>
						<button type="button" class="button rp-btn-delete" id="rp-delete" style="display:none"><?php esc_html_e( 'Delete', 'rotapress' ); ?></button>
						<button type="button" class="button" id="rp-close"><?php esc_html_e( 'Close', 'rotapress' ); ?></button>
					</div>
				</div>
			</div>

			<!-- Scope dialog -->
			<div id="rp-scope-dialog" class="rp-modal-overlay" style="display:none" role="dialog" aria-modal="true" aria-labelledby="rp-scope-title">
				<div class="rp-modal-box rp-modal-narrow">
					<h2 id="rp-scope-title"></h2>
					<p id="rp-scope-description" class="description"></p>
					<div class="rp-scope-option">
						<label><input type="radio" name="rp-scope" value="this" checked> <?php esc_html_e( 'Selected event(s) only', 'rotapress' ); ?></label>
						<span class="rp-scope-hint"><?php esc_html_e( 'This event will become a standalone, non-recurring event.', 'rotapress' ); ?></span>
					</div>
					<div class="rp-scope-option">
						<label><input type="radio" name="rp-scope" value="following"> <?php esc_html_e( 'This and following events', 'rotapress' ); ?></label>
						<span class="rp-scope-hint"><?php esc_html_e( 'A new separate recurring series will be created from this date onward.', 'rotapress' ); ?></span>
					</div>
					<div class="rp-scope-option">
						<label><input type="radio" name="rp-scope" value="all"> <?php esc_html_e( 'All events in series', 'rotapress' ); ?></label>
						<span class="rp-scope-hint"><?php esc_html_e( 'All occurrences will be updated. You can also change the recurrence rule.', 'rotapress' ); ?></span>
					</div>
					<div class="rp-modal-buttons">
						<button type="button" class="button button-primary" id="rp-scope-continue"><?php esc_html_e( 'Continue', 'rotapress' ); ?></button>
						<button type="button" class="button" id="rp-scope-cancel"><?php esc_html_e( 'Cancel', 'rotapress' ); ?></button>
					</div>
				</div>
			</div>

			<!-- iCal panel -->
			<div id="rp-ical-panel" class="rp-modal-overlay" style="display:none">
				<div class="rp-modal-box rp-modal-narrow">
					<h2><?php esc_html_e( 'iCal Feed', 'rotapress' ); ?></h2>

					<!-- No feed state -->
					<div id="rp-ical-no-feed" style="display:none">
						<p><?php esc_html_e( 'You do not have an iCal feed URL yet.', 'rotapress' ); ?></p>
						<p><?php esc_html_e( 'Your feed shows only events assigned to you.', 'rotapress' ); ?></p>
						<div class="rp-modal-buttons">
							<button type="button" class="button button-primary" id="rp-ical-generate"><?php esc_html_e( 'Generate feed URL', 'rotapress' ); ?></button>
							<button type="button" class="button" id="rp-ical-close-nofeed"><?php esc_html_e( 'Close', 'rotapress' ); ?></button>
						</div>
					</div>

					<!-- Has feed state -->
					<div id="rp-ical-has-feed" style="display:none">
						<p><?php esc_html_e( 'This URL contains a secret token — keep it private.', 'rotapress' ); ?></p>
						<div class="rp-ical-url-row">
							<input type="text" id="rp-ical-url" readonly>
							<button type="button" class="button" id="rp-copy-url"><?php esc_html_e( 'Copy URL', 'rotapress' ); ?></button>
						</div>
						<p class="description"><?php esc_html_e( 'Your feed shows only events assigned to you.', 'rotapress' ); ?></p>
						<div class="rp-modal-buttons">
							<button type="button" class="button" id="rp-ical-regen"><?php esc_html_e( 'Regenerate', 'rotapress' ); ?></button>
							<button type="button" class="button rp-btn-delete" id="rp-ical-revoke"><?php esc_html_e( 'Revoke my feed URL', 'rotapress' ); ?></button>
							<button type="button" class="button" id="rp-ical-close"><?php esc_html_e( 'Close', 'rotapress' ); ?></button>
						</div>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/* ── Settings page ─────────────────────────────────────────────── */

	public function render_settings_page(): void {
		$mapping       = RotaPress_Roles::get_mapping();
		$participants  = get_option( 'rotapress_participants', array() );
		$colors        = get_option( 'rotapress_user_colors', array() );
		$reminder_days = get_option( 'rotapress_reminder_days', '7,3,1' );
		$keep_data     = get_option( 'rotapress_keep_data', '0' );
		$email_subject = get_option( 'rotapress_email_subject', RotaPress_Reminders::default_subject() );
		$email_body    = get_option( 'rotapress_email_body', RotaPress_Reminders::default_body() );
		$wp_roles      = wp_roles()->get_names();
		$all_users     = get_users( array( 'orderby' => 'display_name', 'order' => 'ASC' ) );
		$next_cron     = wp_next_scheduled( 'rotapress_daily_reminders' );
		$active_tokens = RotaPress_iCal::get_all_active_tokens();

		if ( ! is_array( $participants ) ) { $participants = array(); }
		if ( ! is_array( $colors ) ) { $colors = array(); }

		$palette = array( '#2271b1', '#d63638', '#00a32a', '#dba617', '#3858e9', '#b32d2e', '#007017', '#996800', '#9b59b6', '#1abc9c', '#e67e22', '#34495e' );

		$saved = isset( $_GET['saved'] ) && '1' === $_GET['saved']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- redirect status flag, not form data
		$revoked = isset( $_GET['token_revoked'] ) && '1' === $_GET['token_revoked']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- redirect status flag, not form data
		$restored = isset( $_GET['restored'] ) && '1' === $_GET['restored']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- redirect status flag, not form data
		$purged = isset( $_GET['purged'] ) && '1' === $_GET['purged']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- redirect status flag, not form data
		?>
		<div class="wrap rotapress-wrap">
			<h1><?php esc_html_e( 'RotaPress Settings', 'rotapress' ); ?></h1>
			<?php if ( $saved ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'rotapress' ); ?></p></div>
			<?php endif; ?>
			<?php if ( $revoked ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Token revoked.', 'rotapress' ); ?></p></div>
			<?php endif; ?>
			<?php if ( $restored ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Event restored from trash.', 'rotapress' ); ?></p></div>
			<?php endif; ?>
			<?php if ( $purged ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Event permanently deleted.', 'rotapress' ); ?></p></div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="rotapress_save_settings">
				<?php wp_nonce_field( 'rotapress_save_settings', 'rotapress_settings_nonce' ); ?>

				<!-- Role Mapping (radio per WP role) -->
				<h2><?php esc_html_e( 'Role Mapping', 'rotapress' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Assign one RotaPress permission level per WordPress role.', 'rotapress' ); ?></p>
				<table class="widefat" style="max-width:600px">
					<thead><tr>
						<th><?php esc_html_e( 'WordPress Role', 'rotapress' ); ?></th>
						<th style="text-align:center"><?php esc_html_e( 'Admin', 'rotapress' ); ?></th>
						<th style="text-align:center"><?php esc_html_e( 'Edit', 'rotapress' ); ?></th>
						<th style="text-align:center"><?php esc_html_e( 'Read', 'rotapress' ); ?></th>
						<th style="text-align:center"><?php esc_html_e( 'None', 'rotapress' ); ?></th>
					</tr></thead>
					<tbody>
						<?php foreach ( $wp_roles as $role_slug => $role_name ) :
							$current_level = '';
							foreach ( array( 'admin', 'edit', 'read' ) as $lvl ) {
								if ( in_array( $role_slug, $mapping[ $lvl ] ?? array(), true ) ) { $current_level = $lvl; break; }
							}
						?>
							<tr>
								<td><strong><?php echo esc_html( translate_user_role( $role_name ) ); ?></strong></td>
								<?php foreach ( array( 'admin', 'edit', 'read' ) as $lvl ) : ?>
									<td style="text-align:center"><input type="radio" name="rotapress_role_for[<?php echo esc_attr( $role_slug ); ?>]" value="<?php echo esc_attr( $lvl ); ?>" <?php checked( $current_level, $lvl ); ?>></td>
								<?php endforeach; ?>
								<td style="text-align:center"><input type="radio" name="rotapress_role_for[<?php echo esc_attr( $role_slug ); ?>]" value="" <?php checked( $current_level, '' ); ?>></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<!-- Participants -->
				<h2><?php esc_html_e( 'Rota Participants', 'rotapress' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Select which users appear in the calendar and assign their colour.', 'rotapress' ); ?></p>
				<div class="rp-participants-list">
					<?php foreach ( $all_users as $u ) :
						$uid = $u->ID; $active = ! empty( $participants[ $uid ] );
						$color_val = $colors[ $uid ] ?? $palette[ $uid % count( $palette ) ];
					?>
						<div class="rp-participant-row">
							<label><input type="checkbox" name="rotapress_participants[<?php echo esc_attr( (string) $uid ); ?>]" value="1" <?php checked( $active ); ?>> <?php echo esc_html( $u->display_name ); ?> <span class="description">(<?php echo esc_html( $u->user_email ); ?>)</span></label>
							<span class="rp-color-swatch" style="background:<?php echo esc_attr( $color_val ); ?>"></span>
							<input type="color" class="rp-color-picker" name="rotapress_colors[<?php echo esc_attr( (string) $uid ); ?>]" value="<?php echo esc_attr( $color_val ); ?>">
						</div>
					<?php endforeach; ?>
				</div>

				<!-- Reminder Notifications -->
				<h2><?php esc_html_e( 'Reminder Notifications', 'rotapress' ); ?></h2>
				<p><label for="rp-reminder-days"><?php esc_html_e( 'Days before event', 'rotapress' ); ?></label><br>
				<input type="text" id="rp-reminder-days" name="rotapress_reminder_days" value="<?php echo esc_attr( (string) $reminder_days ); ?>" class="regular-text"></p>
				<p class="description"><?php esc_html_e( 'Comma-separated list of days, e.g. 7,3,1', 'rotapress' ); ?></p>

				<!-- Email Template -->
				<h2><?php esc_html_e( 'Reminder email template', 'rotapress' ); ?></h2>
				<p class="description"><?php
					printf(
						/* translators: %s: list of available placeholder codes */
						esc_html__( 'Available placeholders: %s', 'rotapress' ),
						'<code>{title}</code>, <code>{assignee}</code>, <code>{date}</code>, <code>{notes}</code>, <code>{days}</code>, <code>{site}</code>, <code>{calendar_url}</code>, <code>{no_reminder_url}</code>'
					);
				?></p>
				<p><label for="rp-email-subject"><?php esc_html_e( 'Subject', 'rotapress' ); ?></label><br>
				<textarea id="rp-email-subject" name="rotapress_email_subject" rows="1" class="large-text"><?php echo esc_textarea( $email_subject ); ?></textarea></p>
				<p><label for="rp-email-body"><?php esc_html_e( 'Body', 'rotapress' ); ?></label><br>
				<textarea id="rp-email-body" name="rotapress_email_body" rows="10" class="large-text"><?php echo esc_textarea( $email_body ); ?></textarea></p>

				<!-- Test Email -->
				<div class="rp-test-email-box">
					<h3><?php esc_html_e( 'Test Email', 'rotapress' ); ?></h3>
					<p class="description"><?php esc_html_e( 'Use this tool to verify your email template and delivery without waiting for the daily reminder. "Dummy" mode fills all placeholders with sample data so you can preview the template. "Real event" mode fetches actual upcoming published events, sends genuine reminder emails — including working opt-out links — and redirects delivery to your test address instead of the real assignees.', 'rotapress' ); ?></p>
					<p>
						<label for="rp-test-email-addr"><?php esc_html_e( 'Test recipient', 'rotapress' ); ?></label><br>
						<input type="email" id="rp-test-email-addr" placeholder="<?php esc_attr_e( 'recipient@example.com', 'rotapress' ); ?>" class="regular-text">
					</p>
					<p>
						<label for="rp-test-email-mode"><?php esc_html_e( 'Mode', 'rotapress' ); ?></label><br>
						<select id="rp-test-email-mode">
							<option value="dummy"><?php esc_html_e( 'Dummy data (template preview)', 'rotapress' ); ?></option>
							<option value="1"><?php esc_html_e( 'Next 1 real event', 'rotapress' ); ?></option>
							<option value="2"><?php esc_html_e( 'Next 2 real events', 'rotapress' ); ?></option>
							<option value="3"><?php esc_html_e( 'Next 3 real events', 'rotapress' ); ?></option>
						</select>
						<button type="button" class="button" id="rp-test-email-btn" style="margin-left:8px"><?php esc_html_e( 'Send test', 'rotapress' ); ?></button>
						<span id="rp-test-email-status"></span>
					</p>
				</div>
				<p class="description">&#x2139;&#xFE0F; <?php esc_html_e( 'RotaPress sends email via wp_mail(). For reliable delivery, install an SMTP plugin such as FluentSMTP, WP Mail SMTP, or Post SMTP.', 'rotapress' ); ?></p>

				<!-- Cron Status -->
				<h2><?php esc_html_e( 'Cron Status', 'rotapress' ); ?></h2>
				<?php if ( $next_cron ) : ?>
					<p>
						<?php
						/* translators: %s: date and time of next scheduled cron run */
						printf( esc_html__( 'Next scheduled reminder run: %s', 'rotapress' ), esc_html( wp_date( 'Y-m-d H:i:s', $next_cron ) ) ); ?>
					</p>
				<?php else : ?>
					<p><?php esc_html_e( 'No cron event scheduled. Deactivate and reactivate the plugin to reschedule.', 'rotapress' ); ?></p>
				<?php endif; ?>
				<p class="description"><?php esc_html_e( 'WordPress cron runs on any page visit to your site (front-end or admin). For reliable scheduling, configure a real server cron job that calls wp-cron.php every few minutes.', 'rotapress' ); ?></p>

				<!-- Data Retention -->
				<h2><?php esc_html_e( 'Data Retention', 'rotapress' ); ?></h2>
				<p><label><input type="checkbox" name="rotapress_keep_data" value="1" <?php checked( '1', $keep_data ); ?>> <?php esc_html_e( 'Keep all RotaPress data when the plugin is deleted', 'rotapress' ); ?></label></p>

				<?php submit_button(); ?>
			</form>

			<hr style="margin: 30px 0;">

			<!-- Active iCal Feeds (outside form — has its own actions) -->
			<h2><?php esc_html_e( 'Active iCal Feeds', 'rotapress' ); ?></h2>
			<p class="description"><?php esc_html_e( 'All users with an active iCal feed token. Revoke a token to immediately disable the feed URL.', 'rotapress' ); ?></p>
			<?php if ( empty( $active_tokens ) ) : ?>
				<p><?php esc_html_e( 'No active feed tokens.', 'rotapress' ); ?></p>
			<?php else : ?>
				<table class="widefat" style="max-width:700px">
					<thead><tr>
						<th><?php esc_html_e( 'User', 'rotapress' ); ?></th>
						<th><?php esc_html_e( 'Email', 'rotapress' ); ?></th>
						<th><?php esc_html_e( 'Created', 'rotapress' ); ?></th>
						<th></th>
					</tr></thead>
					<tbody>
						<?php foreach ( $active_tokens as $tok ) : ?>
							<tr>
								<td><?php echo esc_html( $tok['display_name'] ); ?></td>
								<td><?php echo esc_html( $tok['user_email'] ); ?></td>
								<td><?php echo esc_html( $tok['created'] ); ?></td>
								<td>
									<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=rotapress_revoke_token&user_id=' . $tok['user_id'] ), 'rotapress_revoke_token' ) ); ?>"
									   class="button button-small rp-btn-delete"
									   onclick="return confirm('<?php echo esc_js( __( 'Revoke this token? The feed URL will stop working immediately.', 'rotapress' ) ); ?>');">
										<?php esc_html_e( 'Revoke', 'rotapress' ); ?>
									</a>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<hr style="margin: 30px 0;">

			<!-- Trash (soft-deleted events) -->
			<h2><?php esc_html_e( 'Trash', 'rotapress' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Deleted events are kept in the trash for 30 days. You can restore them here.', 'rotapress' ); ?></p>
			<?php
			$trash_page  = max( 1, absint( wp_unslash( $_GET['trash_page'] ?? 1 ) ) ); // phpcs:ignore WordPress.Security.NonceVerification
			$per_page    = 20;
			$trash_query = new \WP_Query( array(
				'post_type'      => 'rp_event',
				'post_status'    => 'trash',
				'posts_per_page' => $per_page,
				'paged'          => $trash_page,
				'orderby'        => 'modified',
				'order'          => 'DESC',
			) );
			$total_trash = $trash_query->found_posts;
			$total_pages = $trash_query->max_num_pages;
			?>
			<?php if ( 0 === $total_trash ) : ?>
				<p><?php esc_html_e( 'Trash is empty.', 'rotapress' ); ?></p>
			<?php else : ?>
				<p>
					<?php
					printf(
						/* translators: %d: number of events in trash */
						esc_html__( '%d event(s) in trash.', 'rotapress' ),
						absint( $total_trash )
					);
					?>
				</p>
				<table class="widefat" style="max-width:900px">
					<thead><tr>
						<th><?php esc_html_e( 'Title', 'rotapress' ); ?></th>
						<th><?php esc_html_e( 'Event date', 'rotapress' ); ?></th>
						<th><?php esc_html_e( 'Deleted by', 'rotapress' ); ?></th>
						<th><?php esc_html_e( 'Deleted on', 'rotapress' ); ?></th>
						<th></th>
					</tr></thead>
					<tbody>
						<?php foreach ( $trash_query->posts as $tp ) :
							$deleted_by_id   = (int) get_post_meta( $tp->ID, '_rp_deleted_by', true );
							$deleted_by_name = __( 'Unknown', 'rotapress' );
							if ( $deleted_by_id > 0 ) {
								$del_user = get_userdata( $deleted_by_id );
								if ( $del_user ) {
									$deleted_by_name = $del_user->display_name;
								}
							}
						?>
							<tr>
								<td><?php echo esc_html( $tp->post_title ); ?></td>
								<td><?php echo esc_html( (string) get_post_meta( $tp->ID, '_rp_event_date', true ) ); ?></td>
								<td><?php echo esc_html( $deleted_by_name ); ?></td>
								<td><?php echo esc_html( $tp->post_modified ); ?></td>
								<td>
									<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=rotapress_restore_event&event_id=' . $tp->ID ), 'rotapress_restore_event' ) ); ?>"
									   class="button button-small">
										<?php esc_html_e( 'Restore', 'rotapress' ); ?>
									</a>
									<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=rotapress_purge_event&event_id=' . $tp->ID ), 'rotapress_purge_event' ) ); ?>"
									   class="button button-small rp-btn-delete"
									   onclick="return confirm('<?php echo esc_js( __( 'Permanently delete this event? This cannot be undone.', 'rotapress' ) ); ?>');">
										<?php esc_html_e( 'Delete permanently', 'rotapress' ); ?>
									</a>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<?php if ( $total_pages > 1 ) : ?>
					<p class="rp-trash-pagination">
						<?php for ( $i = 1; $i <= $total_pages; $i++ ) : ?>
							<?php if ( $i === $trash_page ) : ?>
								<strong><?php echo esc_html( (string) $i ); ?></strong>
							<?php else : ?>
								<a href="<?php echo esc_url( admin_url( 'admin.php?page=rotapress-settings&trash_page=' . $i ) ); ?>"><?php echo esc_html( (string) $i ); ?></a>
							<?php endif; ?>
						<?php endfor; ?>
					</p>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/* ── Save settings ─────────────────────────────────────────────── */

	public function handle_save_settings(): void {
		check_admin_referer( 'rotapress_save_settings', 'rotapress_settings_nonce' );
		if ( ! current_user_can( 'rotapress_admin' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'rotapress' ) );
		}

		/* Role mapping (radio per WP role). */
		$raw_roles = isset( $_POST['rotapress_role_for'] ) && is_array( $_POST['rotapress_role_for'] ) ? wp_unslash( $_POST['rotapress_role_for'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each value sanitized via sanitize_text_field() in the loop below
		$mapping   = array( 'admin' => array(), 'edit' => array(), 'read' => array() );
		foreach ( $raw_roles as $wp_role => $rp_level ) {
			$rp_level = sanitize_text_field( $rp_level );
			$wp_role  = sanitize_text_field( $wp_role );
			if ( '' !== $rp_level && isset( $mapping[ $rp_level ] ) ) {
				$mapping[ $rp_level ][] = $wp_role;
			}
		}
		update_option( 'rotapress_role_mapping', $mapping );

		/* Participants. */
		$raw_p = isset( $_POST['rotapress_participants'] ) && is_array( $_POST['rotapress_participants'] ) ? wp_unslash( $_POST['rotapress_participants'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- keys cast to int in the loop below
		$participants = array();
		foreach ( $raw_p as $uid => $val ) { $participants[ (int) $uid ] = 1; }
		update_option( 'rotapress_participants', $participants );

		/* Colors. */
		$raw_c = isset( $_POST['rotapress_colors'] ) && is_array( $_POST['rotapress_colors'] ) ? wp_unslash( $_POST['rotapress_colors'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each value sanitized via sanitize_hex_color() in the loop below
		$colors = array();
		foreach ( $raw_c as $uid => $hex ) {
			$s = sanitize_hex_color( $hex );
			if ( $s ) { $colors[ (int) $uid ] = $s; }
		}
		update_option( 'rotapress_user_colors', $colors );

		/* Reminder days. */
		update_option( 'rotapress_reminder_days', sanitize_text_field( wp_unslash( $_POST['rotapress_reminder_days'] ?? '7,3,1' ) ) );

		/* Email template — wp_unslash() before sanitizing because WordPress
		 * applies addslashes() to all $_POST data (wp_magic_quotes), which
		 * would otherwise compound backslashes on every save. */
		update_option( 'rotapress_email_subject', sanitize_text_field( wp_unslash( $_POST['rotapress_email_subject'] ?? RotaPress_Reminders::default_subject() ) ) );
		update_option( 'rotapress_email_body', sanitize_textarea_field( wp_unslash( $_POST['rotapress_email_body'] ?? RotaPress_Reminders::default_body() ) ) );

		/* Keep data. */
		update_option( 'rotapress_keep_data', isset( $_POST['rotapress_keep_data'] ) ? '1' : '0' );

		wp_safe_redirect( admin_url( 'admin.php?page=rotapress-settings&saved=1' ) );
		exit;
	}

	/**
	 * Restore an event from trash.
	 */
	public function handle_restore_event(): void {
		check_admin_referer( 'rotapress_restore_event' );
		if ( ! current_user_can( 'rotapress_admin' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'rotapress' ) );
		}
		$event_id = absint( wp_unslash( $_GET['event_id'] ?? 0 ) );
		if ( $event_id > 0 ) {
			wp_untrash_post( $event_id );
			/* wp_untrash_post restores to the pre-trash status which may be
			 * 'draft'. Force back to 'publish' so the event reappears. */
			wp_update_post( array( 'ID' => $event_id, 'post_status' => 'publish' ) );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=rotapress-settings&restored=1' ) );
		exit;
	}

	/**
	 * Permanently delete an event from trash.
	 */
	public function handle_purge_event(): void {
		check_admin_referer( 'rotapress_purge_event' );
		if ( ! current_user_can( 'rotapress_admin' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'rotapress' ) );
		}
		$event_id = absint( wp_unslash( $_GET['event_id'] ?? 0 ) );
		if ( $event_id > 0 ) {
			wp_delete_post( $event_id, true );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=rotapress-settings&purged=1' ) );
		exit;
	}
}
