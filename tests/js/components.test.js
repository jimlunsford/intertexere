import { fireEvent, render, screen } from '@testing-library/react';
import { inspectSuggestionInsertion } from '../../src/editor/insertion';
import {
	AIControls,
	AnalysisBody,
	insertionReadOnlyMessage,
} from '../../src/editor/components';

jest.mock( '@wordpress/components', () => {
	// JSX in this isolated package-boundary mock needs the WordPress element runtime.
	// eslint-disable-next-line @wordpress/no-unused-vars-before-return
	const React = require( '@wordpress/element' );
	return {
		Button: ( {
			href,
			children,
			icon: ignoredIcon,
			variant: ignoredVariant,
			isBusy: ignoredBusy,
			...props
		} ) =>
			href ? (
				<a href={ href } { ...props }>
					{ children }
				</a>
			) : (
				<button { ...props }>{ children }</button>
			),
		Notice: ( { children } ) => <div role="alert">{ children }</div>,
		Placeholder: ( { children } ) => <div>{ children }</div>,
		Spinner: () => <span aria-label="loading" />,
	};
} );
jest.mock( '@wordpress/icons', () => ( { external: 'external' } ) );
jest.mock( '@wordpress/i18n', () => ( { __: ( value ) => value } ) );
jest.mock( '@wordpress/blocks', () => ( { serialize: jest.fn() } ) );

const suggestion = {
	target_post_id: 14,
	target_title: '<b>Literal destination</b>',
	target_permalink: 'https://example.test/destination/',
	score: 50,
	reason: { label: 'The draft uses terms from this article title.' },
	already_linked: false,
	location: {
		anchor_text: 'literal destination',
		excerpt: 'A literal destination appears here.',
		occurrence: 0,
	},
};

