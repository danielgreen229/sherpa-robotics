import { test, expect, Page } from "@playwright/test";
import fs from "node:fs";
const imageA = "/media/" + "a".repeat(64) + ".png",
  imageB = "/media/" + "b".repeat(64) + ".png";
const product = {
  id: 1,
  external_code: "SHOP-EXTERNAL-001",
  name: "Футболка из хлопка с длинным названием для проверки адаптивной карточки",
  price: "1320.00",
  purchase_price: "880.00",
  discount: "33.33",
  description:
    "Описание импортированного товара.\nМягкая хлопковая ткань, удобный крой.",
  attributes: [
    { key: "Размер", value: "46(M)" },
    { key: "Цвет", value: "Grigio/Verde" },
    { key: "Бренд", value: "OMSA" },
    { key: "Состав", value: "100% хлопок" },
    {
      key: "Ссылка на упаковку",
      value: "https://example.com/" + "a".repeat(180),
    },
  ],
  thumbnail: { url: "https://example.com/a.png", path: imageA },
  images: [
    { url: "https://example.com/a.png", path: imageA },
    { url: "https://example.com/b.png", path: imageB },
  ],
};
// Deterministic UI fixtures. catalog.spec.ts contains the separate full-stack scenarios.
async function fixtures(page: Page, initialView: string | null = "list") {
  await page.addInitScript((initialView) => {
    if (initialView && !localStorage.getItem("catalog.view"))
      localStorage.setItem("catalog.view", initialView);
    sessionStorage.setItem(
      "catalog.session",
      JSON.stringify({
        token: "ui-test-token",
        email: "admin@example.com",
        expires_at: Math.floor(Date.now() / 1000) + 3600,
      }),
    );
  }, initialView);
  await page.route("**/api/**", async (route) => {
    const url = new URL(route.request().url());
    if (url.pathname === "/api/imports" && route.request().method() === "POST")
      return route.fulfill({
        status: 202,
        json: { id: "a".repeat(32), status: "queued" },
      });
    if (url.pathname === "/api/imports") return route.fulfill({ json: [] });
    if (url.pathname.startsWith("/api/imports/"))
      return route.fulfill({
        json: {
          id: "a".repeat(32),
          filename: "dropped.xlsx",
          status: "completed",
          total: 40,
          processed: 40,
          progress: 100,
          created: 40,
          updated: 0,
          invalid: 0,
          failure: null,
          errors: [],
        },
      });
    if (url.pathname === "/api/products/1")
      return route.fulfill({ json: product });
    const p = Number(url.searchParams.get("page") ?? 1);
    return route.fulfill({
      json: {
        items: Array.from({ length: 20 }, (_, i) => ({
          ...product,
          id: (p - 1) * 20 + i + 1,
          name: product.name + " " + ((p - 1) * 20 + i + 1),
        })),
        total: 40,
        page: p,
        limit: 20,
        total_pages: 2,
      },
    });
  });
  await page.route("**/media/**", (route) =>
    process.env["UI_REAL_PHOTO"]
      ? route.fulfill({
          contentType: "image/jpeg",
          body: fs.readFileSync(process.env["UI_REAL_PHOTO"]),
        })
      : route.fulfill({
          contentType: "image/svg+xml",
          body: '<svg xmlns="http://www.w3.org/2000/svg" width="600" height="800" viewBox="0 0 600 800"><rect width="600" height="800" fill="#dce2e7"/><path d="M160 100H440L490 650H340L300 330L260 650H110Z" fill="#487baa"/><path d="M160 160H440M150 220H450M140 280H460" stroke="white" stroke-width="12"/></svg>',
        }),
  );
}
async function dropFile(page: Page, names: string[]) {
  const transfer = await page.evaluateHandle((names) => {
    const data = new DataTransfer();
    for (const name of names)
      data.items.add(
        new File(["fixture"], name, {
          type: "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
        }),
      );
    return data;
  }, names);
  await page
    .locator(".file-zone")
    .dispatchEvent("dragenter", { dataTransfer: transfer });
  await expect(page.locator(".file-zone")).toHaveClass(/drag-over/);
  await page
    .locator(".file-zone")
    .dispatchEvent("drop", { dataTransfer: transfer });
  await transfer.dispose();
}
test("UI: XLSX drop, upload, rejection of XLS and multiple files", async ({
  page,
}) => {
  await fixtures(page);
  await page.goto("/import");
  await dropFile(page, ["dropped.xlsx"]);
  await expect(page.locator(".file-zone")).toContainText("dropped.xlsx");
  await expect(page.locator(".file-zone")).not.toHaveClass(/drag-over/);
  await expect(page.getByTestId("upload")).toBeEnabled();
  const request = page.waitForRequest(
    (r) => r.url().endsWith("/api/imports") && r.method() === "POST",
  );
  await page.getByTestId("upload").click();
  expect((await request).postDataBuffer()?.toString()).toContain(
    "dropped.xlsx",
  );
  await expect(page.getByTestId("job-status")).toHaveText("Завершён");
  await dropFile(page, ["old.xls"]);
  await expect(page.getByRole("alert")).toContainText("Формат .xls");
  await expect(page.getByTestId("upload")).toBeDisabled();
  await dropFile(page, ["a.xlsx", "b.xlsx"]);
  await expect(page.getByRole("alert")).toContainText("один файл");
});
test("UI: copy, thumbnail, zoom, Escape, return to filters", async ({
  page,
  context,
  browserName,
}) => {
  await fixtures(page);
  if (browserName === "chromium")
    await context.grantPermissions(["clipboard-read", "clipboard-write"]);
  await page.goto(
    "/products/1?returnTo=" +
      encodeURIComponent("/products?page=2&name=хлопок"),
  );
  await page
    .getByRole("button", { name: "Скопировать внешний код SHOP-EXTERNAL-001" })
    .click();
  await expect(
    page.getByRole("status").filter({ hasText: "Внешний код скопирован" }),
  ).toHaveText("Внешний код скопирован");
  if (browserName === "chromium")
    expect(await page.evaluate(() => navigator.clipboard.readText())).toBe(
      "SHOP-EXTERNAL-001",
    );
  await page.getByRole("button", { name: "Фото 2", exact: true }).click();
  await expect(page.locator(".photo-zoom img")).toHaveAttribute("src", imageB);
  await page.getByRole("button", { name: "Увеличить фотографию" }).click();
  await expect(page.locator("dialog")).toBeVisible();
  await page.keyboard.press("Escape");
  await expect(page.locator("dialog")).not.toBeVisible();
  await page.getByRole("link", { name: "← Каталог товаров" }).click();
  await expect(page).toHaveURL(/page=2.*name=/);
});
test("UI: pagination requests page 2 and scrolls to list", async ({ page }) => {
  await fixtures(page);
  await page.setViewportSize({ width: 1280, height: 720 });
  await page.goto("/products");
  await expect(page.getByTestId("product-row")).toHaveCount(20);
  await page
    .getByRole("button", { name: "Следующая страница", exact: true })
    .scrollIntoViewIfNeeded();
  expect(await page.evaluate(() => scrollY)).toBeGreaterThan(400);
  await page
    .getByRole("button", { name: "Следующая страница", exact: true })
    .click();
  await expect(page).toHaveURL(/page=2/);
  await expect
    .poll(() =>
      page
        .locator(".catalog-panel")
        .evaluate((el) => Math.round(el.getBoundingClientRect().top)),
    )
    .toBeLessThan(130);
  await expect(page.getByTestId("product-row").first()).toContainText("21");
});
for (const width of [320, 360, 390, 768, 1024, 1440, 2560, 3840])
  test(`UI: layout ${width}px`, async ({ page }) => {
    await fixtures(page);
    await page.setViewportSize({ width, height: 1000 });
    const errors: string[] = [];
    page.on("pageerror", (error) => errors.push(error.message));
    for (const path of ["/products", "/products/1", "/import", "/login"]) {
      await page.goto(path);
      await page.locator("h1,h2").first().waitFor();
      if (path === "/products")
        await expect(page.getByTestId("product-row")).toHaveCount(20);
      if (path === "/products/1")
        await expect(page.getByTestId("product-title")).toBeVisible();
      await expect
        .poll(() =>
          page.evaluate(
            () => document.documentElement.scrollWidth <= innerWidth,
          ),
        )
        .toBeTruthy();
      if (
        process.env["UI_CAPTURE"] === "1" &&
        ((width === 1440 && path === "/products/1") ||
          (width === 390 && path === "/products"))
      )
        await page.screenshot({
          path: `/tmp/catalog-glass-${width}.png`,
          fullPage: true,
        });
    }
    expect(errors).toEqual([]);
  });
