import { serialize } from '@wordpress/blocks';
import {
	RichTextData,
	applyFormat,
	create,
	toHTMLString,
} from '@wordpress/rich-text';

export const INSERTION_BLOCKS = new Set( [
	'core/paragraph',
	'core/heading',
	'core/list-item',
] );

const LINK_TYPES = new Set( [ 'core/link', 'a' ] );

function isLinkFormat( format ) {
	return Boolean(
		format &&
			( LINK_TYPES.has( format.type ) ||
				String( format.tagName || '' ).toLowerCase() === 'a' )
	);
}

function linkUrl( format ) {
	return (
		format?.attributes?.url ||
		format?.attributes?.href ||
		format?.unregisteredAttributes?.href ||
		''
	);
}

function comparableObject( value ) {
	if (
		value === null ||
		[ 'string', 'number', 'boolean' ].includes( typeof value )
	) {
		return value;
	}
	if ( Array.isArray( value ) ) {
		return value.map( comparableObject );
	}
	if ( typeof value !== 'object' ) {
		return undefined;
	}

	const result = {};
	Object.keys( value )
		.filter( ( key ) => key !== 'formatType' )
		.sort()
		.forEach( ( key ) => {
			const comparable = comparableObject( value[ key ] );
			if ( comparable !== undefined ) {
				result[ key ] = comparable;
			}
		} );
	return result;
}

function formatFingerprint( format ) {
	return JSON.stringify( comparableObject( format ) );
}

function formatsFingerprint( value, includeLinks = true ) {
	return Array.from( { length: value.text.length }, ( ignored, index ) =>
		( value.formats[ index ] || [] )
			.filter( ( format ) => includeLinks || ! isLinkFormat( format ) )
			.map( formatFingerprint )
	);
}

function replacementsFingerprint( value ) {
	return JSON.stringify( comparableObject( value.replacements || [] ) );
}

export function findExactOccurrence( text, exactText, occurrence ) {
	if (
		typeof text !== 'string' ||
		typeof exactText !== 'string' ||
		exactText.length === 0 ||
		! Number.isInteger( occurrence ) ||
		occurrence < 0
	) {
		return null;
	}

	let offset = 0;
	for ( let index = 0; index <= occurrence; index += 1 ) {
		const found = text.indexOf( exactText, offset );
		if ( found === -1 ) {
			return null;
		}
		if ( index === occurrence ) {
			return { start: found, end: found + exactText.length };
		}
		offset = found + exactText.length;
	}
	return null;
}

function exactOccurrences( text, exactText ) {
	const ranges = [];
	let occurrence = 0;
	while ( true ) {
		const range = findExactOccurrence( text, exactText, occurrence );
		if ( ! range ) {
			return ranges;
		}
		ranges.push( { ...range, occurrence } );
		occurrence += 1;
	}
}

function contentValue( content ) {
	if ( content instanceof RichTextData ) {
		return {
			html: content.toHTMLString(),
			representation: 'rich-text-data',
		};
	}
	if ( typeof content === 'string' ) {
		return { html: content, representation: 'string' };
	}
	return null;
}

export function contentIdentity( content ) {
	const current = contentValue( content );
	return current
		? JSON.stringify( {
				representation: current.representation,
				html: current.html,
		  } )
		: '';
}

export function richTextPlainText( block ) {
	if (
		! block ||
		! INSERTION_BLOCKS.has( block.name ) ||
		! Object.prototype.hasOwnProperty.call(
			block.attributes || {},
			'content'
		)
	) {
		return null;
	}
	const current = contentValue( block.attributes.content );
	if ( ! current ) {
		return null;
	}
	try {
		return create( { html: current.html } ).text;
	} catch {
		return null;
	}
}

function rangeHasReplacement( value, range ) {
	for ( let index = range.start; index < range.end; index += 1 ) {
		if ( value.replacements?.[ index ] ) {
			return true;
		}
	}
	return false;
}

