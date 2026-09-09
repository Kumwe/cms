import { execFileSync } from 'node:child_process';
import { mkdtempSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { expect, request as apiRequest, test, type Page } from '@playwright/test';
import { gotoAfterRuntimeConvergence } from './support/runtime-convergence';
import { preferStructuredContentForm } from './support/studio-authoring';

/**
 * Installable site-theme lifecycle: the shipped Horizon example is installed through the signed demo
 * pipeline, activated onto the public site from the administrator screen, exercised against the same
 * navigation model the built-in templates render — top-level items, a nested submenu, canonical
 * nested hrefs, current state, and the responsive toggle — and then deactivated so every other spec
 * keeps seeing the default presentation. The spec runs last in each project (file order is
 * alphabetical and workers are serial), so the activate → assert → deactivate window is private.
 */

const baseUrl = process.env.KUMWE_BROWSER_BASE_URL ?? 'http://127.0.0.1:8080';
const administratorEmail = process.env.KUMWE_BROWSER_ADMIN_EMAIL ?? 'browser-administrator@kumwe.test';
const administratorPassword = process.env.KUMWE_BROWSER_ADMIN_PASSWORD
  ?? 'browser administrator password';

const themeIdentifier = 'kumwe/horizon-theme-example';
const parentTitle = 'Horizon Voyages';
const parentSlug = 'horizon-voyages';
const parentSegment = 'voyages';
const childTitle = 'Horizon Crew';
const childSlug = 'horizon-crew';
const childSegment = 'crew';

/** Install the Horizon example through the same signed pipeline the demo command drives. */
function installHorizonExample(): void {
  const scratch = mkdtempSync(join(tmpdir(), 'kumwe-theme-spec-'));
  const passwordFile = join(scratch, 'administrator-password');
  try {
    writeFileSync(passwordFile, administratorPassword, { mode: 0o600 });
    execFileSync('php', [
      'bin/kumwe',
      'demo:install-examples',
      `--admin-email=${administratorEmail}`,
      `--admin-password-file=${passwordFile}`,
      '--extensions=horizon-theme',
    ], { cwd: process.cwd(), stdio: 'pipe' });
  } finally {
    rmSync(scratch, { recursive: true, force: true });
  }
}

async function signInAdministrator(page: Page): Promise<void> {
  await gotoAfterRuntimeConvergence(page, '/administrator/login', 'The administrator sign-in page');
  await page.getByLabel('Email address').fill(administratorEmail);
  await page.getByLabel('Password').fill(administratorPassword);
  await page.getByRole('button', { name: 'Sign in to Kumwe' }).click();
  await expect(page).toHaveURL(/\/administrator$/u);
}

/** Reload the extension screen through the generation drain until Horizon reaches the requested state. */
async function waitForHorizonStatus(
  page: Page,
  status?: 'active' | 'disabled',
): Promise<void> {
  const expected = status === undefined
    ? /template · 1\.0\.0 · (?:active|disabled)/u
    : new RegExp(`template · 1\\.0\\.0 · ${status}`, 'u');
  await gotoAfterRuntimeConvergence(
    page,
    '/administrator/extensions',
    'The Horizon extension listing',
  );
  const extension = page.locator('article').filter({ hasText: themeIdentifier }).first();
  await expect(extension).toContainText(expected);
}

/** Poll the public homepage, bypassing its public cache window, until the theme state matches. */
async function expectPublicTheme(active: boolean): Promise<void> {
  const api = await apiRequest.newContext({ baseURL: baseUrl });
  try {
    await expect.poll(async () => {
      const response = await api.get(`/?theme-probe=${Date.now()}`);
      return response.ok() ? (await response.text()).includes('class="horizon"') : null;
    }, {
      message: `the public site to render ${active ? 'the Horizon theme' : 'the default presentation'}`,
      timeout: 25_000,
    }).toBe(active);
  } finally {
    await api.dispose();
  }
}

/** Publish a page through the ordinary editorial workflow unless an earlier project run left it published. */
async function ensurePublishedPage(page: Page, title: string, slug: string): Promise<void> {
  const probe = await page.context().request.get(`/pages/${slug}`);
  if (probe.ok()) {
    return;
  }
  await preferStructuredContentForm(page.context());
  await page.goto('/administrator/content/new');
  await page.getByLabel('Title').fill(title);
  await page.getByLabel('URL slug').fill(slug);
  await page.getByRole('textbox', { name: 'Rich text editor' }).first().fill(`Published ${title}`);
  await page.getByRole('button', { name: 'Create draft' }).click();
  await expect(page).toHaveURL(/\/administrator\/content\/[0-9a-f-]+\/edit$/u);
  await page.getByRole('button', { name: 'Move to Review' }).click();
  await page.getByRole('button', { name: 'Move to Published' }).click();
  await expect(page.getByRole('link', { name: 'View page' })).toBeVisible();
}

/** Remove every primary-menu item whose label matches, so the rendered menu is deterministic. */
async function deleteMenuItemsByTitle(page: Page, titles: readonly (string | RegExp)[]): Promise<void> {
  await page.goto('/administrator/navigation');
  page.on('dialog', (dialog) => void dialog.accept());
  for (const title of titles) {
    for (;;) {
      const label = page.locator('.menu-item input[name="title"]');
      const values = await label.evaluateAll((inputs) =>
        inputs.map((input) => (input as HTMLInputElement).value));
      const index = values.findIndex((value) =>
        typeof title === 'string' ? value === title : title.test(value));
      if (index === -1) {
        break;
      }
      await page.locator('.menu-item')
        .filter({ has: page.locator(`input[name="title"][value="${values[index]}"]`) })
        .first()
        .getByRole('button', { name: 'Delete' })
        .click();
      await expect(page).toHaveURL(/\/administrator\/navigation/u);
      await page.goto('/administrator/navigation');
    }
  }
}

/** Add one primary-menu item through the administrator screen and confirm its calculated path. */
async function addMenuItem(page: Page, options: {
  label: string;
  contentTitle: string;
  segment: string;
  parentPath?: string;
  expectedPath: string;
}): Promise<void> {
  await page.goto('/administrator/navigation');
  const addItem = page.locator('details').filter({ hasText: 'Add a menu item' }).first();
  await addItem.locator('summary').click();
  const form = addItem.locator('form');
  await form.getByLabel('Link type').selectOption('content');
  await form.locator('select[name="content_id"]')
    .selectOption({ label: `${options.contentTitle} · Published` });
  await form.getByLabel('Link label').fill(options.label);
  await form.getByLabel('URL segment').fill(options.segment);
  if (options.parentPath !== undefined) {
    await form.locator('select[name="parent_id"]').selectOption({ label: options.parentPath });
  }
  await form.getByRole('button', { name: 'Add link' }).click();
  await expect(page.getByText(`Calculated menu path: ${options.expectedPath}`, { exact: true }))
    .toBeVisible();
}

test.beforeAll(async ({ browser }) => {
  test.setTimeout(180_000);
  installHorizonExample();

  const page = await browser.newPage({ baseURL: baseUrl });
  try {
    await signInAdministrator(page);
    await ensurePublishedPage(page, parentTitle, parentSlug);
    await ensurePublishedPage(page, childTitle, childSlug);
    // Earlier specs append throwaway timestamped menu items; remove them so the themed menu
    // renders a stable tree before pixel comparison.
    await deleteMenuItemsByTitle(page, [/^Browser About /u, childTitle, parentTitle]);
    await addMenuItem(page, {
      label: parentTitle,
      contentTitle: parentTitle,
      segment: parentSegment,
      expectedPath: `/${parentSegment}`,
    });
    await addMenuItem(page, {
      label: childTitle,
      contentTitle: childTitle,
      segment: childSegment,
      parentPath: `/${parentSegment}`,
      expectedPath: `/${parentSegment}/${childSegment}`,
    });

    await waitForHorizonStatus(page);
    const extension = page.locator('article').filter({ hasText: themeIdentifier }).first();
    await extension.getByRole('button', { name: 'Use for site' }).click();
    await expect(page).toHaveURL(/\/administrator\/extensions$/u);
    await waitForHorizonStatus(page, 'active');
  } finally {
    await page.close();
  }
  await expectPublicTheme(true);
});

test.afterAll(async ({ browser }) => {
  test.setTimeout(180_000);
  const page = await browser.newPage({ baseURL: baseUrl });
  try {
    await signInAdministrator(page);
    await waitForHorizonStatus(page);
    const extension = page.locator('article').filter({ hasText: themeIdentifier }).first();
    const disable = extension.getByRole('button', { name: 'Disable' });
    if (await disable.count()) {
      await disable.click();
      await expect(page).toHaveURL(/\/administrator\/extensions$/u);
    }
    await waitForHorizonStatus(page, 'disabled');
    await deleteMenuItemsByTitle(page, [childTitle, parentTitle]);
  } finally {
    await page.close();
  }
  await expectPublicTheme(false);
});

test('activated Horizon theme renders the complete navigation model', async ({ page, isMobile }) => {
  await page.goto('/');
  await expect(page.locator('body.horizon')).toBeVisible();

  const navigation = page.locator('nav[aria-label="Main navigation"]');
  const toggle = page.getByRole('button', { name: 'Open site navigation' });
  if (isMobile) {
    await expect(toggle).toBeVisible();
    await expect(navigation).toBeHidden();
    await toggle.click();
    await expect(toggle).toHaveAttribute('aria-expanded', 'true');
  } else {
    await expect(toggle).toBeHidden();
  }
  await expect(navigation).toBeVisible();
  for (const item of ['Home', 'Capabilities', 'Platform', 'Administrator', parentTitle]) {
    await expect(navigation.getByRole('link', { name: item, exact: true })).toBeVisible();
  }
  await expect(navigation.getByRole('link', { name: 'Home', exact: true }))
    .toHaveAttribute('aria-current', 'page');

  const nested = navigation.getByRole('link', { name: childTitle, exact: true });
  if (isMobile) {
    await expect(nested).toBeVisible();
  } else {
    await expect(nested).toBeHidden();
    await navigation.getByRole('link', { name: parentTitle, exact: true }).hover();
    await expect(nested).toBeVisible();
  }
  await expect(nested).toHaveAttribute('href', `/${parentSegment}/${childSegment}`);
  await nested.click();
  await expect(page).toHaveURL(`/${parentSegment}/${childSegment}`);
  await expect(page.getByRole('heading', { level: 1, name: childTitle })).toBeVisible();
  await expect(page.locator('body.horizon')).toBeVisible();
  if (isMobile) {
    await toggle.click();
  }
  await expect(page.locator('nav[aria-label="Main navigation"]')
    .getByRole('link', { name: childTitle, exact: true, includeHidden: true }))
    .toHaveAttribute('aria-current', 'page');
});

test('themed homepage matches its visual baseline', async ({ page }) => {
  await page.goto('/');
  await expect(page.locator('body.horizon')).toBeVisible();
  await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
  await expect(page).toHaveScreenshot('horizon-homepage.png', { fullPage: true });
});
