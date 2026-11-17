// Dva prohlížeče na obrazovce průběhu: zámek diskuse, převzetí uloženého textu a průběžná synchronizace docházky.
// Spuštění: docker compose run --rm screenshots sh -c "npm install --silent --prefix /tmp/pw playwright@1.49.1 && node concurrency-check.cjs"
const { chromium } = require('playwright');

const base = process.env.BASE_URL || 'http://app';
const meetingId = process.env.MEETING || '3';
const check = (condition, message) => {
	if (!condition) {
		throw new Error(`NEPROŠLO: ${message}`);
	}
	console.log(`ok – ${message}`);
};

async function login(browser, email) {
	const page = await (await browser.newContext({ viewport: { width: 1280, height: 900 } })).newPage();
	page.on('pageerror', (error) => console.error(`Chyba JS (${email}):`, error.message));
	page.on('dialog', (dialog) => dialog.accept());
	await page.goto(`${base}/prihlaseni`);
	await page.fill('input[name=email]', email);
	await page.fill('input[name=password]', 'demo-heslo-123');
	await Promise.all([page.waitForNavigation(), page.click('form [type=submit]')]);
	await page.goto(`${base}/admin/proceedings/default/${meetingId}`);
	return page;
}

(async () => {
	const browser = await chromium.launch();
	const admin = await login(browser, 'spravce@demo.konsel.test');
	if (await admin.locator('button:has-text("Zahájit zasedání")').count()) {
		await admin.click('button:has-text("Ostatní přítomni")');
		await admin.waitForSelector('.quorum-ok');
		await Promise.all([admin.waitForNavigation(), admin.click('button:has-text("Zahájit zasedání")')]);
	}
	const editor = await login(browser, 'editor@demo.konsel.test');

	const id = await admin.locator('textarea[name=discussion]').first().getAttribute('id');
	const mine = admin.locator(`#${id}`);
	const theirs = editor.locator(`#${id}`);

	await mine.click();
	await mine.fill('Starostka zapisuje diskusi.');
	await editor.waitForFunction((tid) => document.getElementById(tid)?.readOnly === true, id, { timeout: 12000 });
	const notice = await editor.locator(`[data-lock-for="${id}"]`).innerText();
	check(notice.includes('Upravuje Jana Správcová'), `druhý prohlížeč vidí zámek: „${notice.trim()}“`);

	await admin.locator('h1').click(); // opuštění pole → uložení a uvolnění zámku
	await admin.waitForFunction((tid) => document.getElementById(tid)?.closest('form').querySelector('[data-autosave-status]').textContent.startsWith('Uloženo'), id);
	try {
		await editor.waitForFunction((tid) => document.getElementById(tid)?.readOnly === false, id, { timeout: 12000 });
	} catch (error) {
		console.log('LADĚNÍ zámek u editorky:', await editor.locator(`[data-lock-for="${id}"]`).evaluate((el) => el.outerHTML));
		console.log('LADĚNÍ textarea:', await theirs.evaluate((el) => `readOnly=${el.readOnly} active=${document.activeElement === el}`));
		throw error;
	}
	check(await theirs.inputValue() === 'Starostka zapisuje diskusi.', 'po uvolnění má druhý prohlížeč uložený text a může psát');

	const before = (await editor.locator('.quorum-indicator').innerText()).replace(/\s+/g, ' ');
	await admin.locator('.attendance-row').first().locator('button[value=absent]').click();
	await editor.waitForFunction((text) => document.querySelector('.quorum-indicator').innerText.replace(/\s+/g, ' ') !== text, before, { timeout: 12000 });
	check(true, 'změna docházky se do druhého prohlížeče promítla průběžným obnovením');

	await browser.close();
	console.log('Souběžná práce funguje.');
})().catch((error) => { console.error(error); process.exit(1); });
