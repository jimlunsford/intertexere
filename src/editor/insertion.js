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

function replacementsFingerprint( value ) {
	return JSON.stringify( comparableObject( value.replacements || [] ) );
}

function sameFormats( beforeFormats, afterFormats, includeLinks = true ) {
	const before = ( beforeFormats || [] ).filter(
		( format ) => includeLinks || ! isLinkFormat( format )
	);
	const after = ( afterFormats || [] ).filter(
		( format ) => includeLinks || ! isLinkFormat( format )
	);
	if ( before.length !== after.length ) {
		return false;
	}
	for ( let index = 0; index < before.length; index += 1 ) {
		if (
			before[ index ] !== after[ index ] &&
			formatFingerprint( before[ index ] ) !==
				formatFingerprint( after[ index ] )
		) {
			return false;
		}
	}
	return true;
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
	let exactOneLink = true;
	let firstLink = null;
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
		if ( links.length !== 1 ) {
			exactOneLink = false;
		} else if ( ! firstLink ) {
			firstLink = formatFingerprint( links[ 0 ] );
		} else if ( firstLink !== formatFingerprint( links[ 0 ] ) ) {
			exactOneLink = false;
		}
	}
	if ( ! hasLink ) {
		return 'clear';
	}
	const exactBoundary =
		linkFormatsAt( value, range.start - 1 ).length === 0 &&
		linkFormatsAt( value, range.end ).length === 0;
	if ( exactSameTarget && exactBoundary ) {
		return 'already-linked';
	}
	if ( exactOneLink && exactBoundary ) {
		return 'linked';
	}
	return 'overlap';
}

function verifyAppliedLink( before, after, range, currentPermalink ) {
	if (
		before.text !== after.text ||
		( before.replacements !== after.replacements &&
			replacementsFingerprint( before ) !==
				replacementsFingerprint( after ) )
	) {
		return false;
	}

	for ( let index = 0; index < after.text.length; index += 1 ) {
		if (
			! sameFormats(
				before.formats?.[ index ],
				after.formats?.[ index ],
				false
			)
		) {
			return false;
		}
		const beforeLinks = linkFormatsAt( before, index );
		const afterLinks = linkFormatsAt( after, index );
		if ( index >= range.start && index < range.end ) {
			if (
				afterLinks.length !== 1 ||
				linkUrl( afterLinks[ 0 ] ) !== currentPermalink
			) {
				return false;
			}
		} else if ( ! sameFormats( beforeLinks, afterLinks ) ) {
			return false;
		}
	}
	return true;
}

export function inspectInsertionRange(
	block,
	exactText,
	occurrence,
	currentPermalink = ''
) {
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
		const overlap = linkOverlapState( value, range, currentPermalink );
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
		evidence.occurrence,
		currentPermalink
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

export function commitValidatedLink(
	block,
	evidence,
	currentPermalink,
	updateBlockAttributes
) {
	if (
		! block ||
		block.clientId !== evidence.block_client_id ||
		block.name !== evidence.block_name ||
		typeof updateBlockAttributes !== 'function'
	) {
		return { status: 'stale' };
	}
	const mutation = applyValidatedLink( block, evidence, currentPermalink );
	if ( mutation.status !== 'ready' ) {
		return mutation;
	}
	updateBlockAttributes( evidence.block_client_id, {
		content: mutation.nextContent,
	} );
	return { ...mutation, status: 'inserted' };
}

export function inspectSuggestionInsertion(
	suggestion,
	aiEvaluation,
	getBlock
) {
	const started = globalThis.performance?.now?.() || Date.now();
	const aiAnchor = aiEvaluation?.anchor?.exact_text
		? aiEvaluation.anchor
		: null;
	let locations = [];
	if ( aiAnchor ) {
		locations = [
			{
				block_client_id: aiAnchor.block_client_id,
				block_name: aiAnchor.block_name,
				anchor_text: aiAnchor.exact_text,
				occurrence: aiAnchor.occurrence,
				unit_key: aiAnchor.unit_key,
			},
		];
	} else if ( Array.isArray( suggestion?.location_candidates ) ) {
		locations = suggestion.location_candidates;
	} else if ( suggestion?.location ) {
		locations = [ suggestion.location ];
	}
	const failures = [];

	for ( const location of locations ) {
		const clientId = location?.block_client_id;
		const blockName = location?.block_name;
		const exactText = location?.anchor_text;
		const occurrence = location?.occurrence;
		if (
			! clientId ||
			! INSERTION_BLOCKS.has( blockName ) ||
			typeof exactText !== 'string' ||
			exactText.length === 0 ||
			! Number.isInteger( occurrence )
		) {
			failures.push( 'unsupported-block' );
			continue;
		}
		const block = getBlock( clientId );
		if ( ! block || block.name !== blockName ) {
			failures.push( 'changed' );
			continue;
		}
		const inspected = inspectInsertionRange(
			block,
			exactText,
			occurrence,
			suggestion?.target_permalink || ''
		);
		if ( inspected.status !== 'ready' ) {
			failures.push(
				{
					unsupported: 'unsupported-block',
					linked: 'already-linked',
					'already-linked': 'already-linked',
					overlap: 'link-overlap',
					'replacement-overlap': 'replacement-overlap',
					stale: 'changed',
					malformed: 'unmappable',
				}[ inspected.status ] || 'unavailable'
			);
			continue;
		}

		return {
			evidence: {
				block_client_id: clientId,
				block_name: blockName,
				exact_text: exactText,
				occurrence,
				unit_key: aiAnchor?.unit_key || null,
				source_kind: aiAnchor ? 'ai' : 'deterministic',
			},
			location,
			reason: '',
			inspectionMs:
				( globalThis.performance?.now?.() || Date.now() ) - started,
		};
	}

	const priority = [
		'already-linked',
		'link-overlap',
		'replacement-overlap',
		'changed',
		'unmappable',
		'unsupported-block',
		'unavailable',
	];
	return {
		evidence: null,
		location: locations[ 0 ] || null,
		reason:
			priority.find( ( code ) => failures.includes( code ) ) ||
			suggestion?.location_status ||
			'unavailable',
		inspectionMs:
			( globalThis.performance?.now?.() || Date.now() ) - started,
	};
}

export function resolveInsertionEvidence( suggestion, aiEvaluation, getBlock ) {
	return inspectSuggestionInsertion( suggestion, aiEvaluation, getBlock )
		.evidence;
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
