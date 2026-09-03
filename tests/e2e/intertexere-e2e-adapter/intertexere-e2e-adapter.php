<?php
/**
 * Plugin Name: Intertexere E2E AI Adapter
 * Description: Deterministic provider-independent adapter used only by wp-env browser tests.
 */

add_filter(
	'option_intertexere_settings',
	static function ( $settings ) {
		$settings = is_array( $settings ) ? $settings : array();
		$settings['enable_ai_enhancement'] = 'disabled' !== get_option( 'intertexere_e2e_ai_mode', 'available' );
		return $settings;
	},
	99
);

add_action(
	'rest_api_init',
	static function (): void {
		register_rest_route(
			'intertexere-e2e/v1',
			'/mode',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'callback'            => static function ( WP_REST_Request $request ): WP_REST_Response {
					$mode = sanitize_key( (string) $request->get_param( 'mode' ) );
					if ( ! in_array( $mode, array( 'available', 'disabled', 'unavailable', 'delayed', 'failure', 'insertion-delayed', 'audit-stale', 'audit-unavailable', 'audit-unauthorized', 'audit-target-readonly' ), true ) ) {
						$mode = 'available';
					}
					update_option( 'intertexere_e2e_ai_mode', $mode, false );
					return rest_ensure_response( array( 'mode' => $mode ) );
				},
			)
		);
	}
);

add_filter(
	'user_has_cap',
	static function ( array $allcaps ): array {
		$mode = get_option( 'intertexere_e2e_ai_mode', 'available' );
		if ( 'audit-unauthorized' === $mode ) {
			unset( $allcaps['manage_intertexere'] );
		}
		if ( 'audit-target-readonly' === $mode ) {
			unset( $allcaps['edit_posts'], $allcaps['edit_published_posts'], $allcaps['edit_others_posts'] );
		}
		return $allcaps;
	},
	99
);

add_filter(
	'intertexere_ai_client_adapter',
	static function ( $production_adapter ) {
		if ( ! interface_exists( '\\Intertexere\\AI_Client_Adapter' ) ) {
			return $production_adapter;
		}

		return new class() implements \Intertexere\AI_Client_Adapter {
			public function is_supported( array $request ): bool {
				return 'unavailable' !== get_option( 'intertexere_e2e_ai_mode', 'available' );
			}

			public function generate( array $request ) {
				if ( 'failure' === get_option( 'intertexere_e2e_ai_mode', 'available' ) ) {
					return new WP_Error(
						'prompt_network_error',
						'secret provider detail',
						array( 'status' => 503 )
					);
				}
				if ( 'delayed' === get_option( 'intertexere_e2e_ai_mode', 'available' ) ) {
					usleep( 800000 );
				}
				$prompt = json_decode( (string) $request['prompt'], true );
				$evaluations = array();
				$unit = isset( $prompt['draft']['units'][0] ) && is_array( $prompt['draft']['units'][0] ) ? $prompt['draft']['units'][0] : null;
				$anchor_text = 'Deterministic WordPress Performance';
				$anchor = is_array( $unit ) && false !== strpos( (string) $unit['text'], $anchor_text )
					? array( 'unit_key' => (string) $unit['unit_key'], 'exact_text' => $anchor_text, 'occurrence' => 0 )
					: null;
				foreach ( isset( $prompt['candidates'] ) && is_array( $prompt['candidates'] ) ? $prompt['candidates'] : array() as $index => $candidate ) {
					$evaluations[] = array(
						'candidate_key' => (string) $candidate['candidate_key'],
						'decision'      => 0 === $index ? 'keep' : 'drop',
						'rank'          => 0 === $index ? 1 : null,
						'reason'        => 'Deterministic E2E enhancement keeps the strongest contextual destination.',
						'anchor'        => 0 === $index ? $anchor : null,
					);
				}

				return array(
					'raw' => (string) wp_json_encode( array( 'contract_version' => 1, 'evaluations' => $evaluations ) ),
					'provider_latency_ms' => 0.0,
				);
			}
		};
	}
);

add_action(
	'intertexere_insertion_rest_before_validation',
	static function (): void {
		if ( 'insertion-delayed' === get_option( 'intertexere_e2e_ai_mode', 'available' ) ) {
			usleep( 800000 );
		}
	}
);

add_action(
	'intertexere_audit_before_final_object_authority',
	static function (): void {
		if ( 'audit-stale' !== get_option( 'intertexere_e2e_ai_mode', 'available' ) ) {
			return;
		}
		$original = get_option( \Intertexere\Schema::GRAPH_GENERATION_OPTION );
		update_option( \Intertexere\Schema::GRAPH_GENERATION_OPTION, 'intertexere-e2e-stale', false );
		add_action(
			'shutdown',
			static function () use ( $original ): void {
				update_option( \Intertexere\Schema::GRAPH_GENERATION_OPTION, $original, false );
			},
			PHP_INT_MAX
		);
	}
);

add_filter(
	'query',
	static function ( string $query ): string {
		static $checking_mode = false;
		if ( $checking_mode ) {
			return $query;
		}
		$checking_mode = true;
		$mode = get_option( 'intertexere_e2e_ai_mode', 'available' );
		$checking_mode = false;
		if ( 'audit-unavailable' === $mode
			&& false !== strpos( $query, 'AS orphans' )
			&& false !== strpos( $query, 'AS noncanonical' ) ) {
			return 'SELECT * FROM intertexere_e2e_missing_audit_table';
		}
		return $query;
	}
);
