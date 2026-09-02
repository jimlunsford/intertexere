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

test.describe( 'Intertexere read-only editor suggestions', () => {
	test.beforeAll( async ( { requestUtils } ) => {
		await requestUtils.activatePlugin( 'intertexere' );
	} );

	test.beforeEach( async ( { requestUtils } ) => {
		await requestUtils.deleteAllPosts();
		await requestUtils.createPost( {
			title: candidateTitle,
			content: candidateContent,
			status: 'publish',
			date_gmt: new Date().toISOString().replace( /\.\d{3}Z$/, '' ),
		} );
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

	test( 'ignores a late older response and renders recoverable server states', async ( {
		admin,
		editor,
		page,
	} ) => {
		await admin.createNewPost( {
			title: candidateTitle,
			content: unsavedContent,
			showWelcomeGuide: false,
		} );
		await openSidebar( page );

		let first = true;
		await page.route(
			'**/wp-json/intertexere/v1/editor-suggestions',
			async ( route ) => {
				if ( first ) {
					first = false;
					await new Promise( ( resolve ) =>
						setTimeout( resolve, 500 )
					);
					await route.fulfill( {
						status: 500,
						contentType: 'application/json',
						body: JSON.stringify( {
							code: 'old',
							message: 'Old response',
						} ),
					} );
					return;
				}
				await route.fulfill( {
					status: 503,
					contentType: 'application/json',
					body: JSON.stringify( {
						code: 'intertexere_index_unavailable',
						message: 'Index unavailable',
					} ),
				} );
			}
		);

		await page.getByRole( 'button', { name: 'Analyze draft' } ).click();
		await editor.setContent(
			unsavedContent.replace( 'guide', 'fresh guide' )
		);
		await page.getByRole( 'button', { name: 'Analyze draft' } ).click();
		await expect( page.getByText( 'Index unavailable' ) ).toBeVisible();
		await page.waitForTimeout( 600 );
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
			path: `/wp/v2/posts/${ saved.id }?context=edit`,
		} );
		expect( beforeSave.content.raw ).toContain(
			'Original database content.'
		);
		expect( beforeSave.content.raw ).not.toContain(
			'predictable local analysis'
		);

		await editor.saveDraft();
		const afterSave = await requestUtils.rest( {
			path: `/wp/v2/posts/${ saved.id }?context=edit`,
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
} );
