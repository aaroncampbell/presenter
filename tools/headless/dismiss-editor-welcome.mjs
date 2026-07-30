/**
 * Close WordPress's first-use editor guide when a fresh local user sees it.
 *
 * @param {import('@playwright/test').Page} page Editor page.
 * @return {Promise<boolean>} Whether the guide was closed.
 */
export async function dismissEditorWelcome( page ) {
	const dialog = page.getByRole( 'dialog', {
		name: 'Welcome to the editor',
		exact: true,
	} );

	if ( 1 !== ( await dialog.count() ) ) {
		return false;
	}

	await dialog.getByRole( 'button', { name: 'Close', exact: true } ).click();

	return true;
}