describe( 'analysis presentation', () => {
	test( 'a server-rejected shared heading stays read-only with View and Dismiss', () => {
		const readOnly = {
			...suggestion,
			location: null,
			location_candidates: [],
			location_status: 'no-specific-phrase',
		};
		const onInsert = jest.fn();
		const onDismiss = jest.fn();
		const getBlock = jest.fn( () => ( {
			name: 'core/heading',
			attributes: { content: 'New Here?' },
		} ) );
		const availability = inspectSuggestionInsertion(
			readOnly,
			null,
			getBlock
		);
		expect( availability ).toMatchObject( {
			evidence: null,
			location: null,
			reason: 'no-specific-phrase',
		} );
		render(
			<AnalysisBody
				status="results"
				error=""
				response={ { suggestions: [ readOnly ] } }
				suggestions={ [ readOnly ] }
				stale={ false }
				onDismiss={ onDismiss }
				insertionEvidence={ new Map() }
				insertionAvailability={ new Map( [ [ 14, availability ] ] ) }
				insertionStates={ {} }
				onInsert={ onInsert }
			/>
		);
		expect(
			screen.queryByRole( 'button', { name: 'Insert Link' } )
		).toBeNull();
		expect( screen.getByRole( 'link', { name: /View/ } ) ).toBeVisible();
		expect(
			screen.getByText( /No destination-specific phrase/ )
		).toBeVisible();
		expect(
			screen.queryByText( /Proposed phrase:|Draft context:|New Here/ )
		).toBeNull();
		fireEvent.click( screen.getByRole( 'button', { name: 'Dismiss' } ) );
		expect( onDismiss ).toHaveBeenCalledTimes( 1 );
		expect( onInsert ).not.toHaveBeenCalled();
		expect( getBlock ).not.toHaveBeenCalled();
	} );

	test.each( [
		[ 'no-specific-phrase', /No destination-specific phrase/ ],
		[ 'unsupported-block', /block Intertexere does not edit/ ],
		[ 'already-linked', /phrase is already linked/ ],
		[ 'link-overlap', /overlaps another link/ ],
		[ 'replacement-overlap', /non-text editor object/ ],
		[ 'changed', /changed after analysis/ ],
		[ 'unmappable', /could not be mapped safely/ ],
		[ 'unknown-code', /not currently available/ ],
	] )(
		'maps %s to a precise safe read-only explanation',
		( code, expected ) => {
			expect( insertionReadOnlyMessage( code ) ).toMatch( expected );
		}
	);
	test( 'renders Insert Link only for current exact insertion evidence and requires a click', () => {
		const onInsert = jest.fn();
		const insertionEvidence = new Map( [
			[
				14,
				{
					block_client_id: 'paragraph-1',
					block_name: 'core/paragraph',
					exact_text: 'literal destination',
					occurrence: 0,
				},
			],
		] );
		render(
			<AnalysisBody
				status="results"
				error=""
				response={ { suggestions: [ suggestion ] } }
				suggestions={ [ suggestion ] }
				stale={ false }
				onDismiss={ jest.fn() }
				insertionEvidence={ insertionEvidence }
				insertionStates={ {} }
				onInsert={ onInsert }
			/>
		);
		expect( onInsert ).not.toHaveBeenCalled();
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Insert Link' } )
		);
		expect( onInsert ).toHaveBeenCalledTimes( 1 );
		expect( onInsert ).toHaveBeenCalledWith(
			suggestion,
			insertionEvidence.get( 14 )
		);
	} );

	test( 'renders the location paired with the selected read-only reason', () => {
		const linked = {
			anchor_text: 'linked phrase',
			excerpt: 'Linked context belongs to this failure.',
			occurrence: 0,
		};
		render(
			<AnalysisBody
				status="results"
				error=""
				response={ { suggestions: [ suggestion ] } }
				suggestions={ [ suggestion ] }
				stale={ false }
				onDismiss={ jest.fn() }
				insertionEvidence={ new Map() }
				insertionAvailability={
					new Map( [
						[
							14,
							{
								evidence: null,
								location: linked,
								reason: 'already-linked',
							},
						],
					] )
				}
				insertionStates={ {} }
				onInsert={ jest.fn() }
			/>
		);
		expect( screen.getByText( '“linked phrase”' ) ).toBeVisible();
		expect(
			screen.getByText( 'Linked context belongs to this failure.' )
		).toBeVisible();
		expect( screen.getByText( /phrase is already linked/ ) ).toBeVisible();
		expect( screen.queryByText( '“literal destination”' ) ).toBeNull();
		expect(
			screen.queryByText( 'A literal destination appears here.' )
		).toBeNull();
	} );

	test( 'does not fall back to an unrelated phrase for an aggregate reason', () => {
		render(
			<AnalysisBody
				status="results"
				error=""
				response={ { suggestions: [ suggestion ] } }
				suggestions={ [ suggestion ] }
				stale={ false }
				onDismiss={ jest.fn() }
				insertionEvidence={ new Map() }
				insertionAvailability={
					new Map( [
						[
							14,
							{
								evidence: null,
								location: null,
								reason: 'no-specific-phrase',
							},
						],
					] )
				}
				insertionStates={ {} }
				onInsert={ jest.fn() }
			/>
		);
		expect(
			screen.getByText( /No destination-specific phrase/ )
		).toBeVisible();
		expect( screen.queryByText( '“literal destination”' ) ).toBeNull();
		expect(
			screen.queryByText( 'A literal destination appears here.' )
		).toBeNull();
	} );

	test( 'exposes validating and announced success states accessibly', () => {
		const insertionEvidence = new Map( [
			[ 14, { exact_text: 'literal destination' } ],
		] );
		const { rerender } = render(
			<AnalysisBody
				status="results"
				error=""
				response={ { suggestions: [ suggestion ] } }
				suggestions={ [ suggestion ] }
				stale={ false }
				onDismiss={ jest.fn() }
				insertionEvidence={ insertionEvidence }
				insertionStates={ {
					14: {
						status: 'validating',
						message: 'Validation in progress.',
					},
				} }
				onInsert={ jest.fn() }
			/>
		);
		const validating = screen.getByRole( 'button', {
			name: 'Insert Link',
		} );
		expect( validating ).toBeDisabled();
		expect( validating ).toHaveTextContent( 'Validating…' );
		expect( screen.getByRole( 'status' ) ).toHaveTextContent(
			'Validation in progress.'
		);

		rerender(
			<AnalysisBody
				status="results"
				error=""
				response={ { suggestions: [ suggestion ] } }
				suggestions={ [ suggestion ] }
				stale={ false }
				onDismiss={ jest.fn() }
				insertionEvidence={ insertionEvidence }
				insertionStates={ {
					14: { status: 'inserted', message: 'Link inserted.' },
				} }
				onInsert={ jest.fn() }
			/>
		);
		expect(
			screen.queryByRole( 'button', { name: 'Insert Link' } )
		).toBeNull();
		expect( screen.getByRole( 'status' ) ).toHaveTextContent(
			'Link inserted.'
		);
	} );

	test.each( [
		[ 'ready', /Analyze the unsaved draft/ ],
		[ 'loading', /Analyzing the current draft/ ],
		[ 'unavailable', /Index unavailable/ ],
		[ 'error', /Request failed/ ],
	] )( 'renders the %s state', ( status, expected ) => {
		render(
			<AnalysisBody
				status={ status }
				error={
					status === 'unavailable'
						? 'Index unavailable'
						: 'Request failed'
				}
				response={ null }
				suggestions={ [] }
				stale={ false }
				onDismiss={ jest.fn() }
			/>
		);
		expect( screen.getByText( expected ) ).toBeInTheDocument();
	} );

	test( 'renders an explicit no-suggestion state', () => {
		render(
			<AnalysisBody
				status="results"
				error=""
				response={ { suggestions: [] } }
				suggestions={ [] }
				stale={ false }
				onDismiss={ jest.fn() }
			/>
		);
		expect(
			screen.getByText( /No current suggestion/ )
		).toBeInTheDocument();
	} );

	test( 'renders an all-dropped AI state without hiding deterministic availability', () => {
		render(
			<AnalysisBody
				status="results"
				error=""
				response={ { suggestions: [ suggestion ] } }
				suggestions={ [] }
				stale={ false }
				onDismiss={ jest.fn() }
				enhancedMode
			/>
		);
		expect(
			screen.getByText( /AI kept no candidates/ )
		).toBeInTheDocument();
	} );

	test( 'renders safe suggestion text, View, Dismiss, and stale state without insertion', () => {
		const onDismiss = jest.fn();
		render(
			<AnalysisBody
				status="results"
				error=""
				response={ { suggestions: [ suggestion ] } }
				suggestions={ [ suggestion ] }
				stale
				onDismiss={ onDismiss }
			/>
		);
		expect(
			screen.getByText( '<b>Literal destination</b>' )
		).toBeInTheDocument();
		expect( screen.queryByText( 'Insert Link' ) ).not.toBeInTheDocument();
		expect( screen.getByRole( 'link', { name: /View/ } ) ).toHaveAttribute(
			'href',
			suggestion.target_permalink
		);
		expect( screen.getByText( /older draft state/ ) ).toBeInTheDocument();
		fireEvent.click( screen.getByRole( 'button', { name: 'Dismiss' } ) );
		expect( onDismiss ).toHaveBeenCalledWith( 14 );
	} );

	test( 'renders AI rank, escaped explanation, and exact anchor separately from deterministic score', () => {
		const evaluations = new Map( [
			[
				14,
				{
					rank: 1,
					reason: '<script>literal explanation</script>',
					anchor: { exact_text: '<em>existing phrase</em>' },
				},
			],
		] );
		render(
			<AnalysisBody
				status="results"
				error=""
				response={ { suggestions: [ suggestion ] } }
				suggestions={ [ suggestion ] }
				stale={ false }
				onDismiss={ jest.fn() }
				aiEvaluations={ evaluations }
				enhancedMode
			/>
		);
		expect( screen.getByText( '50' ) ).toBeInTheDocument();
		expect( screen.getByText( '1' ) ).toBeInTheDocument();
		expect(
			screen.getByText( '<script>literal explanation</script>' )
		).toBeInTheDocument();
		expect(
			screen.getByText( /<em>existing phrase<\/em>/ )
		).toBeInTheDocument();
		expect( document.querySelector( 'script' ) ).toBeNull();
		expect( screen.queryByText( 'Insert Link' ) ).not.toBeInTheDocument();
	} );
} );

