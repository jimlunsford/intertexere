import { Button, Notice, Placeholder, Spinner } from '@wordpress/components';
import { external } from '@wordpress/icons';
import { __ } from '@wordpress/i18n';

export function insertionReadOnlyMessage( reason ) {
	const messages = {
		'no-specific-phrase': __(
			'No destination-specific phrase was found in a supported block.',
			'intertexere'
		),
		'unsupported-block': __(
			'Matching text appears only in a block Intertexere does not edit.',
			'intertexere'
		),
		'already-linked': __(
			'The matching phrase is already linked.',
			'intertexere'
		),
		'link-overlap': __(
			'The matching phrase overlaps another link.',
			'intertexere'
		),
		'replacement-overlap': __(
			'The matching phrase crosses a non-text editor object.',
			'intertexere'
		),
		changed: __(
			'The phrase or block changed after analysis. Refresh suggestions.',
			'intertexere'
		),
		unmappable: __(
			'The matching phrase could not be mapped safely to the current editor text.',
			'intertexere'
		),
		unavailable: __(
			'This location is not currently available for safe insertion.',
			'intertexere'
		),
	};
	return messages[ reason ] || messages.unavailable;
}

export function SuggestionCard( {
	suggestion,
	onDismiss,
	stale,
	aiEvaluation,
	insertionEvidence,
	insertionAvailability,
	insertionState,
	onInsert,
} ) {
	const location = insertionAvailability?.location || suggestion.location;
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
			{ ! stale && ! insertionEvidence && (
				<p>
					{ insertionReadOnlyMessage(
						insertionAvailability?.reason ||
							suggestion.location_status
					) }
				</p>
			) }
			{ insertionState?.message && (
				<p
					className={ `intertexere-suggestion-card__insertion-status is-${ insertionState.status }` }
					role="status"
					aria-live="polite"
				>
					{ insertionState.message }
				</p>
			) }
			{ insertionEvidence && ! stale && ! insertionState?.message && (
				<p className="intertexere-suggestion-card__insertion-help">
					{ __(
						'Insert Link wraps only the shown existing phrase in this unsaved draft. It does not save or publish the post.',
						'intertexere'
					) }
				</p>
			) }
			<div className="intertexere-suggestion-card__actions">
				{ insertionEvidence &&
					! stale &&
					insertionState?.status !== 'inserted' && (
						<Button
							variant="primary"
							onClick={ onInsert }
							disabled={ insertionState?.status === 'validating' }
							isBusy={ insertionState?.status === 'validating' }
							aria-label={ __( 'Insert Link', 'intertexere' ) }
						>
							{ insertionState?.status === 'validating'
								? __( 'Validating…', 'intertexere' )
								: __( 'Insert Link', 'intertexere' ) }
						</Button>
					) }
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
	insertionEvidence = new Map(),
	insertionAvailability = new Map(),
	insertionStates = {},
	onInsert,
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
				insertionEvidence={ insertionEvidence.get(
					suggestion.target_post_id
				) }
				insertionAvailability={ insertionAvailability.get(
					suggestion.target_post_id
				) }
				insertionState={
					insertionStates[ suggestion.target_post_id ] || {
						status: 'ready',
						message: '',
					}
				}
				onInsert={ () =>
					onInsert(
						suggestion,
						insertionEvidence.get( suggestion.target_post_id )
					)
				}
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