test("UI: missing images show a placeholder; matte hover works with reduced motion", async ({
  page,
}) => {
  await fixtures(page);
  await page.emulateMedia({ reducedMotion: "reduce" });
  await page.route("**/media/**", (route) =>
    route.fulfill({ status: 404, body: "" }),
  );
  await page.goto("/products/1");
  await expect(page.locator(".photo-placeholder")).toBeVisible();
  await expect(
    page.getByRole("button", { name: "Увеличить фотографию" }),
  ).toHaveCount(0);
  await page.mouse.move(0, 0);
  const before = await page
    .locator(".shop-summary")
    .evaluate((el) => getComputedStyle(el).backgroundColor);
  await page.locator(".shop-summary").hover();
  const after = await page
    .locator(".shop-summary")
    .evaluate((el) => getComputedStyle(el).backgroundColor);
  expect(after).not.toBe(before);
  expect(
    await page
      .locator(".shop-summary")
      .evaluate((el) => getComputedStyle(el).transitionDuration),
  ).toBe("0s");
});

test("UI: first photo in list, three/five columns, saved preference, grid pagination", async ({
  page,
}) => {
  await fixtures(page);
  await page.setViewportSize({ width: 1600, height: 1000 });
  await page.goto("/products");
  await expect(page.locator(".product-list-link img").first()).toHaveAttribute(
    "src",
    imageA,
  );
  await page.getByRole("button", { name: "По 3", exact: false }).click();
  await expect(page.getByTestId("product-card")).toHaveCount(20);
  await expect
    .poll(() =>
      page
        .locator(".product-grid")
        .evaluate(
          (el) => getComputedStyle(el).gridTemplateColumns.split(" ").length,
        ),
    )
    .toBe(3);
  await page.getByRole("button", { name: "По 5", exact: false }).click();
  await expect
    .poll(() =>
      page
        .locator(".product-grid")
        .evaluate(
          (el) => getComputedStyle(el).gridTemplateColumns.split(" ").length,
        ),
    )
    .toBe(5);
  await page.reload();
  await expect(
    page.getByRole("button", { name: "По 5", exact: false }),
  ).toHaveAttribute("aria-pressed", "true");
  await page
    .getByRole("button", { name: "Следующая страница", exact: true })
    .click();
  await expect(page).toHaveURL(/page=2/);
  await expect(page.getByTestId("product-card").first()).toContainText("21");
  await expect
    .poll(() =>
      page
        .locator(".catalog-panel")
        .evaluate((el) => Math.round(el.getBoundingClientRect().top)),
    )
    .toBeLessThan(130);
  await page.getByRole("button", { name: "Список", exact: false }).click();
  await expect(page.getByTestId("product-row")).toHaveCount(20);
});
for (const width of [320, 390, 768, 1024, 1440, 2560, 3840])
  test(`UI: grids, image sizing and unified radius ${width}px`, async ({
    page,
  }) => {
    await fixtures(page);
    await page.setViewportSize({ width, height: 1000 });
    await page.goto("/products");
    for (const label of ["По 3", "По 5"]) {
      await page.getByRole("button", { name: label, exact: false }).click();
      await expect(page.getByTestId("product-card")).toHaveCount(20);
      expect(
        await page.evaluate(
          () => document.documentElement.scrollWidth <= innerWidth,
        ),
      ).toBeTruthy();
      const expected =
        width < 360
          ? 1
          : width <= 800
            ? 2
            : label === "По 3"
              ? 3
              : width <= 1000
                ? 3
                : width <= 1250
                  ? 4
                  : 5;
      await expect
        .poll(() =>
          page
            .locator(".product-grid")
            .evaluate(
              (el) =>
                getComputedStyle(el).gridTemplateColumns.split(" ").length,
            ),
        )
        .toBe(expected);
    }
    const buttonRadius = await page
      .getByRole("button", { name: "Найти", exact: true })
      .evaluate((el) => getComputedStyle(el).borderTopLeftRadius);
    const inputRadius = await page
      .locator(".mdc-notched-outline__leading")
      .first()
      .evaluate((el) => getComputedStyle(el).borderTopLeftRadius);
    expect(buttonRadius).toBe("14px");
    expect(inputRadius).toBe(buttonRadius);
    if (process.env["UI_CAPTURE"] === "1" && width === 1440)
      await page.screenshot({
        path: "/tmp/catalog-grid-1440.png",
        fullPage: true,
      });
    await page.goto("/products/1");
    await expect(page.locator(".photo-zoom img")).toBeVisible();
    const sizes = await page.locator(".photo-zoom img").evaluate((el) => {
      const img = el as HTMLImageElement;
      return {
        height: img.clientHeight,
        width: img.clientWidth,
        naturalRatio: img.naturalWidth / img.naturalHeight,
        parentHeight: img.parentElement!.clientHeight,
        parentWidth: img.parentElement!.clientWidth,
        left: img.getBoundingClientRect().left,
        parentLeft: img.parentElement!.getBoundingClientRect().left,
      };
    });
    expect(Math.abs(sizes.width - sizes.parentWidth)).toBeLessThanOrEqual(1);
    expect(Math.abs(sizes.left - sizes.parentLeft)).toBeLessThanOrEqual(1);
    if (width <= 800)
      expect(
        Math.abs(sizes.width / sizes.height - sizes.naturalRatio),
      ).toBeLessThan(0.02);
    else
      expect(
        await page
          .locator(".photo-zoom img")
          .evaluate((el) => getComputedStyle(el).objectFit),
      ).toBe("contain");
    expect(sizes.parentHeight - sizes.height).toBeLessThan(4);
    expect(
      await page.evaluate(
        () => document.documentElement.scrollWidth <= innerWidth,
      ),
    ).toBeTruthy();
    if (process.env["UI_CAPTURE"] === "1" && (width === 1440 || width === 390))
      await page.screenshot({
        path: `/tmp/catalog-detail-v2-${width}.png`,
        fullPage: true,
      });
  });

