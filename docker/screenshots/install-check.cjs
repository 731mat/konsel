// Projde webovou instalaci na prázdné instanci (produkční image) a uloží screenshot každého kroku.
// Použití: BASE_URL=http://app CODE=XXXXX-XXXXX node install-check.cjs
const { chromium } = require('playwright');
const base = process.env.BASE_URL || 'http://app';
(async () => {
	const browser = await chromium.launch();
	const page = await (await browser.newContext({ viewport: { width: 1280, height: 900 } })).newPage();
	page.on('pageerror', (error) => console.error('Chyba JS:', error.message));
	const shot = async (name) => page.screenshot({ path: `/out/install-${name}.png`, fullPage: true });

	await page.goto(`${base}/`);
	console.log('úvod →', page.url());
	await shot('1-code');
	await page.fill('input[name=code]', process.env.CODE);
	await Promise.all([page.waitForNavigation(), page.click('form [type=submit]')]);
	await shot('2-check');
	await Promise.all([page.waitForNavigation(), page.click('a.btn-primary')]);
	await page.fill('input[name=name]', 'Neplachovice');
	await page.fill('input[name=officialName]', 'Obec Neplachovice');
	await page.fill('input[name=city]', 'Neplachovice');
	await shot('3-municipality');
	await Promise.all([page.waitForNavigation(), page.click('form [type=submit][name=send]')]);
	await page.check('input[name=hasBoard]');
	await shot('4-bodies');
	await Promise.all([page.waitForNavigation(), page.click('form [type=submit][name=send]')]);
	await shot('5-options');
	await Promise.all([page.waitForNavigation(), page.click('form [type=submit][name=send]')]);
	await page.fill('input[name=name]', 'Jana Starostová');
	await page.fill('input[name=email]', 'starosta@neplachovice.cz');
	await page.fill('input[name=password]', 'instalace-heslo-123');
	await page.fill('input[name=passwordConfirm]', 'instalace-heslo-123');
	await shot('6-admin');
	await Promise.all([page.waitForNavigation(), page.click('form [type=submit][name=send]')]);
	await shot('7-done');
	console.log('konec →', page.url());
	console.log((await page.locator('.alert').first().textContent())?.trim());
	await browser.close();
})();
