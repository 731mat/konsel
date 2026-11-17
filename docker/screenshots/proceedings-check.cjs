// Ověří obrazovku průběhu: docházka přes AJAX překreslí ukazatel usnášeníschopnosti bez načtení stránky.
// Spuštění: docker compose run --rm screenshots sh -c "npm install --silent --prefix /tmp/pw playwright@1.49.1 && node proceedings-check.cjs"
const { chromium } = require('playwright');

const base = process.env.BASE_URL || 'http://app';
const meetingId = process.env.MEETING || '3';
const check = (condition, message) => {
	if (!condition) {
		throw new Error(`NEPROŠLO: ${message}`);
	}
	console.log(`ok – ${message}`);
};

(async () => {
	const browser = await chromium.launch();
	const page = await (await browser.newContext({ viewport: { width: 1280, height: 900 } })).newPage();
	page.on('pageerror', (error) => console.error('Chyba JS:', error.message));
	await page.goto(`${base}/prihlaseni`);
	await page.fill('input[name=email]', 'spravce@demo.konsel.test');
	await page.fill('input[name=password]', 'demo-heslo-123');
	await Promise.all([page.waitForNavigation(), page.click('form [type=submit]')]);
	await page.goto(`${base}/admin/proceedings/default/${meetingId}`);

	await page.evaluate(() => { window.konselMarker = 1; });
	const quorum = page.locator('.quorum-indicator');

	await page.locator('.attendance-row').first().locator('button[value=excused]').click();
	await page.waitForFunction(() => document.querySelector('.attendance-row button[value=excused]')?.getAttribute('aria-pressed') === 'true');
	check(true, 'první člen omluven');

	await page.click('button:has-text("Ostatní přítomni")');
	await page.waitForFunction(() => document.querySelector('.quorum-indicator')?.classList.contains('quorum-ok'));
	check((await quorum.innerText()).includes('přítomno 8 z 9'), `ukazatel: ${(await quorum.innerText()).replace(/\s+/g, ' ')}`);
	check(await page.evaluate(() => window.konselMarker === 1), 'bez znovunačtení stránky (AJAX snippety)');
	await page.screenshot({ path: '/out/proceedings-after.png', fullPage: true });
	await browser.close();
	console.log('Průběh funguje.');
})().catch((error) => { console.error(error); process.exit(1); });
