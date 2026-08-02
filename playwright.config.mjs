import { defineConfig } from '@playwright/test';

const baseURL = process.env.SOLASTOCK_STAGING_BASE_URL;
if (!baseURL) {
    throw new Error('SOLASTOCK_STAGING_BASE_URL is required; browser UAT cannot default to production.');
}
const parsedBaseURL = new URL(baseURL);
const forbiddenHosts = new Set(['solavel.com', 'www.solavel.com']);
const loopback = ['127.0.0.1', '::1', 'localhost'].includes(parsedBaseURL.hostname.toLowerCase());
if (forbiddenHosts.has(parsedBaseURL.hostname.toLowerCase())
    || (parsedBaseURL.protocol !== 'https:' && !(loopback && parsedBaseURL.protocol === 'http:'))) {
    throw new Error('Browser UAT requires a dedicated staging host (HTTPS, or network-isolated loopback) and refuses production.');
}
if (process.env.SOLASTOCK_BROWSER_ENVIRONMENT !== 'isolated_staging') {
    throw new Error('SOLASTOCK_BROWSER_ENVIRONMENT=isolated_staging is required.');
}

const requestedViewport = process.env.SOLASTOCK_VIEWPORT?.match(/^(\d+)x(\d+)$/);
const viewport = requestedViewport
    ? { width: Number(requestedViewport[1]), height: Number(requestedViewport[2]) }
    : undefined;

export default defineConfig({
    testDir: './tests/browser',
    timeout: 30_000,
    // The suite exercises one standing isolated staging tenant and intentionally mutates its
    // document sequences, assignments, settings, and stock. File-level workers
    // would race those shared resources; concurrency scenarios create their own
    // isolated browser contexts inside a single test.
    workers: 1,
    retries: 0,
    use: {
        baseURL,
        browserName: 'chromium',
        headless: true,
        viewport,
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
    },
});