test("UI: compact copy does not navigate, card rows align, image/title/price form one link", async ({
  page,
  context,
  browserName,
}) => {
  await fixtures(page);
  if (browserName === "chromium")
    await context.grantPermissions(["clipboard-read", "clipboard-write"]);
  await page.setViewportSize({ width: 1440, height: 1000 });
  await page.goto("/products");
  await page.getByRole("button", { name: "По 5", exact: false }).click();
  const cards = page.getByTestId("product-card");
  await expect(cards).toHaveCount(20);
  await cards
    .first()
    .getByRole("button", { name: "Скопировать внешний код SHOP-EXTERNAL-001" })
    .click();
  await expect(page).toHaveURL(/\/products$/);
  if (browserName === "chromium")
    expect(await page.evaluate(() => navigator.clipboard.readText())).toBe(
      "SHOP-EXTERNAL-001",
    );
  const rows = await cards.evaluateAll((els) =>
    els.slice(0, 5).map((el) => ({
      top: el.getBoundingClientRect().top,
      bottom: el.getBoundingClientRect().bottom,
      price: el.querySelector(".card-price")!.getBoundingClientRect().top,
    })),
  );
  expect(new Set(rows.map((row) => Math.round(row.top))).size).toBe(1);
  expect(new Set(rows.map((row) => Math.round(row.bottom))).size).toBe(1);
  expect(new Set(rows.map((row) => Math.round(row.price))).size).toBe(1);
  await expect(cards.first().locator(".card-main-link")).toContainText("1 320");
  await cards.first().locator(".card-main-link").click();
  await expect(page.getByTestId("product-title")).toBeVisible();
});
test("UI: product has no import block or requests and preserves source links", async ({
  page,
}) => {
  await fixtures(page);
  let imports = 0;
  page.on("request", (request) => {
    if (request.url().includes("/api/imports")) imports++;
  });
  await page.goto("/products/1");
  await expect(page.getByTestId("product-title")).toBeVisible();
  await expect(page.locator("app-import-overview")).toHaveCount(0);
  await expect(
    page.getByRole("heading", { name: "Последний импорт", exact: true }),
  ).toHaveCount(0);
  expect(imports).toBe(0);
  await page.locator(".image-sources summary").click();
  await expect(page.getByRole("link", { name: "Источник 1" })).toHaveAttribute(
    "href",
    "https://example.com/a.png",
  );
});
test("UI: new users start with three columns; view changes preserve the loaded page", async ({
  page,
}) => {
  await fixtures(page, null);
  await page.setViewportSize({ width: 1440, height: 900 });
  let requests = 0;
  page.on("request", (request) => {
    if (request.url().includes("/api/products?")) requests++;
  });
  await page.goto("/products");
  await expect(page.getByTestId("product-card")).toHaveCount(20);
  await expect(
    page.getByRole("button", { name: "По 3", exact: false }),
  ).toHaveAttribute("aria-pressed", "true");
  const before = requests;
  await page.getByRole("button", { name: "По 5", exact: false }).click();
  await expect(
    page.getByRole("button", { name: "По 5", exact: false }),
  ).toHaveAttribute("aria-pressed", "true");
  expect(requests).toBe(before);
});

