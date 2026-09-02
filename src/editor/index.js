import apiFetch from '@wordpress/api-fetch';
import { store as blockEditorStore } from '@wordpress/block-editor';
import { Button, Notice, PanelBody } from '@wordpress/components';
import { dispatch, select, useSelect } from '@wordpress/data';
import {
	PluginSidebar,
	PluginSidebarMoreMenuItem,
	store as editorStore,
} from '@wordpress/editor';
import { link } from '@wordpress/icons';
import { registerPlugin } from '@wordpress/plugins';
import { useEffect, useMemo, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import {
	analysisCacheKey,
	aiCacheKey,
	createRequestGate,
	isCurrentAIResponse,
	visibleSuggestions,
} from './request-state';
import { buildSnapshot, clientHashInput, sha256 } from './snapshot';
import { AIControls, AnalysisBody } from './components';
import {
	applyValidatedLink,
	collectDraftLinks,
	contentIdentity,
	isCurrentValidationResponse,
	resolveInsertionEvidence,
	richTextPlainText,
} from './insertion';
import './style.scss';

const settings = window.IntertexereEditorSettings;

function initialAIStatus( ai ) {
	if ( ! ai?.enabled ) {
		return 'disabled';
	}
	return ai.available ? 'ready' : 'unavailable';
}

function failedAIStatus( error ) {
	if ( error?.code === 'intertexere_ai_stale' ) {
		return 'stale';
	}
	if (
		[ 'intertexere_ai_unavailable', 'intertexere_ai_disabled' ].includes(
			error?.code
		)
	) {
		return 'unavailable';
	}
	return 'failed';
}

function insertionFailure( error ) {
	const states = {
		intertexere_insertion_stale: [
			'stale',
			__(
				'The draft or suggestion changed. Refresh before inserting.',
				'intertexere'
			),
		],
		intertexere_insertion_duplicate: [
			'duplicate',
			__(
				'This draft already links to that destination.',
				'intertexere'
			),
		],
		intertexere_insertion_already_linked: [
			'already-linked',
			__(
				'This exact phrase is already linked to that destination.',
				'intertexere'
			),
		],
		intertexere_insertion_unsupported: [
			'unsupported',
			__(
				'This block is not supported for link insertion.',
				'intertexere'
			),
		],
		intertexere_insertion_target_unavailable: [
			'target-unavailable',
			__(
				'The suggested destination is no longer available.',
				'intertexere'
			),
		],
		intertexere_insertion_link_overlap: [
			'overlap',
			__( 'The exact phrase overlaps an existing link.', 'intertexere' ),
		],
	};
	const fallback = [
		'validation-error',
		error?.message ||
			__(
				'Intertexere could not validate this insertion.',
				'intertexere'
			),
	];
	const [ status, message ] = states[ error?.code ] || fallback;
	return { status, message };
}

function readEditorState() {
	const editor = select( editorStore );
	const blockEditor = select( blockEditorStore );
	const taxonomies = {};
	Object.values( settings.taxonomyFields || {} ).forEach( ( field ) => {
		taxonomies[ field ] = editor.getEditedPostAttribute( field ) || [];
	} );
	return {
		postId: editor.getCurrentPostId() || 0,
		postType: editor.getCurrentPostType(),
		title: editor.getEditedPostAttribute( 'title' ) || '',
		taxonomies,
		blocks: blockEditor.getBlocks(),
	};
}

export function EditorSuggestionsSidebar() {
	const editorState = useSelect( ( registrySelect ) => {
		const editor = registrySelect( 'core/editor' );
		const blockEditor = registrySelect( 'core/block-editor' );
		const taxonomies = {};
		Object.values( settings.taxonomyFields || {} ).forEach( ( field ) => {
			taxonomies[ field ] = editor.getEditedPostAttribute( field ) || [];
		} );
		return {
			postId: editor.getCurrentPostId() || 0,
			postType: editor.getCurrentPostType(),
			title: editor.getEditedPostAttribute( 'title' ) || '',
			taxonomies,
			blocks: blockEditor.getBlocks(),
		};
	}, [] );

	const postIdentity = `${ editorState.postType || '' }:${
		editorState.postId || 0
	}`;
	const gate = useRef( createRequestGate() );
	const aiGate = useRef( createRequestGate() );
	const insertionGate = useRef( createRequestGate() );
	const controller = useRef( null );
	const aiController = useRef( null );
	const insertionController = useRef( null );
	const cache = useRef( new Map() );
	const aiCache = useRef( new Map() );
	const identity = useRef( postIdentity );
	const currentSignature = useRef( '' );
	const currentLinkSignature = useRef( '' );
	const [ state, setState ] = useState( {
		status: 'ready',
		response: null,
		error: '',
		signature: '',
		postIdentity: '',
	} );
	const [ dismissed, setDismissed ] = useState( new Set() );
	const [ aiState, setAiState ] = useState( {
		status: initialAIStatus( settings.ai ),
		response: null,
		error: '',
		signature: '',
	} );
	const [ enhancedMode, setEnhancedMode ] = useState( false );
	const [ insertionStates, setInsertionStates ] = useState( {} );

	identity.current = postIdentity;
	const snapshotResult = useMemo( () => {
		try {
			return {
				snapshot: buildSnapshot( editorState, settings ),
				error: '',
			};
		} catch ( error ) {
			return { snapshot: null, error: error.message };
		}
	}, [ editorState ] );
	const snapshot = snapshotResult.snapshot;
	const snapshotError = snapshotResult.error;
	currentSignature.current = snapshot?.signature || '';
	const draftLinksResult = useMemo( () => {
		try {
			return {
				links: collectDraftLinks(
					editorState.blocks,
					settings.insertion
				),
				error: '',
			};
		} catch ( error ) {
			return { links: null, error: error.message };
		}
	}, [ editorState.blocks ] );
	const draftLinks = draftLinksResult.links;
	const draftLinkSignature = draftLinks ? JSON.stringify( draftLinks ) : '';
	currentLinkSignature.current = draftLinkSignature;

	const stale = Boolean(
		state.response && snapshot && state.signature !== snapshot.signature
	);
	const deterministicShown = visibleSuggestions( state.response, dismissed );
	const aiEvaluations = useMemo( () => {
		const map = new Map();
		( aiState.response?.evaluations || [] ).forEach( ( evaluation ) => {
			map.set( evaluation.target_post_id, evaluation );
		} );
		return map;
	}, [ aiState.response ] );
	const shown =
		enhancedMode && aiState.status === 'enhanced'
			? deterministicShown
					.filter(
						( suggestion ) =>
							aiEvaluations.get( suggestion.target_post_id )
								?.decision === 'keep'
					)
					.sort(
						( left, right ) =>
							aiEvaluations.get( left.target_post_id ).rank -
							aiEvaluations.get( right.target_post_id ).rank
					)
			: deterministicShown;
	const insertionEvidence = useMemo( () => {
		const evidence = new Map();
		if ( ! snapshot || ! draftLinks ) {
			return evidence;
		}
		shown.forEach( ( suggestion ) => {
			const resolved = resolveInsertionEvidence(
				suggestion,
				enhancedMode
					? aiEvaluations.get( suggestion.target_post_id )
					: null,
				( clientId ) => select( blockEditorStore ).getBlock( clientId )
			);
			if ( resolved ) {
				evidence.set( suggestion.target_post_id, resolved );
			}
		} );
		return evidence;
	}, [ shown, enhancedMode, aiEvaluations, snapshot, draftLinks ] );

	useEffect( () => {
		controller.current?.abort();
		aiController.current?.abort();
		insertionController.current?.abort();
		gate.current.invalidate();
		aiGate.current.invalidate();
		insertionGate.current.invalidate();
		cache.current.clear();
		aiCache.current.clear();
		setDismissed( new Set() );
		setState( {
			status: 'ready',
			response: null,
			error: '',
			signature: '',
			postIdentity: '',
		} );
		setAiState( {
			status: initialAIStatus( settings.ai ),
			response: null,
			error: '',
			signature: '',
		} );
		setEnhancedMode( false );
		setInsertionStates( {} );
	}, [ postIdentity ] );

	useEffect(
		() => () => {
			controller.current?.abort();
			aiController.current?.abort();
			insertionController.current?.abort();
			gate.current.invalidate();
			aiGate.current.invalidate();
			insertionGate.current.invalidate();
		},
		[]
	);

	useEffect( () => {
		if (
			aiState.signature &&
			aiState.postIdentity === postIdentity &&
			snapshot?.signature !== aiState.signature
		) {
			aiController.current?.abort();
			aiGate.current.invalidate();
			aiCache.current.clear();
			setAiState( ( previous ) => ( {
				...previous,
				status: 'stale',
				error: '',
			} ) );
			setEnhancedMode( false );
		}
	}, [
		snapshot?.signature,
		aiState.signature,
		aiState.postIdentity,
		postIdentity,
	] );

	useEffect( () => {
		insertionController.current?.abort();
		insertionGate.current.invalidate();
		setInsertionStates( ( current ) => {
			const next = { ...current };
			Object.keys( next ).forEach( ( targetId ) => {
				if ( next[ targetId ].status === 'validating' ) {
					next[ targetId ] = {
						status: 'stale',
						message: __(
							'The draft changed during validation. Nothing was inserted.',
							'intertexere'
						),
					};
				}
			} );
			return next;
		} );
	}, [ snapshot?.signature, draftLinkSignature ] );

	const analyze = async () => {
		if ( ! snapshot ) {
			return;
		}
		controller.current?.abort();
		aiController.current?.abort();
		insertionController.current?.abort();
		insertionGate.current.invalidate();
		setInsertionStates( {} );
		aiGate.current.invalidate();
		aiCache.current.clear();
		setEnhancedMode( false );
		setAiState( {
			status: initialAIStatus( settings.ai ),
			response: null,
			error: '',
			signature: '',
			postIdentity: '',
		} );
		controller.current = new AbortController();
		const requestToken = gate.current.begin();
		const requestedIdentity = postIdentity;
		const requestedSignature = snapshot.signature;
		setState( ( previous ) => ( {
			...previous,
			status: 'loading',
			error: '',
		} ) );

		try {
			const draftHash = await sha256( clientHashInput( snapshot ) );
			const response = await apiFetch( {
				path: settings.route,
				method: 'POST',
				data: snapshot.payload,
				signal: controller.current.signal,
			} );

			if (
				! gate.current.owns( requestToken ) ||
				requestedIdentity !== identity.current
			) {
				return;
			}
			if ( response.draft_hash !== draftHash ) {
				throw new Error(
					__(
						'Draft analysis returned for a different editor state.',
						'intertexere'
					)
				);
			}
			const key = analysisCacheKey(
				requestedIdentity,
				response.draft_hash,
				response
			);
			cache.current.set( key, {
				postIdentity: requestedIdentity,
				draftHash: response.draft_hash,
				response,
			} );
			if ( cache.current.size > 20 ) {
				cache.current.delete( cache.current.keys().next().value );
			}
			setDismissed( new Set() );
			setState( {
				status: 'results',
				response,
				error: '',
				signature: requestedSignature,
			} );
		} catch ( error ) {
			if (
				error?.name === 'AbortError' ||
				! gate.current.owns( requestToken )
			) {
				return;
			}
			setState( {
				status:
					error?.code === 'intertexere_index_unavailable'
						? 'unavailable'
						: 'error',
				response: null,
				error:
					error?.message ||
					__( 'Draft analysis failed.', 'intertexere' ),
				signature: requestedSignature,
			} );
		}
	};

	const enhanceWithAI = async () => {
		if (
			! snapshot ||
			! state.response ||
			stale ||
			! settings.ai?.enabled
		) {
			return;
		}

		insertionController.current?.abort();
		insertionGate.current.invalidate();
		setInsertionStates( {} );

		const candidateIds = deterministicShown
			.slice( 0, settings.ai.maxCandidates || 8 )
			.map( ( suggestion ) => suggestion.target_post_id );
		if ( candidateIds.length === 0 ) {
			return;
		}

		const key = aiCacheKey(
			state.response.analysis_id,
			candidateIds,
			settings.ai.contractVersion,
			settings.ai.promptVersion
		);
		if ( aiCache.current.has( key ) ) {
			setAiState( {
				status: 'enhanced',
				response: aiCache.current.get( key ),
				error: '',
				signature: snapshot.signature,
				postIdentity,
			} );
			setEnhancedMode( true );
			return;
		}

		aiController.current?.abort();
		aiController.current = new AbortController();
		const requestToken = aiGate.current.begin();
		const requestedIdentity = postIdentity;
		const requestedSignature = snapshot.signature;
		const requestedAnalysis = state.response.analysis_id;
		setAiState( {
			status: 'loading',
			response: null,
			error: '',
			signature: requestedSignature,
			postIdentity: requestedIdentity,
		} );
		setEnhancedMode( false );

		try {
			const response = await apiFetch( {
				path: settings.aiRoute,
				method: 'POST',
				data: {
					draft: snapshot.payload,
					analysis_id: requestedAnalysis,
					candidate_ids: candidateIds,
				},
				signal: aiController.current.signal,
			} );

			if (
				! aiGate.current.owns( requestToken ) ||
				requestedIdentity !== identity.current ||
				requestedSignature !== currentSignature.current ||
				! isCurrentAIResponse( response, {
					analysisId: requestedAnalysis,
					draftHash: state.response.draft_hash,
					indexGeneration: state.response.index_generation,
					graphGeneration: state.response.graph_generation,
					candidateIds,
					contractVersion: settings.ai.contractVersion,
					promptVersion: settings.ai.promptVersion,
				} )
			) {
				return;
			}

			aiCache.current.set( key, response );
			setAiState( {
				status: 'enhanced',
				response,
				error: '',
				signature: requestedSignature,
				postIdentity: requestedIdentity,
			} );
			setEnhancedMode( true );
		} catch ( error ) {
			if (
				error?.name === 'AbortError' ||
				! aiGate.current.owns( requestToken )
			) {
				return;
			}
			setAiState( {
				status: failedAIStatus( error ),
				response: null,
				error:
					error?.message ||
					__(
						'AI enhancement failed. Deterministic suggestions remain available.',
						'intertexere'
					),
				signature: requestedSignature,
				postIdentity: requestedIdentity,
			} );
		}
	};

	const insertLink = async ( suggestion, displayedEvidence ) => {
		if (
			! snapshot ||
			! draftLinks ||
			! state.response ||
			stale ||
			! displayedEvidence
		) {
			return;
		}

		const aiEvaluation =
			enhancedMode && aiState.status === 'enhanced'
				? aiEvaluations.get( suggestion.target_post_id )
				: null;
		const currentEvidence = resolveInsertionEvidence(
			suggestion,
			aiEvaluation,
			( clientId ) => select( blockEditorStore ).getBlock( clientId )
		);
		if (
			! currentEvidence ||
			JSON.stringify( currentEvidence ) !==
				JSON.stringify( displayedEvidence )
		) {
			setInsertionStates( ( current ) => ( {
				...current,
				[ suggestion.target_post_id ]: {
					status: 'stale',
					message: __(
						'The exact anchor changed. Refresh before inserting.',
						'intertexere'
					),
				},
			} ) );
			return;
		}

		const requestedIdentity = postIdentity;
		const requestedSignature = snapshot.signature;
		const requestedLinkSignature = draftLinkSignature;
		const requestedAnalysis = state.response.analysis_id;
		const requestedDraftHash = state.response.draft_hash;
		const requestedBlock = select( blockEditorStore ).getBlock(
			currentEvidence.block_client_id
		);
		const requestedContentIdentity = contentIdentity(
			requestedBlock?.attributes?.content
		);
		const requestedText = richTextPlainText( requestedBlock );
		const requestedUnits = snapshot.payload.units.filter(
			( unit ) => unit.client_id === currentEvidence.block_client_id
		);
		if (
			! requestedBlock ||
			! requestedContentIdentity ||
			requestedText === null ||
			requestedUnits.length !== 1
		) {
			return;
		}
		insertionController.current?.abort();
		insertionController.current = new AbortController();
		const requestToken = insertionGate.current.begin();

		setInsertionStates( ( current ) => ( {
			...current,
			[ suggestion.target_post_id ]: {
				status: 'validating',
				message: __(
					'Validating the current draft and destination…',
					'intertexere'
				),
			},
		} ) );

		try {
			const [ requestedMarkupIdentity, requestedTextIdentity ] =
				await Promise.all( [
					sha256( requestedUnits[ 0 ].markup ),
					sha256( requestedText ),
				] );
			if (
				! insertionGate.current.owns( requestToken ) ||
				requestedIdentity !== identity.current ||
				requestedSignature !== currentSignature.current
			) {
				return;
			}
			const response = await apiFetch( {
				path: settings.insertionRoute,
				method: 'POST',
				data: {
					draft: snapshot.payload,
					analysis_id: requestedAnalysis,
					target_post_id: suggestion.target_post_id,
					source_kind: currentEvidence.source_kind,
					anchor: {
						block_client_id: currentEvidence.block_client_id,
						block_name: currentEvidence.block_name,
						exact_text: currentEvidence.exact_text,
						occurrence: currentEvidence.occurrence,
						unit_key: currentEvidence.unit_key,
					},
					draft_links: draftLinks,
				},
				signal: insertionController.current.signal,
			} );

			if (
				! insertionGate.current.owns( requestToken ) ||
				requestedIdentity !== identity.current ||
				requestedSignature !== currentSignature.current ||
				requestedLinkSignature !== currentLinkSignature.current ||
				! isCurrentValidationResponse( response, {
					contractVersion: settings.insertion.contractVersion,
					analysisId: requestedAnalysis,
					draftHash: requestedDraftHash,
					targetPostId: suggestion.target_post_id,
					sourceKind: currentEvidence.source_kind,
					anchor: currentEvidence,
					markupIdentity: requestedMarkupIdentity,
					textIdentity: requestedTextIdentity,
				} )
			) {
				return;
			}

			const finalEditorState = readEditorState();
			const finalIdentity = `${ finalEditorState.postType || '' }:${
				finalEditorState.postId || 0
			}`;
			const finalSnapshot = buildSnapshot( finalEditorState, settings );
			const finalLinks = collectDraftLinks(
				finalEditorState.blocks,
				settings.insertion
			);
			const finalBlock = select( blockEditorStore ).getBlock(
				currentEvidence.block_client_id
			);
			const finalEvidence = resolveInsertionEvidence(
				suggestion,
				aiEvaluation,
				( clientId ) => select( blockEditorStore ).getBlock( clientId )
			);

			if (
				! insertionGate.current.owns( requestToken ) ||
				finalIdentity !== requestedIdentity ||
				finalSnapshot.signature !== requestedSignature ||
				JSON.stringify( finalLinks ) !== requestedLinkSignature ||
				! finalBlock ||
				finalBlock.name !== currentEvidence.block_name ||
				contentIdentity( finalBlock.attributes?.content ) !==
					requestedContentIdentity ||
				JSON.stringify( finalEvidence ) !==
					JSON.stringify( currentEvidence )
			) {
				setInsertionStates( ( current ) => ( {
					...current,
					[ suggestion.target_post_id ]: {
						status: 'stale',
						message: __(
							'The editor changed during validation. Nothing was inserted.',
							'intertexere'
						),
					},
				} ) );
				return;
			}

			const mutation = applyValidatedLink(
				finalBlock,
				currentEvidence,
				response.current_permalink
			);
			if ( mutation.status !== 'ready' ) {
				setInsertionStates( ( current ) => ( {
					...current,
					[ suggestion.target_post_id ]: {
						status: mutation.status,
						message: __(
							'The exact RichText range is no longer safe. Nothing was inserted.',
							'intertexere'
						),
					},
				} ) );
				return;
			}

			dispatch( blockEditorStore ).updateBlockAttributes(
				currentEvidence.block_client_id,
				{ content: mutation.nextContent }
			);

			insertionGate.current.invalidate();
			aiController.current?.abort();
			aiGate.current.invalidate();
			cache.current.clear();
			aiCache.current.clear();
			setEnhancedMode( false );
			setAiState( {
				status: 'stale',
				response: null,
				error: '',
				signature: '',
				postIdentity: requestedIdentity,
			} );
			setState( ( current ) => ( { ...current, signature: '' } ) );
			setInsertionStates( ( current ) => ( {
				...current,
				[ suggestion.target_post_id ]: {
					status: 'inserted',
					message: __(
						'Link inserted in the unsaved draft. Save or publish with WordPress when you are ready.',
						'intertexere'
					),
				},
			} ) );
		} catch ( error ) {
			if (
				error?.name === 'AbortError' ||
				! insertionGate.current.owns( requestToken )
			) {
				return;
			}
			setInsertionStates( ( current ) => ( {
				...current,
				[ suggestion.target_post_id ]: insertionFailure( error ),
			} ) );
		}
	};

	return (
		<PluginSidebar
			name="intertexere-editor-suggestions"
			title={ __( 'Intertexere', 'intertexere' ) }
			icon={ link }
		>
			<PanelBody>
				{ stale && (
					<Notice status="warning" isDismissible={ false }>
						{ __(
							'The draft changed. Refresh suggestions before relying on these results.',
							'intertexere'
						) }
					</Notice>
				) }
				{ snapshotError || draftLinksResult.error ? (
					<Notice status="warning" isDismissible={ false }>
						{ snapshotError || draftLinksResult.error }
					</Notice>
				) : (
					<AnalysisBody
						status={ state.status }
						error={ state.error }
						response={ state.response }
						suggestions={ shown }
						stale={ stale }
						aiEvaluations={
							enhancedMode ? aiEvaluations : new Map()
						}
						enhancedMode={ enhancedMode }
						insertionEvidence={ insertionEvidence }
						insertionStates={ insertionStates }
						onInsert={ insertLink }
						onDismiss={ ( targetId ) =>
							setDismissed( ( current ) =>
								new Set( current ).add( targetId )
							)
						}
					/>
				) }
				<AIControls
					configured={ Boolean(
						settings.ai?.enabled && settings.ai?.available
					) }
					status={ stale ? 'stale' : aiState.status }
					error={ aiState.error }
					onEnhance={ enhanceWithAI }
					onToggleMode={ () =>
						setEnhancedMode( ( current ) => ! current )
					}
					enhancedMode={ enhancedMode }
					hasDeterministicResults={ Boolean(
						state.response && deterministicShown.length
					) }
				/>
				<Button
					variant="primary"
					onClick={ analyze }
					disabled={ ! snapshot }
				>
					{ state.response
						? __( 'Refresh suggestions', 'intertexere' )
						: __( 'Analyze draft', 'intertexere' ) }
				</Button>
			</PanelBody>
		</PluginSidebar>
	);
}

if ( settings ) {
	registerPlugin( 'intertexere-editor-suggestions', {
		render: () => (
			<>
				<PluginSidebarMoreMenuItem
					target="intertexere-editor-suggestions"
					icon={ link }
				>
					{ __( 'Intertexere', 'intertexere' ) }
				</PluginSidebarMoreMenuItem>
				<EditorSuggestionsSidebar />
			</>
		),
	} );
}
