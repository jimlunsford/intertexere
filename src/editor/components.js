import { Button, Notice, Placeholder, Spinner } from '@wordpress/components';
import { external } from '@wordpress/icons';
import { __ } from '@wordpress/i18n';

export function SuggestionCard( { suggestion, onDismiss, stale } ) {
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
				{ __(
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