test("UI: mobile product title and price precede the gallery, anchors and keyboard work", async ({
  page,
}) => {
  await fixtures(page);
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto("/products/1");
  await expect(page.getByTestId("product-title")).toBeVisible();
  const bounds = await page.evaluate(() => ({
    title: document.querySelector("h1")!.getBoundingClientRect().bottom,
    price: document.querySelector(".detail-price")!.getBoundingClientRect()
      .bottom,
    photo: document.querySelector(".gallery-panel")!.getBoundingClientRect()
      .top,
  }));
  expect(bounds.title).toBeLessThan(bounds.photo);
  expect(bounds.price).toBeLessThan(bounds.photo);
  await page.getByRole("button", { name: "Увеличить фотографию" }).focus();
  await page.keyboard.press("ArrowRight");
  await expect(page.locator(".photo-zoom img")).toHaveAttribute("src", imageB);
  await page.getByRole("link", { name: "Все характеристики ↓" }).click();
  await expect(page).toHaveURL(/\/products\/1.*#characteristics/);
  await expect
    .poll(() =>
      page
        .locator("#characteristics")
        .evaluate((el) => el.getBoundingClientRect().top),
    )
    .toBeLessThan(130);
});

for (const width of [801, 1024, 1440, 1920, 3840, 5120])
  test(`UI: desktop gallery matches complete right block ${width}px`, async ({
    page,
  }) => {
    await fixtures(page);
    await page.setViewportSize({ width, height: 900 });
    await page.goto("/products/1");
    await expect(page.getByTestId("product-title")).toBeVisible();
    const box = await page.evaluate(() => {
      const gallery = document
        .querySelector(".gallery-panel")!
        .getBoundingClientRect();
      const title = document
        .querySelector(".shop-title")!
        .getBoundingClientRect();
      const summary = document
        .querySelector(".shop-summary")!
        .getBoundingClientRect();
      return {
        top: gallery.top - title.top,
        bottom: gallery.bottom - summary.bottom,
        height: gallery.height,
        rightHeight: summary.bottom - title.top,
        scroll: document.documentElement.scrollWidth,
        width: innerWidth,
      };
    });
    expect(Math.abs(box.top)).toBeLessThanOrEqual(1);
    expect(Math.abs(box.bottom)).toBeLessThanOrEqual(1);
    expect(Math.abs(box.height - box.rightHeight)).toBeLessThanOrEqual(1);
    expect(box.scroll).toBeLessThanOrEqual(box.width);
    await expect(
      page.getByRole("button", { name: "Фото 2", exact: true }),
    ).toBeVisible();
  });
test("UI: reset confirms catalog deletion, cancels and clears cached products", async ({
  page,
}) => {
  await fixtures(page);
  let cleared = false,
    deletes = 0;
  const task = "a".repeat(32);
  await page.route("**/api/imports", (route) => {
    if (route.request().method() === "DELETE") {
      deletes++;
      cleared = true;
      expect(route.request().postDataJSON()).toEqual({
        confirm: "CLEAR_CATALOG",
      });
      return route.fulfill({
        json: {
          deleted: 3,
          deleted_products: 40,
          deleted_images: 91,
          cleanup_failed: 0,
        },
      });
    }
    return route.fulfill({
      json: cleared
        ? []
        : [{ id: task, filename: "completed.xlsx", status: "completed" }],
    });
  });
  await page.route("**/api/products?**", (route) =>
    cleared
      ? route.fulfill({
          json: { items: [], total: 0, page: 1, limit: 20, total_pages: 0 },
        })
      : route.fallback(),
  );
  await page.goto("/import?job=" + task);
  await expect(page.getByTestId("job-status")).toHaveText("Завершён");
  await page
    .getByRole("button", { name: "Очистить все импорты", exact: true })
    .click();
  await expect(page.getByRole("dialog")).toBeVisible();
  await page.getByRole("button", { name: "Отмена", exact: true }).click();
  expect(deletes).toBe(0);
  await page
    .getByRole("button", { name: "Очистить все импорты", exact: true })
    .click();
  await page
    .getByRole("button", { name: "Удалить импорты и товары", exact: true })
    .click();
  await expect(page.getByRole("dialog")).not.toBeVisible();
  await expect(page).not.toHaveURL(/job=/);
  await expect(page.locator(".history")).toContainText(
    "История импортов пуста",
  );
  await expect(page.locator(".history")).toContainText(
    "Удалено импортов: 3, товаров: 40",
  );
  expect(deletes).toBe(1);
  await page.goto("/products");
  await expect(
    page.getByRole("heading", { name: "Товары не найдены" }),
  ).toBeVisible();
  await expect(page.getByTestId("product-row")).toHaveCount(0);
});
test("UI: active history blocks clearing and server conflict leaves history intact", async ({
  page,
}) => {
  await fixtures(page);
  let active = true;
  await page.route("**/api/imports", (route) =>
    route.request().method() === "DELETE"
      ? route.fulfill({
          status: 409,
          json: {
            error:
              "Дождитесь завершения активных импортов перед очисткой истории.",
          },
        })
      : route.fulfill({
          json: [
            {
              id: "a".repeat(32),
              filename: "active.xlsx",
              status: active ? "processing" : "completed",
            },
          ],
        }),
  );
  await page.goto("/import");
  await expect(
    page.getByRole("button", { name: "Очистить все импорты", exact: true }),
  ).toBeDisabled();
  active = false;
  await page.reload();
  await page
    .getByRole("button", { name: "Очистить все импорты", exact: true })
    .click();
  await page
    .getByRole("button", { name: "Удалить импорты и товары", exact: true })
    .click();
  await expect(page.getByRole("dialog")).toContainText("Дождитесь завершения");
  await expect(page.locator(".history-row")).toContainText("active.xlsx");
});

for (const count of [0, 1, 12])
  test(`UI: gallery sizing with ${count} photos`, async ({ page }) => {
    await fixtures(page);
    await page.route("**/api/products/1", (route) =>
      route.fulfill({
        json: {
          ...product,
          images: Array.from({ length: count }, (_, i) => ({
            url: "https://example.com/" + i + ".jpg",
            path: "/media/" + i.toString(16).padStart(64, "0") + ".jpg",
          })),
        },
      }),
    );
    await page.setViewportSize({ width: 1440, height: 900 });
    await page.goto("/products/1");
    await expect(page.getByTestId("product-title")).toBeVisible();
    const boxes = await page.evaluate(() => ({
      gallery: document.querySelector(".gallery-panel")!.getBoundingClientRect()
        .bottom,
      summary: document.querySelector(".shop-summary")!.getBoundingClientRect()
        .bottom,
    }));
    expect(Math.abs(boxes.gallery - boxes.summary)).toBeLessThanOrEqual(1);
    if (count === 0)
      await expect(page.locator(".photo-placeholder")).toBeVisible();
    if (count === 1) await expect(page.locator(".photo-thumbs")).toHaveCount(0);
    if (count > 1) {
      await page.getByRole("button", { name: "Фото 12", exact: true }).click();
      await expect(page.locator(".photo-zoom img")).toHaveAttribute(
        "src",
        "/media/" + "b".padStart(64, "0") + ".jpg",
      );
    }
    expect(
      await page.evaluate(
        () => document.documentElement.scrollWidth <= innerWidth,
      ),
    ).toBeTruthy();
  });

test("UI: empty history can still reset remaining products and shows global scope", async ({
  page,
}) => {
  await fixtures(page);
  await page.route("**/api/imports", (route) =>
    route.request().method() === "DELETE"
      ? route.fulfill({
          json: {
            deleted: 0,
            deleted_products: 40,
            deleted_images: 0,
            cleanup_failed: 0,
          },
        })
      : route.fulfill({ json: [] }),
  );
  await page.goto("/import");
  await expect(page.locator(".history-row")).toHaveCount(0);
  await expect(
    page.getByRole("button", { name: "Очистить все импорты", exact: true }),
  ).toBeEnabled();
  await page
    .getByRole("button", { name: "Очистить все импорты", exact: true })
    .click();
  await expect(page.getByRole("dialog")).toContainText(
    "ВСЕ товары общего каталога",
  );
  await expect(page.getByRole("dialog")).not.toContainText(
    "Товары и фотографии останутся",
  );
  await page
    .getByRole("button", { name: "Удалить импорты и товары", exact: true })
    .click();
  await expect(page.locator(".history")).toContainText(
    "Удалено импортов: 0, товаров: 40",
  );
});

test("UI: transient polling failure recovers, report pages and full JSON download work", async ({
  page,
}) => {
  await fixtures(page);
  const id = "c".repeat(32);
  const errors = Array.from({ length: 250 }, (_, i) => ({
    row: i + 2,
    severity: "error",
    field: "name",
    message: `Issue ${i + 1}`,
  }));
  let calls = 0;
  await page.route(`**/api/imports/${id}`, (route) =>
    ++calls === 1
      ? route.fulfill({ status: 503, json: { error: "Temporary failure" } })
      : route.fulfill({
          json: {
            id,
            filename: "report.xlsx",
            status: "completed",
            total: 250,
            processed: 250,
            progress: 100,
            created: 0,
            updated: 0,
            invalid: 250,
            failure: null,
            errors: errors.slice(0, 100),
            error_count: 250,
          },
        }),
  );
  await page.route(`**/api/imports/${id}/errors?*`, (route) => {
    const current = Number(
      new URL(route.request().url()).searchParams.get("page"),
    );
    return route.fulfill({
      json: {
        items: errors.slice((current - 1) * 100, current * 100),
        page: current,
        limit: 100,
        total: 250,
        total_pages: 3,
      },
    });
  });
  await page.route(`**/api/imports/${id}/report`, (route) =>
    route.fulfill({
      contentType: "application/json",
      body: JSON.stringify({ errors }),
    }),
  );
  await page.goto(`/import?job=${id}`);
  await expect(
    page.getByRole("heading", { name: "Отчёт: 250 сообщений" }),
  ).toBeVisible();
  await expect(page.locator(".global-error")).toHaveCount(0);
  await expect(page.locator(".report-table tbody tr")).toHaveCount(100);
  await page.getByRole("button", { name: "Далее", exact: true }).click();
  await expect(page.locator(".report-pagination")).toContainText(
    "Страница 2 из 3",
  );
  await expect(page.locator(".report-table tbody tr").first()).toContainText(
    "Issue 101",
  );
  await page.getByRole("button", { name: "Далее", exact: true }).click();
  await expect(page.locator(".report-table tbody tr")).toHaveCount(50);
  await expect(
    page.getByRole("button", { name: "Далее", exact: true }),
  ).toBeDisabled();
  const downloading = page.waitForEvent("download");
  await page.getByRole("button", { name: "Скачать JSON" }).click();
  const stream = await (await downloading).createReadStream();
  const chunks: Buffer[] = [];
  for await (const chunk of stream!) chunks.push(Buffer.from(chunk));
  expect(JSON.parse(Buffer.concat(chunks).toString()).errors).toHaveLength(250);
});

test("UI: exhausted polling can be restarted without reloading or reimporting", async ({
  page,
}) => {
  await fixtures(page);
  const id = "d".repeat(32);
  let recovering = false;
  await page.route(`**/api/imports/${id}`, (route) =>
    recovering
      ? route.fulfill({
          json: {
            id,
            filename: "recovered.xlsx",
            status: "completed",
            total: 1,
            processed: 1,
            progress: 100,
            created: 1,
            updated: 0,
            invalid: 0,
            failure: null,
            errors: [],
          },
        })
      : route.fulfill({
          status: 503,
          json: { error: "Temporary status failure" },
        }),
  );
  await page.goto(`/import?job=${id}`);
  await expect(
    page.getByRole("button", { name: "Повторить запрос статуса" }),
  ).toBeVisible({ timeout: 20000 });
  recovering = true;
  await page.getByRole("button", { name: "Повторить запрос статуса" }).click();
  await expect(page.getByTestId("job-status")).toHaveText("Завершён");
  await expect(page.getByTestId("created-count")).toHaveText("1");
});

test("UI: gallery arrows keep selected thumbnail visible", async ({ page }) => {
  await fixtures(page);
  await page.emulateMedia({ reducedMotion: "reduce" });
  await page.setViewportSize({ width: 1024, height: 900 });
  await page.route("**/api/products/1", (route) =>
    route.fulfill({
      json: {
        ...product,
        images: Array.from({ length: 12 }, (_, i) => ({
          url: `https://example.com/${i}.jpg`,
          path: `/media/${i.toString(16).padStart(64, "0")}.jpg`,
        })),
      },
    }),
  );
  await page.goto("/products/1");
  await page.locator(".photo-zoom").focus();
  for (let i = 0; i < 11; ++i) await page.keyboard.press("ArrowRight");
  await expect(
    page.getByRole("button", { name: "Фото 12", exact: true }),
  ).toHaveAttribute("aria-pressed", "true");
  await expect
    .poll(() =>
      page.evaluate(() => {
        const strip = document
          .querySelector(".photo-thumbs")!
          .getBoundingClientRect();
        const selected = document
          .querySelector('.photo-thumbs [aria-pressed="true"]')!
          .getBoundingClientRect();
        return (
          selected.left >= strip.left - 1 && selected.right <= strip.right + 1
        );
      }),
    )
    .toBeTruthy();
});
