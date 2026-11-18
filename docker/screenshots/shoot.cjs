// Screenshoty aplikace pro kontrolu vzhledu: make screenshots → temp/screenshots/*.png
const { chromium } = require('playwright');

const base = process.env.BASE_URL || 'http://app';
const pages = ((process.env.PAGES || '').trim() || '/admin,/admin/meeting,/admin/meeting/detail/3,/admin/member,/admin/settings/ai').split(',');
const viewports = { desktop: { width: 1440, height: 900 }, mobile: { width: 390, height: 844 } };

(async () => {
	const browser = await chromium.launch();
	for (const [name, viewport] of Object.entries(viewports)) {
		const context = await browser.newContext({ viewport, locale: 'cs-CZ', deviceScaleFactor: name === 'mobile' ? 2 : 1 });
		const page = await context.newPage();

		await page.goto(base + '/');
		await page.screenshot({ path: `/out/${name}-homepage.png`, fullPage: true });
		await page.goto(base + '/prihlaseni');
		await page.screenshot({ path: `/out/${name}-login.png`, fullPage: true });
		await page.fill('input[name=email]', process.env.LOGIN_EMAIL || 'spravce@demo.konsel.test');
		await page.fill('input[name=password]', process.env.LOGIN_PASSWORD || 'demo-heslo-123');
		await Promise.all([page.waitForNavigation(), page.click('form [type=submit]')]);

		for (const path of pages) {
			await page.goto(base + path);
			const file = path.split('?')[0].replace(/^\/|\/$/g, '').replace(/[^a-z0-9]+/gi, '-').slice(0, 80) || 'root';
			await page.screenshot({ path: `/out/${name}-${file}.png`, fullPage: true });
		}
		await context.close();
	}
	await browser.close();
	console.log('Screenshoty uloženy do temp/screenshots/');
})().catch((error) => { console.error(error); process.exit(1); });
