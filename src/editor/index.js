import apiFetch from '@wordpress/api-fetch';
import { Button, Notice, PanelBody } from '@wordpress/components';
import { useSelect } from '@wordpress/data';
import { PluginSidebar, PluginSidebarMoreMenuItem } from '@wordpress/editor';
import { link } from '@wordpress/icons';
import { registerPlugin } from '@wordpress/plugins';
import { useEffect, useMemo, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import {
	analysisCacheKey,
	aiCacheKey,
	createRequestGate,
	visibleSuggestions,
} from './request-state';
import { buildSnapshot, clientHashInput, sha256 } from './snapshot';
import { AIControls, AnalysisBody } from './components';
import './style.scss';

const settings = window.IntertexereEditorSettings;

export function EditorSuggestionsSidebar() {
	const editorState = useSelect( ( select ) => {
		const editor = select( 'core/editor' );
		const blockEditor = select( 'core/block-editor' );
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
	const controller = useRef( null );
	const aiController = useRef( null );
	const cache = useRef( new Map() );
	const aiCache = useRef( new Map() );
	const identity = useRef( postIdentity );
	const currentSignature = useRef( '' );
	const [ state, setState ] = useState( {
		status: 'ready',
		response: null,
		error: '',
		signature: '',
		postIdentity: '',
	} );
	const [ dismissed, setDismissed ] = useState( new Set() );
	const [ aiState, setAiState ] = useState( {
		status: settings.ai?.enabled
			? settings.ai?.available
				? 'ready'
				: 'unavailable'
			: 'disabled',
		response: null,
		error: '',
		signature: '',
	} );
	const [ enhancedMode, setEnhancedMode ] = useState( false );

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
	const shown = enhancedMode && aiState.status === 'enhanced'
		? deterministicShown
			.filter( ( suggestion ) => aiEvaluations.get( suggestion.target_post_id )?.decision === 'keep' )
			.sort( ( left, right ) => aiEvaluations.get( left.target_post_id ).rank - aiEvaluations.get( right.target_post_id ).rank )
		: deterministicShown;

	useEffect( () => {
		controller.current?.abort();
		aiController.current?.abort();
		gate.current.invalidate();
		aiGate.current.invalidate();
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
			status: settings.ai?.enabled
				? settings.ai?.available
					? 'ready'
					: 'unavailable'
				: 'disabled',
			response: null,
			error: '',
			signature: '',
		} );
		setEnhancedMode( false );
	}, [ postIdentity ] );

	useEffect( () => () => {
		controller.current?.abort();
		aiController.current?.abort();
		gate.current.invalidate();
		aiGate.current.invalidate();
	}, [] );

	useEffect( () => {
		if ( aiState.signature && aiState.postIdentity === postIdentity && snapshot?.signature !== aiState.signature ) {
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
	}, [ snapshot?.signature, aiState.signature, aiState.postIdentity, postIdentity ] );

	const analyze = async () => {
		if ( ! snapshot ) {
			return;
		}
		controller.current?.abort();
		aiController.current?.abort();
		aiGate.current.invalidate();
		aiCache.current.clear();
		setEnhancedMode( false );
		setAiState( {
			status: settings.ai?.enabled
				? settings.ai?.available
					? 'ready'
					: 'unavailable'
				: 'disabled',
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
		if ( ! snapshot || ! state.response || stale || ! settings.ai?.enabled ) {
			return;
		}

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
				response.analysis_id !== requestedAnalysis ||
				response.draft_hash !== state.response.draft_hash ||
				response.index_generation !== state.response.index_generation ||
				response.graph_generation !== state.response.graph_generation
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
			if ( error?.name === 'AbortError' || ! aiGate.current.owns( requestToken ) ) {
				return;
			}
			const unavailable = [
				'intertexere_ai_unavailable',
				'intertexere_ai_disabled',
			].includes( error?.code );
			const staleResponse = error?.code === 'intertexere_ai_stale';
			setAiState( {
				status: staleResponse ? 'stale' : unavailable ? 'unavailable' : 'failed',
				response: null,
				error: error?.message || __( 'AI enhancement failed. Deterministic suggestions remain available.', 'intertexere' ),
				signature: requestedSignature,
				postIdentity: requestedIdentity,
			} );
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
				{ snapshotError ? (
					<Notice status="warning" isDismissible={ false }>
						{ snapshotError }
					</Notice>
				) : (
					<AnalysisBody
						status={ state.status }
						error={ state.error }
						response={ state.response }
						suggestions={ shown }
						stale={ stale }
						aiEvaluations={ enhancedMode ? aiEvaluations : new Map() }
						enhancedMode={ enhancedMode }
						onDismiss={ ( targetId ) =>
							setDismissed( ( current ) =>
								new Set( current ).add( targetId )
							)
						}
					/>
				) }
				<AIControls
					configured={ Boolean( settings.ai?.enabled && settings.ai?.available ) }
					status={ aiState.status }
					error={ aiState.error }
					onEnhance={ enhanceWithAI }
					onToggleMode={ () => setEnhancedMode( ( current ) => ! current ) }
					enhancedMode={ enhancedMode }
					hasDeterministicResults={ Boolean( state.response && deterministicShown.length ) }
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
