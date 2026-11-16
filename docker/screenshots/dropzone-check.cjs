// Ověří, že přetažení souboru do diskuse soubor přidá do formuláře a že komentář se souborem odejde.
const { chromium } = require('playwright');
(async () => {
	const browser = await chromium.launch();
	const page = await (await browser.newContext()).newPage();
	await page.goto('http://app/prihlaseni');
	await page.fill('input[name=email]', 'spravce@demo.konsel.test');
	await page.fill('input[name=password]', 'demo-heslo-123');
	await Promise.all([page.waitForNavigation(), page.click('form [type=submit]')]);
	await page.goto('http://app/admin/agenda/detail/18');

	const selected = await page.evaluate(() => {
		const zone = document.querySelector('form.comment-form [data-dropzone]');
		const transfer = new DataTransfer();
		transfer.items.add(new File(['obsah souboru'], 'zapis-z-kontroly.pdf', { type: 'application/pdf' }));
		zone.dispatchEvent(new DragEvent('dragover', { dataTransfer: transfer, bubbles: true, cancelable: true }));
		zone.dispatchEvent(new DragEvent('drop', { dataTransfer: transfer, bubbles: true, cancelable: true }));
		return { files: zone.querySelector('[data-dropzone-input]').files.length, list: zone.querySelector('[data-dropzone-list]').textContent.trim() };
	});
	console.log('po přetažení:', JSON.stringify(selected));

	await page.fill('form.comment-form textarea', 'Přikládám zápis z kontroly.');
	await page.click('form.comment-form button[name=send]');
	await page.waitForFunction(() => document.querySelector('#diskuse')?.textContent.includes('zapis-z-kontroly.pdf'), null, { timeout: 10000 });
	console.log('komentář se souborem odeslán přes AJAX: ano');
	await browser.close();
})().catch((error) => { console.error('CHYBA', error.message); process.exit(1); });
