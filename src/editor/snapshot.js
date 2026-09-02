import { serialize } from '@wordpress/blocks';

export const SUPPORTED_BLOCKS = new Set( [
	'core/paragraph',
	'core/heading',
	'core/list-item',
	'core/pullquote',
	'core/verse',
	'core/preformatted',
	'core/table',
	'core/image',
	'core/audio',
	'core/video',
] );

export const EXTERNAL_ENTITY_BLOCKS = new Set( [
	'core/block',
	'core/template-part',
	'core/navigation',
] );

const sortNumericUnique = ( values ) =>
	[
		...new Set(
			( Array.isArray( values ) ? values : [] )
				.filter( Number.isInteger )
				.filter( ( value ) => value > 0 )
		),
	].sort( ( left, right ) => left - right );

export function collectUnits( blocks, limits ) {
	const units = [];
	const encoder = new TextEncoder();

	const visit = ( block ) => {
		if ( ! block || EXTERNAL_ENTITY_BLOCKS.has( block.name ) ) {
			return;
		}

		if ( SUPPORTED_BLOCKS.has( block.name ) ) {
			const markup = serialize( [ block ] );
			if ( encoder.encode( markup ).length > limits.unitBytes ) {
				throw new Error(
					'One supported block exceeds the analysis size limit.'
				);
			}
			units.push( {
				client_id: block.clientId,
				block_name: block.name,
				markup,
			} );
			if ( units.length > limits.units ) {
				throw new Error(
					'This draft has too many supported blocks to analyze.'
				);
			}
			return;
		}

		( block.innerBlocks || [] ).forEach( visit );
	};

	( blocks || [] ).forEach( visit );
	return units;
}

export function buildSnapshot( editorState, settings ) {
	const taxonomies = {};
	Object.entries( settings.taxonomyFields || {} )
		.sort( ( [ left ], [ right ] ) => left.localeCompare( right ) )
		.forEach( ( [ taxonomy, field ] ) => {
			taxonomies[ taxonomy ] = sortNumericUnique(
				editorState.taxonomies[ field ]
			);
		} );

	const payload = {
		post_id: Number.isInteger( editorState.postId )
			? editorState.postId
			: 0,
		post_type: editorState.postType,
		title: typeof editorState.title === 'string' ? editorState.title : '',
		taxonomies,
		units: collectUnits( editorState.blocks, settings.limits ),
	};

	const json = JSON.stringify( payload );
	if (
		new TextEncoder().encode( json ).length > settings.limits.payloadBytes
	) {
		throw new Error( 'This draft exceeds the 256 KiB analysis limit.' );
	}

	return { payload, signature: json };
}

export async function sha256( value ) {
	const bytes = new TextEncoder().encode( value );
	const digest = await globalThis.crypto.subtle.digest( 'SHA-256', bytes );
	return Array.from( new Uint8Array( digest ) )
		.map( ( byte ) => byte.toString( 16 ).padStart( 2, '0' ) )
		.join( '' );
}

export function clientHashInput( snapshot ) {
	return JSON.stringify( {
		algorithm_version: 1,
		...snapshot.payload,
	} );
}
