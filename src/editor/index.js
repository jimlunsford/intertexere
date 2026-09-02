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
	createRequestGate,
	visibleSuggestions,
} from './request-state';
import { buildSnapshot, clientHashInput, sha256 } from './snapshot';
import { AnalysisBody } from './components';
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
	const controller = useRef( null );
	const cache = useRef( new Map() );
	const identity = useRef( postIdentity );
	const [ state, setState ] = useState( {
		status: 'ready',
		response: null,
		error: '',
		signature: '',
	} );
	const [ dismissed, setDismissed ] = useState( new Set() );

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

	const stale = Boolean(
		state.response && snapshot && state.signature !== snapshot.signature
	);
	const shown = visibleSuggestions( state.response, dismissed );

	useEffect( () => {
		controller.current?.abort();
		gate.current.invalidate();
		cache.current.clear();
		setDismissed( new Set() );
		setState( {
			status: 'ready',
			response: null,
			error: '',
			signature: '',
		} );
	}, [ postIdentity ] );

	useEffect( () => () => controller.current?.abort(), [] );

	const analyze = async () => {
		if ( ! snapshot ) {
			return;
		}
		controller.current?.abort();
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
						onDismiss={ ( targetId ) =>
							setDismissed( ( current ) =>
								new Set( current ).add( targetId )
							)
						}
					/>
				) }
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
