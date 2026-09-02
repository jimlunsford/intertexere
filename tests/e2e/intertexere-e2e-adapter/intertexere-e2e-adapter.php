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
					if ( ! in_array( $mode, array( 'available', 'disabled', 'unavailable', 'delayed' ), true ) ) {
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
				if ( 'delayed' === get_option( 'intertexere_e2e_ai_mode', 'available' ) ) {
					usleep( 800000 );
				}
				$prompt = json_decode( (string) $request['prompt'], true );
				$evaluations = array();
				foreach ( isset( $prompt['candidates'] ) && is_array( $prompt['candidates'] ) ? $prompt['candidates'] : array() as $index => $candidate ) {
					$evaluations[] = array(
						'candidate_key' => (string) $candidate['candidate_key'],
						'decision'      => 0 === $index ? 'keep' : 'drop',
						'rank'          => 0 === $index ? 1 : null,
						'reason'        => 'Deterministic E2E enhancement keeps the strongest contextual destination.',
						'anchor'        => null,
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