function linkFormatsAt( value, index ) {
	return ( value.formats?.[ index ] || [] ).filter( isLinkFormat );
}

function linkOverlapState( value, range, currentPermalink = '' ) {
	let hasLink = false;
	let exactSameTarget = Boolean( currentPermalink );
	for ( let index = range.start; index < range.end; index += 1 ) {
		const links = linkFormatsAt( value, index );
		if ( links.length ) {
			hasLink = true;
		}
		if (
			links.length !== 1 ||
			! currentPermalink ||
			linkUrl( links[ 0 ] ) !== currentPermalink
		) {
			exactSameTarget = false;
		}
	}
	if ( ! hasLink ) {
		return 'clear';
	}
	if (
		exactSameTarget &&
		linkFormatsAt( value, range.start - 1 ).length === 0 &&
		linkFormatsAt( value, range.end ).length === 0
	) {
		return 'already-linked';
	}
	return 'overlap';
}

function verifyAppliedLink( before, after, range, currentPermalink ) {
	if (
		before.text !== after.text ||
		replacementsFingerprint( before ) !==
			replacementsFingerprint( after ) ||
		JSON.stringify( formatsFingerprint( before, false ) ) !==
			JSON.stringify( formatsFingerprint( after, false ) )
	) {
		return false;
	}

	for ( let index = 0; index < after.text.length; index += 1 ) {
		const beforeLinks = linkFormatsAt( before, index ).map(
			formatFingerprint
		);
		const afterLinks = linkFormatsAt( after, index );
		if ( index >= range.start && index < range.end ) {
			if (
				afterLinks.length !== 1 ||
				linkUrl( afterLinks[ 0 ] ) !== currentPermalink
			) {
				return false;
			}
		} else if (
			JSON.stringify( beforeLinks ) !==
			JSON.stringify( afterLinks.map( formatFingerprint ) )
		) {
			return false;
		}
	}
	return true;
}

export function inspectInsertionRange( block, exactText, occurrence ) {
	if (
		! block ||
		! INSERTION_BLOCKS.has( block.name ) ||
		! Object.prototype.hasOwnProperty.call(
			block.attributes || {},
			'content'
		)
	) {
		return { status: 'unsupported' };
	}
	const current = contentValue( block.attributes.content );
	if ( ! current ) {
		return { status: 'malformed' };
	}

	try {
		const value = create( { html: current.html } );
		const range = findExactOccurrence( value.text, exactText, occurrence );
		if ( ! range ) {
			return { status: 'stale' };
		}
		if ( rangeHasReplacement( value, range ) ) {
			return { status: 'replacement-overlap' };
		}
		const overlap = linkOverlapState( value, range );
		if ( overlap !== 'clear' ) {
			return { status: overlap };
		}
		return {
			status: 'ready',
			value,
			range,
			representation: current.representation,
			contentIdentity: contentIdentity( block.attributes.content ),
		};
	} catch ( error ) {
		return { status: 'malformed', error };
	}
}

export function applyValidatedLink( block, evidence, currentPermalink ) {
	const inspected = inspectInsertionRange(
		block,
		evidence.exact_text,
		evidence.occurrence
	);
	if ( inspected.status !== 'ready' ) {
		return inspected;
	}
	if (
		typeof currentPermalink !== 'string' ||
		currentPermalink.length === 0
	) {
		return { status: 'target-unavailable' };
	}

	try {
		const nextValue = applyFormat(
			inspected.value,
			{ type: 'core/link', attributes: { url: currentPermalink } },
			inspected.range.start,
			inspected.range.end
		);
		if (
			! verifyAppliedLink(
				inspected.value,
				nextValue,
				inspected.range,
				currentPermalink
			)
		) {
			return { status: 'invariant-failed' };
		}

		const serialized = toHTMLString( { value: nextValue } );
		const reparsed = create( { html: serialized } );
		if (
			! verifyAppliedLink(
				inspected.value,
				reparsed,
				inspected.range,
				currentPermalink
			)
		) {
			return { status: 'serialization-failed' };
		}

		return {
			status: 'ready',
			range: inspected.range,
			nextContent:
				inspected.representation === 'rich-text-data'
					? new RichTextData( nextValue )
					: serialized,
		};
	} catch ( error ) {
		return { status: 'malformed', error };
	}
}

