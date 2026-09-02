import {
	buildSnapshot,
	clientHashInput,
	collectUnits,
} from '../../src/editor/snapshot';

jest.mock( '@wordpress/blocks', () => ( {
	serialize: jest.fn( ( [ block ] ) => block.markup ),
} ) );

const limits = { payloadBytes: 262144, units: 500, unitBytes: 16384 };
const block = ( clientId, name, markup, innerBlocks = [] ) => ( {
	clientId,
	name,
	markup,
	innerBlocks,
} );

describe( 'editor snapshot', () => {
	test( 'collects supported nested units in editor order without duplicating containers', () => {
		const blocks = [
			block( 'group', 'core/group', '', [
				block(
					'p1',
					'core/paragraph',
					'<!-- wp:paragraph --><p>First</p><!-- /wp:paragraph -->'
				),
				block( 'quote', 'core/quote', '', [
					block(
						'p2',
						'core/paragraph',
						'<!-- wp:paragraph --><p>Second</p><!-- /wp:paragraph -->'
					),
				] ),
			] ),
			block(
				'table',
				'core/table',
				'<!-- wp:table --><figure><table><tbody><tr><td>Third</td></tr></tbody></table></figure><!-- /wp:table -->'
			),
		];

		expect(
			collectUnits( blocks, limits ).map( ( unit ) => unit.client_id )
		).toEqual( [ 'p1', 'p2', 'table' ] );
	} );

	test( 'excludes external entity controllers and unsupported literal content', () => {
		const blocks = [
			block( 'synced', 'core/block', '', [
				block( 'hidden', 'core/paragraph', 'hidden' ),
			] ),
			block( 'html', 'core/html', '<script>unsafe()</script>' ),
			block( 'nav', 'core/navigation', '', [
				block( 'hidden2', 'core/paragraph', 'hidden' ),
			] ),
		];
		expect( collectUnits( blocks, limits ) ).toEqual( [] );
	} );

	test( 'canonicalizes taxonomy IDs and exact payload key order', () => {
		const result = buildSnapshot(
			{
				postId: 7,
				postType: 'post',
				title: 'Unsaved title',
				taxonomies: { tags: [ 9, 3, 9 ], categories: [ 8, 2 ] },
				blocks: [
					block(
						'p1',
						'core/paragraph',
						'<!-- wp:paragraph --><p>Draft</p><!-- /wp:paragraph -->'
					),
				],
			},
			{
				taxonomyFields: { post_tag: 'tags', category: 'categories' },
				limits,
			}
		);

		expect( result.payload.taxonomies ).toEqual( {
			category: [ 2, 8 ],
			post_tag: [ 3, 9 ],
		} );
		expect( Object.keys( result.payload ) ).toEqual( [
			'post_id',
			'post_type',
			'title',
			'taxonomies',
			'units',
		] );
		expect( clientHashInput( result ) ).toContain(
			'"algorithm_version":1'
		);
	} );

	test( 'enforces unit count, unit size, and payload size before transport', () => {
		expect( () =>
			collectUnits(
				[ block( 'large', 'core/paragraph', 'x'.repeat( 20 ) ) ],
				{ ...limits, unitBytes: 10 }
			)
		).toThrow( /block exceeds/ );
		expect( () =>
			collectUnits(
				[
					block( 'one', 'core/paragraph', 'one' ),
					block( 'two', 'core/paragraph', 'two' ),
				],
				{ ...limits, units: 1 }
			)
		).toThrow( /too many/ );
		expect( () =>
			buildSnapshot(
				{
					postId: 0,
					postType: 'post',
					title: 'large',
					taxonomies: {},
					blocks: [
						block( 'large', 'core/paragraph', 'x'.repeat( 100 ) ),
					],
				},
				{ taxonomyFields: {}, limits: { ...limits, payloadBytes: 50 } }
			)
		).toThrow( /256 KiB/ );
	} );
} );
