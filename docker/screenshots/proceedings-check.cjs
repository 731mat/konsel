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

	// --- Hlasování ---
	page.on('dialog', (dialog) => dialog.accept());
	await Promise.all([page.waitForNavigation(), page.click('button:has-text("Zahájit zasedání")')]);
	const item = page.locator('.proceedings-item', { has: page.locator('button:has-text("Hlasovat o návrhu usnesení")') }).first();
	const itemId = await item.getAttribute('id');
	await item.locator('button:has-text("Hlasovat o návrhu usnesení")').click();
	await page.waitForSelector(`#${itemId} .voting-round-open`);
	check(await page.locator(`#${itemId} .voting-matrix-row`).count() === 8, 'hlasuje 8 přítomných');
	await page.locator(`#${itemId} button:has-text("Všichni přítomní pro")`).click();
	await page.waitForFunction((id) => document.querySelector(`#${id} .voting-tally`)?.innerText.includes('8 pro'), itemId);
	await page.locator(`#${itemId} .voting-matrix-row`).nth(1).locator('button[value=against]').click();
	await page.waitForFunction((id) => document.querySelector(`#${id} .voting-tally`)?.innerText.includes('1 proti'), itemId);
	await page.screenshot({ path: '/out/proceedings-voting.png', fullPage: true });
	await page.locator(`#${itemId} button:has-text("Ukončit hlasování")`).click();
	await page.waitForSelector(`#${itemId} .voting-round:not(.voting-round-open)`);
	const summary = (await page.locator(`#${itemId} .voting-round`).last().innerText()).replace(/\s+/g, ' ');
	check(summary.includes('přijato') && summary.includes('7 pro'), `výsledek: ${summary.slice(0, 120)}`);
	check(await page.evaluate(() => window.konselMarker === undefined), 'zahájení znovu načetlo stránku, hlasování už běží přes AJAX');

	// --- Diskuse: uloží se při opuštění pole, i když hned kliknu na hlasování jiného bodu ---
	const discussion = page.locator(`#${itemId} textarea[name=discussion]`);
	await discussion.fill('Pan Novotný navrhl ověřovatele, bez dalších připomínek.');
	const next = page.locator('.proceedings-item', { has: page.locator('button:has-text("Hlasovat o návrhu usnesení")') }).first();
	const nextId = await next.getAttribute('id');
	await next.locator('button:has-text("Hlasovat o návrhu usnesení")').click();
	await page.waitForSelector(`#${nextId} .voting-round-open`);
	await page.waitForFunction((id) => document.querySelector(`#${id} [data-autosave-status]`)?.textContent.startsWith('Uloženo'), itemId);
	check(await discussion.inputValue() === 'Pan Novotný navrhl ověřovatele, bez dalších připomínek.', 'rozepsaná diskuse zůstala po překreslení hlasování');
	await page.reload();
	check(await page.locator(`#${itemId} textarea[name=discussion]`).inputValue() === 'Pan Novotný navrhl ověřovatele, bez dalších připomínek.', 'diskuse je uložená i po načtení stránky');
	await page.screenshot({ path: '/out/proceedings-after.png', fullPage: true });
	await browser.close();
	console.log('Průběh funguje.');
})().catch((error) => { console.error(error); process.exit(1); });