describe( 'AI controls', () => {
	test.each( [ 'ready', 'loading', 'enhanced' ] )(
		'renders the explicit control for %s state',
		( status ) => {
			render(
				<AIControls
					configured
					status={ status }
					error=""
					onEnhance={ jest.fn() }
					onToggleMode={ jest.fn() }
					enhancedMode={ false }
					hasDeterministicResults
				/>
			);
			const button = screen.getByRole( 'button', {
				name:
					status === 'loading'
						? /Enhancing with AI/
						: /Enhance with AI/,
			} );
			expect( button ).toBeInTheDocument();
			expect( button.disabled ).toBe( status === 'loading' );
		}
	);

	test.each( [
		[ 'disabled', /disabled in Intertexere settings/ ],
		[ 'unavailable', /No compatible configured AI model/ ],
		[ 'failed', /Deterministic suggestions are unchanged/ ],
		[ 'stale', /AI enhancement is stale/ ],
	] )( 'renders the %s fallback state', ( status, expected ) => {
		render(
			<AIControls
				configured={ false }
				status={ status }
				error=""
				onEnhance={ jest.fn() }
				onToggleMode={ jest.fn() }
				enhancedMode={ false }
				hasDeterministicResults
			/>
		);
		expect( screen.getByText( expected ) ).toBeInTheDocument();
		expect(
			screen.getByText( /Provider processing and retention/ )
		).toBeInTheDocument();
	} );

	test( 'requires an explicit click and exposes deterministic mode after enhancement', () => {
		const onEnhance = jest.fn();
		const onToggleMode = jest.fn();
		render(
			<AIControls
				configured
				status="enhanced"
				error=""
				onEnhance={ onEnhance }
				onToggleMode={ onToggleMode }
				enhancedMode
				hasDeterministicResults
			/>
		);
		expect( onEnhance ).not.toHaveBeenCalled();
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Enhance with AI' } )
		);
		expect( onEnhance ).toHaveBeenCalledTimes( 1 );
		fireEvent.click(
			screen.getByRole( 'button', {
				name: /View deterministic suggestions/,
			} )
		);
		expect( onToggleMode ).toHaveBeenCalledTimes( 1 );
		expect( screen.queryByText( 'Insert Link' ) ).not.toBeInTheDocument();
	} );
} );
