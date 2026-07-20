import { defineConfig } from '@playwright/test';

const requestedViewport = process.env.SOLASTOCK_VIEWPORT?.match(/^(\d+)x(\d+)$/);
const viewport = requestedViewport
    ? { width: Number(requestedViewport[1]), height: Number(requestedViewport[2]) }
    : undefined;

export default defineConfig({
    testDir: './tests/browser',
    timeout: 30_000,
    // The suite exercises one standing QA tenant and intentionally mutates its
    // document sequences, assignments, settings, and stock. File-level workers
    // would race those shared resources; concurrency scenarios create their own
    // isolated browser contexts inside a single test.
    workers: 1,
    retries: 0,
    use: {
        baseURL: 'https://solavel.com',
        browserName: 'chromium',
        headless: true,
        viewport,
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
    },
});
