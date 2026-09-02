import { create, registerFormatType, RichTextData } from '@wordpress/rich-text';
import {
	INSERTION_BLOCKS,
	applyValidatedLink,
	collectDraftLinks,
	commitValidatedLink,
	contentIdentity,
	findExactOccurrence,
	inspectInsertionRange,
	isCurrentValidationResponse,
	resolveInsertionEvidence,
} from '../../src/editor/insertion';

jest.mock( '@wordpress/blocks', () => ( {
	serialize: jest.fn( ( blocks ) =>
		blocks
			.map( ( current ) => current.attributes?.content || '' )
			.join( '' )
	),
} ) );

beforeAll( () => {
	registerFormatType( 'core/link', {
		title: 'Link',
		tagName: 'a',
		className: null,
		attributes: { url: 'href' },
	} );
} );

function block( name, content, clientId = 'block-1' ) {
	return {
		clientId,
		name,
		attributes: { content },
	};
}

function evidence( exactText, occurrence = 0 ) {
	return {
		block_client_id: 'block-1',
		block_name: 'core/paragraph',
		exact_text: exactText,
		occurrence,
		unit_key: null,
		source_kind: 'deterministic',
	};
}

describe( 'exact RichText range identity', () => {
	test( 'the insertion allowlist is exact', () => {
		expect( [ ...INSERTION_BLOCKS ] ).toEqual( [
			'core/paragraph',
			'core/heading',
			'core/list-item',
		] );
	} );

	test( 'repeated phrases require the submitted zero-based occurrence', () => {
		const text = 'repeat, repeat, repeat';
		expect( findExactOccurrence( text, 'repeat', 0 ) ).toEqual( {
			start: 0,
			end: 6,
		} );
		expect( findExactOccurrence( text, 'repeat', 1 ) ).toEqual( {
			start: 8,
			end: 14,
		} );
		expect( findExactOccurrence( text, 'repeat', 3 ) ).toBeNull();
	} );

	test( 'matching is case-sensitive and non-overlapping', () => {
		expect( findExactOccurrence( 'Link link', 'link', 0 ) ).toEqual( {
			start: 5,
			end: 9,
		} );
		expect( findExactOccurrence( 'aaaa', 'aa', 1 ) ).toEqual( {
			start: 2,
			end: 4,
		} );
		expect( findExactOccurrence( 'Link', 'link', 0 ) ).toBeNull();
	} );

	test( 'JavaScript computes UTF-16 indices for emoji and supplementary Unicode', () => {
		const text = 'A🙂 𐐷 exact';
		expect( findExactOccurrence( text, 'exact', 0 ) ).toEqual( {
			start: 7,
			end: 12,
		} );
	} );

	test.each( [
		[ 'core/paragraph', '<strong>Exact</strong> phrase' ],
		[ 'core/heading', '<em>Exact</em> phrase' ],
		[ 'core/list-item', '<code>Exact</code> phrase' ],
	] )( 'parses a safe %s direct content range', ( name, content ) => {
		expect(
			inspectInsertionRange( block( name, content ), 'Exact', 0 )
		).toMatchObject( {
			status: 'ready',
			range: { start: 0, end: 5 },
		} );
	} );

	test.each( [
		'core/quote',
		'core/pullquote',
		'core/verse',
		'core/preformatted',
		'core/table',
		'core/image',
		'core/gallery',
		'acme/custom',
	] )( 'rejects unsupported block %s', ( name ) => {
		expect(
			inspectInsertionRange( block( name, 'Exact' ), 'Exact', 0 ).status
		).toBe( 'unsupported' );
	} );

	test( 'rejects absent content and a stale exact occurrence', () => {
		expect(
			inspectInsertionRange(
				{ name: 'core/paragraph', attributes: {} },
				'Exact',
				0
			).status
		).toBe( 'unsupported' );
		expect(
			inspectInsertionRange(
				block( 'core/paragraph', 'Exact' ),
				'exact',
				0
			).status
		).toBe( 'stale' );
	} );
} );

