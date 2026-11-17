// Projde všechny stránky wiki v aplikaci a ověří, že se každý diagram Mermaid vykreslil bez chyby.
const { chromium } = require('playwright');
const base = process.env.BASE_URL || 'http://app';
(async () => {
	const browser = await chromium.launch();
	const page = await (await browser.newContext({ viewport: { width: 1440, height: 900 } })).newPage();
	page.on('pageerror', (error) => console.error('Chyba JS:', error.message));
	await page.goto(`${base}/prihlaseni`);
	await page.fill('input[name=email]', 'ctenar@demo.konsel.test');
	await page.fill('input[name=password]', 'demo-heslo-123');
	await Promise.all([page.waitForNavigation(), page.click('form [type=submit]')]);
	await page.goto(`${base}/admin/help`);
	const links = await page.locator('.wiki-nav a').evaluateAll((as) => as.map((a) => a.href));
	let failed = 0;
	for (const href of links) {
		await page.goto(href);
		const expected = await page.locator('.wiki-diagram, pre > code.language-mermaid').count();
		if (expected > 0) {
			await page.waitForFunction(() => [...document.querySelectorAll('.wiki-diagram')].every((d) => d.getAttribute('data-processed') === 'true'), null, { timeout: 20000 }).catch(() => {});
		}
		const result = await page.evaluate(() => ({
			diagrams: document.querySelectorAll('.wiki-diagram').length,
			rendered: document.querySelectorAll('.wiki-diagram svg').length,
			errors: [...document.querySelectorAll('.wiki-diagram')].filter((d) => /Syntax error|Parse error/i.test(d.textContent)).length,
		}));
		const name = href.split('page=')[1] ?? 'index';
		const ok = result.errors === 0 && result.rendered === result.diagrams;
		failed += ok ? 0 : 1;
		console.log(`${ok ? 'ok' : 'CHYBA'} – ${name}: diagramů ${result.diagrams}, vykresleno ${result.rendered}, chyb ${result.errors}`);
		if (name === '03-moduly-a-propojeni' || name === '04-zivotni-cykly') {
			await page.screenshot({ path: `/out/wiki-${name}.png`, fullPage: true });
		}
	}
	await browser.close();
	process.exit(failed ? 1 : 0);
})().catch((error) => { console.error(error); process.exit(1); });
