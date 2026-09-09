import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page, type TestInfo } from '@playwright/test';
import { expectNoDocumentOverflow } from './support/interface-diagnostics';
import { awaitStudioLaunchSettled } from './support/studio-authoring';

const administratorEmail = process.env.KUMWE_BROWSER_ADMIN_EMAIL
  ?? 'browser-administrator@kumwe.test';
const administratorPassword = process.env.KUMWE_BROWSER_ADMIN_PASSWORD
  ?? 'browser administrator password';
const portalEmail = process.env.KUMWE_BROWSER_PORTAL_EMAIL ?? 'browser-portal@kumwe.test';
const portalPassword = process.env.KUMWE_BROWSER_PORTAL_PASSWORD ?? 'browser portal password';

const administratorSurfaces = [
  ['/administrator', 'core.administrator.dashboard'],
  ['/administrator/content', 'core.administrator.content-collection'],
  ['/administrator/content/new', 'core.administrator.content-editor'],
  ['/administrator/content-models', 'core.administrator.content-models'],
  ['/administrator/navigation', 'core.administrator.navigation'],
  ['/administrator/extensions', 'core.administrator.extensions'],
  ['/administrator/automation', 'core.administrator.automation'],
  ['/administrator/media', 'core.administrator.media'],
  ['/administrator/settings', 'core.administrator.settings'],
] as const;

/** Sign into the administrator through the production server-rendered form. */
async function signInAdministrator(page: Page): Promise<void> {
  await page.goto('/administrator/login');
  await page.getByLabel('Email address').fill(administratorEmail);
  await page.getByLabel('Password').fill(administratorPassword);
  await page.getByRole('button', { name: 'Sign in to Kumwe' }).click();
  await expect(page).toHaveURL(/\/administrator$/u);
}

/** Sign into the ordinary portal workspace without bypassing membership selection. */
async function signInPortal(page: Page): Promise<void> {
  await page.goto('/portal/login');
  await page.getByLabel('Email address').fill(portalEmail);
  await page.getByLabel('Password').fill(portalPassword);
  await page.getByLabel('Workspace').fill('north');
  await page.getByRole('button', { name: 'Sign in' }).click();
  await expect(page).toHaveURL(/\/portal$/u);
}

/** Require WCAG 2.2 AA automated rules while the deterministic layout checks cover geometry. */
async function expectAccessible(page: Page): Promise<void> {
  const scan = await new AxeBuilder({ page })
    .withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa'])
    .analyze();
  expect(scan.violations, JSON.stringify(scan.violations, null, 2)).toEqual([]);
}

/** Attach one reviewable screenshot without turning the state into an unapproved visual baseline. */
async function attachScreenshot(page: Page, testInfo: TestInfo, name: string): Promise<void> {
  const path = testInfo.outputPath(`${name}.png`);
  await page.screenshot({ path, fullPage: true, animations: 'disabled', caret: 'hide' });
  await testInfo.attach(name, { path, contentType: 'image/png' });
}

test('Phase 5 administrator surfaces use one KIS task shell without route or payload drift', async ({
  page,
}, testInfo) => {
  await page.goto('/administrator/login');
  await expect(page.locator('[data-kis-surface="core.administrator.login"]')).toBeVisible();
  await signInAdministrator(page);

  for (const [path, surface] of administratorSurfaces) {
    await page.goto(path);
    await expect(page.locator(`[data-kis-surface="${surface}"]`)).toBeVisible();
    await expect(page.getByRole('heading', { level: 1 })).toHaveCount(1);
    if (path === '/administrator/content/new') {
      // The Content editor mounts the pinned Studio page builder beside the structured form; wait for
      // that launch to settle so the overflow check measures the surface an editor actually sees.
      const launch = await awaitStudioLaunchSettled(page);
      if (launch === 'fallback') {
        await expect(page.locator('[data-studio-authoring-fallback]')).toBeVisible();
        await expect(page.getByRole('heading', { name: 'Structured editor fallback' })).toBeVisible();
      } else {
        await expect(page.locator('[data-studio-authoring-region]')).toBeVisible();
        await expect(page.getByRole('heading', { name: 'Compose this item visually' })).toBeVisible();
        if (launch === 'ready') {
          await expect(page.locator('#kumwe-studio-content')).toBeVisible();
          await expect(page.getByRole('button', { name: 'Use the structured form' })).toBeVisible();
        } else {
          await expect(page.locator('[data-studio-authoring-fallback-form]')).toBeVisible();
        }
      }
      await expect(page.locator('[data-studio-authoring-fallback-form]'))
        .toHaveAttribute('data-studio-authoring-intent', 'create');
    }
    await expectNoDocumentOverflow(page, {
      root: '#administrator-content',
      detectControlOverlaps: false,
    });
  }

  await page.goto('/administrator/settings');
  await expect(page.locator('kumwe-dirty-form')).toHaveCount(1);
  await expect(page.locator('[data-kis-dirty-status]')).toContainText('saved when you submit');
  await page.getByLabel('Footer text').fill('Phase 5 unsaved-state evidence');
  await expect(page.locator('kumwe-dirty-form')).toHaveAttribute('data-dirty', '');
  await expect(page.locator('[data-kis-dirty-status]')).toHaveText('Unsaved changes');
  await expectAccessible(page);
  await attachScreenshot(page, testInfo, 'phase-five-settings');
});

