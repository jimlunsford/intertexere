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

export function visibleSuggestions( response, dismissed ) {
	if ( ! response || ! Array.isArray( response.suggestions ) ) {
		return [];
	}
	return response.suggestions.filter(
		( suggestion ) => ! dismissed.has( suggestion.target_post_id )
	);
}
