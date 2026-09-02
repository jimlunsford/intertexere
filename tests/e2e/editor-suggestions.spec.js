const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );

const candidateTitle = 'Deterministic WordPress Performance';
const candidateContent =
	'<!-- wp:paragraph --><p>Deterministic WordPress performance notes for local editorial analysis.</p><!-- /wp:paragraph -->';
const unsavedContent =
	'<!-- wp:paragraph --><p>Our deterministic WordPress performance guide explains predictable local analysis.</p><!-- /wp:paragraph -->';

async function openSidebar( page ) {
	await page.evaluate( () => {
		window.wp.data
			.dispatch( 'core/edit-post' )
			.openGeneralSidebar(
				'intertexere-editor-suggestions/intertexere-editor-suggestions'
			);
	} );
	await expect(
		page.getByRole( 'button', { name: 'Analyze draft' } )
	).toBeVisible();
}

function editorSettings( page ) {
	return page.getByLabel( 'Editor settings' );
}

test.describe( 'Intertexere read-only editor suggestions', () => {
	test.beforeAll( async ( { requestUtils } ) => {
		await requestUtils.activatePlugin( 'intertexere' );
	} );

	test( 'requires explicit AI invocation and overlays fake ranking without changing deterministic score', async ( {
		admin,
		editor,
		page,
	} ) => {
		await admin.createNewPost( {
			title: candidateTitle,
			content: unsavedContent,
			showWelcomeGuide: false,
		} );

		let aiRequests = 0;
		page.on( 'request', ( request ) => {
			if ( request.url().includes( '/editor-suggestions/ai-enhance' ) ) {
				aiRequests += 1;
			}
		} );

		await openSidebar( page );
		await expect(
			page.getByText( /Provider processing and retention/ )
		).toHaveCount( 0 );
		await page.getByRole( 'button', { name: 'Analyze draft' } ).click();
		await expect(
			page.getByRole( 'heading', { name: candidateTitle, exact: true } )
		).toBeVisible();
		await expect(
			page.getByText( /Provider processing and retention/ )
		).toBeVisible();
		expect( aiRequests ).toBe( 0 );

		const deterministicScore = await page
			.getByText( /Deterministic relevance:/ )
			.locator( '..' )
			.textContent();
		await page.getByRole( 'button', { name: 'Enhance with AI' } ).click();
		await expect( page.getByText( /AI contextual rank:/ ) ).toBeVisible();
		await expect(
			page.getByText( /Deterministic E2E enhancement/ )
		).toBeVisible();
		expect( aiRequests ).toBe( 1 );
		expect(
			await page
				.getByText( /Deterministic relevance:/ )
				.locator( '..' )
				.textContent()
		).toBe( deterministicScore );
		expect( await page.getByText( 'Insert Link' ).count() ).toBe( 0 );
		await page
			.getByRole( 'button', { name: 'View deterministic suggestions' } )
			.click();
		await expect( page.getByText( /AI contextual rank:/ ) ).toHaveCount(
			0
		);
		await page.getByRole( 'button', { name: 'Enhance with AI' } ).click();
		await expect( page.getByText( /AI contextual rank:/ ) ).toBeVisible();
		expect( aiRequests ).toBe( 1 );

		await editor.setContent(
			unsavedContent.replace( 'guide', 'changed guide' )
		);
		await expect(
			editorSettings( page ).getByText( /AI enhancement is stale/ )
		).toBeVisible();
		expect( aiRequests ).toBe( 1 );
	} );

	test( 'provider failure preserves deterministic suggestions and normal saving', async ( {
		admin,
		editor,
		page,
		requestUtils,
	} ) => {
		await requestUtils.rest( {
			path: '/intertexere-e2e/v1/mode',
			method: 'POST',
			data: { mode: 'failure' },
		} );
		await admin.createNewPost( {
			title: candidateTitle,
			content: unsavedContent,
			showWelcomeGuide: false,
		} );
		await openSidebar( page );
		await page.getByRole( 'button', { name: 'Analyze draft' } ).click();
		await expect(
			page.getByRole( 'heading', { name: candidateTitle, exact: true } )
		).toBeVisible();

		await page.getByRole( 'button', { name: 'Enhance with AI' } ).click();
		await expect(
			editorSettings( page ).getByText(
				'The configured AI provider could not be reached in time.'
			)
		).toBeVisible();
		await expect( page.getByText( 'secret provider detail' ) ).toHaveCount(
			0
		);
		await expect(
			page.getByRole( 'heading', { name: candidateTitle, exact: true } )
		).toBeVisible();
		await editor.saveDraft();
		await expect(
			page.getByTestId( 'snackbar' ).filter( { hasText: 'Draft saved.' } )
		).toBeVisible();
	} );

	test.beforeEach( async ( { requestUtils } ) => {
		await requestUtils.rest( {
			path: '/intertexere-e2e/v1/mode',
			method: 'POST',
			data: { mode: 'available' },
		} );
		await requestUtils.deleteAllPosts();
		await requestUtils.createPost( {
			title: candidateTitle,
			content: candidateContent,
			status: 'publish',
			date_gmt: new Date().toISOString().replace( /\.\d{3}Z$/, '' ),
		} );
	} );

	test( 'keeps deterministic suggestions available when AI is disabled', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		await requestUtils.rest( {
			path: '/intertexere-e2e/v1/mode',
			method: 'POST',
			data: { mode: 'disabled' },
		} );
		await admin.createNewPost( {
			title: candidateTitle,
			content: unsavedContent,
			showWelcomeGuide: false,
		} );
		await openSidebar( page );
		await page.getByRole( 'button', { name: 'Analyze draft' } ).click();
		await expect(
			page.getByRole( 'heading', {
				name: candidateTitle,
				exact: true,
			} )
		).toBeVisible();
		await expect(
			editorSettings( page ).getByText( /AI enhancement is disabled/ )
		).toBeVisible();
		expect(
			await page
				.getByRole( 'button', { name: 'Enhance with AI' } )
				.count()
		).toBe( 0 );
	} );

	test( 'keeps deterministic suggestions available when no compatible model exists', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		await requestUtils.rest( {
			path: '/intertexere-e2e/v1/mode',
			method: 'POST',
			data: { mode: 'unavailable' },
		} );
		await admin.createNewPost( {
			title: candidateTitle,
			content: unsavedContent,
			showWelcomeGuide: false,
		} );
		await openSidebar( page );
		await page.getByRole( 'button', { name: 'Analyze draft' } ).click();
		await expect(
			page.getByRole( 'heading', { name: candidateTitle, exact: true } )
		).toBeVisible();
		await expect(
			editorSettings( page ).getByText(
				/No compatible configured AI model/
			)
		).toBeVisible();
		expect(
			await page
				.getByRole( 'button', { name: 'Enhance with AI' } )
				.count()
		).toBe( 0 );
	} );

	test( 'marks an in-flight enhancement stale and ignores its late response', async ( {
		admin,
		editor,
		page,
		requestUtils,
	} ) => {
		await requestUtils.rest( {
			path: '/intertexere-e2e/v1/mode',
			method: 'POST',
			data: { mode: 'delayed' },
		} );
		await admin.createNewPost( {
			title: candidateTitle,
			content: unsavedContent,
			showWelcomeGuide: false,
		} );
		await openSidebar( page );
		await page.getByRole( 'button', { name: 'Analyze draft' } ).click();
		await expect(
			page.getByRole( 'heading', { name: candidateTitle, exact: true } )
		).toBeVisible();
		await page.getByRole( 'button', { name: 'Enhance with AI' } ).click();
		await expect(
			page.getByRole( 'button', { name: /Enhancing with AI/ } )
		).toBeVisible();
		await editor.setContent(
			unsavedContent.replace( 'guide', 'changed guide' )
		);
		await expect(
			editorSettings( page ).getByText( /AI enhancement is stale/ )
		).toBeVisible();
		await page.waitForTimeout( 1200 );
		await expect( page.getByText( /AI contextual rank:/ ) ).toHaveCount(
			0
		);
	} );

	test( 'deterministic refresh invalidates an in-flight AI request', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		await requestUtils.rest( {
			path: '/intertexere-e2e/v1/mode',
			method: 'POST',
			data: { mode: 'delayed' },
		} );
		await admin.createNewPost( {
			title: candidateTitle,
			content: unsavedContent,
			showWelcomeGuide: false,
		} );
		await openSidebar( page );
		await page.getByRole( 'button', { name: 'Analyze draft' } ).click();
		await expect(
			page.getByRole( 'heading', { name: candidateTitle, exact: true } )
		).toBeVisible();
		await page.getByRole( 'button', { name: 'Enhance with AI' } ).click();
		await expect(
			page.getByRole( 'button', { name: /Enhancing with AI/ } )
		).toBeVisible();
		await page
			.getByRole( 'button', { name: 'Refresh suggestions' } )
			.click();
		await expect(
			page.getByRole( 'heading', { name: candidateTitle, exact: true } )
		).toBeVisible();
		await page.waitForTimeout( 1200 );
		await expect( page.getByText( /AI contextual rank:/ ) ).toHaveCount(
			0
		);
		await expect(
			page.getByRole( 'button', { name: 'Enhance with AI' } )
		).toBeVisible();
	} );

	test( 'clears an enhanced overlay on post navigation', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		const first = await requestUtils.createPost( {
			title: 'First AI source',
			content: unsavedContent,
			status: 'draft',
		} );
		const second = await requestUtils.createPost( {
			title: 'Second AI source',
			content:
				'<!-- wp:paragraph --><p>Unrelated content.</p><!-- /wp:paragraph -->',
			status: 'draft',
		} );
		await admin.editPost( first.id );
		await openSidebar( page );
		await page.getByRole( 'button', { name: 'Analyze draft' } ).click();
		await page.getByRole( 'button', { name: 'Enhance with AI' } ).click();
		await expect( page.getByText( /AI contextual rank:/ ) ).toBeVisible();

		await admin.editPost( second.id );
		await openSidebar( page );
		await expect( page.getByText( /AI contextual rank:/ ) ).toHaveCount(
			0
		);
		await expect(
			page.getByRole( 'button', { name: 'Analyze draft' } )
		).toBeVisible();
	} );

	test( 'saves exactly the edited content after AI enhancement', async ( {
		admin,
		editor,
		page,
		requestUtils,
	} ) => {
		const source = await requestUtils.createPost( {
			title: 'AI save source',
			content:
				'<!-- wp:paragraph --><p>Original.</p><!-- /wp:paragraph -->',
			status: 'draft',
		} );
		await admin.editPost( source.id );
		await editor.setContent( unsavedContent );
		const contentBeforeAI = await page.evaluate( () =>
			window.wp.data.select( 'core/editor' ).getEditedPostContent()
		);
		await openSidebar( page );
		await page.getByRole( 'button', { name: 'Analyze draft' } ).click();
		await page.getByRole( 'button', { name: 'Enhance with AI' } ).click();
		await expect( page.getByText( /AI contextual rank:/ ) ).toBeVisible();
		expect(
			await page.evaluate( () =>
				window.wp.data.select( 'core/editor' ).getEditedPostContent()
			)
		).toBe( contentBeforeAI );
		await editor.saveDraft();
		const saved = await requestUtils.rest( {
			path: `/wp/v2/posts/${ source.id }`,
			params: { context: 'edit' },
		} );
		expect( saved.content.raw ).toBe( contentBeforeAI );
	} );

	test( 'keeps the normal WordPress preview flow after AI enhancement', async ( {
		admin,
		editor,
		page,
	} ) => {
		await admin.createNewPost( {
			title: 'AI preview source',
			content: unsavedContent,
			showWelcomeGuide: false,
		} );
		await openSidebar( page );
		await page.getByRole( 'button', { name: 'Analyze draft' } ).click();
		await expect(
			page.getByRole( 'heading', { name: candidateTitle, exact: true } )
		).toBeVisible();
		await page.getByRole( 'button', { name: 'Enhance with AI' } ).click();
		await expect( page.getByText( /AI contextual rank:/ ) ).toBeVisible();

		const preview = await editor.openPreviewPage();
		await expect(
			preview.getByText(
				'Our deterministic WordPress performance guide explains predictable local analysis.'
			)
		).toBeVisible();
		await expect(
			preview.getByText( /Deterministic E2E enhancement/ )
		).toHaveCount( 0 );
		await preview.close();
	} );

	test( 'uses unsaved iframe-editor state only after a manual trigger and marks it stale', async ( {
		admin,
		editor,
		page,
	} ) => {
		await admin.createNewPost( {
			title: candidateTitle,
			content: unsavedContent,
			showWelcomeGuide: false,
		} );

		let analysisRequests = 0;
		page.on( 'request', ( request ) => {
			if (
				request.url().includes( '/intertexere/v1/editor-suggestions' )
			) {
				analysisRequests += 1;
			}
		} );

		await openSidebar( page );
		expect( analysisRequests ).toBe( 0 );
		await editor.setContent( unsavedContent.replace( 'guide', 'article' ) );
		await expect.poll( () => analysisRequests ).toBe( 0 );

		await page.getByRole( 'button', { name: 'Analyze draft' } ).click();
		await expect(
			page.getByRole( 'heading', {
				name: candidateTitle,
				exact: true,
			} )
		).toBeVisible();
		expect( analysisRequests ).toBe( 1 );
		expect( await page.getByText( 'Insert Link' ).count() ).toBe( 0 );

		await editor.setContent(
			unsavedContent.replace( 'guide', 'changed guide' )
		);
		await expect(
			page
				.getByLabel( 'Editor settings' )
				.getByText( /The draft changed/ )
		).toBeVisible();
		await expect.poll( () => analysisRequests ).toBe( 1 );

		await page.getByRole( 'button', { name: 'Dismiss' } ).click();
		await expect( page.getByText( /No current suggestion/ ) ).toBeVisible();
	} );

	test( 'accepts line-separator Unicode without a client-server draft mismatch', async ( {
		admin,
		page,
	} ) => {
		await admin.createNewPost( {
			title: candidateTitle,
			content: unsavedContent.replace(
				'guide explains',
				'guide\u2028explains\u2029'
			),
			showWelcomeGuide: false,
		} );
		await openSidebar( page );
		await page.getByRole( 'button', { name: 'Analyze draft' } ).click();
		await expect(
			page.getByRole( 'heading', {
				name: candidateTitle,
				exact: true,
			} )
		).toBeVisible();
		await expect(
			page.getByText(
				'Draft analysis returned for a different editor state.'
			)
		).toHaveCount( 0 );
	} );

	test( 'ignores a late older response and renders recoverable server states', async ( {
		admin,
		page,
	} ) => {
		await admin.createNewPost( {
			title: candidateTitle,
			content: unsavedContent,
			showWelcomeGuide: false,
		} );
		await openSidebar( page );

		await page.evaluate( () => {
			const originalFetch = window.fetch.bind( window );
			let analysisCalls = 0;
			window.fetch = ( resource, options ) => {
				const url =
					typeof resource === 'string' ? resource : resource.url;
				if ( ! url.includes( '/intertexere/v1/editor-suggestions' ) ) {
					return originalFetch( resource, options );
				}

				analysisCalls += 1;
				if ( analysisCalls === 1 ) {
					return new Promise( ( resolve ) => {
						window.__intertexereReleaseOldResponse = () =>
							resolve(
								new Response(
									JSON.stringify( {
										code: 'old',
										message: 'Old response',
									} ),
									{
										status: 500,
										headers: {
											'Content-Type': 'application/json',
										},
									}
								)
							);
					} );
				}

				return Promise.resolve(
					new Response(
						JSON.stringify( {
							code: 'intertexere_index_unavailable',
							message: 'Index unavailable',
						} ),
						{
							status: 503,
							headers: { 'Content-Type': 'application/json' },
						}
					)
				);
			};
		} );

		await page.getByRole( 'button', { name: 'Analyze draft' } ).click();
		await expect
			.poll( () =>
				page.evaluate(
					() =>
						typeof window.__intertexereReleaseOldResponse ===
						'function'
				)
			)
			.toBe( true );
		await page.getByRole( 'button', { name: 'Analyze draft' } ).click();
		await expect(
			page
				.getByLabel( 'Editor settings' )
				.getByText( 'Index unavailable' )
		).toBeVisible();
		await page.evaluate( () => window.__intertexereReleaseOldResponse() );
		await page.waitForTimeout( 100 );
		await expect( page.getByText( 'Old response' ) ).toHaveCount( 0 );
	} );

	test( 'does not persist unsaved analysis content and leaves normal save behavior intact', async ( {
		admin,
		editor,
		page,
		requestUtils,
	} ) => {
		const saved = await requestUtils.createPost( {
			title: 'Saved source',
			content:
				'<!-- wp:paragraph --><p>Original database content.</p><!-- /wp:paragraph -->',
			status: 'draft',
			date_gmt: new Date().toISOString().replace( /\.\d{3}Z$/, '' ),
		} );
		await admin.editPost( saved.id );
		await editor.setContent( unsavedContent );
		await openSidebar( page );
		await page.getByRole( 'button', { name: 'Analyze draft' } ).click();
		await expect(
			page.getByRole( 'heading', {
				name: candidateTitle,
				exact: true,
			} )
		).toBeVisible();

		const beforeSave = await requestUtils.rest( {
			path: `/wp/v2/posts/${ saved.id }`,
			params: { context: 'edit' },
		} );
		expect( beforeSave.content.raw ).toContain(
			'Original database content.'
		);
		expect( beforeSave.content.raw ).not.toContain(
			'predictable local analysis'
		);

		await editor.saveDraft();
		const afterSave = await requestUtils.rest( {
			path: `/wp/v2/posts/${ saved.id }`,
			params: { context: 'edit' },
		} );
		expect( afterSave.content.raw ).toContain(
			'predictable local analysis'
		);
	} );

	test( 'removes the UI when disabled while ordinary editing still saves', async ( {
		admin,
		editor,
		page,
		requestUtils,
	} ) => {
		await requestUtils.deactivatePlugin( 'intertexere' );
		try {
			await admin.createNewPost( {
				title: 'Plugin disabled editing',
				content:
					'<!-- wp:paragraph --><p>Ordinary editing.</p><!-- /wp:paragraph -->',
				showWelcomeGuide: false,
			} );
			expect( await page.getByText( 'Analyze draft' ).count() ).toBe( 0 );
			await editor.setContent(
				'<!-- wp:paragraph --><p>Ordinary editing still works.</p><!-- /wp:paragraph -->'
			);
			await editor.saveDraft();
			await expect(
				page
					.getByTestId( 'snackbar' )
					.filter( { hasText: 'Draft saved.' } )
			).toBeVisible();
		} finally {
			await requestUtils.activatePlugin( 'intertexere' );
		}
	} );

	test( 'clears session results and does not analyze automatically after post navigation', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		const first = await requestUtils.createPost( {
			title: 'First editor source',
			content: unsavedContent,
			status: 'draft',
		} );
		const second = await requestUtils.createPost( {
			title: 'Second editor source',
			content:
				'<!-- wp:paragraph --><p>Unrelated second draft content.</p><!-- /wp:paragraph -->',
			status: 'draft',
		} );
		let analysisRequests = 0;
		page.on( 'request', ( request ) => {
			if (
				request.url().includes( '/intertexere/v1/editor-suggestions' )
			) {
				analysisRequests += 1;
			}
		} );

		await admin.editPost( first.id );
		await openSidebar( page );
		await page.getByRole( 'button', { name: 'Analyze draft' } ).click();
		await expect(
			page.getByRole( 'heading', {
				name: candidateTitle,
				exact: true,
			} )
		).toBeVisible();
		await expect.poll( () => analysisRequests ).toBe( 1 );

		await admin.editPost( second.id );
		await openSidebar( page );
		await expect(
			page.getByRole( 'heading', {
				name: candidateTitle,
				exact: true,
			} )
		).toHaveCount( 0 );
		await page.waitForTimeout( 100 );
		expect( analysisRequests ).toBe( 1 );
	} );
} );
