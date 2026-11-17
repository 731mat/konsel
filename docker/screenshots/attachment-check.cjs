// Přílohy bodu: tlačítko + otevře okno, přetažení souborů na kartu otevře okno se soubory a nahrání je přidá do seznamu.
const { chromium } = require('playwright');
const base = process.env.BASE_URL || 'http://app';
(async () => {
	const browser = await chromium.launch();
	const page = await (await browser.newContext({ viewport: { width: 1280, height: 900 } })).newPage();
	page.on('pageerror', (error) => console.error('Chyba JS:', error.message));
	await page.goto(`${base}/prihlaseni`);
	await page.fill('input[name=email]', 'spravce@demo.konsel.test');
	await page.fill('input[name=password]', 'demo-heslo-123');
	await Promise.all([page.waitForNavigation(), page.click('form [type=submit]')]);
	await page.goto(`${base}/admin/agenda/detail/18`);

	console.log('patička s formulářem pryč:', await page.locator('#prilohy .card-footer').count() === 0 ? 'ano' : 'NE');
	await page.click('#prilohy [data-bs-target="#attachment-modal"]');
	await page.waitForSelector('#attachment-modal.show', { timeout: 5000 });
	console.log('tlačítko + otevře okno: ano');
	await page.click('#attachment-modal [data-bs-dismiss="modal"]');
	// po animaci zavření (Bootstrap během ní další otevření ignoruje)
	await page.waitForFunction(() => document.querySelector('#attachment-modal').style.display === 'none');

	const dropped = await page.evaluate(() => {
		const zone = document.querySelector('#prilohy');
		const transfer = new DataTransfer();
		transfer.items.add(new File(['%PDF-1.4 test'], 'smlouva-o-dilo.pdf', { type: 'application/pdf' }));
		zone.dispatchEvent(new DragEvent('dragover', { dataTransfer: transfer, bubbles: true, cancelable: true }));
		zone.dispatchEvent(new DragEvent('drop', { dataTransfer: transfer, bubbles: true, cancelable: true }));
		return document.querySelector('#attachment-modal [data-dropzone-list]').textContent.trim();
	});
	await page.waitForSelector('#attachment-modal.show', { timeout: 5000 });
	console.log('přetažení na kartu otevře okno se souborem:', dropped.includes('smlouva-o-dilo.pdf') ? 'ano' : 'NE');
	await page.waitForTimeout(500);
	await page.screenshot({ path: '/out/attachment-modal.png' });

	await Promise.all([page.waitForNavigation(), page.click('#attachment-modal button[name=upload]')]);
	console.log('nahráno:', (await page.locator('#prilohy').textContent()).includes('smlouva-o-dilo.pdf') ? 'ano' : 'NE');
	await browser.close();
})().catch((error) => { console.error('CHYBA', error.message); process.exit(1); });
