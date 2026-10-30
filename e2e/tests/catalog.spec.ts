import { test, expect, Page } from '@playwright/test';
import path from 'node:path';

const email = process.env['ADMIN_EMAIL'] ?? 'admin@example.com';
const password = process.env['ADMIN_PASSWORD'] ?? 'CatalogDemo2026!';
async function login(page: Page) {
  await page.goto('/login');
  await page.getByTestId('email').fill(email);
  await page.getByTestId('password').fill(password);
  await page.getByRole('button', { name: 'Войти', exact: true }).click();
  await expect(page).toHaveURL(/\/products/);
  await page.getByRole('button', { name: 'Список', exact: false }).click();
}

test.describe.serial('Catalog end-to-end', () => {
  test('guard protects routes, login works and wrong password is rejected', async ({ page }) => {
    await page.goto('/products');
    await expect(page).toHaveURL(/\/login/);
    await page.getByTestId('email').fill(email);
    await page.getByTestId('password').fill('incorrect-password');
    await page.getByRole('button', { name: 'Войти', exact: true }).click();
    await expect(page.getByRole('alert')).toContainText('Неверный email или пароль');
    await page.getByTestId('password').fill(password);
    await page.getByRole('button', { name: 'Войти', exact: true }).click();
    await expect(page).toHaveURL(/\/products/);
  });

  test('original XLSX runs asynchronously, produces report and 40 products', async ({ page }) => {
    await login(page);
    await page.goto('/import');
    await page.getByTestId('file').setInputFiles(path.resolve(__dirname, '../../examples/products.xlsx'));
    const request = page.waitForResponse(
      (response) => response.url().endsWith('/api/imports') && response.request().method() === 'POST',
    );
    await page.getByTestId('upload').click();
    const response = await request;
    expect(response.status()).toBe(202);
    const task = await response.json();
    expect(task.status).toBe('queued');
    await expect(page.getByTestId('job-status')).toHaveText('Завершён', { timeout: 150000 });
    await expect(page.getByTestId('created-count')).toHaveText('40');
    await page.getByRole('link', { name: 'Посмотреть товары' }).click();
    await expect(page.getByTestId('product-row')).toHaveCount(20);
    await expect(page.getByText('Найдено: 40')).toBeVisible();
    await page.getByTestId('product-row').first().getByRole('link').click();
    await expect(page.getByTestId('product-title')).toContainText('Бермуды');
    await expect(page.getByRole('heading', { name: 'Характеристики', exact: true })).toBeVisible();
    await expect(page.locator('.product-id')).toContainText('ID');
    await expect(page.getByText('33.33%', { exact: false })).toBeVisible();
  });

  test('repeat import updates the catalog without duplicates', async ({ page }) => {
    await login(page);
    await page.goto('/import');
    await page.getByTestId('file').setInputFiles(path.resolve(__dirname, '../../examples/products.xlsx'));
    await page.getByTestId('upload').click();
    await expect(page.getByTestId('job-status')).toHaveText('Завершён', { timeout: 150000 });
    await expect(page.getByTestId('created-count')).toHaveText('0');
    await page.goto('/products');
    await expect(page.getByText('Найдено: 40')).toBeVisible();
  });

  test('search, server pagination, export and mobile layout work', async ({ page }) => {
    const errors: string[] = [];
    page.on('pageerror', (error) => errors.push(error.message));
    await login(page);
    const next = page.waitForResponse(
      (response) => response.url().includes('/api/products?') && response.url().includes('page=2'),
    );
    await page.getByRole('button', { name: 'Следующая страница' }).click();
    await next;
    await expect(page.getByTestId('product-row')).toHaveCount(20);
    await page.getByTestId('search').fill('Бермуды');
    await page.getByRole('button', { name: 'Найти', exact: true }).click();
    await expect(page.getByTestId('product-row')).toHaveCount(2);
    const downloadEvent = page.waitForEvent('download');
    await page.getByRole('button', { name: 'Экспорт XLSX' }).click();
    const download = await downloadEvent;
    expect(download.suggestedFilename()).toBe('products.xlsx');
    await page.setViewportSize({ width: 390, height: 844 });
    await expect(page.getByRole('heading', { name: 'Товары', exact: true })).toBeVisible();
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBeTruthy();
    expect(errors).toEqual([]);
  });
  test('reset removes history and the entire catalog', async ({ page }) => {
    await login(page);
    await page.goto('/import');
    await page.getByRole('button', { name: 'Очистить все импорты', exact: true }).click();
    await expect(page.getByRole('dialog')).toBeVisible();
    const response = page.waitForResponse(
      (r) => r.url().endsWith('/api/imports') && r.request().method() === 'DELETE',
    );
    await page.getByRole('button', { name: 'Удалить импорты и товары', exact: true }).click();
    const cleared = await response;
    expect(cleared.status()).toBe(200);
    expect((await cleared.json()).deleted_products).toBe(40);
    await expect(page.locator('.history')).toContainText('История импортов пуста');
    await page.reload();
    await expect(page.locator('.history-row')).toHaveCount(0);
    await page.goto('/products');
    await expect(page.getByText('Найдено: 0')).toBeVisible();
    await expect(page.getByTestId('product-row')).toHaveCount(0);
  });
  test('fresh import after reset creates all 40 products again', async ({ page }) => {
    await login(page);
    await page.goto('/import');
    await page.getByTestId('file').setInputFiles(path.resolve(__dirname, '../../examples/products.xlsx'));
    await page.getByTestId('upload').click();
    await expect(page.getByTestId('job-status')).toHaveText('Завершён', { timeout: 150000 });
    await expect(page.getByTestId('created-count')).toHaveText('40');
    await page.goto('/products');
    await expect(page.getByText('Найдено: 40')).toBeVisible();
  });
});
