import { test, expect } from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';

const credentialsPath = process.env.SOLASTOCK_PHASE6A_UAT_CREDENTIALS;
const scenario = process.env.SOLASTOCK_PHASE6A_UAT_SCENARIO ?? 'dual-existing';
if (!credentialsPath) throw new Error('SOLASTOCK_PHASE6A_UAT_CREDENTIALS is required.');
const credentials = JSON.parse(fs.readFileSync(credentialsPath, 'utf8'))
    .find((row) => row.scenario === scenario);
if (!credentials || !credentials.email || !credentials.password) {
    throw new Error(`Missing protected isolated-staging credential for ${scenario}.`);
}
const evidenceDir = process.env.SOLASTOCK_PHASE6A_BROWSER_EVIDENCE;

async function login(page) {
    await page.goto('/__phase6a/login');
    await page.locator('input[type="email"]').fill(credentials.email);
    await page.locator('input[type="password"]').fill(credentials.password);
    await page.locator('button[type="submit"]').click();
    await expect(page).toHaveURL(/\/dashboard/);
}

async function verifyWizard(page, locale, viewportName) {
    await page.goto(`/integrations/solabooks?locale=${locale}`);
    await expect(page.locator('html')).toHaveAttribute('dir', locale === 'ar' ? 'rtl' : 'ltr');
    await page.getByRole('button', { name: locale === 'ar' ? 'معالج الاتصال' : 'Connection wizard' }).click();
    await expect(page.locator('.wizard')).toBeVisible();
    await expect(page.locator('.wizard__comparison')).toBeVisible();
    await expect(page.locator('.wizard')).toContainText('SolaStock');
    await expect(page.locator('.wizard')).toContainText('SolaCount');
    await expect(page.locator('.wizard')).not.toContainText(/secret|signature|exception trace/i);
    await expect(page.locator('body')).not.toContainText('Error 500');
    if (evidenceDir) {
        fs.mkdirSync(evidenceDir, { recursive: true });
        await page.screenshot({ path: path.join(evidenceDir, `wizard-${scenario}-${locale}-${viewportName}.png`), fullPage: true });
    }
}

test('connection wizard is truthful and responsive in English and Arabic RTL', async ({ browser }) => {
    test.setTimeout(120_000);
    for (const [viewportName, viewport] of Object.entries({ desktop: { width: 1440, height: 1000 }, tablet: { width: 1024, height: 1366 }, mobile: { width: 390, height: 844 } })) {
        const context = await browser.newContext({ viewport });
        const page = await context.newPage();
        await login(page);
        await verifyWizard(page, 'en', viewportName);
        await verifyWizard(page, 'ar', viewportName);
        await context.close();
    }
});

test('wizard discovery is read-only for an unauthorized browser session', async ({ browser }) => {
    const context = await browser.newContext();
    const page = await context.newPage();
    const response = await page.request.get('/api/v1/integration/solabooks/wizard/discovery');
    // The direct staging stack may reject before authentication (401/419) or
    // at the tenant-context boundary (409 no_organization); all are fail-closed.
    expect([401, 409, 419]).toContain(response.status());
    await context.close();
});
