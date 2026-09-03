const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );

const auditUrl = '/wp-admin/tools.php?page=intertexere-site-link-audit';

test.describe( 'Intertexere Site Link Audit', () => {
	test.beforeAll( async ( { requestUtils } ) => {
		await requestUtils.activatePlugin( 'intertexere' );
	} );

	test.beforeEach( async ( { requestUtils } ) => {
		await requestUtils.rest( {
			path: '/intertexere-e2e/v1/mode',
			method: 'POST',
			data: { mode: 'available' },
		} );
		await requestUtils.deleteAllPosts();
	} );

	test( 'authorized admin sees accurate read-only categories and actions', async ( {
		page,
		requestUtils,
	} ) => {
		const orphan = await requestUtils.createPost( {
			title: 'Content body orphan fixture',
			content: '<p>No saved body links point here.</p>',
			status: 'publish',
			date_gmt: new Date().toISOString().replace( /\.\d{3}Z$/, '' ),
		} );
		const target = await requestUtils.createPost( {
			title: 'Thin inbound fixture',
			content: '<p>One source points here.</p>',
			status: 'publish',
			date_gmt: new Date().toISOString().replace( /\.\d{3}Z$/, '' ),
		} );
		const source = await requestUtils.createPost( {
			title: 'Audit source fixture',
			content: `<p><a href="${ target.link }">Thin target</a><a href="/missing-audit-e2e/">Unknown target</a></p>`,
			status: 'publish',
			date_gmt: new Date().toISOString().replace( /\.\d{3}Z$/, '' ),
		} );
		const sourceBefore = await requestUtils.rest( {
			path: `/wp/v2/posts/${ source.id }`,
			params: { context: 'edit' },
		} );

		let aiRequests = 0;
		page.on( 'request', ( request ) => {
			if ( request.url().includes( '/editor-suggestions/ai-enhance' ) ) {
				aiRequests += 1;
			}
		} );
		await page.goto( auditUrl );
		await expect(
			page.getByRole( 'heading', { name: 'Intertexere Site Link Audit' } )
		).toBeVisible();
		await expect(
			page.getByText( /literal links in saved post content/i )
		).toBeVisible();
		await expect( page.getByText( /Navigation, templates/ ) ).toBeVisible();
		await expect(
			page.getByText( /no internal links anywhere/i )
		).toHaveCount( 0 );
		await expect(
			page.getByRole( 'heading', { name: 'Active-generation findings' } )
		).toBeVisible();

		await page
			.getByRole( 'link', { name: 'Content-body orphans' } )
			.click();
		await page
			.getByLabel( 'Exact post ID or slug' )
			.fill( String( orphan.id ) );
		await page.getByRole( 'button', { name: 'Filter audit' } ).click();
		await expect(
			page.getByRole( 'rowheader', {
				name: 'Content body orphan fixture',
			} )
		).toBeVisible();
		await expect(
			page.getByRole( 'link', {
				name: 'View Content body orphan fixture',
			} )
		).toBeVisible();
		await expect(
			page.getByRole( 'link', {
				name: 'Edit Content body orphan fixture',
			} )
		).toBeVisible();

		await page
			.getByRole( 'link', { name: 'Thin inbound coverage' } )
			.click();
		await page
			.getByLabel( 'Exact post ID or slug' )
			.fill( String( target.id ) );
		await page.getByRole( 'button', { name: 'Filter audit' } ).click();
		await expect(
			page.getByText( /Exactly one qualifying inbound source/ )
		).toBeVisible();

		await page
			.getByRole( 'link', { name: 'Unresolved and unavailable' } )
			.click();
		await page
			.getByLabel( 'Exact post ID or slug' )
			.fill( String( source.id ) );
		await page.getByRole( 'button', { name: 'Filter audit' } ).click();
		await expect(
			page.getByText( /Unresolved internal URL/ )
		).toBeVisible();
		await expect(
			page.getByRole( 'button', { name: /repair|fix|replace/i } )
		).toHaveCount( 0 );
		expect( aiRequests ).toBe( 0 );

		const sourceAfter = await requestUtils.rest( {
			path: `/wp/v2/posts/${ source.id }`,
			params: { context: 'edit' },
		} );
		expect( sourceAfter.content.raw ).toBe( sourceBefore.content.raw );
		expect( sourceAfter.status ).toBe( 'publish' );
	} );

	test( 'keyset pagination, empty, stale, and unavailable states render safely', async ( {
		page,
		requestUtils,
	} ) => {
		for ( let index = 0; index < 22; index += 1 ) {
			await requestUtils.createPost( {
				title: `Audit pagination ${ index }`,
				content: '<p>Orphan fixture.</p>',
				status: 'publish',
				date_gmt: new Date().toISOString().replace( /\.\d{3}Z$/, '' ),
			} );
		}
		await page.goto( `${ auditUrl }&category=orphans` );
		await expect(
			page.getByRole( 'link', { name: 'Next page' } )
		).toBeVisible();
		await page.getByRole( 'link', { name: 'Next page' } ).focus();
		await page.keyboard.press( 'Enter' );
		await expect( page ).toHaveURL( /cursor=/ );

		await page.goto( `${ auditUrl }&category=thin&search=99999999` );
		await expect( page.getByRole( 'status' ) ).toContainText(
			'No current findings'
		);

		await requestUtils.rest( {
			path: '/intertexere-e2e/v1/mode',
			method: 'POST',
			data: { mode: 'audit-stale' },
		} );
		await page.goto( `${ auditUrl }&category=orphans` );
		await expect( page.getByRole( 'status' ) ).toContainText(
			/changed during this request/i
		);

		await requestUtils.rest( {
			path: '/intertexere-e2e/v1/mode',
			method: 'POST',
			data: { mode: 'audit-unavailable' },
		} );
		await page.goto( auditUrl );
		await expect( page.getByRole( 'status' ) ).toContainText(
			/could not be calculated/i
		);
	} );

	test( 'an editor without manage_intertexere cannot access site-wide audit data', async ( {
		browser,
		requestUtils,
	} ) => {
		const username = `audit_editor_${ Date.now() }`;
		const password = 'intertexere-e2e-password';
		await requestUtils.createUser( {
			username,
			email: `${ username }@example.com`,
			password,
			roles: [ 'editor' ],
		} );
		const context = await browser.newContext();
		const page = await context.newPage();
		await page.goto( '/wp-login.php' );
		await page.getByLabel( 'Username or Email Address' ).fill( username );
		await page.getByLabel( 'Password', { exact: true } ).fill( password );
		await page.getByRole( 'button', { name: 'Log In' } ).click();
		await page.goto( auditUrl );
		await expect(
			page.getByText( /not allowed to access this page/i )
		).toBeVisible();
		await expect(
			page.getByText( 'Intertexere Site Link Audit', { exact: true } )
		).toHaveCount( 0 );
		await context.close();
	} );
} );
