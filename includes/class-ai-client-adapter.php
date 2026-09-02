<?php
/**
 * Provider-neutral WordPress AI Client adapter.
 *
 * @package Intertexere
 */

namespace Intertexere;

interface AI_Client_Adapter {
	/**
	 * Check the fully configured prompt builder immediately before use.
	 *
	 * @param array<string, mixed> $request Canonical adapter request.
	 */
	public function is_supported( array $request ): bool;

	/**
	 * Generate one bounded structured response.
	 *
	 * @param array<string, mixed> $request Canonical adapter request.
	 * @return array{raw:string,provider_latency_ms:float}|\WP_Error
	 */
	public function generate( array $request );
}

final class WordPress_AI_Client_Adapter implements AI_Client_Adapter {
	/** @var \WP_AI_Client_Prompt_Builder|null */
	private $prepared_builder;

	public function is_supported( array $request ): bool {
		$this->prepared_builder = null;
		$builder = $this->build( $request );
		if ( ! $builder instanceof \WP_AI_Client_Prompt_Builder ) {
			return false;
		}

		$this->prepared_builder = $builder;
		try {
			return true === $builder->is_supported_for_text_generation();
		} catch ( \Throwable $throwable ) {
			$this->prepared_builder = null;
			return false;
		}
	}

	public function generate( array $request ) {
		$builder = $this->prepared_builder;
		$this->prepared_builder = null;

		if ( ! $builder instanceof \WP_AI_Client_Prompt_Builder ) {
			$builder = $this->build( $request );
		}
		try {
			$supported = $builder instanceof \WP_AI_Client_Prompt_Builder && true === $builder->is_supported_for_text_generation();
		} catch ( \Throwable $throwable ) {
			return new \WP_Error( 'intertexere_ai_invalid_configuration', 'The AI prompt could not be configured safely.' );
		}
		if ( ! $supported ) {
			return new \WP_Error( 'intertexere_ai_no_compatible_model', 'No compatible configured AI model is available.' );
		}

		$started = microtime( true );
		$result  = $builder->generate_text_result();
		$latency = round( ( microtime( true ) - $started ) * 1000, 3 );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		try {
			$raw = $result->toText();
		} catch ( \Throwable $throwable ) {
			return new \WP_Error( 'intertexere_ai_result_unreadable', 'The AI result did not contain readable structured text.' );
		}

		return array(
			'raw'                 => (string) $raw,
			'provider_latency_ms' => $latency,
		);
	}

	/**
	 * Construct the exact WordPress 7.1 prompt builder configuration.
	 *
	 * @param array<string, mixed> $request Canonical adapter request.
	 * @return \WP_AI_Client_Prompt_Builder|null
	 */
	private function build( array $request ) {
		if ( ! function_exists( 'wp_ai_client_prompt' )
			|| ! class_exists( '\\WP_AI_Client_Prompt_Builder' )
			|| ! class_exists( '\\WordPress\\AiClient\\Providers\\Http\\DTO\\RequestOptions' ) ) {
			return null;
		}

		try {
			$options = \WordPress\AiClient\Providers\Http\DTO\RequestOptions::fromArray(
				array(
					\WordPress\AiClient\Providers\Http\DTO\RequestOptions::KEY_TIMEOUT => AI_Enhancement::PROVIDER_TIMEOUT_SECONDS,
				)
			);

			return wp_ai_client_prompt( (string) $request['prompt'] )
				->using_system_instruction( (string) $request['system_instruction'] )
				->using_max_tokens( AI_Enhancement::MAX_OUTPUT_TOKENS )
				->using_request_options( $options )
				->as_json_response( $request['response_schema'] );
		} catch ( \Throwable $throwable ) {
			return null;
		}
	}
}
