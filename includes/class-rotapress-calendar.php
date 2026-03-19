<?php
declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST API for RotaPress events, users, and bulk actions.
 *
 * Virtual recurrence model:
 *   - Only parent events with _rp_rrule are stored in wp_posts.
 *   - GET /events expands rrules on the fly, returning virtual instances
 *     with synthetic IDs like "{parent_id}_20250715".
 *   - Exception events (edited single instances) are real posts with
 *     _rp_exception_for = parent_id and _rp_exception_date = YYYY-MM-DD.
 *   - The parent's _rp_exdates JSON array tracks dates that have been
 *     detached, deleted, or replaced by exceptions.
 *
 * @package RotaPress
 */
class RotaPress_Calendar {

	private const PALETTE = array(
		'#2271b1', '#d63638', '#00a32a', '#dba617',
		'#3858e9', '#b32d2e', '#007017', '#996800',
		'#9b59b6', '#1abc9c', '#e67e22', '#34495e',
	);

	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_action( 'wp_trash_post', array( $this, 'stamp_deleted_by' ) );
	}

	/**
	 * Record who trashed an rp_event so it shows in the Trash table.
	 */
	public function stamp_deleted_by( int $post_id ): void {
		if ( 'rp_event' !== get_post_type( $post_id ) ) {
			return;
		}
		update_post_meta( $post_id, '_rp_deleted_by', get_current_user_id() );
	}

	public function register_routes(): void {
		register_rest_route( 'rotapress/v1', '/events', array(
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => array( $this, 'get_events' ),
			'permission_callback' => function (): bool { return current_user_can( 'rotapress_read' ); },
			'args' => array(
				'start' => array( 'required' => true, 'validate_callback' => array( $this, 'validate_date' ), 'sanitize_callback' => 'sanitize_text_field' ),
				'end'   => array( 'required' => true, 'validate_callback' => array( $this, 'validate_date' ), 'sanitize_callback' => 'sanitize_text_field' ),
			),
		) );

		register_rest_route( 'rotapress/v1', '/events', array(
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'create_event' ),
			'permission_callback' => function (): bool { return current_user_can( 'rotapress_edit' ); },
		) );

		/* Bulk must be registered BEFORE the wildcard (?P<id>) route. */
		register_rest_route( 'rotapress/v1', '/events/bulk', array(
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'bulk_action' ),
			'permission_callback' => function (): bool { return current_user_can( 'rotapress_edit' ); },
		) );

		register_rest_route( 'rotapress/v1', '/events/(?P<id>[a-zA-Z0-9_]+)', array(
			'methods'             => \WP_REST_Server::EDITABLE,
			'callback'            => array( $this, 'update_event' ),
			'permission_callback' => function (): bool { return current_user_can( 'rotapress_edit' ); },
		) );

		register_rest_route( 'rotapress/v1', '/events/(?P<id>[a-zA-Z0-9_]+)', array(
			'methods'             => \WP_REST_Server::DELETABLE,
			'callback'            => array( $this, 'delete_event' ),
			'permission_callback' => function (): bool { return current_user_can( 'rotapress_edit' ); },
		) );

		register_rest_route( 'rotapress/v1', '/users', array(
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => array( $this, 'get_users' ),
			'permission_callback' => function (): bool { return current_user_can( 'rotapress_read' ); },
		) );

		register_rest_route( 'rotapress/v1', '/test-email', array(
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'send_test_email' ),
			'permission_callback' => function (): bool { return current_user_can( 'rotapress_admin' ); },
		) );

		register_rest_route( 'rotapress/v1', '/trash', array(
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => array( $this, 'get_trash' ),
			'permission_callback' => function (): bool { return current_user_can( 'rotapress_admin' ); },
		) );

		register_rest_route( 'rotapress/v1', '/trash/restore/(?P<id>\d+)', array(
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'restore_from_trash' ),
			'permission_callback' => function (): bool { return current_user_can( 'rotapress_admin' ); },
		) );

		register_rest_route( 'rotapress/v1', '/trash/purge/(?P<id>\d+)', array(
			'methods'             => \WP_REST_Server::DELETABLE,
			'callback'            => array( $this, 'purge_from_trash' ),
			'permission_callback' => function (): bool { return current_user_can( 'rotapress_admin' ); },
		) );
	}

	/* ── Helpers: parse virtual IDs ───────────────────────────────── */

	/**
	 * A virtual ID is "{parent_id}_{YYYYMMDD}" for recurring instances.
	 * A real ID is just a numeric post ID.
	 */
	private static function parse_event_id( string $id ): array {
		if ( preg_match( '/^(\d+)_(\d{8})$/', $id, $m ) ) {
			return array(
				'type'      => 'virtual',
				'parent_id' => (int) $m[1],
				'date'      => substr( $m[2], 0, 4 ) . '-' . substr( $m[2], 4, 2 ) . '-' . substr( $m[2], 6, 2 ),
			);
		}
		return array(
			'type'    => 'real',
			'post_id' => (int) $id,
		);
	}

	/* ── GET /events ──────────────────────────────────────────────── */

	public function get_events( \WP_REST_Request $request ): \WP_REST_Response {
		$start = sanitize_text_field( $request->get_param( 'start' ) );
		$end   = sanitize_text_field( $request->get_param( 'end' ) );

		$events = array();

		/* 1. Non-recurring events + exception events in range. */
		$query = new \WP_Query( array(
			'post_type'      => 'rp_event',
			'posts_per_page' => 1000,
			'post_status'    => 'publish',
			'meta_query'     => array(
				array(
					'key'     => '_rp_event_date',
					'value'   => array( $start, $end ),
					'compare' => 'BETWEEN',
					'type'    => 'DATE',
				),
			),
		) );

		foreach ( $query->posts as $post ) {
			$rrule_json = (string) get_post_meta( $post->ID, '_rp_rrule', true );
			if ( '' !== $rrule_json ) {
				/* This is a recurring parent — skip, we expand below. */
				continue;
			}
			$events[] = $this->format_real_event( $post );
		}

		/* 2. Recurring parents: expand rrule virtually. */
		$parents = get_posts( array(
			'post_type'      => 'rp_event',
			'posts_per_page' => -1,
			'post_status'    => 'publish',
			'meta_query'     => array(
				array(
					'key'     => '_rp_rrule',
					'compare' => 'EXISTS',
				),
				array(
					'key'     => '_rp_rrule',
					'value'   => '',
					'compare' => '!=',
				),
			),
		) );

		foreach ( $parents as $parent ) {
			$rrule = json_decode( (string) get_post_meta( $parent->ID, '_rp_rrule', true ), true );
			if ( ! is_array( $rrule ) ) {
				continue;
			}

			$parent_date = (string) get_post_meta( $parent->ID, '_rp_event_date', true );
			$exdates     = RotaPress_Recurrence::get_exdates( $parent->ID );
			$dates       = RotaPress_Recurrence::expand_in_range( $rrule, $parent_date, $start, $end, $exdates );

			foreach ( $dates as $date ) {
				$events[] = $this->format_virtual_event( $parent, $date );
			}
		}

		return new \WP_REST_Response( $events, 200 );
	}

	/* ── POST /events ─────────────────────────────────────────────── */

	public function create_event( \WP_REST_Request $request ) {
		$params        = $request->get_json_params();
		$title         = sanitize_text_field( $params['title'] ?? '' );
		$event_date    = sanitize_text_field( $params['event_date'] ?? '' );
		$assigned_user = (int) ( $params['assigned_user'] ?? 0 );
		$notes         = sanitize_textarea_field( $params['notes'] ?? '' );
		$rrule_raw     = $params['rrule'] ?? null;

		if ( '' === $title || '' === $event_date ) {
			return new \WP_Error( 'rotapress_missing_fields', __( 'Title and event date are required.', 'rotapress' ), array( 'status' => 400 ) );
		}

		$post_id = wp_insert_post( array(
			'post_type'   => 'rp_event',
			'post_status' => 'publish',
			'post_title'  => $title,
		) );
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		update_post_meta( $post_id, '_rp_event_date', $event_date );
		update_post_meta( $post_id, '_rp_assigned_user', $assigned_user );
		update_post_meta( $post_id, '_rp_notes', $notes );
		update_post_meta( $post_id, '_rp_no_reminder', isset( $params['no_reminder'] ) ? (int) (bool) $params['no_reminder'] : 0 );

		if ( is_array( $rrule_raw ) && RotaPress_Recurrence::validate( $rrule_raw ) ) {
			update_post_meta( $post_id, '_rp_rrule', wp_json_encode( $rrule_raw ) );
			update_post_meta( $post_id, '_rp_exdates', wp_json_encode( array() ) );
		}

		$post = get_post( $post_id );
		return new \WP_REST_Response( $this->format_real_event( $post ), 201 );
	}

	/* ── PUT /events/{id} ─────────────────────────────────────────── */

	public function update_event( \WP_REST_Request $request ) {
		$raw_id = sanitize_text_field( $request->get_param( 'id' ) );
		$parsed = self::parse_event_id( $raw_id );
		$params = $request->get_json_params();
		$scope  = sanitize_text_field( $params['recurrence_scope'] ?? 'this' );

		if ( 'virtual' === $parsed['type'] ) {
			return $this->update_virtual( $parsed, $params, $scope );
		}

		/* Real event (standalone or exception or parent). */
		$id   = $parsed['post_id'];
		$post = get_post( $id );
		if ( ! $post || 'rp_event' !== $post->post_type ) {
			return new \WP_Error( 'rotapress_not_found', __( 'Event not found.', 'rotapress' ), array( 'status' => 404 ) );
		}

		$is_parent = '' !== (string) get_post_meta( $id, '_rp_rrule', true );

		if ( $is_parent && 'all' === $scope ) {
			/* Update parent + rrule. */
			$this->apply_update( $id, $params );
			$rrule_raw = $params['rrule'] ?? null;
			if ( is_array( $rrule_raw ) && RotaPress_Recurrence::validate( $rrule_raw ) ) {
				update_post_meta( $id, '_rp_rrule', wp_json_encode( $rrule_raw ) );
			}
		} else {
			/* Standalone or exception event — just update fields. */
			$this->apply_update( $id, $params );
		}

		$post = get_post( $id );
		return new \WP_REST_Response( $this->format_real_event( $post ), 200 );
	}

	/**
	 * Update a virtual (unexpanded) recurring instance.
	 */
	private function update_virtual( array $parsed, array $params, string $scope ) {
		$parent_id = $parsed['parent_id'];
		$date      = $parsed['date'];
		$parent    = get_post( $parent_id );

		if ( ! $parent || 'rp_event' !== $parent->post_type ) {
			return new \WP_Error( 'rotapress_not_found', __( 'Event not found.', 'rotapress' ), array( 'status' => 404 ) );
		}

		if ( 'all' === $scope ) {
			/*
			 * Update the parent itself — but never change the parent's
			 * start date (_rp_event_date), because that defines when the
			 * series begins. Only title, assignee, notes propagate.
			 */
			$safe_params = $params;
			unset( $safe_params['event_date'] );
			$this->apply_update( $parent_id, $safe_params );

			$rrule_raw = $params['rrule'] ?? null;
			if ( is_array( $rrule_raw ) && RotaPress_Recurrence::validate( $rrule_raw ) ) {
				update_post_meta( $parent_id, '_rp_rrule', wp_json_encode( $rrule_raw ) );
			}
			$parent = get_post( $parent_id );
			return new \WP_REST_Response( $this->format_real_event( $parent ), 200 );
		}

		if ( 'following' === $scope ) {
			/*
			 * Truncate the original series to end before $date.
			 * Create a NEW parent starting at $date with updated fields
			 * and the ORIGINAL until date (not the truncated one).
			 */
			$rrule_json   = get_post_meta( $parent_id, '_rp_rrule', true );
			$original_rrule = json_decode( (string) $rrule_json, true );
			$original_until = $original_rrule['until'] ?? '';

			RotaPress_Recurrence::truncate_until( $parent_id, $date );

			$new_title = sanitize_text_field( $params['title'] ?? $parent->post_title );
			$new_user  = (int) ( $params['assigned_user'] ?? get_post_meta( $parent_id, '_rp_assigned_user', true ) );
			$new_notes = sanitize_textarea_field( $params['notes'] ?? (string) get_post_meta( $parent_id, '_rp_notes', true ) );
			$new_date  = sanitize_text_field( $params['event_date'] ?? $date );

			$new_id = wp_insert_post( array(
				'post_type'   => 'rp_event',
				'post_status' => 'publish',
				'post_title'  => $new_title,
			) );
			if ( is_wp_error( $new_id ) ) {
				return $new_id;
			}

			update_post_meta( $new_id, '_rp_event_date', $new_date );
			update_post_meta( $new_id, '_rp_assigned_user', $new_user );
			update_post_meta( $new_id, '_rp_notes', $new_notes );

			/* New parent gets provided rrule, or original rrule with original until. */
			$rrule_raw = $params['rrule'] ?? $original_rrule;
			if ( is_array( $rrule_raw ) ) {
				/* Ensure the new series keeps the original end date, not the truncated one. */
				if ( ! isset( $params['rrule'] ) && '' !== $original_until ) {
					$rrule_raw['until'] = $original_until;
				}
				if ( RotaPress_Recurrence::validate( $rrule_raw ) ) {
					update_post_meta( $new_id, '_rp_rrule', wp_json_encode( $rrule_raw ) );
					update_post_meta( $new_id, '_rp_exdates', wp_json_encode( array() ) );
				}
			}

			$new_post = get_post( $new_id );
			return new \WP_REST_Response( $this->format_real_event( $new_post ), 200 );
		}

		/* scope = "this" → detach into a fully standalone event. */
		RotaPress_Recurrence::add_exdate( $parent_id, $date );

		$exc_id = wp_insert_post( array(
			'post_type'   => 'rp_event',
			'post_status' => 'publish',
			'post_title'  => sanitize_text_field( $params['title'] ?? $parent->post_title ),
		) );
		if ( is_wp_error( $exc_id ) ) {
			return $exc_id;
		}

		$exc_date  = sanitize_text_field( $params['event_date'] ?? $date );
		$exc_user  = (int) ( $params['assigned_user'] ?? get_post_meta( $parent_id, '_rp_assigned_user', true ) );
		$exc_notes = sanitize_textarea_field( $params['notes'] ?? (string) get_post_meta( $parent_id, '_rp_notes', true ) );

		update_post_meta( $exc_id, '_rp_event_date', $exc_date );
		update_post_meta( $exc_id, '_rp_assigned_user', $exc_user );
		update_post_meta( $exc_id, '_rp_notes', $exc_notes );
		/* No _rp_exception_for — this is now a fully independent event. */

		$exc_post = get_post( $exc_id );
		return new \WP_REST_Response( $this->format_real_event( $exc_post ), 200 );
	}

	/* ── DELETE /events/{id} ──────────────────────────────────────── */

	public function delete_event( \WP_REST_Request $request ) {
		$raw_id = sanitize_text_field( $request->get_param( 'id' ) );
		$parsed = self::parse_event_id( $raw_id );
		$params = $request->get_json_params();
		$scope  = sanitize_text_field( $params['recurrence_scope'] ?? 'this' );

		if ( 'virtual' === $parsed['type'] ) {
			return $this->delete_virtual( $parsed, $scope );
		}

		$id   = $parsed['post_id'];
		$post = get_post( $id );
		if ( ! $post || 'rp_event' !== $post->post_type ) {
			return new \WP_Error( 'rotapress_not_found', __( 'Event not found.', 'rotapress' ), array( 'status' => 404 ) );
		}

		$is_parent = '' !== (string) get_post_meta( $id, '_rp_rrule', true );

		if ( $is_parent && 'all' === $scope ) {
			/* Trash parent (which removes all virtual instances). */
			wp_trash_post( $id );
		} else {
			wp_trash_post( $id );
		}

		return new \WP_REST_Response( array( 'deleted' => true, 'id' => $raw_id ), 200 );
	}

	private function delete_virtual( array $parsed, string $scope ) {
		$parent_id = $parsed['parent_id'];
		$date      = $parsed['date'];
		$parent    = get_post( $parent_id );

		if ( ! $parent || 'rp_event' !== $parent->post_type ) {
			return new \WP_Error( 'rotapress_not_found', __( 'Event not found.', 'rotapress' ), array( 'status' => 404 ) );
		}

		if ( 'all' === $scope ) {
			wp_trash_post( $parent_id );
		} elseif ( 'following' === $scope ) {
			RotaPress_Recurrence::truncate_until( $parent_id, $date );
		} else {
			/* "this" — just add exdate. */
			RotaPress_Recurrence::add_exdate( $parent_id, $date );
		}

		return new \WP_REST_Response( array( 'deleted' => true, 'id' => $parent_id . '_' . str_replace( '-', '', $date ) ), 200 );
	}

	/* ── POST /events/bulk ────────────────────────────────────────── */

	public function bulk_action( \WP_REST_Request $request ) {
		$params = $request->get_json_params();
		$action = sanitize_text_field( $params['action'] ?? '' );
		$ids    = $params['ids'] ?? array();

		if ( ! is_array( $ids ) || empty( $ids ) ) {
			return new \WP_Error( 'rotapress_missing_ids', __( 'No events selected.', 'rotapress' ), array( 'status' => 400 ) );
		}

		if ( 'reassign' === $action ) {
			$assigned_user  = (int) ( $params['assigned_user'] ?? 0 );
			$rec_scope      = sanitize_text_field( $params['recurrence_scope'] ?? 'this' );
			$count          = 0;

			foreach ( $ids as $raw_id ) {
				$parsed = self::parse_event_id( (string) $raw_id );

				if ( 'real' === $parsed['type'] ) {
					$post = get_post( $parsed['post_id'] );
					if ( ! $post || 'rp_event' !== $post->post_type ) { continue; }
					$is_parent = '' !== (string) get_post_meta( $parsed['post_id'], '_rp_rrule', true );

					if ( $is_parent && 'all' === $rec_scope ) {
						update_post_meta( $parsed['post_id'], '_rp_assigned_user', $assigned_user );
					} else {
						update_post_meta( $parsed['post_id'], '_rp_assigned_user', $assigned_user );
					}
					++$count;
				} elseif ( 'virtual' === $parsed['type'] ) {
					if ( 'all' === $rec_scope ) {
						/* Update the parent — affects all instances. */
						update_post_meta( $parsed['parent_id'], '_rp_assigned_user', $assigned_user );
					} else {
						/* "this" — detach into standalone event with new assignee. */
						RotaPress_Recurrence::add_exdate( $parsed['parent_id'], $parsed['date'] );
						$parent = get_post( $parsed['parent_id'] );
						if ( ! $parent ) { continue; }

						$new_id = wp_insert_post( array(
							'post_type'   => 'rp_event',
							'post_status' => 'publish',
							'post_title'  => $parent->post_title,
						) );
						if ( is_wp_error( $new_id ) ) { continue; }

						update_post_meta( $new_id, '_rp_event_date', $parsed['date'] );
						update_post_meta( $new_id, '_rp_assigned_user', $assigned_user );
						update_post_meta( $new_id, '_rp_notes', get_post_meta( $parsed['parent_id'], '_rp_notes', true ) );
					}
					++$count;
				}
			}
			return new \WP_REST_Response( array( 'updated' => $count ), 200 );
		}

		if ( 'delete' === $action ) {
			$rec_scope = sanitize_text_field( $params['recurrence_scope'] ?? 'this' );
			$count     = 0;

			foreach ( $ids as $raw_id ) {
				$parsed = self::parse_event_id( (string) $raw_id );

				if ( 'real' === $parsed['type'] ) {
					$post = get_post( $parsed['post_id'] );
					if ( $post && 'rp_event' === $post->post_type ) {
						wp_trash_post( $parsed['post_id'] );
						++$count;
					}
				} elseif ( 'virtual' === $parsed['type'] ) {
					if ( 'all' === $rec_scope ) {
						wp_trash_post( $parsed['parent_id'] );
					} else {
						RotaPress_Recurrence::add_exdate( $parsed['parent_id'], $parsed['date'] );
					}
					++$count;
				}
			}
			return new \WP_REST_Response( array( 'deleted' => $count ), 200 );
		}

		return new \WP_Error( 'rotapress_invalid_action', __( 'Invalid bulk action.', 'rotapress' ), array( 'status' => 400 ) );
	}

	/* ── POST /test-email ─────────────────────────────────────────── */

	public function send_test_email( \WP_REST_Request $request ) {
		$params = $request->get_json_params();
		$to     = sanitize_email( $params['to'] ?? '' );
		$mode   = sanitize_key( $params['mode'] ?? 'dummy' );
		$count  = min( 3, max( 1, (int) ( $params['count'] ?? 1 ) ) );

		if ( ! is_email( $to ) ) {
			return new \WP_Error( 'rotapress_invalid_email', __( 'Invalid email address.', 'rotapress' ), array( 'status' => 400 ) );
		}

		$subject_tpl = get_option( 'rotapress_email_subject', RotaPress_Reminders::default_subject() );
		$body_tpl    = get_option( 'rotapress_email_body', RotaPress_Reminders::default_body() );

		/* ── Dummy mode: fill template with sample data ── */
		if ( 'dummy' === $mode ) {
			$placeholders = array(
				'{title}'           => __( 'Sample Event Title', 'rotapress' ),
				'{assignee}'        => __( 'Jane Doe', 'rotapress' ),
				'{date}'            => wp_date( 'Y-m-d', strtotime( '+3 days' ) ),
				'{notes}'           => __( 'Remember to prepare the images.', 'rotapress' ),
				'{days}'            => '3',
				'{site}'            => get_bloginfo( 'name' ),
				'{calendar_url}'    => admin_url( 'admin.php?page=rotapress' ),
				'{no_reminder_url}' => add_query_arg(
					array(
						'rp_no_reminder' => '1',
						'rp_event'       => 'DEMO',
						'rp_token'       => 'DEMO_TOKEN',
					),
					home_url( '/' )
				),
			);

			$subject = str_replace( array_keys( $placeholders ), array_values( $placeholders ), $subject_tpl );
			$body    = str_replace( array_keys( $placeholders ), array_values( $placeholders ), $body_tpl );

			$sent = wp_mail( $to, $subject, $body );
			if ( ! $sent ) {
				return new \WP_Error( 'rotapress_mail_failed', __( 'Failed to send email. Check your SMTP configuration.', 'rotapress' ), array( 'status' => 500 ) );
			}

			return new \WP_REST_Response( array( 'sent' => true, 'mode' => 'dummy' ), 200 );
		}

		/* ── Events mode: send real reminders redirected to the test address ── */

		/*
		 * Over-fetch candidates ordered by date, then pick the first $count
		 * that have an assigned participant with a valid user account.
		 */
		$candidates = get_posts( array(
			'post_type'      => 'rp_event',
			'posts_per_page' => 50,
			'post_status'    => 'publish',
			'meta_key'       => '_rp_event_date',
			'orderby'        => 'meta_value',
			'order'          => 'ASC',
			'meta_query'     => array(
				array(
					'key'     => '_rp_event_date',
					'value'   => gmdate( 'Y-m-d' ),
					'compare' => '>=',
					'type'    => 'DATE',
				),
			),
		) );

		$events = array();
		foreach ( $candidates as $post ) {
			if ( count( $events ) >= $count ) { break; }
			$uid = (int) get_post_meta( $post->ID, '_rp_assigned_user', true );
			if ( $uid > 0 && get_userdata( $uid ) ) {
				$events[] = $post;
			}
		}

		if ( empty( $events ) ) {
			return new \WP_Error(
				'rotapress_no_events',
				__( 'No upcoming published events with an assigned participant were found.', 'rotapress' ),
				array( 'status' => 404 )
			);
		}

		$sent_count = 0;
		$today      = gmdate( 'Y-m-d' );
		foreach ( $events as $event ) {
			$event_date  = (string) get_post_meta( $event->ID, '_rp_event_date', true );
			$days_before = (int) round(
				( strtotime( $event_date ) - strtotime( $today ) ) / DAY_IN_SECONDS
			);
			if ( RotaPress_Reminders::dispatch_test( $event, $event_date, $days_before, $subject_tpl, $body_tpl, $to ) ) {
				++$sent_count;
			}
		}

		return new \WP_REST_Response(
			array(
				'sent'  => $sent_count,
				'total' => count( $events ),
				'mode'  => 'events',
			),
			200
		);
	}

	/* ── GET /users ───────────────────────────────────────────────── */

	public function get_users( \WP_REST_Request $request ): \WP_REST_Response {
		$participants = get_option( 'rotapress_participants', array() );
		$colors       = get_option( 'rotapress_user_colors', array() );
		$user_ids     = array();

		if ( is_array( $participants ) && ! empty( $participants ) ) {
			$user_ids = array_map( 'intval', array_keys( array_filter( $participants ) ) );
		}

		$result = array();
		foreach ( $user_ids as $uid ) {
			$user = get_userdata( $uid );
			if ( ! $user ) { continue; }
			$color = '';
			if ( is_array( $colors ) && isset( $colors[ $uid ] ) ) {
				$color = sanitize_hex_color( $colors[ $uid ] ) ?: '';
			}
			if ( ! $color ) {
				$color = self::PALETTE[ $uid % count( self::PALETTE ) ];
			}
			$result[] = array(
				'ID'           => $uid,
				'display_name' => $user->display_name,
				'user_email'   => $user->user_email,
				'color'        => $color,
			);
		}
		return new \WP_REST_Response( $result, 200 );
	}

	/* ── Trash management ─────────────────────────────────────────── */

	public function get_trash( \WP_REST_Request $request ): \WP_REST_Response {
		$trashed = get_posts( array(
			'post_type'      => 'rp_event',
			'post_status'    => 'trash',
			'posts_per_page' => 200,
			'orderby'        => 'modified',
			'order'          => 'DESC',
		) );

		$items = array();
		foreach ( $trashed as $post ) {
			$items[] = array(
				'id'         => $post->ID,
				'title'      => $post->post_title,
				'event_date' => (string) get_post_meta( $post->ID, '_rp_event_date', true ),
				'trashed_at' => $post->post_modified,
				'is_parent'  => '' !== (string) get_post_meta( $post->ID, '_rp_rrule', true ),
			);
		}
		return new \WP_REST_Response( $items, 200 );
	}

	public function restore_from_trash( \WP_REST_Request $request ): \WP_REST_Response {
		$id   = (int) $request->get_param( 'id' );
		$post = get_post( $id );
		if ( ! $post || 'rp_event' !== $post->post_type || 'trash' !== $post->post_status ) {
			return new \WP_REST_Response( array( 'error' => 'not_found' ), 404 );
		}
		wp_untrash_post( $id );
		return new \WP_REST_Response( array( 'restored' => true, 'id' => $id ), 200 );
	}

	public function purge_from_trash( \WP_REST_Request $request ): \WP_REST_Response {
		$id   = (int) $request->get_param( 'id' );
		$post = get_post( $id );
		if ( ! $post || 'rp_event' !== $post->post_type || 'trash' !== $post->post_status ) {
			return new \WP_REST_Response( array( 'error' => 'not_found' ), 404 );
		}
		wp_delete_post( $id, true );
		return new \WP_REST_Response( array( 'purged' => true, 'id' => $id ), 200 );
	}

	/* ── Formatting ───────────────────────────────────────────────── */

	/**
	 * Format a real (stored) event for the API response.
	 */
	private function format_real_event( \WP_Post $post ): array {
		$id         = $post->ID;
		$event_date = (string) get_post_meta( $id, '_rp_event_date', true );
		$assigned   = (int) get_post_meta( $id, '_rp_assigned_user', true );
		$notes      = (string) get_post_meta( $id, '_rp_notes', true );
		$rrule_json = (string) get_post_meta( $id, '_rp_rrule', true );
		$is_parent  = '' !== $rrule_json;

		return array(
			'id'            => (string) $id,
			'title'         => $post->post_title,
			'event_date'    => $event_date,
			'start'         => $event_date,
			'assigned_user' => $assigned,
			'assigned_name' => $this->get_user_name( $assigned ),
			'color'         => $this->get_user_color( $assigned ),
			'notes'         => $notes,
			'no_reminder'   => (bool) get_post_meta( $id, '_rp_no_reminder', true ),
			'is_recurring'  => $is_parent,
			'is_parent'     => $is_parent,
			'parent_id'     => $is_parent ? $id : null,
			'rrule'         => $is_parent ? json_decode( $rrule_json, true ) : null,
		);
	}

	/**
	 * Format a virtual (expanded) recurring instance for the API response.
	 */
	private function format_virtual_event( \WP_Post $parent, string $date ): array {
		$parent_id = $parent->ID;
		$assigned  = (int) get_post_meta( $parent_id, '_rp_assigned_user', true );
		$notes     = (string) get_post_meta( $parent_id, '_rp_notes', true );
		$rrule_json = (string) get_post_meta( $parent_id, '_rp_rrule', true );
		$vid       = $parent_id . '_' . str_replace( '-', '', $date );

		return array(
			'id'            => $vid,
			'title'         => $parent->post_title,
			'event_date'    => $date,
			'start'         => $date,
			'assigned_user' => $assigned,
			'assigned_name' => $this->get_user_name( $assigned ),
			'color'         => $this->get_user_color( $assigned ),
			'notes'         => $notes,
			'no_reminder'   => (bool) get_post_meta( $parent_id, '_rp_no_reminder', true ),
			'is_recurring'  => true,
			'is_parent'     => false,
			'parent_id'     => $parent_id,
			'rrule'         => json_decode( $rrule_json, true ),
		);
	}

	private function get_user_name( int $uid ): string {
		if ( $uid <= 0 ) { return ''; }
		$u = get_userdata( $uid );
		return $u ? $u->display_name : '';
	}

	private function get_user_color( int $uid ): string {
		$colors = get_option( 'rotapress_user_colors', array() );
		if ( is_array( $colors ) && isset( $colors[ $uid ] ) ) {
			$c = sanitize_hex_color( $colors[ $uid ] );
			if ( $c ) { return $c; }
		}
		if ( $uid > 0 ) {
			return self::PALETTE[ $uid % count( self::PALETTE ) ];
		}
		return '#2271b1';
	}

	private function apply_update( int $post_id, array $params ): void {
		if ( isset( $params['title'] ) ) {
			wp_update_post( array( 'ID' => $post_id, 'post_title' => sanitize_text_field( $params['title'] ) ) );
		}
		if ( isset( $params['event_date'] ) ) {
			update_post_meta( $post_id, '_rp_event_date', sanitize_text_field( $params['event_date'] ) );
		}
		if ( isset( $params['assigned_user'] ) ) {
			update_post_meta( $post_id, '_rp_assigned_user', (int) $params['assigned_user'] );
		}
		if ( isset( $params['notes'] ) ) {
			update_post_meta( $post_id, '_rp_notes', sanitize_textarea_field( $params['notes'] ) );
		}
		if ( isset( $params['no_reminder'] ) ) {
			update_post_meta( $post_id, '_rp_no_reminder', (int) (bool) $params['no_reminder'] );
		}
	}

	public function validate_date( $param ): bool {
		return (bool) preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $param );
	}
}
