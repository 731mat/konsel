// Záznam zasedání: + otevře okno, nahrání MP3 přidá přehrávač, soubor se dá přehrát (Range).
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
	await page.goto(`${base}/admin/meeting/detail/2`);
	await page.click('#zaznam [data-bs-target="#recording-modal"]');
	await page.waitForSelector('#recording-modal.show', { timeout: 5000 });
	const mp3 = Buffer.concat([Buffer.from('ID3\x03\x00\x00\x00\x00\x00\x0a', 'binary'), Buffer.alloc(10), Buffer.from([0xff, 0xfb, 0x90, 0x64]), Buffer.alloc(4000)]);
	await page.setInputFiles('#recording-modal input[type=file]', { name: 'zasedani-12-11.mp3', mimeType: 'audio/mpeg', buffer: mp3 });
	await Promise.all([page.waitForNavigation(), page.click('#recording-modal button[name=upload]')]);
	const src = await page.getAttribute('#zaznam audio', 'src');
	console.log('přehrávač po nahrání:', src ? 'ano' : 'NE');
	const response = await page.request.get(`${base}${src}`, { headers: { Range: 'bytes=0-99' } });
	console.log('přetáčení (HTTP 206):', response.status() === 206 ? 'ano' : `NE (${response.status()})`, response.headers()['content-type']);
	await page.locator('#zaznam').screenshot({ path: '/out/recording-card.png' });
	await browser.close();
})().catch((error) => { console.error('CHYBA', error.message); process.exit(1); });