describe( 'RichText link mutation', () => {
	test.each( [
		[ 'bold', '<strong>Exact phrase</strong>' ],
		[ 'italic', '<em>Exact phrase</em>' ],
		[ 'code', '<code>Exact phrase</code>' ],
		[ 'strikethrough', '<s>Exact phrase</s>' ],
		[
			'underline',
			'<span style="text-decoration:underline">Exact phrase</span>',
		],
		[ 'text color', '<span style="color:#f00">Exact phrase</span>' ],
		[ 'highlight', '<mark>Exact phrase</mark>' ],
		[
			'mixed formats',
			'<strong>Exact <em>phrase</em></strong> &amp; punctuation 🙂.',
		],
	] )( 'adds only core/link while preserving %s', ( label, html ) => {
		const current = create( { html } );
		const exactText = current.text.startsWith( 'Exact phrase' )
			? 'Exact phrase'
			: current.text.slice( 0, 12 );
		const result = applyValidatedLink(
			block( 'core/paragraph', html ),
			evidence( exactText ),
			'https://example.test/current/'
		);
		expect( result.status ).toBe( 'ready' );
		const next = create( { html: result.nextContent } );
		expect( next.text ).toBe( current.text );
		expect( result.nextContent ).toContain(
			'<a href="https://example.test/current/">'
		);
		expect( result.nextContent ).not.toMatch( /data-|target=|rel=|class=/ );
	} );

	test( 'links the selected repeated occurrence only', () => {
		const result = applyValidatedLink(
			block( 'core/paragraph', 'Exact and Exact' ),
			evidence( 'Exact', 1 ),
			'https://example.test/current/'
		);
		expect( result.status ).toBe( 'ready' );
		expect( result.nextContent ).toBe(
			'Exact and <a href="https://example.test/current/">Exact</a>'
		);
	} );

	test( 'preserves entities, punctuation, Unicode, and emoji text', () => {
		const html = 'Before &amp; 🙂 “Exact” 𐐷 after.';
		const result = applyValidatedLink(
			block( 'core/paragraph', html ),
			evidence( '“Exact”' ),
			'https://example.test/current/'
		);
		expect( result.status ).toBe( 'ready' );
		expect( create( { html: result.nextContent } ).text ).toBe(
			create( { html } ).text
		);
	} );

	test( 'rejects same-link and different-link overlap without serialization', () => {
		const same = block(
			'core/paragraph',
			'<a href="https://example.test/current/">Exact</a>'
		);
		expect( inspectInsertionRange( same, 'Exact', 0 ).status ).toBe(
			'overlap'
		);
		expect(
			applyValidatedLink(
				same,
				evidence( 'Exact' ),
				'https://example.test/current/'
			).status
		).toBe( 'already-linked' );
		expect(
			applyValidatedLink(
				block(
					'core/paragraph',
					'<a href="https://example.test/other/">Exact phrase</a>'
				),
				evidence( 'phrase' ),
				'https://example.test/current/'
			).status
		).toBe( 'overlap' );
	} );

	test( 'rejects a range that crosses a RichText replacement object', () => {
		const current = block(
			'core/paragraph',
			'Before <img src="https://example.test/image.jpg" alt="replacement"> after'
		);
		const parsed = create( { html: current.attributes.content } );
		expect( parsed.replacements.some( Boolean ) ).toBe( true );
		expect( inspectInsertionRange( current, parsed.text, 0 ).status ).toBe(
			'replacement-overlap'
		);
	} );

	test( 'prepares and mutates a maximum-size allowed unit under 100 ms', () => {
		const current = block(
			'core/paragraph',
			`${ 'bounded '.repeat( 1800 ) }Exact`
		);
		const started = performance.now();
		const result = applyValidatedLink(
			current,
			evidence( 'Exact' ),
			'https://example.test/current/'
		);
		const elapsed = performance.now() - started;
		expect( result.status ).toBe( 'ready' );
		expect( elapsed ).toBeLessThan( 100 );
	} );

	test( 'retains the current content representation identity', () => {
		expect( contentIdentity( 'Exact' ) ).toContain(
			'"representation":"string"'
		);
		expect( contentIdentity( null ) ).toBe( '' );
		const richText = RichTextData.fromHTMLString( 'Exact' );
		const result = applyValidatedLink(
			block( 'core/paragraph', richText ),
			evidence( 'Exact' ),
			'https://example.test/current/'
		);
		expect( result.status ).toBe( 'ready' );
		expect( result.nextContent ).toBeInstanceOf( RichTextData );
	} );

	test( 'dispatches exactly once and a repeated current-block attempt is a no-op', () => {
		const current = block( 'core/paragraph', 'Exact' );
		const update = jest.fn( ( clientId, attributes ) => {
			expect( clientId ).toBe( 'block-1' );
			current.attributes = attributes;
		} );
		expect(
			commitValidatedLink(
				current,
				evidence( 'Exact' ),
				'https://example.test/current/',
				update
			).status
		).toBe( 'inserted' );
		expect( update ).toHaveBeenCalledTimes( 1 );
		expect(
			commitValidatedLink(
				current,
				evidence( 'Exact' ),
				'https://example.test/current/',
				update
			).status
		).toBe( 'already-linked' );
		expect( update ).toHaveBeenCalledTimes( 1 );
	} );
} );

