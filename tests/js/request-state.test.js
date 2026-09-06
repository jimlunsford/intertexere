import {
	analysisCacheKey,
	aiCacheKey,
	createRequestGate,
	isCurrentAIResponse,
	isCurrentAnalysisResponse,
	sameOrderedValues,
	visibleSuggestions,
} from '../../src/editor/request-state';

describe( 'asynchronous request state', () => {
	test( 'rejects older and invalidated request ownership', () => {
		const gate = createRequestGate();
		const first = gate.begin();
		const second = gate.begin();
		expect( gate.owns( first ) ).toBe( false );
		expect( gate.owns( second ) ).toBe( true );
		gate.invalidate();
		expect( gate.owns( second ) ).toBe( false );
	} );

	test( 'rejects stale AI response identity, generations, versions, and candidate order', () => {
		const expected = {
			analysisId: 'analysis',
			draftHash: 'draft',
			indexGeneration: 'index',
			graphGeneration: 'graph',
			candidateIds: [ 4, 2 ],
			contractVersion: 1,
			promptVersion: 3,
		};
		const response = {
			analysis_id: 'analysis',
			draft_hash: 'draft',
			index_generation: 'index',
			graph_generation: 'graph',
			candidate_ids: [ 4, 2 ],
			contract_version: 1,
			prompt_version: 3,
		};
		expect( isCurrentAIResponse( response, expected ) ).toBe( true );
		for ( const [ field, value ] of [
			[ 'analysis_id', 'old-analysis' ],
			[ 'draft_hash', 'old-draft' ],
			[ 'index_generation', 'old-index' ],
			[ 'graph_generation', 'old-graph' ],
			[ 'candidate_ids', [ 2, 4 ] ],
			[ 'contract_version', 2 ],
			[ 'prompt_version', 4 ],
		] ) {
			expect(
				isCurrentAIResponse(
					{ ...response, [ field ]: value },
					expected
				)
			).toBe( false );
		}
	} );

	test( 'accepts only the current deterministic response contract and algorithm', () => {
		const expected = {
			draftHash: 'draft',
			contractVersion: 2,
			algorithmVersion: 3,
		};
		const response = {
			draft_hash: 'draft',
			contract_version: 2,
			algorithm_version: 3,
			suggestions: [],
		};
		expect( isCurrentAnalysisResponse( response, expected ) ).toBe( true );
		for ( const [ field, value ] of [
			[ 'draft_hash', 'old-draft' ],
			[ 'contract_version', 1 ],
			[ 'algorithm_version', 1 ],
			[ 'suggestions', null ],
		] ) {
			expect(
				isCurrentAnalysisResponse(
					{ ...response, [ field ]: value },
					expected
				)
			).toBe( false );
		}
	} );

	test( 'binds AI responses to the exact ordered candidate set', () => {
		expect( sameOrderedValues( [ 4, 2 ], [ 4, 2 ] ) ).toBe( true );
		expect( sameOrderedValues( [ 4, 2 ], [ 2, 4 ] ) ).toBe( false );
		expect( sameOrderedValues( [ 4 ], [ 4, 2 ] ) ).toBe( false );
		expect( sameOrderedValues( null, [ 4 ] ) ).toBe( false );
	} );

	test( 'keys AI session cache to analysis, ordered candidates, and contract versions', () => {
		expect( aiCacheKey( 'analysis', [ 4, 2 ], 1, 3 ) ).toBe(
			'analysis:4,2:1:3'
		);
		expect( aiCacheKey( 'analysis', [ 2, 4 ], 1, 3 ) ).not.toBe(
			aiCacheKey( 'analysis', [ 4, 2 ], 1, 3 )
		);
	} );

	test( 'keys session cache to post, hash, algorithm, and active generations', () => {
		expect(
			analysisCacheKey( 'post:4', 'draft', {
				algorithm_version: 1,
				index_generation: 'index-a',
				graph_generation: 'graph-b',
			} )
		).toBe( 'post:4:draft:1:index-a:graph-b' );
	} );

	test( 'dismisses only target IDs from the current in-memory response', () => {
		const response = {
			suggestions: [ { target_post_id: 1 }, { target_post_id: 2 } ],
		};
		expect( visibleSuggestions( response, new Set( [ 1 ] ) ) ).toEqual( [
			{ target_post_id: 2 },
		] );
		expect( visibleSuggestions( response, new Set() ) ).toHaveLength( 2 );
	} );
} );