export function resolveInsertionEvidence( suggestion, aiEvaluation, getBlock ) {
	const aiAnchor = aiEvaluation?.anchor?.exact_text
		? aiEvaluation.anchor
		: null;
	const location = suggestion?.location;
	const source = aiAnchor || location;
	const clientId = aiAnchor?.block_client_id || location?.block_client_id;
	const blockName = aiAnchor?.block_name || location?.block_name;
	const exactText = aiAnchor?.exact_text || location?.anchor_text;
	if (
		! source ||
		! clientId ||
		! INSERTION_BLOCKS.has( blockName ) ||
		typeof exactText !== 'string' ||
		exactText.length === 0
	) {
		return null;
	}

	const block = getBlock( clientId );
	if ( ! block || block.name !== blockName ) {
		return null;
	}
	const current = contentValue( block.attributes?.content );
	if ( ! current ) {
		return null;
	}

	let occurrence = aiAnchor?.occurrence;
	try {
		const value = create( { html: current.html } );
		if ( ! aiAnchor ) {
			const ranges = exactOccurrences( value.text, exactText );
			const matching = ranges.filter(
				( range ) =>
					Array.from( value.text.slice( 0, range.start ) ).length ===
					location.start
			);
			if ( matching.length !== 1 ) {
				return null;
			}
			occurrence = matching[ 0 ].occurrence;
		}
		const inspected = inspectInsertionRange( block, exactText, occurrence );
		if ( inspected.status !== 'ready' ) {
			return null;
		}
	} catch {
		return null;
	}

	return {
		block_client_id: clientId,
		block_name: blockName,
		exact_text: exactText,
		occurrence,
		unit_key: aiAnchor?.unit_key || null,
		source_kind: aiAnchor ? 'ai' : 'deterministic',
	};
}

export function collectDraftLinks( blocks, limits ) {
	const markup = serialize( blocks || [] );
	const documentValue = new globalThis.DOMParser().parseFromString(
		markup,
		'text/html'
	);
	const links = Array.from( documentValue.querySelectorAll( 'a[href]' ) ).map(
		( anchor ) => anchor.getAttribute( 'href' )
	);
	if ( links.length > limits.maxDraftLinks ) {
		throw new Error( 'This draft has too many links to validate safely.' );
	}
	const encoder = new TextEncoder();
	links.forEach( ( href ) => {
		if (
			typeof href !== 'string' ||
			href.length === 0 ||
			encoder.encode( href ).length > limits.maxLinkBytes
		) {
			throw new Error( 'A draft link exceeds the validation limit.' );
		}
	} );
	return links;
}

export function isCurrentValidationResponse( response, expected ) {
	let permalink;
	try {
		permalink = new URL( response?.current_permalink );
	} catch {
		return false;
	}
	return Boolean(
		response &&
			[ 'http:', 'https:' ].includes( permalink.protocol ) &&
			response.contract_version === expected.contractVersion &&
			response.analysis_id === expected.analysisId &&
			response.draft_hash === expected.draftHash &&
			response.target_post_id === expected.targetPostId &&
			response.source_kind === expected.sourceKind &&
			response.block?.client_id === expected.anchor.block_client_id &&
			response.block?.name === expected.anchor.block_name &&
			response.block?.attribute === 'content' &&
			/^[a-f0-9]{64}$/.test( response.block?.markup_identity || '' ) &&
			/^[a-f0-9]{64}$/.test( response.block?.text_identity || '' ) &&
			response.block?.markup_identity === expected.markupIdentity &&
			response.block?.text_identity === expected.textIdentity &&
			response.anchor?.exact_text === expected.anchor.exact_text &&
			response.anchor?.occurrence === expected.anchor.occurrence
	);
}
