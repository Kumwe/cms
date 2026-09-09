import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';
import { expectNoDocumentOverflow } from './support/interface-diagnostics';
import { awaitStudioLaunchSettled, STUDIO_SURFACE_PREFERENCE_KEY } from './support/studio-authoring';

const administratorEmail = process.env.KUMWE_BROWSER_ADMIN_EMAIL ?? 'browser-administrator@kumwe.test';
const administratorPassword = process.env.KUMWE_BROWSER_ADMIN_PASSWORD ?? 'browser administrator password';

/** Sign in through the production server-rendered administrator form. */
async function signInToAdministrator(page: Page): Promise<void> {
  await page.goto('/administrator/login');
  await page.getByLabel('Email address').fill(administratorEmail);
  await page.getByLabel('Password').fill(administratorPassword);
  await page.getByRole('button', { name: 'Sign in to Kumwe' }).click();
  await expect(page).toHaveURL(/\/administrator$/u);
}

/** Require WCAG 2.2 AA automated rules on the surface an editor sees. */
async function expectAccessible(page: Page): Promise<void> {
  const scan = await new AxeBuilder({ page })
    .withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa'])
    .analyze();
  expect(scan.violations, JSON.stringify(scan.violations, null, 2)).toEqual([]);
}

/**
 * The Content editor mounts the pinned Studio page builder as its default surface and swaps with the
 * structured form on request, remembering the editor's choice across navigations.
 *
 * The page carries the Producer-emitted deployment as an inert JSON block and a `modulepreload` for
 * the exact browser module; the start module imports that module, mounts the contextual shell into
 * the target, and only then hides the structured form. Without a network path to the configured
 * package origin the launch reports `failed` and the form stays, which this journey treats as a real
 * failure: the page builder is the deliverable.
 */
test('the Studio page builder mounts on Content New and swaps with the structured form', async ({
  page,
}) => {
  test.slow();
  await signInToAdministrator(page);

  await page.goto('/administrator/content/new');
  const region = page.locator('[data-studio-authoring-region]');
  await expect(region).toBeVisible();
  const outcome = await awaitStudioLaunchSettled(page);
  expect(outcome, 'The pinned Studio browser module must mount from the configured origin.').toBe('ready');

  const mount = page.locator('#kumwe-studio-content');
  const toggle = page.getByRole('button', { name: 'Use the structured form' });
  // The structured form's own title field; the mounted page builder carries a second "Title" input.
  const title = page.locator('[data-studio-authoring-fallback-form]').getByLabel('Title');
  await expect(page.locator('[data-studio-launch-status]')).toHaveText('The Studio page builder is ready.');
  await expect(region).toHaveAttribute('data-studio-surface', 'studio');
  await expect(mount).toBeVisible();
  await expect(mount.locator('*').first()).toBeAttached();
  await expect(title).toBeHidden();
  await expect(toggle).toBeVisible();
  await expectNoDocumentOverflow(page, { root: '#administrator-content', detectControlOverlaps: false });
  await expectAccessible(page);

  // Studio asks how to start a new item; a blank start opens the contextual editor in the same mount.
  await expect(page.getByRole('heading', { name: 'Choose how to start' })).toBeVisible();
  await page.getByRole('radio', { name: /Blank start/u }).check();
  await page.getByRole('button', { name: 'Start Studio' }).click();
  await expect(mount.locator('kumwe-studio-contextual')).toBeAttached();
  await expect(page.getByRole('heading', { name: 'Choose how to start' })).toBeHidden();
  await expect(page.locator('[data-studio-launch-status]')).toHaveText('The Studio page builder is ready.');
  await expectNoDocumentOverflow(page, { root: '#administrator-content', detectControlOverlaps: false });
  await expectAccessible(page);

  await toggle.click();
  await expect(region).toHaveAttribute('data-studio-surface', 'form');
  await expect(mount).toBeHidden();
  await expect(title).toBeVisible();
  await expect(page.getByRole('button', { name: 'Use the page builder' })).toHaveAttribute('aria-pressed', 'true');
  expect(await page.evaluate((key) => window.localStorage.getItem(key), STUDIO_SURFACE_PREFERENCE_KEY))
    .toBe('form');

  // The remembered choice survives a navigation: the page opens on the form and Studio waits for the toggle.
  await page.reload();
  expect(await awaitStudioLaunchSettled(page)).toBe('deferred');
  await expect(page.locator('[data-studio-launch-status]')).toHaveText('The page builder loads when you switch to it.');
  await expect(region).toHaveAttribute('data-studio-surface', 'form');
  await expect(mount.locator('*')).toHaveCount(0);
  await expect(title).toBeVisible();
  await expectNoDocumentOverflow(page, { root: '#administrator-content', detectControlOverlaps: false });
  await expectAccessible(page);

  await page.getByRole('button', { name: 'Use the page builder' }).click();
  await expect(region).toHaveAttribute('data-studio-surface', 'studio');
  expect(await awaitStudioLaunchSettled(page)).toBe('ready');
  await expect(mount).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Choose how to start' })).toBeVisible();
  await expect(title).toBeHidden();
  expect(await page.evaluate((key) => window.localStorage.getItem(key), STUDIO_SURFACE_PREFERENCE_KEY))
    .toBe('studio');

  // An explicit surface in the URL wins over the remembered one and becomes the new memory.
  await page.goto('/administrator/content/new?surface=form');
  expect(await awaitStudioLaunchSettled(page)).toBe('deferred');
  await expect(region).toHaveAttribute('data-studio-surface', 'form');
  await expect(title).toBeVisible();
  expect(await page.evaluate((key) => window.localStorage.getItem(key), STUDIO_SURFACE_PREFERENCE_KEY))
    .toBe('form');
});
