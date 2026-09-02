import { fireEvent, render, screen } from '@testing-library/react';
import { AIControls, AnalysisBody } from '../../src/editor/components';

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
	},
};

describe( 'analysis presentation', () => {
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
			[ 14, {
				rank: 1,
				reason: '<script>literal explanation</script>',
				anchor: { exact_text: '<em>existing phrase</em>' },
			} ],
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
		expect( screen.getByText( '<script>literal explanation</script>' ) ).toBeInTheDocument();
		expect( screen.getByText( /<em>existing phrase<\/em>/ ) ).toBeInTheDocument();
		expect( document.querySelector( 'script' ) ).toBeNull();
		expect( screen.queryByText( 'Insert Link' ) ).not.toBeInTheDocument();
	} );
} );

describe( 'AI controls', () => {
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
		expect( screen.getByText( /Provider processing and retention/ ) ).toBeInTheDocument();
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
		fireEvent.click( screen.getByRole( 'button', { name: 'Enhance with AI' } ) );
		expect( onEnhance ).toHaveBeenCalledTimes( 1 );
		fireEvent.click( screen.getByRole( 'button', { name: /View deterministic suggestions/ } ) );
		expect( onToggleMode ).toHaveBeenCalledTimes( 1 );
		expect( screen.queryByText( 'Insert Link' ) ).not.toBeInTheDocument();
	} );
} );
