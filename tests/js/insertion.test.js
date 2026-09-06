import { create, registerFormatType, RichTextData } from '@wordpress/rich-text';
import {
	INSERTION_BLOCKS,
	applyValidatedLink,
	collectDraftLinks,
	commitValidatedLink,
	contentIdentity,
	findExactOccurrence,
	inspectInsertionRange,
	inspectSuggestionInsertion,
	isCurrentValidationResponse,
	resolveInsertionEvidence,
	richTextPlainText,
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

	test( 'maps entities, Unicode, emoji, inline formatting, spaces, and line breaks exactly', () => {
		expect(
			richTextPlainText(
				block(
					'core/paragraph',
					'<strong>café &amp; resolve 🙂</strong>  keep<br>moving'
				)
			)
		).toBe( 'café & resolve 🙂  keep\nmoving' );
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

	test( 'preserves an existing non-overlapping link exactly', () => {
		const html =
			'<a href="https://example.test/existing/">Existing</a> then Exact';
		const result = applyValidatedLink(
			block( 'core/paragraph', html ),
			evidence( 'Exact' ),
			'https://example.test/current/'
		);
		expect( result.status ).toBe( 'ready' );
		expect( result.nextContent ).toBe(
			'<a href="https://example.test/existing/">Existing</a> then <a href="https://example.test/current/">Exact</a>'
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
			'linked'
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
		process.stdout.write(
			`\n0.5 client maximum-unit fixture: ${ elapsed.toFixed( 3 ) } ms\n`
		);
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
		target_permalink: 'https://example.test/current/',
		location: {
			block_client_id: 'block-1',
			block_name: 'core/paragraph',
			anchor_text: 'Exact',
			start: 999,
			occurrence: 0,
		},
	};

	test( 'uses exact text plus occurrence and never treats a PHP offset as a JS index', () => {
		const resolved = resolveInsertionEvidence( suggestion, null, () =>
			block( 'core/paragraph', '🙂 Exact and Exact' )
		);
		expect( resolved ).toMatchObject( {
			exact_text: 'Exact',
			occurrence: 0,
			source_kind: 'deterministic',
		} );
	} );

	test( 'exact text remains case-sensitive', () => {
		expect(
			resolveInsertionEvidence(
				{
					...suggestion,
					location: { ...suggestion.location, anchor_text: 'exact' },
				},
				null,
				() => block( 'core/paragraph', 'Exact and Exact' )
			)
		).toBeNull();
	} );

	test( 'selects the first currently insertable bounded alternate', () => {
		const alternateSuggestion = {
			...suggestion,
			location_candidates: [
				{
					...suggestion.location,
					block_client_id: 'linked',
				},
				{
					...suggestion.location,
					block_client_id: 'safe',
					anchor_text: 'keep moving',
				},
			],
		};
		const availability = inspectSuggestionInsertion(
			alternateSuggestion,
			null,
			( clientId ) =>
				clientId === 'linked'
					? block(
							'core/paragraph',
							'<a href="https://example.test/other/">Exact</a>',
							'linked'
					  )
					: block( 'core/paragraph', 'We keep moving today.', 'safe' )
		);
		expect( availability.evidence ).toMatchObject( {
			block_client_id: 'safe',
			exact_text: 'keep moving',
			occurrence: 0,
		} );
		expect( availability.inspectionMs ).toBeGreaterThanOrEqual( 0 );
	} );

	test.each( [
		[
			'unsupported block',
			{
				block_name: 'core/pullquote',
				anchor_text: 'Exact',
			},
			block( 'core/pullquote', 'Exact', 'unsafe' ),
		],
		[
			'already-linked range',
			{ anchor_text: 'Exact' },
			block(
				'core/paragraph',
				'<a href="https://example.test/other/">Exact</a>',
				'unsafe'
			),
		],
		[
			'another-link overlap',
			{ anchor_text: 'Exact phrase' },
			block(
				'core/paragraph',
				'<a href="https://example.test/other/">Exact</a> phrase',
				'unsafe'
			),
		],
		[
			'RichText replacement overlap',
			{ anchor_text: 'Before  after' },
			block(
				'core/paragraph',
				'Before <img src="x.jpg" alt="replacement"> after',
				'unsafe'
			),
		],
	] )(
		'skips an %s candidate and selects a later safe candidate',
		( label, first, unsafeBlock ) => {
			const safe = {
				...suggestion.location,
				block_client_id: 'safe',
				anchor_text: 'keep moving',
			};
			const availability = inspectSuggestionInsertion(
				{
					...suggestion,
					location_candidates: [
						{
							...suggestion.location,
							block_client_id: 'unsafe',
							...first,
						},
						safe,
					],
				},
				null,
				( clientId ) =>
					clientId === 'unsafe'
						? unsafeBlock
						: block( 'core/paragraph', 'We keep moving.', 'safe' )
			);
			expect( availability.location ).toBe( safe );
			expect( availability.evidence ).toMatchObject( {
				block_client_id: 'safe',
				exact_text: 'keep moving',
			} );
		}
	);

	test( 'skips unsafe occurrence zero and selects a later occurrence in the same block', () => {
		const first = { ...suggestion.location, block_client_id: 'repeated' };
		const second = { ...first, occurrence: 1 };
		const availability = inspectSuggestionInsertion(
			{
				...suggestion,
				location_candidates: [ first, second ],
			},
			null,
			() =>
				block(
					'core/paragraph',
					'<a href="https://example.test/other/">Exact</a> then Exact',
					'repeated'
				)
		);
		expect( availability.location ).toBe( second );
		expect( availability.evidence ).toMatchObject( {
			exact_text: 'Exact',
			occurrence: 1,
		} );
	} );

	test( 'inspects the maximum bounded location set within the interactive budget', () => {
		const candidates = Array.from( { length: 8 }, ( unused, index ) => ( {
			...suggestion.location,
			block_client_id: `candidate-${ index }`,
		} ) );
		const started = performance.now();
		const availability = inspectSuggestionInsertion(
			{ ...suggestion, location_candidates: candidates },
			null,
			( clientId ) => {
				const index = Number( clientId.replace( 'candidate-', '' ) );
				return block(
					'core/paragraph',
					index === 7
						? 'Exact'
						: '<a href="https://example.test/other/">Exact</a>',
					clientId
				);
			}
		);
		const elapsed = performance.now() - started;
		process.stdout.write(
			`\n0.6.1 client location fixture: 8 candidates, ${ elapsed.toFixed(
				3
			) } ms\n`
		);
		expect( availability.evidence.block_client_id ).toBe( 'candidate-7' );
		expect( elapsed ).toBeLessThan( 100 );
	} );

	test( 'reaches a later specific suffix after early linked full-title candidates', () => {
		const candidates = Array.from( { length: 7 }, ( unused, index ) => ( {
			...suggestion.location,
			block_client_id: `full-title-${ index }`,
			anchor_text: 'Discipline Dispatch: Keep Moving',
			occurrence: index,
		} ) );
		const safe = {
			...suggestion.location,
			block_client_id: 'later-safe',
			anchor_text: 'keep moving',
			occurrence: 0,
		};
		candidates.push( safe );
		const availability = inspectSuggestionInsertion(
			{ ...suggestion, location_candidates: candidates },
			null,
			( clientId ) =>
				clientId === 'later-safe'
					? block(
							'core/paragraph',
							'We keep moving after the unsafe matches.',
							clientId
					  )
					: block(
							'core/paragraph',
							'<a href="https://example.test/other/">Discipline Dispatch: Keep Moving</a>',
							clientId
					  )
		);
		expect( availability.location ).toBe( safe );
		expect( availability.evidence ).toMatchObject( {
			block_client_id: 'later-safe',
			exact_text: 'keep moving',
			occurrence: 0,
		} );
	} );

	test( 'keeps a deterministic read-only reason paired with its failed location', () => {
		const changed = {
			...suggestion.location,
			block_client_id: 'changed',
			anchor_text: 'Changed phrase',
			excerpt: 'Changed context.',
		};
		const linked = {
			...suggestion.location,
			block_client_id: 'linked',
			anchor_text: 'Linked phrase',
			excerpt: 'Linked context.',
		};
		const inspect = () =>
			inspectSuggestionInsertion(
				{
					...suggestion,
					location_candidates: [ changed, linked ],
				},
				null,
				( clientId ) =>
					clientId === 'linked'
						? block(
								'core/paragraph',
								'<a href="https://example.test/other/">Linked phrase</a>',
								clientId
						  )
						: block( 'core/paragraph', 'Changed copy.', clientId )
			);
		const first = inspect();
		const second = inspect();
		expect( first.evidence ).toBeNull();
		expect( first.reason ).toBe( 'already-linked' );
		expect( first.location ).toBe( linked );
		expect( second.reason ).toBe( first.reason );
		expect( second.location ).toBe( linked );
	} );

	test.each( [
		[
			'<a href="https://example.test/other/">Exact</a>',
			'Already linked',
			'already-linked',
		],
		[
			'<a href="https://example.test/other/">Ex</a>act',
			'Partial overlap',
			'link-overlap',
		],
		[
			'Before <img src="x.jpg" alt="replacement"> after',
			'Replacement overlap',
			'replacement-overlap',
		],
	] )( 'reports precise read-only reason for %s', ( html, label, reason ) => {
		const current = block( 'core/paragraph', html );
		const exactText =
			label === 'Replacement overlap' ? create( { html } ).text : 'Exact';
		const availability = inspectSuggestionInsertion(
			{
				...suggestion,
				location: { ...suggestion.location, anchor_text: exactText },
			},
			null,
			() => current
		);
		expect( availability.evidence ).toBeNull();
		expect( availability.reason ).toBe( reason );
	} );

	test.each( [
		[ null, 'block disappearance' ],
		[ block( 'core/heading', '🙂 Exact and Exact' ), 'block name change' ],
		[ block( 'core/paragraph', '🙂 Changed only' ), 'attribute change' ],
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