test('Phase 5 portal home exposes plain-language access readiness and secure recovery boundaries', async ({
  page,
}, testInfo) => {
  await page.goto('/portal/login');
  await expect(page.locator('[data-kis-surface="core.portal.login"]')).toBeVisible();
  await signInPortal(page);

  await expect(page.locator('[data-kis-surface="core.portal.home"]')).toBeVisible();
  await expect(page.getByRole('heading', {
    level: 1,
    name: 'Welcome to your workspace',
  })).toBeVisible();
  const accessContext = page.locator(
    '[data-kis-dashboard-widget="core.dashboard.access-context"]',
  );
  await expect(accessContext.getByRole('heading', {
    level: 2,
    name: 'Your access context',
  })).toBeVisible();
  await expect(accessContext).toContainText(
    'The organization and workspace governing this portal session.',
  );
  await expect(accessContext.getByText('north', { exact: true })).toBeVisible();
  await expect(accessContext.getByText('acme', { exact: true })).toBeVisible();
  await expectNoDocumentOverflow(page, { root: '#portal-main', detectControlOverlaps: false });

  await page.goto('/portal/security');
  await expect(page.locator('[data-kis-surface="core.portal.security"]')).toBeVisible();
  await expect(page.locator('[data-copy-value]')).toHaveCount(0);
  await expect(page.getByRole('button', { name: 'Start setup' })).toBeVisible();
  await expectAccessible(page);
  await attachScreenshot(page, testInfo, 'phase-five-portal-security');
});

test('Phase 5 public presentation keeps the configured home identity and long labels bounded', async ({
  page,
}, testInfo) => {
  // KIS-EVIDENCE-BEGIN p6-003-template-use-ui
  await page.goto('/');
  await expect(page.locator('[data-kis-surface="core.public.home"]')).toBeVisible();
  await expect(page.getByRole('heading', { level: 1 })).toHaveCount(1);
  await page.getByRole('heading', { level: 1 }).evaluate((heading) => {
    heading.textContent = 'A localized public heading with deliberately extended operational context '
      .repeat(4)
      .trim();
  });
  await expectNoDocumentOverflow(page, { root: '#site-content', detectControlOverlaps: false });
  await expectAccessible(page);
  await attachScreenshot(page, testInfo, 'phase-five-public-long-label');
  // KIS-EVIDENCE-END p6-003-template-use-ui
});

