import { Button, Notice, Placeholder, Spinner } from '@wordpress/components';
import { external } from '@wordpress/icons';
import { __ } from '@wordpress/i18n';

export function SuggestionCard( {
	suggestion,
	onDismiss,
	stale,
	aiEvaluation,
} ) {
	const location = suggestion.location;
	return (
		<div className="intertexere-suggestion-card">
			<h3>{ suggestion.target_title }</h3>
			<p className="intertexere-suggestion-card__url">
				{ suggestion.target_permalink }
			</p>
			<p>{ suggestion.reason.label }</p>
			<p>
				<strong>
					{ __( 'Deterministic relevance:', 'intertexere' ) }
				</strong>{ ' ' }
				{ suggestion.score }
			</p>
			{ aiEvaluation && (
				<div className="intertexere-suggestion-card__ai">
					<p>
						<strong>
							{ __( 'AI contextual rank:', 'intertexere' ) }
						</strong>{ ' ' }
						{ aiEvaluation.rank }
					</p>
					<p>{ aiEvaluation.reason }</p>
					{ aiEvaluation.anchor?.exact_text && (
						<p>
							<strong>
								{ __(
									'AI-selected existing phrase:',
									'intertexere'
								) }
							</strong>{ ' ' }
							“{ aiEvaluation.anchor.exact_text }”
						</p>
					) }
				</div>
			) }
			{ location?.anchor_text && (
				<p>
					<strong>{ __( 'Proposed phrase:', 'intertexere' ) }</strong>{ ' ' }
					“{ location.anchor_text }”
				</p>
			) }
			{ location?.excerpt && (
				<p>
					<strong>{ __( 'Draft context:', 'intertexere' ) }</strong>{ ' ' }
					{ location.excerpt }
				</p>
			) }
			{ ! location && (
				<p>
					{ __(
						'This relationship has no safe phrase or block location.',
						'intertexere'
					) }
				</p>
			) }
			<p>
				{ suggestion.already_linked
					? __( 'Already linked from this draft.', 'intertexere' )
					: __( 'Not linked from this draft.', 'intertexere' ) }
			</p>
			{ stale && (
				<p className="intertexere-suggestion-card__stale">
					{ __(
						'This suggestion is based on an older draft state.',
						'intertexere'
					) }
				</p>
			) }
			<div className="intertexere-suggestion-card__actions">
				<Button
					variant="secondary"
					href={ suggestion.target_permalink }
					target="_blank"
					rel="noopener noreferrer"
					icon={ external }
				>
					{ __( 'View', 'intertexere' ) }
				</Button>
				<Button variant="tertiary" onClick={ onDismiss }>
					{ __( 'Dismiss', 'intertexere' ) }
				</Button>
			</div>
		</div>
	);
}

export function AnalysisBody( {
	status,
	error,
	response,
	suggestions,
	stale,
	onDismiss,
	aiEvaluations = new Map(),
	enhancedMode = false,
} ) {
	if ( status === 'loading' ) {
		return (
			<Placeholder>
				<Spinner />{ ' ' }
				{ __( 'Analyzing the current draft…', 'intertexere' ) }
			</Placeholder>
		);
	}
	if ( status === 'unavailable' ) {
		return (
			<Notice status="warning" isDismissible={ false }>
				{ error }
			</Notice>
		);
	}
	if ( status === 'error' ) {
		return (
			<Notice status="error" isDismissible={ false }>
				{ error }
			</Notice>
		);
	}
	if ( response && suggestions.length === 0 ) {
		return (
			<p>
				{ enhancedMode
					? __(
							'AI kept no candidates. The deterministic result remains available.',
							'intertexere'
					  )
					: __(
							'No current suggestion meets the deterministic relevance threshold.',
							'intertexere'
					  ) }
			</p>
		);
	}
	if ( suggestions.length > 0 ) {
		return suggestions.map( ( suggestion ) => (
			<SuggestionCard
				key={ suggestion.target_post_id }
				suggestion={ suggestion }
				stale={ stale }
				aiEvaluation={ aiEvaluations.get( suggestion.target_post_id ) }
				onDismiss={ () => onDismiss( suggestion.target_post_id ) }
			/>
		) );
	}
	return (
		<p>
			{ __(
				'Analyze the unsaved draft to find deterministic internal-link opportunities.',
				'intertexere'
			) }
		</p>
	);
}

export function AIControls( {
	configured,
	status,
	error,
	onEnhance,
	onToggleMode,
	enhancedMode,
	hasDeterministicResults,
} ) {
	if ( ! hasDeterministicResults ) {
		return null;
	}

	return (
		<div className="intertexere-ai-controls">
			<h3>{ __( 'Optional AI enhancement', 'intertexere' ) }</h3>
			<p>
				{ __(
					'Deterministic suggestions work without AI. If you explicitly enhance them, the unsaved title, bounded draft excerpts, and bounded candidate context may leave this WordPress server through the provider configured in WordPress. Provider processing and retention are governed by that provider, not Intertexere.',
					'intertexere'
				) }
			</p>
			{ status === 'disabled' && (
				<Notice status="info" isDismissible={ false }>
					{ __(
						'AI enhancement is disabled in Intertexere settings.',
						'intertexere'
					) }
				</Notice>
			) }
			{ status === 'unavailable' && (
				<Notice status="warning" isDismissible={ false }>
					{ error ||
						__(
							'No compatible configured AI model is available.',
							'intertexere'
						) }
				</Notice>
			) }
			{ status === 'failed' && (
				<Notice status="error" isDismissible={ false }>
					{ error ||
						__(
							'AI enhancement failed. Deterministic suggestions are unchanged.',
							'intertexere'
						) }
				</Notice>
			) }
			{ status === 'stale' && (
				<Notice status="warning" isDismissible={ false }>
					{ __(
						'The AI enhancement is stale. Refresh deterministic suggestions before enhancing again.',
						'intertexere'
					) }
				</Notice>
			) }
			{ configured &&
				! [ 'disabled', 'unavailable', 'stale' ].includes( status ) && (
					<Button
						variant="secondary"
						onClick={ onEnhance }
						disabled={ status === 'loading' }
					>
						{ status === 'loading'
							? __( 'Enhancing with AI…', 'intertexere' )
							: __( 'Enhance with AI', 'intertexere' ) }
					</Button>
				) }
			{ status === 'enhanced' && (
				<Button variant="tertiary" onClick={ onToggleMode }>
					{ enhancedMode
						? __( 'View deterministic suggestions', 'intertexere' )
						: __( 'View AI-enhanced suggestions', 'intertexere' ) }
				</Button>
			) }
		</div>
	);
}
