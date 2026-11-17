// Ověří editor komentářů (formátování, Ctrl+Enter, soubor vložený ze schránky) a barvy obce (uložení, tmavý režim, návrat).
// Spuštění: docker compose run --rm screenshots sh -c "npm install --silent --prefix /tmp/pw playwright@1.49.1 && node editor-check.cjs"
const { chromium } = require('playwright');

const base = process.env.BASE_URL || 'http://app';
const check = (condition, message) => {
	if (!condition) {
		throw new Error(`NEPROŠLO: ${message}`);
	}
	console.log(`ok – ${message}`);
};

(async () => {
	const browser = await chromium.launch();
	const context = await browser.newContext({ viewport: { width: 1280, height: 900 }, locale: 'cs-CZ' });
	const page = await context.newPage();
	page.on('pageerror', (error) => console.error('Chyba JS:', error.message));
	await page.goto(`${base}/prihlaseni`);
	await page.fill('input[name=email]', 'spravce@demo.konsel.test');
	await page.fill('input[name=password]', 'demo-heslo-123');
	await Promise.all([page.waitForNavigation(), page.click('form [type=submit]')]);

	// --- Editor komentářů ---
	await page.goto(`${base}/admin/agenda/detail/18`);
	const editor = page.locator('form.comment-form .rich-editor-content');
	await editor.waitFor();
	check(await page.locator('form.comment-form textarea[name=body]').isHidden(), 'textarea je nahrazená editorem');

	await editor.click();
	await page.keyboard.type('Prosím o ');
	await page.keyboard.press('Control+b');
	await page.keyboard.type('doplnění');
	await page.keyboard.press('Control+b');
	await page.keyboard.type(' podkladů:');
	await page.keyboard.press('Enter');
	await page.keyboard.type('- smlouva');
	await page.keyboard.press('Enter');
	await page.keyboard.type('rozpočet');
	const value = await page.locator('form.comment-form textarea[name=body]').inputValue();
	check(value.includes('<strong>doplnění</strong>') && value.includes('<ul><li><p>smlouva</p></li><li><p>rozpočet</p></li></ul>'), `HTML v poli: ${value}`);
	check(await page.locator('.rich-editor-button[aria-label="Odrážky"]').getAttribute('aria-pressed') === 'true', 'tlačítko odrážek ukazuje aktivní stav');

	// obrázek ze schránky (bez text/html) patří do příloh, ne do textu
	await editor.evaluate((element) => {
		const transfer = new DataTransfer();
		transfer.items.add(new File(['png'], 'snimek.png', { type: 'image/png' }));
		element.dispatchEvent(new ClipboardEvent('paste', { clipboardData: transfer, bubbles: true, cancelable: true }));
	});
	check((await page.locator('[data-dropzone-list] li').allTextContents()).join().includes('snimek.png'), 'vložený obrázek je mezi přílohami');

	await page.keyboard.press('Control+Enter');
	await page.waitForFunction(() => [...document.querySelectorAll('.comment-body')].some((body) => body.innerHTML.includes('<strong>doplnění</strong>')));
	check(true, 'Ctrl+Enter odeslal komentář a vykreslil se s formátováním');
	check(await page.locator('form.comment-form .rich-editor-content').count() === 1, 'po AJAXu je editor znovu připravený');
	check((await page.locator('.comment-file').allTextContents()).join().includes('snimek.png'), 'příloha z obrázku je u komentáře');
	await page.screenshot({ path: '/out/editor-after-submit.png', fullPage: true });

	// --- Barvy obce ---
	await page.goto(`${base}/admin/settings/appearance`);
	await page.click('[data-theme-preset][data-primary="#11457e"]');
	const previewPrimary = await page.locator('[data-theme-preview]').evaluate((element) => element.style.getPropertyValue('--bs-primary'));
	check(previewPrimary === '#11457e', 'náhled se přebarví hned po výběru kombinace');
	await page.fill('[data-theme-input=primary]', '#f5d76e');
	check(await page.locator('[data-theme-contrast]').isVisible(), 'příliš světlá barva ukáže varování');
	await page.click('[data-theme-preset][data-primary="#11457e"]');
	await Promise.all([page.waitForNavigation(), page.click('form [name=save]')]);
	const primary = await page.evaluate(() => getComputedStyle(document.documentElement).getPropertyValue('--bs-primary').trim());
	check(primary === '#11457e', `po uložení má celá aplikace novou barvu (${primary})`);

	await page.goto(`${base}/admin/project/detail/1`);
	await page.screenshot({ path: '/out/brand-light.png', fullPage: true });
	await page.evaluate(() => { document.documentElement.dataset.bsTheme = 'dark'; });
	await page.screenshot({ path: '/out/brand-dark.png', fullPage: true });
	await page.evaluate(() => { document.documentElement.dataset.bsTheme = 'light'; });

	const citizen = await browser.newPage({ viewport: { width: 1280, height: 900 } });
	await citizen.goto(`${base}/`);
	await citizen.screenshot({ path: '/out/brand-homepage.png', fullPage: true });
	await citizen.goto(`${base}/obec/neplachovice/podnety/1`);
	await citizen.screenshot({ path: '/out/brand-citizen.png', fullPage: true });

	await page.goto(`${base}/admin/settings/appearance`);
	page.once('dialog', (dialog) => dialog.accept());
	await Promise.all([page.waitForNavigation(), page.click('button:has-text("Vrátit výchozí barvy")')]);
	const reset = await page.evaluate(() => getComputedStyle(document.documentElement).getPropertyValue('--bs-primary').trim());
	check(reset === '#1b5e8c', 'návrat k výchozím barvám');

	await browser.close();
	console.log('Editor i barvy fungují.');
})().catch((error) => { console.error(error); process.exit(1); });
