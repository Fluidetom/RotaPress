<?php
declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RotaPress_Roles {

	private const DEFAULTS = array(
		'admin' => array( 'administrator' ),
		'edit'  => array( 'editor' ),
		'read'  => array( 'author' ),
	);

	private const HIERARCHY = array(
		'admin' => array( 'rotapress_admin', 'rotapress_edit', 'rotapress_read' ),
		'edit'  => array( 'rotapress_edit', 'rotapress_read' ),
		'read'  => array( 'rotapress_read' ),
	);

	public function __construct() {
		add_filter( 'user_has_cap', array( $this, 'grant_caps' ), 10, 4 );
	}

	public static function activate(): void {
		if ( ! get_option( 'rotapress_role_mapping' ) ) {
			update_option( 'rotapress_role_mapping', self::DEFAULTS );
		}
		if ( ! get_option( 'rotapress_reminder_days' ) ) {
			update_option( 'rotapress_reminder_days', '7,3,1' );
		}
		if ( ! get_option( 'rotapress_participants' ) ) {
			update_option( 'rotapress_participants', array() );
		}
		if ( ! wp_next_scheduled( 'rotapress_daily_reminders' ) ) {
			wp_schedule_event(
				(int) strtotime( 'tomorrow 08:00:00' ),
				'daily',
				'rotapress_daily_reminders'
			);
		}
	}

	public static function deactivate(): void {
		wp_clear_scheduled_hook( 'rotapress_daily_reminders' );
	}

	public function grant_caps( array $allcaps, array $caps, array $args, \WP_User $user ): array {
		$rp_role = self::get_rp_role_for_user( $user );
		if ( '' === $rp_role ) {
			return $allcaps;
		}
		if ( isset( self::HIERARCHY[ $rp_role ] ) ) {
			foreach ( self::HIERARCHY[ $rp_role ] as $cap ) {
				$allcaps[ $cap ] = true;
			}
		}
		return $allcaps;
	}

	public static function get_rp_role_for_user( \WP_User $user ): string {
		$mapping = get_option( 'rotapress_role_mapping', self::DEFAULTS );
		if ( ! is_array( $mapping ) ) {
			$mapping = self::DEFAULTS;
		}
		foreach ( array( 'admin', 'edit', 'read' ) as $level ) {
			$wp_roles = $mapping[ $level ] ?? array();
			foreach ( (array) $wp_roles as $wp_role ) {
				if ( in_array( $wp_role, $user->roles, true ) ) {
					return $level;
				}
			}
		}
		return '';
	}

	public static function get_mapping(): array {
		$mapping = get_option( 'rotapress_role_mapping', self::DEFAULTS );
		return is_array( $mapping ) ? $mapping : self::DEFAULTS;
	}
}
