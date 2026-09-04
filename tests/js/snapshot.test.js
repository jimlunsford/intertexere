import {
	buildSnapshot,
	clientHashInput,
	collectUnits,
} from '../../src/editor/snapshot';
import { createHash } from 'crypto';

const hashFixture = require( '../fixtures/editor-draft-hash.json' );

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
	test( 'collects supported units through reviewed Core containers in editor order', () => {
		const blocks = [
			block( 'group', 'core/group', '', [
				block( 'columns', 'core/columns', '', [
					block( 'column', 'core/column', '', [
						block(
							'p1',
							'core/paragraph',
							'<!-- wp:paragraph --><p>First</p><!-- /wp:paragraph -->'
						),
					] ),
				] ),
				block( 'list', 'core/list', '', [
					block( 'li', 'core/list-item', '<li>Second</li>' ),
				] ),
				block( 'quote', 'core/quote', '', [
					block(
						'p2',
						'core/paragraph',
						'<!-- wp:paragraph --><p>Third</p><!-- /wp:paragraph -->'
					),
				] ),
			] ),
			block( 'gallery', 'core/gallery', '', [
				block( 'image', 'core/image', '<figure>Fourth</figure>' ),
			] ),
			block(
				'table',
				'core/table',
				'<!-- wp:table --><figure><table><tbody><tr><td>Fifth</td></tr></tbody></table></figure><!-- /wp:table -->'
			),
		];

		expect(
			collectUnits( blocks, limits ).map( ( unit ) => unit.client_id )
		).toEqual( [ 'p1', 'li', 'p2', 'image', 'table' ] );
	} );

	test( 'stops at external entities, dynamic controllers, and unknown parents', () => {
		const blocks = [
			block( 'synced', 'core/block', '', [
				block( 'synced-child', 'core/paragraph', 'hidden' ),
			] ),
			block( 'template', 'core/template-part', '', [
				block( 'template-child', 'core/paragraph', 'hidden' ),
			] ),
			block( 'nav', 'core/navigation', '', [
				block( 'nav-child', 'core/paragraph', 'hidden' ),
			] ),
			block( 'query', 'core/query', '', [
				block( 'query-child', 'core/paragraph', 'hidden' ),
			] ),
			block( 'custom', 'acme/controller', '', [
				block( 'custom-child', 'core/paragraph', 'hidden' ),
			] ),
			block( 'html', 'core/html', '<script>unsafe()</script>' ),
		];
		expect( collectUnits( blocks, limits ) ).toEqual( [] );
	} );

	test( 'excluded subtree content does not affect draft identity', () => {
		const snapshot = ( hiddenText ) =>
			buildSnapshot(
				{
					postId: 0,
					postType: 'post',
					title: 'Stable title',
					taxonomies: {},
					blocks: [
						block(
							'visible',
							'core/paragraph',
							'<p>Stable visible prose</p>'
						),
						block( 'custom', 'acme/controller', '', [
							block( 'hidden', 'core/paragraph', hiddenText ),
						] ),
						block( 'query', 'core/query', '', [
							block( 'dynamic', 'core/paragraph', hiddenText ),
						] ),
					],
				},
				{ taxonomyFields: {}, limits }
			);

		expect( snapshot( 'first hidden value' ) ).toEqual(
			snapshot( 'different hidden value' )
		);
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
			'"algorithm_version":2'
		);
	} );

	test( 'matches the shared PHP canonical Unicode hash fixture', () => {
		const canonical = clientHashInput( { payload: hashFixture.payload } );
		expect( canonical ).toBe( hashFixture.canonical );
		expect(
			createHash( 'sha256' ).update( canonical, 'utf8' ).digest( 'hex' )
		).toBe( hashFixture.sha256 );
		expect( canonical ).toContain( '\u2028' );
		expect( canonical ).toContain( '\u2029' );
		expect( canonical ).not.toContain( '\\u2028' );
		expect( canonical ).not.toContain( '\\u2029' );
		expect( canonical ).toContain( '普通 Unicode 😀 emoji' );
		expect( canonical ).toContain( '\\"quote\\"' );
		expect( canonical ).toContain( '\\\\ backslash' );
		expect( canonical ).toContain( '\\ncontrol\\ttext' );
		expect( canonical ).toContain( '\\u0001' );
		expect( canonical ).not.toContain( '\\/' );
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
