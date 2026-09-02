import {
	analysisCacheKey,
	aiCacheKey,
	createRequestGate,
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
