import { defineConfig } from "@playwright/test";
import { isIP } from "node:net";

const requestedBaseURL = process.env.BIYAHE_STAGING_BASE_URL ?? "http://127.0.0.1:8080";
const stagingURL = new URL(requestedBaseURL);
const host = stagingURL.hostname.toLowerCase().replace(/\.$/, "").replace(/^\[|\]$/g, "");
const loopbackHost = host === "localhost" || (isIP(host) === 4 && host.startsWith("127.")) || (isIP(host) === 6 && host === "::1");

if (!loopbackHost || !["http:", "https:"].includes(stagingURL.protocol) || stagingURL.username || stagingURL.password || stagingURL.pathname !== "/" || stagingURL.search || stagingURL.hash) {
  throw new Error("Browser checks require a loopback staging origin with no credentials or URL path.");
}

const baseURL = stagingURL.origin;

export default defineConfig({
  testDir: "./e2e",
  fullyParallel: false,
  workers: 1,
  retries: 0,
  timeout: 30_000,
  globalTimeout: 8 * 60_000,
  reporter: [["list"], ["html", { outputFolder: "playwright-report", open: "never" }]],
  outputDir: "test-results",
  use: {
    baseURL,
    browserName: "chromium",
    trace: "retain-on-failure",
    screenshot: "only-on-failure",
    video: "retain-on-failure",
  },
  projects: [
    { name: "mobile-320", use: { viewport: { width: 320, height: 800 }, isMobile: true, hasTouch: true } },
    { name: "mobile-360", use: { viewport: { width: 360, height: 800 }, isMobile: true, hasTouch: true } },
    { name: "mobile-390", use: { viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true } },
  ],
});
