<?php
/**
 * Plugin Name: Intertexere E2E AI Adapter
 * Description: Deterministic provider-independent adapter used only by wp-env browser tests.
 */

add_filter(
	'option_intertexere_settings',
	static function ( $settings ) {
		$settings = is_array( $settings ) ? $settings : array();
		$settings['enable_ai_enhancement'] = true;
		return $settings;
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
				return true;
			}

			public function generate( array $request ) {
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
