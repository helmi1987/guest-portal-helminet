// Guest flow through the NAS simulator (decision 2026-11-04, v0.28.0): captive entry -> portal -> code login
// -> handoff to the simulated NAS -> status page. Run by run.sh; prints one JSON line and exits 0 on success.
// Env: SIM_URL (captive entry of the simulator), CODE (6-digit access code), PRESET, NO_RADIUS=1 (local mode),
// CHROMIUM_PATH (optional browser executable when the installed Playwright has no matching browser).
const { chromium } = require('playwright');

const SIM_URL = process.env.SIM_URL;
const CODE = process.env.CODE;
const PRESET = process.env.PRESET || '?';
const NO_RADIUS = process.env.NO_RADIUS === '1';

(async () => {
    const result = { preset: PRESET, ok: false, steps: [] };
    const step = (name, data = {}) => { result.steps.push({ step: name, ...data }); console.error(`[${PRESET}] flow: ${name} ${JSON.stringify(data)}`); };
    const browser = await chromium.launch(process.env.CHROMIUM_PATH ? { executablePath: process.env.CHROMIUM_PATH } : {});
    try {
        // Self-signed certificates of the test portal and the simulator.
        const context = await browser.newContext({ ignoreHTTPSErrors: true, locale: 'en-US', extraHTTPHeaders: { 'Accept-Language': 'en' } });
        const page = await context.newPage();

        await page.goto(SIM_URL, { waitUntil: 'load' });
        const portal = new URL(page.url());
        step('portal', { url: page.url() });
        if (!/\/portal/.test(portal.pathname)) throw new Error('not redirected to the portal');
        if (await page.locator('input[name=code]').count() === 0) {
            // Method chooser: open the code page on the same origin (the session keeps the redirect).
            if (await page.getByText(/not set up correctly|Not connected/i).count()) throw new Error('portal error page: ' + (await page.locator('h1').first().innerText()));
            await page.goto(`${portal.origin}/portal?method=code`, { waitUntil: 'load' });
        }
        await page.fill('input[name=first_name]', 'Test');
        await page.fill('input[name=last_name]', 'Guest');
        await page.fill('input[name=email]', 'guest@example.com');
        await page.fill('input[name=code]', CODE);
        await page.check('input[name=terms]');
        step('code', { code: CODE.replace(/\d{4}$/, '****') });

        // Submit: the handoff page posts (or navigates) to the simulator, which redirects to the status page.
        await Promise.all([
            page.waitForURL(/\/status\//, { timeout: 30000 }),
            page.click('button[type=submit]'),
        ]);
        step('status', { url: page.url().replace(/signature=[^&]+/, 'signature=...') });
        const text = await page.locator('body').innerText();
        if (NO_RADIUS) {
            result.ok = /being logged in|online/i.test(text);
            step('status_text', { expected: 'waiting (no RADIUS)', ok: result.ok });
        } else {
            // RADIUS accepted the credentials before the simulator redirected; the page may still refresh once.
            for (let i = 0; i < 5 && !/You are online/.test(await page.locator('body').innerText()); i++) {
                await page.waitForTimeout(2000);
                await page.reload({ waitUntil: 'load' });
            }
            result.ok = /You are online/.test(await page.locator('body').innerText());
            step('status_text', { expected: 'You are online', ok: result.ok });
        }
    } catch (e) {
        result.error = String(e && e.message || e);
        step('error', { message: result.error });
    } finally {
        await browser.close();
    }
    console.log(JSON.stringify(result));
    process.exit(result.ok ? 0 : 1);
})();