describe( 'bounded insertion evidence', () => {
	const suggestion = {
		target_post_id: 7,
		location: {
			block_client_id: 'block-1',
			block_name: 'core/paragraph',
			anchor_text: 'Exact',
			start: 2,
		},
	};

	test( 'maps the PHP Unicode location hint to one exact JS occurrence', () => {
		const resolved = resolveInsertionEvidence( suggestion, null, () =>
			block( 'core/paragraph', '🙂 Exact and Exact' )
		);
		expect( resolved ).toMatchObject( {
			exact_text: 'Exact',
			occurrence: 0,
			source_kind: 'deterministic',
		} );
	} );

	test( 'never falls back to another occurrence after a stale location', () => {
		expect(
			resolveInsertionEvidence( suggestion, null, () =>
				block( 'core/paragraph', 'Exact and Exact' )
			)
		).toBeNull();
	} );

	test.each( [
		[ null, 'block disappearance' ],
		[ block( 'core/heading', '🙂 Exact and Exact' ), 'block name change' ],
		[
			block( 'core/paragraph', '🙂 Changed and Exact' ),
			'attribute change',
		],
	] )( 'rejects %s', ( currentBlock ) => {
		expect(
			resolveInsertionEvidence( suggestion, null, () => currentBlock )
		).toBeNull();
	} );

	test( 'uses AI anchor evidence only when it maps to a current allowed block', () => {
		const aiEvaluation = {
			anchor: {
				unit_key: 'u1',
				block_client_id: 'block-1',
				block_name: 'core/paragraph',
				exact_text: 'Exact',
				occurrence: 1,
			},
		};
		expect(
			resolveInsertionEvidence( suggestion, aiEvaluation, () =>
				block( 'core/paragraph', 'Exact and Exact' )
			)
		).toMatchObject( { occurrence: 1, unit_key: 'u1', source_kind: 'ai' } );
	} );

	test( 'collects current unsaved draft link forms and enforces bounds', () => {
		const blocks = [
			block(
				'core/paragraph',
				'<a href="/relative/">Relative</a> <a href="?p=7">Query</a>'
			),
		];
		expect(
			collectDraftLinks( blocks, { maxDraftLinks: 2, maxLinkBytes: 64 } )
		).toEqual( [ '/relative/', '?p=7' ] );
		expect( () =>
			collectDraftLinks( blocks, { maxDraftLinks: 1, maxLinkBytes: 64 } )
		).toThrow( /too many links/ );
	} );

	test( 'accepts only matching bounded server validation evidence', () => {
		const expected = {
			contractVersion: 1,
			analysisId: 'a'.repeat( 64 ),
			draftHash: 'b'.repeat( 64 ),
			targetPostId: 7,
			sourceKind: 'deterministic',
			anchor: evidence( 'Exact' ),
			markupIdentity: 'c'.repeat( 64 ),
			textIdentity: 'd'.repeat( 64 ),
		};
		const response = {
			contract_version: 1,
			analysis_id: expected.analysisId,
			draft_hash: expected.draftHash,
			target_post_id: 7,
			current_permalink: 'https://example.test/current/',
			source_kind: 'deterministic',
			block: {
				client_id: 'block-1',
				name: 'core/paragraph',
				attribute: 'content',
				markup_identity: expected.markupIdentity,
				text_identity: expected.textIdentity,
			},
			anchor: { exact_text: 'Exact', occurrence: 0 },
		};
		expect( isCurrentValidationResponse( response, expected ) ).toBe(
			true
		);
		expect(
			isCurrentValidationResponse(
				{ ...response, target_post_id: 8 },
				expected
			)
		).toBe( false );
		expect(
			isCurrentValidationResponse(
				{ ...response, current_permalink: 'javascript:bad' },
				expected
			)
		).toBe( false );
	} );
} );
