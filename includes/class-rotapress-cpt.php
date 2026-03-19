<?php
declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RotaPress_CPT {

	public function __construct() {
		add_action( 'init', array( $this, 'register_post_type' ) );
		add_action( 'init', array( $this, 'register_meta' ) );
	}

	public function register_post_type(): void {
		register_post_type(
			'rp_event',
			array(
				'labels'          => array(
					'name'          => __( 'Rota Events', 'rotapress' ),
					'singular_name' => __( 'Rota Event', 'rotapress' ),
				),
				'public'          => false,
				'show_ui'         => false,
				'show_in_rest'    => true,
				'supports'        => array( 'title', 'custom-fields' ),
				'map_meta_cap'    => true,
				'capability_type' => 'post',
			)
		);
	}

	public function register_meta(): void {
		$string_meta = array(
			'_rp_event_date' => 'sanitize_text_field',
			'_rp_notes'      => 'sanitize_textarea_field',
			'_rp_rrule'      => 'sanitize_text_field',
		);

		foreach ( $string_meta as $key => $sanitize ) {
			register_post_meta(
				'rp_event',
				$key,
				array(
					'type'              => 'string',
					'single'            => true,
					'show_in_rest'      => true,
					'sanitize_callback' => $sanitize,
					'auth_callback'     => function (): bool {
						return current_user_can( 'rotapress_edit' );
					},
				)
			);
		}

		$int_keys = array( '_rp_assigned_user', '_rp_parent', '_rp_is_override' );
		foreach ( $int_keys as $key ) {
			register_post_meta(
				'rp_event',
				$key,
				array(
					'type'              => 'integer',
					'single'            => true,
					'show_in_rest'      => true,
					'sanitize_callback' => function ( $value ): int {
						return (int) $value;
					},
					'auth_callback'     => function (): bool {
						return current_user_can( 'rotapress_edit' );
					},
				)
			);
		}
	}
}
