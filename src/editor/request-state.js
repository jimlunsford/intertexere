export function createRequestGate() {
	let token = 0;

	return {
		begin() {
			token += 1;
			return token;
		},
		invalidate() {
			token += 1;
		},
		owns( candidate ) {
			return candidate === token;
		},
	};
}

export function analysisCacheKey( postIdentity, draftHash, response ) {
	return [
		postIdentity,
		draftHash,
		response.algorithm_version,
		response.index_generation,
		response.graph_generation,
	].join( ':' );
}

export function aiCacheKey(
	analysisId,
	candidateIds,
	contractVersion,
	promptVersion
) {
	return [
		analysisId,
		candidateIds.join( ',' ),
		contractVersion,
		promptVersion,
	].join( ':' );
}

export function sameOrderedValues( left, right ) {
	return (
		Array.isArray( left ) &&
		Array.isArray( right ) &&
		left.length === right.length &&
		left.every( ( value, index ) => value === right[ index ] )
	);
}

export function isCurrentAIResponse( response, expected ) {
	return Boolean(
		response &&
			response.analysis_id === expected.analysisId &&
			response.draft_hash === expected.draftHash &&
			response.index_generation === expected.indexGeneration &&
			response.graph_generation === expected.graphGeneration &&
			response.contract_version === expected.contractVersion &&
			response.prompt_version === expected.promptVersion &&
			sameOrderedValues( response.candidate_ids, expected.candidateIds )
	);
}

export function visibleSuggestions( response, dismissed ) {
	if ( ! response || ! Array.isArray( response.suggestions ) ) {
		return [];
	}
	return response.suggestions.filter(
		( suggestion ) => ! dismissed.has( suggestion.target_post_id )
	);
}
