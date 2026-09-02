import { fireEvent, render, screen } from '@testing-library/react';
import { AnalysisBody } from '../../src/editor/components';

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
} );