test('Phase 5 presentation modes preserve focus, touch, zoom, high contrast, motion, and print', async ({
  page,
  isMobile,
}) => {
  // KIS-EVIDENCE-BEGIN p6-004-presentation-matrix
  await signInAdministrator(page);
  await page.goto('/administrator/settings');

  // Apply each preference to the settled document independently. Gecko preserves reduced-motion
  // emulation across navigation but restores its context colour scheme, so combining them before the
  // sign-in redirects would measure an engine lifecycle detail instead of either product contract.
  await page.emulateMedia({ colorScheme: 'dark' });
  await page.reload();
  expect(await page.evaluate(() => matchMedia('(prefers-color-scheme: dark)').matches)).toBe(true);
  await expect.poll(async () => page.evaluate(
    () => getComputedStyle(document.documentElement).colorScheme,
  )).toContain('dark');

  await page.emulateMedia({ colorScheme: 'light', reducedMotion: 'reduce' });
  await page.reload();
  expect(await page.evaluate(() => matchMedia('(prefers-reduced-motion: reduce)').matches)).toBe(true);
  const maximumTransitionMilliseconds = await page
    .getByRole('button', { name: 'Save settings and design' })
    .evaluate((button) => Math.max(...getComputedStyle(button).transitionDuration
      .split(',')
      .map((duration) => duration.trim().endsWith('ms')
        ? Number.parseFloat(duration)
        : Number.parseFloat(duration) * 1000)));
  expect(maximumTransitionMilliseconds).toBeLessThanOrEqual(1);

  // Gecko forces a light scheme when forcedColors is set to either explicit value, so prove this
  // independent contract only after the dark and reduced-motion phases have passed with it omitted.
  await page.emulateMedia({ colorScheme: 'light', forcedColors: 'active' });
  await page.reload();
  expect(await page.evaluate(() => matchMedia('(forced-colors: active)').matches)).toBe(true);

  const firstSectionLink = page.locator('.kis-phase-five-section-nav a').first();
  await firstSectionLink.focus();
  await expect(firstSectionLink).toBeFocused();
  if (isMobile) {
    expect((await firstSectionLink.boundingBox())?.height ?? 0).toBeGreaterThanOrEqual(44);
  }

  await page.evaluate(() => {
    document.documentElement.style.fontSize = '200%';
  });
  await expectNoDocumentOverflow(page, { root: '#administrator-content', detectControlOverlaps: false });

  await page.goto('/administrator/content-models');
  await expect(page.locator('#create-content-type')).toHaveAttribute('open', '');
  const defaultContentModels = await expectNoDocumentOverflow(page, {
    root: '#administrator-content',
    detectControlOverlaps: false,
  });
  expect(
    defaultContentModels.findings,
    JSON.stringify(defaultContentModels, null, 2),
  ).toEqual([]);

  await page.evaluate(() => {
    document.documentElement.style.fontSize = '200%';
  });
  const contentTypeEditor = page.locator(
    '#content-type-catalog > details:not(#create-content-type)',
  ).first();
  await contentTypeEditor.evaluate((details) => details.setAttribute('open', ''));
  await contentTypeEditor.scrollIntoViewIfNeeded();
  const zoomedContentType = await expectNoDocumentOverflow(page, {
    root: '#administrator-content',
    detectControlOverlaps: false,
  });
  expect(
    zoomedContentType.findings,
    JSON.stringify(zoomedContentType, null, 2),
  ).toEqual([]);

  const workflowEditor = page.locator('#create-workflow');
  await workflowEditor.evaluate((details) => details.setAttribute('open', ''));
  await workflowEditor.scrollIntoViewIfNeeded();
  const zoomedWorkflow = await expectNoDocumentOverflow(page, {
    root: '#administrator-content',
    detectControlOverlaps: false,
  });
  expect(
    zoomedWorkflow.findings,
    JSON.stringify(zoomedWorkflow, null, 2),
  ).toEqual([]);

  await page.goto('/administrator/settings');
  await page.emulateMedia({ media: 'print', colorScheme: 'light', forcedColors: 'none' });
  await expect(page.locator('.kis-phase-five-section-nav')).toBeHidden();
  const printableSettings = page.locator('form[data-kis-dirty-form]');
  await expect(printableSettings).toBeVisible();
  await expect(printableSettings.getByRole('group', { name: 'Public identity' })).toBeVisible();
  await expect(printableSettings.getByRole('group', { name: 'Design system' })).toBeVisible();
  await expect(printableSettings.getByLabel('Active color scheme')).toBeVisible();
  await expect(printableSettings.getByRole('button', { name: 'Save settings and design' }))
    .toBeHidden();
  await expect(printableSettings.getByRole('button', { name: 'Choose media' })).toBeHidden();
  const printDiagnostics = await expectNoDocumentOverflow(page, {
    root: '#administrator-content',
    detectControlOverlaps: false,
  });
  expect(printDiagnostics.findings, JSON.stringify(printDiagnostics, null, 2)).toEqual([]);
  // KIS-EVIDENCE-END p6-004-presentation-matrix
});
