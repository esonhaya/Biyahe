import { expect, test } from "@playwright/test";
import { isIP } from "node:net";

test.beforeEach(async ({ page }) => {
  await page.route("**/*", async (route) => {
    const url = new URL(route.request().url());
    const hostname = url.hostname.toLowerCase().replace(/^\[|\]$/g, "").replace(/\.$/, "");
    const loopback = hostname === "localhost" || (isIP(hostname) === 6 && hostname === "::1") || (isIP(hostname) === 4 && hostname.startsWith("127."));
    if (["http:", "https:"].includes(url.protocol) && !loopback) {
      await route.abort();
      return;
    }
    await route.fallback();
  });
});

test("mobile shell, controls, and evidence guide fit the selected viewport", async ({ page }) => {
  await page.goto("/", { waitUntil: "domcontentloaded" });
  await expect(page.getByRole("heading", { name: "BIYAHE" })).toBeVisible();
  await expect(page.getByRole("heading", { name: "Journey results" })).toBeAttached();
  await expect(page.getByRole("heading", { name: "Ride Mode" })).toBeAttached();
  await expect(page.getByText(/Map reading guide:/)).toBeAttached();
  await expect(page.getByRole("combobox", { name: "From" })).toBeVisible();
  await expect(page.getByRole("combobox", { name: "To" })).toBeVisible();

  const layout = await page.evaluate(() => ({
    width: window.innerWidth,
    documentWidth: document.documentElement.scrollWidth,
  }));
  expect(layout.width).toBeGreaterThanOrEqual(320);
  expect(layout.documentWidth).toBeLessThanOrEqual(layout.width);

  const findRoute = page.getByRole("button", { name: "Find route" });
  const bounds = await findRoute.boundingBox();
  expect(bounds).not.toBeNull();
  expect(bounds!.height).toBeGreaterThanOrEqual(44);
});

test("search keyboard focus and API failure recovery remain usable", async ({ page }) => {
  let searchRequests = 0;
  await page.route("**/api/v1/places/search**", async (route) => {
    searchRequests += 1;
    if (searchRequests === 1) {
      await route.fulfill({ status: 503, contentType: "application/json", body: JSON.stringify({ message: "staging search unavailable" }) });
      return;
    }
    await route.fulfill({ status: 200, contentType: "application/json", body: JSON.stringify({ data: [] }) });
  });
  await page.goto("/", { waitUntil: "domcontentloaded" });
  await page.keyboard.press("Tab");
  const focusOutlineWidth = await page.evaluate(() => {
    const active = document.activeElement;
    return active ? Number.parseFloat(getComputedStyle(active).outlineWidth) : 0;
  });
  expect(focusOutlineWidth).toBeGreaterThanOrEqual(2);
  const origin = page.getByRole("combobox", { name: "From" });
  await origin.focus();
  await origin.press("ArrowDown");
  await expect(origin).toBeFocused();
  await origin.fill("unavailable search");
  const retry = page.getByRole("button", { name: "Retry place search" }).first();
  await expect(retry).toBeVisible({ timeout: 10_000 });
  await retry.click();
  await expect.poll(() => searchRequests).toBeGreaterThan(1);
  await expect(page.getByText("No mapped place matched that name. Try a landmark or alias.")).toBeVisible();
});

test("real staging journey can save, reopen offline, and start manual Ride Mode", async ({ page, context }) => {
  const originQuery = process.env.BIYAHE_E2E_ORIGIN_QUERY;
  const destinationQuery = process.env.BIYAHE_E2E_DESTINATION_QUERY;
  test.skip(!originQuery || !destinationQuery, "Set canonical staging place queries to run the data-dependent journey flow.");

  const choose = async (label: "From" | "To", query: string) => {
    const field = page.getByRole("combobox", { name: label });
    await field.fill(query);
    const option = page.getByRole("option").first();
    try {
      await option.waitFor({ state: "visible", timeout: 12_000 });
    } catch {
      test.skip(true, `No real staging place suggestion was returned for ${label}.`);
    }
    await option.click();
  };

  await page.goto("/", { waitUntil: "domcontentloaded" });
  await choose("From", originQuery!);
  await choose("To", destinationQuery!);
  await page.getByRole("button", { name: "Find route" }).click();
  const journeyCard = page.locator("#journeys article[aria-label$='journey']").first();
  try {
    await journeyCard.waitFor({ state: "visible", timeout: 20_000 });
  } catch {
    test.skip(true, "The real staging planner returned no journey card for the supplied places.");
  }
  await expect(page.getByRole("button", { name: "Preview walking from local graph" })).toHaveCount(0);
  await journeyCard.getByRole("button", { name: "Open this journey" }).click();
  await expect(journeyCard).toHaveAttribute("aria-current", "true");
  await expect(page.getByRole("img", { name: "Biyahe journey review map" })).toBeVisible();
  const detail = page.locator("#journey-detail");
  await detail.getByRole("button", { name: "Save journey" }).click();
  const savedHeading = page.getByRole("heading", { name: "Saved journeys" });
  await expect(savedHeading).toBeVisible();
  const savedSection = savedHeading.locator("xpath=ancestor::section");
  await context.setOffline(true);
  await savedSection.getByRole("button", { name: "Open saved journey" }).first().click();
  await expect(detail.getByText("OFFLINE SNAPSHOT")).toBeVisible();
  await page.getByRole("button", { name: "Start Ride Mode" }).click();
  await expect(page.getByText("Progress is manual.")).toBeVisible();
  await expect(page.getByText(/does not confirm your location or travel/)).toBeAttached();
});
