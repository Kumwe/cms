import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page, type TestInfo } from '@playwright/test';
import { expectNoDocumentOverflow } from './support/interface-diagnostics';
import { gotoAfterRuntimeConvergence } from './support/runtime-convergence';
import { preferStructuredContentForm } from './support/studio-authoring';

const administratorEmail = process.env.KUMWE_BROWSER_ADMIN_EMAIL ?? 'browser-administrator@kumwe.test';
const administratorPassword = process.env.KUMWE_BROWSER_ADMIN_PASSWORD ?? 'browser administrator password';
const limitedEmail = process.env.KUMWE_BROWSER_LIMITED_EMAIL ?? 'browser-limited@kumwe.test';
const limitedPassword = process.env.KUMWE_BROWSER_LIMITED_PASSWORD ?? 'browser limited password';
const dashboardGroupEmail = 'browser-dashboard@kumwe.test';
const dashboardGroupPassword = 'browser dashboard password';
const dashboardGroupSearch = 'Browser Dashboard Group';
const announcementsDashboardSearch = 'kumwe.announcements-example.navigation';
const administratorContextWidget = 'core.dashboard.administrator-context';
const announcementsDashboardHref =
  `/administrator?dashboard_workflow_search=${encodeURIComponent(announcementsDashboardSearch)}`
    + '#dashboard-customization';
// Poll navigations carry no fragment: a fragment-only difference from the current address is a
// same-document navigation that never re-requests the page, so a poll would watch a stale DOM.
const announcementsDashboardPollHref =
  `/administrator?dashboard_workflow_search=${encodeURIComponent(announcementsDashboardSearch)}`;
const businessDefinitionHandle = 'site.default.session5_order';
const assetInspectionDefinition = 'kumwe.asset-inspection-example.inspection';
const assetInspectionReport = 'kumwe.asset-inspection-example.inspection-summary';
const windhoekOrderId = '019b40d9-8dd0-7ca2-a0db-9eae6a150511';
const windhoekTargetId = '019b40d9-8dd0-7ca2-a0db-9eae6a150521';
const browserInvoiceDefinition = 'site.default.browser_invoice';
const browserInvoiceId = '019b40d9-8dd0-7ca2-a0db-9eae6a150611';

async function expectAccessible(page: Page, include?: string): Promise<void> {
  const builder = new AxeBuilder({ page })
    .withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']);
  if (include !== undefined) {
    builder.include(include);
  }
  const scan = await builder.analyze();
  expect(scan.violations, JSON.stringify(scan.violations, null, 2)).toEqual([]);
}

async function expectStylesLoaded(page: Page): Promise<void> {
  const failedStylesheets = await page.evaluate(() =>
    [...document.querySelectorAll<HTMLLinkElement>('link[rel="stylesheet"]')]
      .filter((stylesheet) => stylesheet.sheet === null)
      .map((stylesheet) => stylesheet.href),
  );
  expect(failedStylesheets).toEqual([]);
  expect(await page.locator('link[rel="stylesheet"]').count()).toBeGreaterThan(0);
}

async function expectFocusedAccessAction(page: Page, action: string): Promise<void> {
  const actionInput = page.locator(`form input[name="action"][value="${action}"]`);
  await expect(actionInput).toHaveCount(1);

  const form = actionInput.locator('xpath=ancestor::form[1]');
  await expect(form).toBeVisible();
  await expect(form.locator('input[name="action"]')).toHaveValue(action);

  const verifier = form.getByRole('group', { name: 'Verify this exact action' });
  await expect(verifier).toHaveCount(1);
  await expect(verifier).toBeVisible();
  await expect(form.locator('select[name="step_up_method"]')).toHaveCount(1);
  await expect(form.locator('input[name="step_up_code"]')).toHaveCount(1);
  await expect(form.locator('input[name="recovery_code"]')).toHaveCount(1);
  await expect(page.getByRole('group', { name: 'Verify this exact action' })).toHaveCount(1);
}

/** Attach the unmodified live interface before any compatibility-only baseline normalization. */
async function attachLiveInterfaceScreenshot(
  page: Page,
  testInfo: TestInfo,
  name: string,
): Promise<void> {
  const path = testInfo.outputPath(`${name}.png`);
  await page.screenshot({ path, fullPage: true, animations: 'disabled', caret: 'hide' });
  await testInfo.attach(name, { path, contentType: 'image/png' });
}

/** Keep legacy comparisons scoped to their original shell; Session 6 has dedicated real screenshots. */
async function preservePreSession6VisualSnapshot(page: Page): Promise<void> {
  await page.locator('.administrator-navigation').evaluate((navigation) => {
    const session6Paths = [
      '/administrator/reports',
      '/administrator/extensions/kumwe/asset-inspection-example',
    ];

    for (const link of navigation.querySelectorAll<HTMLAnchorElement>('a[href]')) {
      const href = link.getAttribute('href') ?? '';
      if (!session6Paths.some((path) => href === path || href.startsWith(`${path}/`))) {
        continue;
      }

      const section = link.closest('section');
      if (section !== null && section.querySelectorAll('a[href]').length === 1) {
        section.remove();
      } else {
        link.closest('li')?.remove();
      }
    }
  });
  await page
    .locator('a[href^="/administrator/business/kumwe.asset-inspection-example."]')
    .evaluateAll((links) => {
      for (const link of links) {
        link.closest('.business-workspace-card')?.remove();
      }
    });
  await page.locator('select[name="definition_id"] option').evaluateAll((options) => {
    for (const option of options) {
      if (option.textContent?.includes('kumwe.asset-inspection-example.') === true) {
        option.remove();
      }
    }
  });
}

async function signIn(
  page: Page,
  email = administratorEmail,
  password = administratorPassword,
): Promise<void> {
  await gotoAfterRuntimeConvergence(page, '/administrator/login', 'The administrator sign-in page');
  await page.getByLabel('Email address').fill(email);
  await page.getByLabel('Password').fill(password);
  await page.getByRole('button', { name: 'Sign in to Kumwe' }).click();
  await expect(page).toHaveURL(/\/administrator$/);
}

async function fillSession5OrderForm(page: Page, name: string): Promise<void> {
  await page.locator('[name="values[name]"]').fill(name);
  await page.locator('[name="values[status]"]').selectOption('ready');
  await page.locator('[name="values[enabled]"][type="checkbox"]').check();
  await page.locator('[name="values[amount]"]').fill('10.000000000000000000000000000000');
  await page.locator('[name="values[price][amount]"]').fill('25.000000000000000000000000000000');
  await page.locator('[name="values[price][currency]"]').fill('nad');
  await page.locator('[name="values[quantity][amount]"]').fill('2.000000000000000000000000000000');
  await page.locator('[name="values[quantity][unit]"]').fill('unit');
  await page.locator('[name="values[service_date]"]').fill('2026-08-10');
  await page.locator('[name="values[local_time]"]').fill('13:14:15.123456');
  await page.locator('[name="values[recorded_at]"]').fill('2026-08-10T11:14:15.123456Z');
  await page.locator('[name="values[scheduled_for][instant]"]')
    .fill('2026-08-10T11:14:15.123456Z');
  await page.locator('[name="values[scheduled_for][timezone]"]').fill('Africa/Windhoek');
  await page.locator('[name="values[credential]"]').fill('browser-secret-value');
}

async function expectAdministratorRecordName(page: Page, value: string): Promise<void> {
  const field = page.locator('.business-detail-fields > div').filter({
    has: page.getByText('Name', { exact: true }),
  });
  await expect(field.locator('dd')).toHaveText(value);
}

async function expectAdministratorRecordRow(page: Page, value: string): Promise<void> {
  await expect(page.locator('.business-record-table tbody tr:visible, .kis-business-result-card:visible').filter({ hasText: value }).first())
    .toBeVisible();
}

async function waitForAnnouncementsStatus(
  page: Page,
  status?: 'active' | 'disabled',
): Promise<void> {
  const expected = status === undefined
    ? /component · 2\.0\.0 · (?:active|disabled)/
    : new RegExp(`component · 2\\.0\\.0 · ${status}`);
  await gotoAfterRuntimeConvergence(
    page,
    '/administrator/extensions',
    'The announcements extension listing',
  );
  const extension = page.locator('article').filter({
    hasText: 'kumwe/announcements-example',
  }).first();
  await expect(extension).toContainText(expected);
}

async function ensureAnnouncementsActive(page: Page): Promise<void> {
  await waitForAnnouncementsStatus(page);
  const extension = page.locator('article').filter({ hasText: 'kumwe/announcements-example' }).first();
  const activate = extension.getByRole('button', { name: 'Activate' });
  if (await activate.count()) {
    await activate.click();
    await expect(page).toHaveURL(/\/administrator\/extensions$/);
  }
  await waitForAnnouncementsStatus(page, 'active');
  await expect.poll(async () => {
    await page.goto('/administrator');
    return page.getByRole('link', { name: 'Announcements', exact: true }).count();
  }, {
    message: 'the active extension navigation to reach the local signed runtime map',
    timeout: 25_000,
  }).toBeGreaterThan(0);
  await page.goto('/administrator/extensions');
}

async function resetPersonalAdministratorDashboard(page: Page): Promise<void> {
  await page.goto('/administrator?dashboard-saved=1#dashboard-customization');
  const personal = page.locator('.kis-dashboard-preference-scope').filter({
    has: page.locator('input[name="scope"][value="user"]'),
  }).first();
  for (const name of ['Reset widgets', 'Reset quick links']) {
    const reset = personal.getByRole('button', { name, exact: true });
    if (await reset.count()) {
      await reset.click();
      await expect(page).toHaveURL(/dashboard-saved=1#dashboard-customization$/u);
    }
  }
}

async function expectDashboardSaved(
  page: Page,
  searchParameter?: readonly [string, string],
): Promise<void> {
  await expect(page).toHaveURL((url) =>
    url.pathname === '/administrator'
      && url.hash === '#dashboard-customization'
      && url.searchParams.get('dashboard-saved') === '1'
      && (searchParameter === undefined
        || url.searchParams.get(searchParameter[0]) === searchParameter[1]),
  );
}

test('login is accessible and visually stable', async ({ page }, testInfo) => {
  await page.goto('/administrator/login');
  await expect(page.getByRole('heading', { name: 'Sign in' })).toBeVisible();
  await expectStylesLoaded(page);
  await expectAccessible(page);
  await page.screenshot({ path: testInfo.outputPath('login.png'), fullPage: true });
});

test('database-backed public presentation is responsive and ready', async ({ page, request }, testInfo) => {
  const readiness = await request.get('/health/ready');
  expect(readiness.status()).toBe(200);

  await page.goto('/');
  await expect(
    page.getByRole('heading', { level: 1, name: /Content systems ready for what comes next/ }),
  ).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Structure once. Publish with confidence.' })).toBeVisible();
  await expect(page.getByRole('heading', { name: 'One content core. Every delivery surface.' })).toBeVisible();
  await expect(page.getByRole('link', { name: /Open administrator/ }).first()).toBeVisible();
  await expect(page.locator('nav[aria-label="Main navigation"]')).toContainText('Capabilities');
  await expect(page.locator('img[src*="kumwe-wordmark.svg"]')).toBeVisible();
  await expectStylesLoaded(page);
  await expectAccessible(page);

  const visualContract = await page.evaluate(() => {
    const element = (selector: string): HTMLElement => {
      const match = document.querySelector(selector);
      if (!(match instanceof HTMLElement)) {
        throw new Error(`Missing visual-contract element: ${selector}`);
      }

      return match;
    };
    const columns = (selector: string): number =>
      getComputedStyle(element(selector)).gridTemplateColumns.trim().split(/\s+/).length;

    return {
      viewportWidth: window.innerWidth,
      horizontalOverflow: document.documentElement.scrollWidth - window.innerWidth,
      headerHeight: Math.round(element('.site-header').getBoundingClientRect().height),
      heroColumns: columns('.managed-hero-grid'),
      sectionColumns: columns('.managed-section-grid'),
      headingSize: Math.round(Number.parseFloat(getComputedStyle(element('h1')).fontSize) * 10) / 10,
      bodyBackground: getComputedStyle(document.body).backgroundColor,
      primaryBackground: getComputedStyle(element('.site-button')).backgroundColor,
    };
  });
  const mobile = testInfo.project.name.startsWith('mobile-');
  expect(visualContract.viewportWidth).toBe(mobile ? 412 : 1440);
  expect(visualContract.horizontalOverflow).toBe(0);
  expect(visualContract.headerHeight).toBeGreaterThanOrEqual(68);
  expect(visualContract.heroColumns).toBe(mobile ? 1 : 2);
  expect(visualContract.sectionColumns).toBe(mobile ? 1 : 2);
  expect(visualContract.headingSize).toBeGreaterThan(mobile ? 34 : 52);
  expect(visualContract.bodyBackground).not.toBe('rgba(0, 0, 0, 0)');
  expect(visualContract.primaryBackground).not.toBe('rgba(0, 0, 0, 0)');

  await page.screenshot({
    path: testInfo.outputPath('public-home.png'),
    fullPage: true,
    animations: 'disabled',
    caret: 'hide',
  });
});

test.describe('public presentation without JavaScript', () => {
  test.use({ javaScriptEnabled: false });

  test('keeps essential navigation and keyboard recovery available at every supported viewport', async ({ page }) => {
    await page.goto('/');
    await expect(page.locator('nav[aria-label="Main navigation"]')).toBeVisible();
    await expect(page.locator('nav[aria-label="Main navigation"]')).toContainText('Capabilities');
    await expect(page.getByRole('button', { name: 'Open site navigation' })).toBeHidden();
    await page.getByRole('link', { name: 'Skip to content' }).focus();
    await page.keyboard.press('Enter');
    await expect(page.locator('#site-content')).toBeFocused();
  });
});

test('policy authoring retains a complete native no-JavaScript path', async ({ browser }) => {
  const context = await browser.newContext({ javaScriptEnabled: false });
  const page = await context.newPage();
  try {
    await signIn(page);
    await page.goto(
      '/administrator/business-security?section=policies&mode=create&kind=resource&step=review#policy-step-review',
    );
    await expect(page).toHaveURL(/kind=resource&step=review#policy-step-review$/u);
    await expect(page.getByRole('link', { name: '4. Review', exact: true }))
      .toHaveAttribute('aria-current', 'step');
    await expect(page.locator('[data-kis-policy-step-panel]')).toHaveCount(4);
    for (const stage of await page.locator('[data-kis-policy-step-panel]').all()) {
      await expect(stage).toBeVisible();
    }
    await expect(page.locator('[data-kis-policy-step-flow] form')).toHaveAttribute('method', 'post');
    await expect(page.getByRole('group', { name: 'Verify this exact action' })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Continue to predicate' })).toHaveAttribute(
      'href',
      /section=policies&mode=create&kind=resource&step=predicate#policy-step-predicate$/u,
    );
  } finally {
    await context.close();
  }
});

test('dashboard preferences save and reset through the native no-JavaScript workflow', async ({
  browser,
}, testInfo) => {
  test.setTimeout(60_000);
  const context = await browser.newContext({ javaScriptEnabled: false });
  const page = await context.newPage();
  let signedIn = false;
  let preferenceMutated = false;
  try {
    await signIn(page);
    signedIn = true;
    const forged = await context.request.post('/administrator/dashboard/preferences', {
      form: {
        _csrf: 'forged-dashboard-token',
        action: 'dashboard-cards.save',
        scope: 'user',
        scope_id: 'forged',
        expected_version: '0',
      },
    });
    expect(forged.status()).toBe(403);

    await page.goto(
      `/administrator?dashboard_workflow_search=${encodeURIComponent(administratorContextWidget)}`
        + '#dashboard-customization',
    );
    const customization = page.locator('#dashboard-customization');
    await expect(customization).toHaveAttribute('open', '');
    const personal = customization.locator('.kis-dashboard-preference-scope').filter({
      has: page.locator('input[name="scope"][value="user"]'),
    }).first();
    const widgetForm = personal.locator('form').filter({
      has: page.locator('button[value="dashboard-cards.save"]'),
    });
    const staleVersion = await widgetForm.locator('input[name="expected_version"]').inputValue();
    const scopeId = await widgetForm.locator('input[name="scope_id"]').inputValue();
    for (const checkbox of await widgetForm.locator('input[type="checkbox"]').all()) {
      await checkbox.uncheck();
    }
    const contextChoice = widgetForm.locator('.kis-dashboard-choice').filter({
      has: page.locator(`input[type="hidden"][value="${administratorContextWidget}"]`),
    });
    await contextChoice.locator('input[type="checkbox"]').check();
    await contextChoice.locator('input[type="number"]').fill('1');
    await widgetForm.getByRole('button', { name: 'Save widgets' }).click();
    preferenceMutated = true;

    await expectDashboardSaved(
      page,
      ['dashboard_workflow_search', administratorContextWidget],
    );
    await expect(page.locator(
      '[data-kis-dashboard-widget="core.dashboard.administrator-context"]',
    )).toBeVisible();
    await expect(page.locator(
      '[data-kis-dashboard-widget="core.dashboard.content-summary"]',
    )).toHaveCount(0);

    const conflict = await context.request.post('/administrator/dashboard/preferences', {
      maxRedirects: 0,
      form: {
        _csrf: await widgetForm.locator('input[name="_csrf"]').inputValue(),
        action: 'dashboard-cards.save',
        scope: 'user',
        scope_id: scopeId,
        expected_version: staleVersion,
        item_0: administratorContextWidget,
        selected_0: '1',
        order_0: '1',
      },
    });
    expect(conflict.status()).toBe(303);
    const conflictLocation = conflict.headers().location;
    expect(conflictLocation)
      .toBe('/administrator?dashboard-error=conflict#dashboard-customization');
    if (conflictLocation === undefined) {
      throw new Error('The dashboard conflict response did not provide a redirect location.');
    }
    await page.goto(conflictLocation);
    await expect(page.getByRole('alert')).toHaveText(
      'The dashboard changed in another session. Review the latest choices and try again.',
    );

    await personal.getByRole('button', { name: 'Reset widgets' }).click();
    await expect(page).toHaveURL(/dashboard-saved=1#dashboard-customization$/u);
    preferenceMutated = false;
    await expect(page.locator(
      '[data-kis-dashboard-widget="core.dashboard.content-summary"]',
    )).toBeVisible();
  } finally {
    try {
      if (testInfo.status !== 'timedOut' && signedIn && preferenceMutated && !page.isClosed()) {
        await resetPersonalAdministratorDashboard(page);
      }
    } finally {
      if (testInfo.status !== 'timedOut' && !page.isClosed()) {
        await context.close();
      }
    }
  }
});

test('an access-group dashboard default reaches its member and can be reset', async ({
  browser,
}, testInfo) => {
  test.setTimeout(90_000);
  const managerContext = await browser.newContext();
  const memberContext = await browser.newContext();
  const manager = await managerContext.newPage();
  const member = await memberContext.newPage();
  let managerSignedIn = false;
  let groupMutated = false;
  try {
    await signIn(manager);
    managerSignedIn = true;
    await manager.goto(
      `/administrator?dashboard_group_search=${encodeURIComponent(dashboardGroupSearch)}`
        + '#dashboard-customization',
    );
    const customization = manager.locator('#dashboard-customization');
    await expect(customization).toHaveAttribute('open', '');
    const group = customization.locator('.kis-dashboard-preference-scope').filter({
      has: manager.getByRole('heading', { name: dashboardGroupSearch, exact: true }),
    });
    const widgetForm = group.locator('form').filter({
      has: manager.locator('button[value="dashboard-cards.save"]'),
    });
    for (const checkbox of await widgetForm.locator('input[type="checkbox"]').all()) {
      await checkbox.uncheck();
    }
    const contextChoice = widgetForm.locator('.kis-dashboard-choice').filter({
      has: manager.locator('input[type="hidden"][value="core.dashboard.administrator-context"]'),
    });
    await contextChoice.locator('input[type="checkbox"]').check();
    await contextChoice.locator('input[type="number"]').fill('1');
    await widgetForm.getByRole('button', { name: 'Save widgets' }).click();
    groupMutated = true;
    await expectDashboardSaved(manager, ['dashboard_group_search', dashboardGroupSearch]);

    await signIn(member, dashboardGroupEmail, dashboardGroupPassword);
    await expect(member.locator(
      '[data-kis-dashboard-widget="core.dashboard.administrator-context"]',
    )).toBeVisible();
    await expect(member.locator(
      '[data-kis-dashboard-widget="core.dashboard.content-summary"]',
    )).toHaveCount(0);

    await manager.goto(
      `/administrator?dashboard_group_search=${encodeURIComponent(dashboardGroupSearch)}`
        + '#dashboard-customization',
    );
    const savedGroup = manager.locator('.kis-dashboard-preference-scope').filter({
      has: manager.getByRole('heading', { name: dashboardGroupSearch, exact: true }),
    });
    await savedGroup.getByRole('button', { name: 'Reset widgets' }).click();
    await expectDashboardSaved(manager, ['dashboard_group_search', dashboardGroupSearch]);
    groupMutated = false;

    await member.reload();
    await expect(member.locator(
      '[data-kis-dashboard-widget="core.dashboard.content-summary"]',
    )).toBeVisible();
  } finally {
    try {
      if (
        testInfo.status !== 'timedOut'
        && managerSignedIn
        && groupMutated
        && !manager.isClosed()
      ) {
        await manager.goto(
          `/administrator?dashboard_group_search=${encodeURIComponent(dashboardGroupSearch)}`
            + '#dashboard-customization',
        );
        const group = manager.locator('.kis-dashboard-preference-scope').filter({
          has: manager.getByRole('heading', { name: dashboardGroupSearch, exact: true }),
        });
        const reset = group.getByRole('button', { name: 'Reset widgets' });
        if (await reset.count()) {
          await reset.click();
          await expectDashboardSaved(manager, ['dashboard_group_search', dashboardGroupSearch]);
        }
      }
    } finally {
      if (testInfo.status !== 'timedOut' && !manager.isClosed()) {
        await managerContext.close();
      }
      if (testInfo.status !== 'timedOut' && !member.isClosed()) {
        await memberContext.close();
      }
    }
  }
});

test.describe('authenticated administrator', () => {
  test.beforeEach(async ({ page }) => signIn(page));

  test('dashboard supports desktop and responsive navigation', async ({ page }, testInfo) => {
    await expect(page.getByRole('heading', { name: 'Your work, at a glance' })).toBeVisible();
    await expect(page.locator('[data-kis-dashboard-widget="core.dashboard.content-summary"]')).toBeVisible();
    const quickLinks = page.locator('.kis-dashboard-shortcut-list a');
    await expect(quickLinks.first()).toBeVisible();
    expect(await quickLinks.count()).toBeGreaterThan(0);
    await expect(quickLinks.filter({ hasText: 'Content' }).first()).toHaveAttribute(
      'href',
      '/administrator/content',
    );

    const search = page.getByRole('searchbox', { name: 'Search content' });
    await expect(search).toBeVisible();
    const searchStyle = await search.evaluate((input) => {
      const style = getComputedStyle(input);
      return {
        background: style.backgroundColor,
        color: style.color,
        border: style.borderColor,
      };
    });
    expect(searchStyle.background).not.toBe('rgba(0, 0, 0, 0)');
    expect(searchStyle.color).not.toBe(searchStyle.background);
    expect(searchStyle.border).not.toBe(searchStyle.background);
    await search.fill('launch');
    await page.getByRole('button', { name: 'Search', exact: true }).click();
    await expect(page).toHaveURL(/\/administrator\/content\?q=launch$/u);
    await page.goto('/administrator');
    await expectStylesLoaded(page);
    const toggle = page.getByRole('button', { name: 'Open administrator navigation' });
    if (testInfo.project.name.startsWith('mobile-')) {
      await expect(toggle).toBeVisible();
      await toggle.click();
      await expect(page.getByRole('navigation', { name: 'Administrator navigation' })).toBeVisible();
      await page.keyboard.press('Escape');
      await expect(toggle).toBeFocused();
    } else {
      await expect(toggle).toBeHidden();
    }
    await expectAccessible(page);
    await expect(page).toHaveScreenshot('dashboard.png', {
      fullPage: true,
      mask: [page.locator('[data-visual-dynamic]')],
      maskColor: '#ffffff',
    });
  });

  test('content discovery and graphical editor work without raw JSON', async ({ page }, testInfo) => {
    await preferStructuredContentForm(page.context());
    await page.goto('/administrator/content');
    await expect(page.getByRole('heading', { name: 'Content', exact: true })).toBeVisible();
    await page.getByRole('searchbox', { name: 'Search' }).fill('launch');
    await page.getByRole('button', { name: 'Apply filters' }).click();
    await expect(page).toHaveURL(/q=launch/);
    await expectAccessible(page);

    await page.goto('/administrator/content/new');
    await expect(page.getByRole('heading', { name: 'Create content' })).toBeVisible();
    await expect(page.getByText('JSON', { exact: true })).toHaveCount(0);
    await expect(page.getByRole('textbox', { name: 'Rich text editor' }).first()).toBeVisible();
    await expect(page.getByRole('toolbar', { name: 'Text formatting' }).first()).toBeVisible();
    await expectAccessible(page);
    await page.screenshot({ path: testInfo.outputPath('content-editor.png'), fullPage: true });
  });

  test('business definitions publish through graphical compatibility gates', async ({ page }, testInfo) => {
    // KIS-EVIDENCE-BEGIN p6-002-definition-ui
    // Eight fully authored field rows plus validation, publication, accessibility and evidence capture
    // already consume almost 30 seconds on WebKit. Give this one intentionally dense journey a bounded
    // budget without weakening an assertion or extending the rest of the browser suite.
    test.setTimeout(60_000);
    const suffix = `${Date.now()}_${Math.floor(Math.random() * 10000)}`;
    const handle = `site.default.browser_invoice_${suffix}`;
    await page.goto('/administrator/business-definitions?new=1');
    await expect(page.getByRole('heading', { name: 'Business definitions' })).toBeVisible();
    await expect(page.locator('textarea[name="definition_json"]')).toHaveCount(0);
    await page.locator('input[name="handle"]').fill(handle);
    await page.getByLabel('Singular label').fill('Browser invoice');
    await page.getByLabel('Plural label').fill('Browser invoices');
    await page.getByRole('tab', { name: 'Fields' }).click();
    const longFieldLabel = `Cross-border invoice reference ${'with controlled operational context '.repeat(2).trim()}`;
    for (let index = 0; index < 8; index += 1) {
      await page.getByRole('button', { name: 'Add field' }).click();
      await expect(page.locator('details[data-row="field"][open]')).toHaveCount(1);
      const field = page.locator('[data-row="field"]').last();
      await field.getByLabel('Handle').fill(index === 0 ? 'reference' : `supporting_field_${index}`);
      await field.getByLabel('Label').fill(index === 0 ? longFieldLabel : `Supporting field ${index}`);
      await field.locator('select').first().selectOption('core.text');
      await field.getByLabel('Length').fill('120');
      if (index === 0) await field.getByText('Required', { exact: true }).click();
    }
    await page.getByRole('button', { name: 'Save and validate draft' }).press('Enter');
    await expect(page).toHaveURL(/\/administrator\/business-definitions\?tab=fields&definition=/);
    await expect(page.locator('[data-row="field"]')).toHaveCount(9);
    await expect(page.locator('details[data-row="field"][open]')).toHaveCount(1);
    await expect(page.getByText(longFieldLabel, { exact: true })).toBeVisible();
    await page.getByRole('tab', { name: 'Publication' }).click();
    await expect(page.getByRole('heading', { name: 'Compatibility plan' })).toBeVisible();
    await expect(page.locator('dt').filter({ hasText: /^Draft checksum$/u })).toBeVisible();
    await expectAccessible(page);
    await page.screenshot({
      path: testInfo.outputPath('business-definition-draft.png'),
      fullPage: true,
      animations: 'disabled',
      caret: 'hide',
    });
    page.once('dialog', (dialog) => dialog.accept());
    await page.getByRole('button', { name: /Publish version 1/ }).press('Enter');
    await expect(page).toHaveURL(/tab=publication/);
    await page.getByRole('tab', { name: 'History' }).click();
    await expect(page.getByRole('heading', { name: 'Version history' })).toBeVisible();
    await expect(page.getByText('Version 1', { exact: true })).toBeVisible();
    await expectAccessible(page);
    // KIS-EVIDENCE-END p6-002-definition-ui
  });

  test('definition workspace contains dense package contracts and permission-reduced actors', async ({
    page,
  }) => {
    await page.goto(
      '/administrator/business-definitions?definition=kumwe.asset-inspection-example.inspection&tab=fields',
    );
    await expect(page.getByText('Package owned.', { exact: false })).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Fields' })).toBeVisible();
    expect(await page.locator('[role="tabpanel"] table tbody tr').count()).toBeGreaterThanOrEqual(7);
    await expect(page.getByText('Restricted internal note', { exact: true })).toBeVisible();
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1)).toBe(true);

    await page.context().clearCookies();
    await signIn(page, limitedEmail, limitedPassword);
    await page.goto(
      '/administrator/business-definitions?definition=019b40d9-8dd0-7ca2-a0db-9eae6a150501&tab=identity',
    );
    await expect(page.getByRole('tab', { name: 'Identity' })).toHaveAttribute('aria-selected', 'true');
    await expect(page.getByRole('link', { name: 'New definition' })).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Save and validate draft' })).toHaveCount(0);
    await expect(page.getByText('Read-only access.', { exact: true })).toBeVisible();
    await expectAccessible(page);
  });

  test('schema plans are inspectable, capability-gated and visually stable', async ({
    page,
    isMobile,
  }, testInfo) => {
    await page.goto('/administrator/business-schema-plans');
    await expect(page.getByRole('heading', { name: 'Business schema plans' })).toBeVisible();
    if (isMobile) {
      await page.getByRole('button', { name: 'Browse schema plans' }).click();
    }
    const pendingApprovalPlan = page
      .locator('.schema-plan-catalog .definition-catalog-item', {
        has: page.locator('.status-pending-approval'),
      })
      .first();
    await expect(pendingApprovalPlan).toBeVisible();
    await pendingApprovalPlan.click();
    await expect(pendingApprovalPlan).toHaveAttribute('aria-current', 'true');
    await page.getByRole('tab', { name: 'Operations' }).click();
    await expect(page.getByRole('heading', { name: 'Generated operations' })).toBeVisible();
    await page.getByRole('tab', { name: 'Summary' }).click();
    await expect(page.getByText('Plan checksum', { exact: true })).toBeVisible();
    await expect(page.getByText('Physical checksum', { exact: true })).toBeVisible();
    await page.getByRole('tab', { name: 'Approval' }).click();
    await expect(page.getByRole('heading', { name: 'Approve exact plan' })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Schema plans', exact: true })).toHaveAttribute(
      'aria-current',
      'page',
    );
    await expect(page.locator('textarea[name="sql"], textarea[name="plan"], input[name="sql"]')).toHaveCount(0);
    await expect(page.locator('.schema-operations-table tbody tr')).not.toHaveCount(0);
    const missingCsrf = await page.context().request.post('/administrator/business-schema-plans/plan', {
      form: { definition_id: '018f22e2-7c8b-7ab0-8f3a-88e8026bb401' },
    });
    expect(missingCsrf.status()).toBe(403);
    expect(await missingCsrf.text()).toContain('security token is invalid');
    await expectStylesLoaded(page);
    await expectAccessible(page);
    await attachLiveInterfaceScreenshot(page, testInfo, 'schema-plans-live');
    await preservePreSession6VisualSnapshot(page);
    await expect(page).toHaveScreenshot('schema-plans.png', {
      fullPage: true,
      mask: [
        page.locator('.schema-plan-catalog'),
        page.locator('.kis-master-detail-catalog .count-badge'),
        page.locator('[data-kis-detail] [data-visual-mask]'),
      ],
      maskColor: '#d9e2e8',
    });
  });

  test('business security is structured, isolated and accessible', async ({ page }, testInfo) => {
    test.slow();
    await page.goto('/administrator/business-security');
    await expect(page.getByRole('heading', { level: 1, name: 'Business Security' })).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Authority overview' })).toBeVisible();
    await expect(page.getByRole('navigation', { name: 'Business Security concerns' }).getByRole('link'))
      .toHaveCount(6);
    await expect(page.locator('input[name="step_up_code"]')).toHaveCount(0);

    await page.getByRole('link', { name: 'Policies', exact: true }).click();
    await expect(page).toHaveURL(/section=policies/u);
    await expect(page.getByRole('heading', { name: 'Policy catalog' })).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Organizations and workspaces' })).toHaveCount(0);
    await page.getByRole('link', { name: 'Create policy', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Create row and field policy' })).toBeVisible();
    await expect(page).toHaveURL(/kind=resource&step=scope#policy-step-scope$/u);
    await expect(page.getByRole('link', { name: '1. Scope', exact: true }))
      .toHaveAttribute('aria-current', 'step');
    await expect(page.locator('#policy-step-scope')).toBeFocused();
    await expect(page.locator('#policy-step-scope')).toBeVisible();
    await expect(page.locator('#policy-step-predicate')).toBeHidden();
    await expect(page.getByRole('group', { name: 'Verify this exact action' })).toBeHidden();
    const policyCode = page.locator('input[name="policy_code"]');
    await policyCode.fill('browser.policy.retained');

    await page.getByRole('link', { name: 'Continue to predicate' }).click();
    await expect(page).toHaveURL(/kind=resource&step=predicate#policy-step-predicate$/u);
    await expect(page.locator('#policy-step-predicate')).toBeFocused();
    await expect(policyCode).toHaveValue('browser.policy.retained');
    await expect(page.locator('#policy-step-scope')).toBeHidden();
    await expect(page.getByRole('link', { name: '2. Predicate', exact: true }))
      .toHaveAttribute('aria-current', 'step');

    await page.getByRole('link', { name: 'Continue to disclosure' }).click();
    await expect(page).toHaveURL(/kind=resource&step=disclosure#policy-step-disclosure$/u);
    await expect(page.locator('#policy-step-disclosure')).toBeFocused();
    await expect(page.locator('textarea')).toHaveCount(0);
    await expect(page.locator('input[name="policy_json"], input[name="canonical_ast"]')).toHaveCount(0);
    for (const usage of [
      'create',
      'update',
      'detail',
      'list',
      'filter',
      'search',
      'sort',
      'aggregate',
      'report',
      'export',
      'audit',
      'mcp',
      'relation',
      'include',
      'public_reference',
    ]) {
      await expect(page.locator(`select[name="fields_${usage}[]"]`)).toBeVisible();
    }
    await expect(policyCode).toHaveValue('browser.policy.retained');
    await expect(page.getByRole('group', { name: 'Verify this exact action' })).toBeHidden();

    await page.getByRole('link', { name: 'Review policy' }).click();
    await expect(page).toHaveURL(/kind=resource&step=review#policy-step-review$/u);
    await expect(page.locator('#policy-step-review')).toBeFocused();
    await expect(page.getByRole('link', { name: '4. Review', exact: true }))
      .toHaveAttribute('aria-current', 'step');
    await expect(policyCode).toHaveValue('browser.policy.retained');
    await expect(page.getByRole('group', { name: 'Verify this exact action' })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Create reviewed policy' })).toBeVisible();
    const missingCsrf = await page.context().request.post('/administrator/business-security', {
      form: { action: 'organization.create', identifier: 'forged', name: 'Forged' },
    });
    expect(missingCsrf.status()).toBe(403);
    expect(await missingCsrf.text()).toContain('security token is invalid');
    await expectStylesLoaded(page);
    await expectAccessible(page);
    const horizontalOverflow = await page.evaluate(() =>
      document.documentElement.scrollWidth - window.innerWidth
    );
    expect(horizontalOverflow).toBe(0);
    await page.screenshot({
      path: testInfo.outputPath('business-security.png'),
      fullPage: true,
      animations: 'disabled',
      caret: 'hide',
    });
  });

  test('users and access separates provisioning, assignments, tokens and step-up', async ({
    page,
  }, testInfo) => {
    // KIS-EVIDENCE-BEGIN p6-001-access-ui
    test.setTimeout(90_000);
    const expectBoundedAccessWorkspace = async (attachment: string): Promise<void> => {
      await expectAccessible(page);
      const diagnostics = await expectNoDocumentOverflow(page, {
        root: '#administrator-content',
        detectControlOverlaps: false,
      });
      expect(diagnostics.findings, JSON.stringify(diagnostics, null, 2)).toEqual([]);
      await attachLiveInterfaceScreenshot(page, testInfo, attachment);
    };

    await page.goto('/administrator/access');
    await expect(page.getByRole('heading', { level: 1, name: 'Users & access' })).toBeVisible();
    await expect(page.getByRole('navigation', { name: 'Users and Access concerns' }).getByRole('link'))
      .toHaveCount(6);
    await expect(page.getByRole('heading', { name: 'Users', exact: true })).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Provision an ordinary portal member' })).toBeVisible();
    await expect(page.locator('input[name="step_up_code"]')).toHaveCount(0);

    await page.getByRole('link', { name: 'Create user' }).first().click();
    await expect(page.getByRole('heading', { name: 'Create active identity' })).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Next: grant portal membership' })).toBeVisible();
    await expectFocusedAccessAction(page, 'user.create');
    await expect(page.getByRole('link', { name: 'Continue to membership' }))
      .toHaveAttribute('href', /business-security\?section=memberships&mode=create/u);
    await expectBoundedAccessWorkspace('access-portal-provisioning');

    await page.goto('/administrator/access?section=groups');
    await expect(page.getByRole('heading', { name: 'Groups and roles' })).toBeVisible();
    await expect(page.locator('input[name="step_up_code"]')).toHaveCount(0);
    await page.getByRole('link', { name: 'Review role' }).first().click();
    await expectFocusedAccessAction(page, 'grant.synchronize');
    const roleGrantChangeSet = page.locator('form[data-role-grant-change-set]');
    await expect(roleGrantChangeSet.locator('input[name="grant_snapshot"]')).toHaveCount(1);
    await expect(roleGrantChangeSet.locator('input[name="grant_snapshot"]')).not.toHaveValue('');
    expect(await roleGrantChangeSet.locator('input[name="selected_capabilities[]"]').count())
      .toBeGreaterThan(0);

    await page.goto('/administrator/access?section=groups');
    await page.getByRole('link', { name: 'Create role' }).first().click();
    await expect(page.getByRole('heading', { name: 'Create group or role' })).toBeVisible();
    await expectFocusedAccessAction(page, 'role.create');
    await expectBoundedAccessWorkspace('access-role-creation');

    await page.goto('/administrator/access?section=grants');
    await expect(page.getByRole('heading', { name: 'Capability grants' })).toBeVisible();
    await expect(page.locator('input[name="step_up_code"]')).toHaveCount(0);
    await page.getByRole('link', { name: 'Create grant' }).first().click();
    await expect(page.getByRole('heading', { name: 'Create capability grant' })).toBeVisible();
    await expectFocusedAccessAction(page, 'grant.create');
    await expectBoundedAccessWorkspace('access-grant-creation');

    await page.goto('/administrator/access?section=assignments');
    await expect(page.getByRole('heading', { name: 'Role assignments' })).toBeVisible();
    await page.getByRole('link', { name: 'Assign role' }).first().click();
    await expect(page.getByRole('heading', { name: 'Assign group or role' })).toBeVisible();
    await expectFocusedAccessAction(page, 'role.assign');
    await expect(page.getByRole('heading', { name: 'Portal membership remains separate' })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Continue portal provisioning' }))
      .toHaveAttribute('href', /business-security\?section=memberships&mode=create/u);
    await expectBoundedAccessWorkspace('access-role-assignment');

    await page.goto('/administrator/access?section=tokens');
    await expect(page.getByRole('heading', { name: 'API, CLI and MCP tokens' })).toBeVisible();
    await page.getByRole('link', { name: 'Create token' }).first().click();
    await expect(page.getByRole('heading', { name: 'Create API, CLI or MCP token' })).toBeVisible();
    await expect(page.getByLabel('Capabilities')).toHaveAttribute('multiple', '');
    await expect(page.getByLabel('Audience')).toBeVisible();
    await expect(page.getByLabel('Purpose')).toBeVisible();
    await expectFocusedAccessAction(page, 'token.create');
    await expectBoundedAccessWorkspace('access-token-issuance');

    await page.goto('/administrator/access?section=events');
    await expect(page.getByRole('heading', { name: 'Security events' })).toBeVisible();
    const securityEvents = page.getByRole('region', { name: 'Identity security events' });
    await expect(securityEvents.locator('tbody')).not.toContainText(/password|recovery_code|metadata/u);
    await expect(page.getByText('Operational metadata and all credential values are omitted.'))
      .toBeVisible();
    await page.getByRole('link', { name: 'Set up my verification' }).click();
    await expect(page.getByRole('heading', { name: 'Authenticator and recovery setup' })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Begin authenticator setup' })).toBeVisible();
    await expect(page.locator('input[name="step_up_code"]')).toHaveCount(0);
    await expectBoundedAccessWorkspace('access-step-up-enrollment');
    await page.getByRole('button', { name: 'Begin authenticator setup' }).click();
    await expect(page.getByText('Add this secret to your authenticator now.')).toBeVisible();
    await expect(page.getByText('Provisioning URI:', { exact: false })).toBeVisible();
    await expect(page.locator('time[datetime]')).toHaveAttribute(
      'datetime',
      /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/u,
    );
    await expect(page.locator('input[name="enrollment_id"]')).toHaveValue(
      /^[0-9a-f-]{36}$/u,
    );
    await expect(page.locator('input[name="step_up_code"]')).toBeVisible();
    await expect(page.getByRole('button', { name: 'Confirm enrollment' })).toBeVisible();
    // KIS-EVIDENCE-END p6-001-access-ui
  });

  test('credential rotation and recovery are focused, verified tasks', async ({ page }) => {
    test.setTimeout(90_000);

    await page.goto('/administrator/access?section=events&mode=review');
    await page.getByRole('link', { name: 'Rotate my credentials' }).click();
    await expect(page.getByRole('heading', { name: 'Change my password' })).toBeVisible();
    await expectFocusedAccessAction(page, 'user.password.change');
    const passwordChange = page
      .locator('form input[name="action"][value="user.password.change"]')
      .locator('xpath=ancestor::form[1]');
    for (const field of ['current_password', 'new_password', 'new_password_confirmation']) {
      await expect(passwordChange.locator(`input[name="${field}"]`)).toHaveAttribute('type', 'password');
    }
    await expect(page.getByRole('heading', { name: 'Reissue recovery codes' })).toBeVisible();
    const reissue = page
      .locator('form input[name="action"][value="step_up.recovery.reissue"]')
      .locator('xpath=ancestor::form[1]');
    await expect(reissue.locator('input[name="recovery_code"]')).toHaveCount(0);

    await page.goto('/administrator/access?section=users');
    await page.getByRole('link', { name: 'Review access' }).first().click();
    const subject = new URL(page.url()).searchParams.get('id') ?? '';
    expect(subject).not.toBe('');
    await expect(page.getByRole('heading', { name: /^Credential recovery for /u })).toBeVisible();

    for (const [mode, heading, action] of [
      ['unenroll', /^Retire the second factors of /u, 'user.step_up.revoke'],
      ['sessions', /^End every session of /u, 'user.sessions.terminate'],
    ] as const) {
      await page.goto(
        `/administrator/access?section=users&mode=${mode}&id=${encodeURIComponent(subject)}`,
      );
      await expect(page.getByRole('heading', { name: heading })).toBeVisible();
      await expect(page.locator('input[name="reason"]')).toHaveCount(1);
      await expectFocusedAccessAction(page, action);
    }

    await page.goto(
      `/administrator/access?section=users&mode=password&id=${encodeURIComponent(subject)}`,
    );
    const ownAccount = await page.getByRole('heading', {
      name: 'Reset your own password from your security panel',
    }).count();
    if (ownAccount === 0) {
      await expectFocusedAccessAction(page, 'user.password.reset');
      await expect(page.locator('input[name="new_password"]')).toHaveAttribute('type', 'password');
      await expect(page.locator('input[name="current_password"]')).toHaveCount(0);
    }
  });

  test('access and entity workspaces remain compact at supported sizes and text zoom', async ({
    page,
    isMobile,
  }) => {
    test.setTimeout(90_000);

    const expectBoundedWorkspace = async (): Promise<void> => {
      const diagnostics = await expectNoDocumentOverflow(page, {
        root: '#administrator-content',
        detectControlOverlaps: false,
      });
      expect(diagnostics.findings, JSON.stringify(diagnostics, null, 2)).toEqual([]);
    };
    const openUsers = async (zoomed = false): Promise<void> => {
      await page.goto('/administrator/access?section=users');
      if (zoomed) {
        await page.evaluate(() => {
          document.documentElement.style.fontSize = '200%';
        });
      }

      const users = page.locator(
        '.kis-responsive-table[role="region"][aria-label="Users"]',
      );
      await expect(users).toHaveCount(1);
      await expect(users).toBeVisible();
      for (const heading of ['User', 'Status', 'Groups and roles', 'Tasks']) {
        await expect(users.getByRole('columnheader', {
          name: heading,
          exact: true,
          includeHidden: true,
        })).toHaveCount(1);
      }
      const firstUser = users.locator('tbody tr').first();
      const identity = firstUser.locator('th[scope="row"]');
      const groups = firstUser.locator('td').nth(1);
      await expect(identity).toBeVisible();
      await expect(groups).toBeVisible();
      await expect(identity).not.toHaveText('');
      await expect(groups).not.toHaveText('');
      const visibleUserColumns = await firstUser.evaluate((row) => {
        const region = row.closest('[role="region"]');
        const identityCell = row.querySelector('th[scope="row"]');
        const groupCell = row.querySelectorAll('td').item(1);
        if (region === null || identityCell === null || groupCell === null) {
          throw new Error('The users table does not expose its identity and group cells.');
        }

        const bounds = region.getBoundingClientRect();
        const contained = (cell: Element): boolean => {
          const cellBounds = cell.getBoundingClientRect();
          return cellBounds.left >= bounds.left - 1 && cellBounds.right <= bounds.right + 1;
        };
        return { groups: contained(groupCell), identity: contained(identityCell) };
      });
      expect(visibleUserColumns).toEqual({ groups: true, identity: true });
      await expect(page.locator('input[name="step_up_code"]')).toHaveCount(0);
      await expectBoundedWorkspace();
    };
    const openAssignment = async (zoomed = false): Promise<void> => {
      await page.goto('/administrator/access?section=assignments&mode=create');
      if (zoomed) {
        await page.evaluate(() => {
          document.documentElement.style.fontSize = '200%';
        });
      }

      await expectFocusedAccessAction(page, 'role.assign');
      const actionInput = page.locator('input[name="action"][value="role.assign"]');
      const form = actionInput.locator('xpath=ancestor::form[1]');
      const assign = form.getByRole('button', { name: 'Assign role' });
      const dimensions = await assign.evaluate((button) => {
        const bounds = button.getBoundingClientRect();
        const fontSize = Number.parseFloat(getComputedStyle(button).fontSize);
        return {
          heightInEm: bounds.height / fontSize,
          widthInEm: bounds.width / fontSize,
        };
      });
      expect(dimensions.heightInEm).toBeLessThanOrEqual(4.5);
      expect(dimensions.widthInEm).toBeLessThanOrEqual(20);
      await expectBoundedWorkspace();
    };
    const openRoleCapabilitySet = async (zoomed = false): Promise<void> => {
      await page.goto('/administrator/access?section=groups');
      await expect(page.locator('input[name="step_up_code"]')).toHaveCount(0);
      await page.getByRole('link', { name: 'Review role' }).first().click();
      if (zoomed) {
        await page.evaluate(() => {
          document.documentElement.style.fontSize = '200%';
        });
      }

      await expectFocusedAccessAction(page, 'grant.synchronize');
      const changeSet = page.locator('form[data-role-grant-change-set]');
      await expect(changeSet).toHaveCount(1);
      await expect(changeSet.locator('input[name="grant_snapshot"]')).toHaveCount(1);
      await expect(changeSet.locator('input[name="grant_snapshot"]')).not.toHaveValue('');
      const capabilityOptions = changeSet.locator('input[name="selected_capabilities[]"]');
      expect(await capabilityOptions.count()).toBeGreaterThan(0);
      await expect(capabilityOptions.first()).toBeVisible();

      const optionLayout = await changeSet.locator('.option-grid').evaluate((grid) => {
        const bounds = grid.getBoundingClientRect();
        const cards = [...grid.querySelectorAll<HTMLElement>('.option-card')];
        const layouts = cards.map((card) => {
          const cardBounds = card.getBoundingClientRect();
          const checkbox = card.querySelector<HTMLElement>('input[type="checkbox"]');
          const copy = card.querySelector<HTMLElement>('span');
          const fontSize = Number.parseFloat(getComputedStyle(card).fontSize);
          if (checkbox === null || copy === null) {
            return {
              contentContained: false,
              heightInEm: Number.POSITIVE_INFINITY,
              chromeHeightInEm: Number.POSITIVE_INFINITY,
            };
          }

          const checkboxBounds = checkbox.getBoundingClientRect();
          const copyBounds = copy.getBoundingClientRect();
          const contained = (childBounds: DOMRect): boolean =>
            childBounds.left >= cardBounds.left - 1
            && childBounds.right <= cardBounds.right + 1
            && childBounds.top >= cardBounds.top - 1
            && childBounds.bottom <= cardBounds.bottom + 1;
          return {
            contentContained: contained(checkboxBounds)
              && contained(copyBounds)
              && copy.scrollWidth <= copy.clientWidth + 1
              && copy.scrollHeight <= copy.clientHeight + 1,
            heightInEm: cardBounds.height / fontSize,
            chromeHeightInEm: (
              cardBounds.height - Math.max(checkboxBounds.height, copyBounds.height)
            ) / fontSize,
          };
        });
        return {
          count: cards.length,
          allContained: cards.every((card) => {
            const cardBounds = card.getBoundingClientRect();
            return cardBounds.left >= bounds.left - 1 && cardBounds.right <= bounds.right + 1;
          }),
          allContentContained: layouts.every((layout) => layout.contentContained),
          maximumHeightInEm: Math.max(...layouts.map((layout) => layout.heightInEm)),
          maximumChromeHeightInEm: Math.max(...layouts.map((layout) => layout.chromeHeightInEm)),
        };
      });
      expect(optionLayout.count).toBeGreaterThan(0);
      expect(optionLayout.allContained).toBe(true);
      expect(optionLayout.allContentContained).toBe(true);
      if (zoomed) {
        expect(optionLayout.maximumChromeHeightInEm).toBeLessThanOrEqual(2.5);
      } else {
        expect(optionLayout.maximumHeightInEm).toBeLessThanOrEqual(10);
      }
      await expectBoundedWorkspace();
    };
    const openEntityDefinition = async (zoomed = false): Promise<void> => {
      await page.goto(
        `/administrator/business-definitions?definition=${assetInspectionDefinition}&tab=fields`,
      );
      if (zoomed) {
        await page.evaluate(() => {
          document.documentElement.style.fontSize = '200%';
        });
      }

      const workspace = page.locator(
        '[data-kis-master-detail="business-definition-workspace"]',
      );
      await expect(workspace).toBeVisible();
      const catalogToggle = workspace.getByRole('button', { name: 'Browse definitions' });
      if (await catalogToggle.isVisible()) {
        await catalogToggle.click();
        await expect(workspace.getByRole('complementary', { name: 'Definition catalog' }))
          .toBeVisible();
        await expect(workspace.locator('.definition-catalog-item').first()).toBeVisible();
        await catalogToggle.click();
      } else {
        await expect(workspace.getByRole('complementary', { name: 'Definition catalog' }))
          .toBeVisible();
      }

      const fields = workspace.getByRole('region', { name: 'Definition fields' });
      await expect(fields).toBeVisible();
      for (const heading of ['Field', 'Type', 'Required', 'Use']) {
        await expect(fields.getByRole('columnheader', {
          name: heading,
          exact: true,
          includeHidden: true,
        })).toHaveCount(1);
      }
      const firstField = fields.locator('tbody tr').first();
      const visibleFieldColumns = await firstField.evaluate((row) => {
        const region = row.closest('[role="region"]');
        const cells = row.querySelectorAll(':scope > th, :scope > td');
        if (region === null || cells.length !== 4) {
          throw new Error('The definition fields table does not expose its four semantic cells.');
        }

        const bounds = region.getBoundingClientRect();
        const contained = (cell: Element): boolean => {
          const cellBounds = cell.getBoundingClientRect();
          return cellBounds.left >= bounds.left - 1 && cellBounds.right <= bounds.right + 1;
        };
        return {
          field: contained(cells.item(0)),
          type: contained(cells.item(1)),
          required: contained(cells.item(2)),
          use: contained(cells.item(3)),
        };
      });
      expect(visibleFieldColumns).toEqual({ field: true, required: true, type: true, use: true });
      await expectBoundedWorkspace();
    };

    const viewports = isMobile
      ? [{ width: 412, height: 915 }]
      : [{ width: 1440, height: 960 }, { width: 1024, height: 800 }];
    for (const viewport of viewports) {
      await page.setViewportSize(viewport);
      await openUsers();
      await openAssignment();
      await openEntityDefinition();
    }

    await openRoleCapabilitySet();
    await openRoleCapabilitySet(true);
    await openUsers(true);
    await openAssignment(true);
    await openEntityDefinition(true);
  });

  test('published content links through a typed menu to its canonical path', async ({
    page,
  }, testInfo) => {
    const suffix = `${Date.now()}-${Math.floor(Math.random() * 10000)}`;
    const title = `Browser About ${suffix}`;
    const slug = `browser-about-${suffix}`;

    await preferStructuredContentForm(page.context());
    await page.goto('/administrator/content/new');
    await page.getByLabel('Title').fill(title);
    await page.getByLabel('URL slug').fill(slug);
    await page.getByRole('textbox', { name: 'Rich text editor' }).first().fill(`Published ${title}`);
    await page.getByRole('button', { name: 'Create draft' }).click();
    await expect(page).toHaveURL(/\/administrator\/content\/[0-9a-f-]+\/edit$/);

    await page.getByRole('button', { name: 'Move to Review' }).click();
    await page.getByRole('button', { name: 'Move to Published' }).click();
    await expect(page.getByRole('link', { name: 'View page' })).toHaveAttribute('href', `/${slug}`);

    await page.goto('/administrator/navigation');
    const addItem = page.locator('details').filter({ hasText: 'Add a menu item' }).first();
    await addItem.locator('summary').click();
    const form = addItem.locator('form');
    await form.getByLabel('Link type').selectOption('content');
    await form.locator('select[name="content_id"]').selectOption({ label: `${title} · Published` });
    await form.getByLabel('Link label').fill(title);
    await form.getByLabel('URL segment').fill(slug);
    await form.getByRole('button', { name: 'Add link' }).click();
    await expect(page.getByText(`Calculated menu path: /${slug}`)).toBeVisible();

    await page.goto(`/${slug}`);
    await expect(page.getByRole('heading', { level: 1, name: title })).toBeVisible();
    await expect(page.getByRole('link', { name: title, includeHidden: true })).toHaveAttribute(
      'href',
      `/${slug}`,
    );
    await expectAccessible(page);
    const diagnostics = await expectNoDocumentOverflow(page, {
      root: '#site-content',
      detectControlOverlaps: false,
    });
    expect(diagnostics.findings, JSON.stringify(diagnostics, null, 2)).toEqual([]);
    await attachLiveInterfaceScreenshot(page, testInfo, 'published-content-canonical-page');
  });

  test('media library is usable and accessible', async ({ page }, testInfo) => {
    await page.goto('/administrator/media');
    await expect(page.getByRole('heading', { name: 'Media library' })).toBeVisible();
    await expect(page.getByLabel('File type')).toBeVisible();
    await expectAccessible(page);
    await page.screenshot({ path: testInfo.outputPath('media-library.png'), fullPage: true });
  });

  test('site identity and corporate schemes are managed graphically', async ({ page }, testInfo) => {
    const handle = `browser_${testInfo.project.name.replaceAll('-', '_')}`;
    await page.goto('/administrator/settings');
    await expect(page.getByRole('heading', { name: 'Site settings' })).toBeVisible();
    const retainedAttemptScheme = page.locator('[data-presentation-scheme-row]').filter({
      has: page.locator(`input[data-presentation-scheme-handle][value="${handle}"]`),
    });
    if (await retainedAttemptScheme.count()) {
      await retainedAttemptScheme.getByRole('button', { name: 'Remove', exact: true }).click();
    }
    await expect(page.getByLabel('Active color scheme')).toHaveValue(/corporate|browser_/);

    await page.getByRole('button', { name: 'Choose media' }).click();
    const mediaDialog = page.getByRole('dialog', { name: 'Choose the site logo' });
    await expect(mediaDialog).toBeVisible();
    await mediaDialog.getByLabel('Filter media').fill('kumwe-symbol');
    await mediaDialog.getByRole('button', { name: /kumwe-symbol\.svg/ }).click();
    await expect(page.locator('#presentation-logo')).toHaveValue(/kumwe-symbol\.svg$/);

    await page.getByRole('button', { name: 'Add color scheme' }).click();
    const scheme = page.locator('[data-presentation-scheme-row]').last();
    await scheme.getByLabel('Name').fill(`Browser ${testInfo.project.name}`);
    await scheme.getByLabel('Handle').fill(handle);
    await page.getByLabel('Active color scheme').selectOption(handle);
    await page.getByLabel('Button treatment').selectOption('soft');
    await page.getByLabel('Button shape').selectOption('pill');
    await expectAccessible(page);
    await page.getByRole('button', { name: 'Save settings and design' }).click();
    await expect(page).toHaveURL(/\/administrator\/settings\?saved=1$/);

    await page.goto('/');
    await expect(page.locator('body')).toHaveAttribute('data-presentation-scheme', handle);
    await expect(page.locator('body')).toHaveAttribute('data-button-style', 'soft');
    await expect(page.locator('body')).toHaveAttribute('data-button-shape', 'pill');
    await expectAccessible(page);

    await page.goto('/administrator/settings');
    await page.getByLabel('Active color scheme').selectOption('corporate');
    await page.getByLabel('Button treatment').selectOption('solid');
    await page.getByLabel('Button shape').selectOption('rounded');
    // Restore the complete presentation document, not only its active controls. Studio locks the
    // whole published presentation revision, so retaining one project-specific inactive scheme would
    // make the next serial browser project correctly demand an explicit composition theme migration.
    const customScheme = page.locator('[data-presentation-scheme-row]').filter({
      has: page.locator(`input[data-presentation-scheme-handle][value="${handle}"]`),
    });
    await customScheme.getByRole('button', { name: 'Remove', exact: true }).click();
    await expect(customScheme).toHaveCount(0);
    await expect(page.getByLabel('Active color scheme').locator(`option[value="${handle}"]`))
      .toHaveCount(0);
    await page.getByRole('button', { name: 'Save settings and design' }).click();
    await expect(page).toHaveURL(/\/administrator\/settings\?saved=1$/);
    await expect(page.getByLabel('Active color scheme').locator(`option[value="${handle}"]`))
      .toHaveCount(0);
  });

  test('automation uses generated job controls rather than JSON', async ({ page }, testInfo) => {
    await page.goto('/administrator/automation');
    await expect(page.getByRole('heading', { name: 'Automation' })).toBeVisible();
    await expect(page.locator('textarea[name="payload"]')).toHaveCount(0);
    await expect(page.getByLabel('Job type')).toBeVisible();
    await expectAccessible(page);
    await page.screenshot({
      path: testInfo.outputPath('automation.png'),
      fullPage: true,
      mask: [page.locator('[data-visual-dynamic]')],
      maskColor: '#ffffff',
    });
  });

  test('generated business workspaces are responsive, accessible and visually bounded', async ({
    page,
  }, testInfo) => {
    await page.goto('/administrator/business');
    await expect(page.getByRole('heading', { level: 1, name: 'Business records' })).toBeVisible();
    await expect(page.getByRole('link', { name: /Open session 5 orders/i })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Open Example inspections' })).toBeVisible();
    const liveNavigation = page.locator('.administrator-navigation');
    await expect(liveNavigation.locator('a[href="/administrator/access"]')).toHaveCount(1);
    await expect(liveNavigation.locator('a[href="/administrator/business-security"]')).toHaveCount(1);
    await expect(liveNavigation.locator('a[href="/administrator/reports"]')).toHaveCount(1);
    await expect(liveNavigation.locator(
      'a[href^="/administrator/extensions/kumwe/asset-inspection-example"]',
    )).toHaveCount(1);
    await expectStylesLoaded(page);
    await expectAccessible(page);
    expect(await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth)).toBe(0);
    await attachLiveInterfaceScreenshot(page, testInfo, 'business-workspaces');
    await preservePreSession6VisualSnapshot(page);
    await expect(page).toHaveScreenshot('administrator-generated-business-workspace.png', {
      fullPage: true,
    });
  });

  test('asset-inspection extension workspace is policy-filtered and bounded', async ({
    page,
  }, testInfo) => {
    await page.goto('/administrator/extensions/kumwe/asset-inspection-example');
    const surface = page.locator(
      '[data-kis-surface="kumwe.asset-inspection-example.administrator.index"]',
    );
    await expect(surface).toBeVisible();
    await expect(page.getByRole('heading', { level: 1, name: 'Asset inspection example' }))
      .toBeVisible();
    await expect(page.getByRole('heading', { name: 'Row and field disclosure' })).toBeVisible();
    await expect(page.getByText('EXAMPLE-INSPECTION-001')).toBeVisible();
    await expect(page.getByText('ROW-POLICY-DENIED')).toHaveCount(0);
    await expect(page.getByText('FOREIGN-SITE-ROW')).toHaveCount(0);
    await expect(page.getByText('Restricted-field disclosure: withheld.')).toBeVisible();
    await expectAccessible(page);
    const diagnostics = await expectNoDocumentOverflow(page, {
      root: '#administrator-content',
      detectControlOverlaps: false,
    });
    expect(diagnostics.findings, JSON.stringify(diagnostics, null, 2)).toEqual([]);
    await attachLiveInterfaceScreenshot(page, testInfo, 'asset-inspection-extension-workspace');
  });

  test('reports execute graphically and expose queued export status', async ({ page }, testInfo) => {
    // KIS-EVIDENCE-BEGIN p6-002-report-ui
    test.setTimeout(120_000);
    await page.goto('/administrator/reports');
    await expect(page.getByRole('heading', { level: 1, name: 'Business reports' })).toBeVisible();
    await expect(page.locator('a[href="/administrator/reports"]')).toHaveAttribute(
      'aria-current',
      'page',
    );
    await expect(page.getByRole('link', { name: 'Asset inspection example', exact: true }))
      .toBeVisible();
    const report = page.locator(`form[action="/administrator/reports/${assetInspectionReport}"]`);
    await expect(report.getByRole('heading', { name: 'Asset inspection example summary' }))
      .toBeVisible();
    await expect(report.getByText(assetInspectionReport, { exact: true })).toBeVisible();
    await report.locator('[name="parameters[minimum_score]"]').fill('70');
    await report.getByRole('button', { name: 'Run report', exact: true }).click();

    const results = page.getByRole('region', { name: 'Report results', exact: true });
    await expect(results).toBeVisible();
    const resultsTable = results.getByRole('region', { name: 'Scrollable report results table' });
    await expect(resultsTable).toBeVisible();
    await expect(resultsTable.getByRole('columnheader', { name: 'Reference' })).toBeVisible();
    await expect(resultsTable.getByRole('columnheader', { name: 'Risk score' })).toBeVisible();
    const accepted = resultsTable.getByRole('row').filter({ hasText: 'BROWSER-INSPECT-001' });
    await expect(accepted).toBeVisible();
    await expect(accepted).toContainText('79');
    await expect(resultsTable.getByText('BROWSER-POLICY-DENIED', { exact: true })).toHaveCount(0);
    await expect(page.getByText('Browser report restricted note', { exact: true })).toHaveCount(0);
    await expectStylesLoaded(page);
    await expectAccessible(page);
    expect(await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth)).toBe(0);
    if ((page.viewportSize()?.width ?? 0) >= 900) {
      const dimensions = await page.locator('.kis-business-report-grid').evaluate((layout) => ({
        catalogWidth: layout.children.item(0)?.getBoundingClientRect().width ?? 0,
        workspaceWidth: layout.children.item(1)?.getBoundingClientRect().width ?? 0,
      }));
      expect(dimensions.workspaceWidth).toBeGreaterThan(dimensions.catalogWidth);
    }
    await page.screenshot({
      path: testInfo.outputPath('administrator-report-results.png'),
      fullPage: true,
      animations: 'disabled',
      caret: 'hide',
    });

    await report.getByRole('button', { name: 'Queue CSV export', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Latest export request' })).toBeVisible();
    await expect(page.getByText('Queued', { exact: true })).toBeVisible();
    await expect(page.locator('dl')).toContainText('Rows');
    await expect(page.locator('dl')).toContainText('Pending');
    const queuedExportDiagnostics = await expectNoDocumentOverflow(page, {
      root: 'section[aria-labelledby="export-history-title"]',
      detectControlOverlaps: false,
    });
    expect(
      queuedExportDiagnostics.findings,
      JSON.stringify(queuedExportDiagnostics, null, 2),
    ).toEqual([]);
    const status = page.getByRole('link', { name: 'Refresh status' });
    await expect(status).toHaveAttribute(
      'href',
      /^\/administrator\/reports\/exports\/[0-9a-f-]{36}$/u,
    );
    await expect(page.getByRole('link', { name: 'Download verified CSV' })).toHaveCount(0);
    await status.click();
    await expect(page.getByRole('heading', { name: 'Latest export request' })).toBeVisible();
    const refreshedExportDiagnostics = await expectNoDocumentOverflow(page, {
      root: 'section[aria-labelledby="export-history-title"]',
      detectControlOverlaps: false,
    });
    expect(
      refreshedExportDiagnostics.findings,
      JSON.stringify(refreshedExportDiagnostics, null, 2),
    ).toEqual([]);
    await expectAccessible(page, 'section[aria-labelledby="export-history-title"]');
    await page.locator('section[aria-labelledby="export-history-title"]').screenshot({
      path: testInfo.outputPath('administrator-export-status.png'),
      animations: 'disabled',
      caret: 'hide',
    });
    // KIS-EVIDENCE-END p6-002-report-ui
  });

  test('generated business list remains responsive and progressively enhanced', async ({
    page,
    isMobile,
  }, testInfo) => {
    await page.goto(`/administrator/business/${businessDefinitionHandle}`);
    await expect(page.locator(isMobile ? '.kis-business-result-card' : '.business-record-table tbody tr').first()).toBeVisible();
    await expect(page.getByRole('link', { name: 'Report', exact: true })).toBeVisible();
    const exportForm = page.locator(
      `form[action="/administrator/reports/core.record-export.${businessDefinitionHandle}/exports"]`,
    );
    await expect(exportForm.getByRole('button', { name: 'Queue CSV export', exact: true })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Export', exact: true })).toHaveCount(0);
    await page.getByLabel('Search records').fill('Windhoek order');
    await page.getByRole('button', { name: 'Apply', exact: true }).click();
    await expect(page.locator(isMobile ? '.kis-business-result-card' : '.business-record-table tbody tr').filter({ hasText: 'Windhoek order' }).first()).toBeVisible();
    expect(await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth)).toBe(0);
    if (isMobile) {
      expect(await page.locator('.business-table-wrap').evaluate((table) =>
        table.scrollHeight - table.clientHeight,
      )).toBe(0);
    }
    await attachLiveInterfaceScreenshot(page, testInfo, 'business-record-list-live');
    await preservePreSession6VisualSnapshot(page);
    await expect(page).toHaveScreenshot('administrator-generated-business-list.png', {
      fullPage: true,
      mask: [page.locator('.business-record-table tbody')],
      maskColor: '#d9e2e8',
    });
    const pageSearch = page.getByLabel('Filter this page');
    await pageSearch.fill('does-not-match-any-seeded-record');
    await expect(page.getByText('No records on this page match your search.')).toBeVisible();
    await pageSearch.clear();
    await page.locator('input[name="bulk_records[]"]:visible').first().check();
    await page.getByLabel('Bulk operation').selectOption('archive');
    await page.getByRole('button', { name: 'Review bulk operation' }).click();
    await expect(page.getByRole('heading', { name: 'Archive selected records' })).toBeVisible();
    await expect(page.locator('input[name="operation_id"]')).not.toHaveValue('');
    await expect(page.locator('input[name="confirmed"]')).toHaveValue('1');
    await page.goBack();
  });

  test('business list export control queues a CSV export that completes and downloads', async ({
    page,
  }) => {
    test.setTimeout(120_000);
    await page.goto(`/administrator/business/${businessDefinitionHandle}`);
    const exportForm = page.locator(
      `form[action="/administrator/reports/core.record-export.${businessDefinitionHandle}/exports"]`,
    );
    await exportForm.getByRole('button', { name: 'Queue CSV export', exact: true }).click();
    await expect(page).toHaveURL(
      `/administrator/reports/core.record-export.${businessDefinitionHandle}/exports`,
    );
    await expect(page.getByRole('heading', { name: 'Latest export request' })).toBeVisible();
    await expect(page.getByText('Queued', { exact: true })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Refresh status' })).toHaveAttribute(
      'href',
      /^\/administrator\/reports\/exports\/[0-9a-f-]{36}$/u,
    );
    await expect(async () => {
      await page.getByRole('link', { name: 'Refresh status' }).click();
      await expect(page.getByText('Completed', { exact: true })).toBeVisible({ timeout: 2_000 });
    }).toPass({ timeout: 60_000, intervals: [1_000] });
    const download = page.getByRole('link', { name: 'Download verified CSV' });
    await expect(download).toBeVisible();
    const href = await download.getAttribute('href');
    expect(href).toMatch(/^\/administrator\/reports\/exports\/[0-9a-f-]{36}\/download$/u);
    const csv = await page.request.get(String(href));
    expect(csv.status()).toBe(200);
    expect(csv.headers()['content-type']).toContain('text/csv');
    const body = await csv.text();
    expect(body).toContain('"Name"');
    expect(body).toContain('"Windhoek order"');
  });

  test('generated operation status and custom views are accessible and bounded', async ({
    page,
  }, testInfo) => {
    test.setTimeout(90_000);
    const expectBoundedGeneratedSurface = async (attachment: string): Promise<void> => {
      await expectAccessible(page);
      const diagnostics = await expectNoDocumentOverflow(page, {
        root: '#administrator-content',
        detectControlOverlaps: false,
      });
      expect(diagnostics.findings, JSON.stringify(diagnostics, null, 2)).toEqual([]);
      await attachLiveInterfaceScreenshot(page, testInfo, attachment);
    };

    await page.goto(`/administrator/business/${businessDefinitionHandle}?new=1`);
    const name = `Operation evidence ${testInfo.project.name} ${Date.now()}`;
    await fillSession5OrderForm(page, name);
    await page.getByRole('button', { name: 'Create record' }).click();
    await expect(page).toHaveURL(new RegExp(
      `/administrator/business/${businessDefinitionHandle}/[^?]+\\?saved=1&completed_operation=`,
    ));
    await page.getByRole('link', { name: 'View operation status' }).click();
    await expect(page.getByRole('heading', { level: 1, name: 'Operation status' })).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Operation completed' })).toBeVisible();
    await expect(page.locator('[data-kis-component="generated-operation-status"]'))
      .toHaveAttribute('data-kis-surface', 'core.administrator.generated-operation');
    await expect(page.getByRole('link', { name: 'Return to record', exact: true })).toBeVisible();
    await expectBoundedGeneratedSurface('administrator-operation-status');

    await page.goto(`/administrator/business/${assetInspectionDefinition}`);
    const customView = page.getByRole('link', { name: 'Inspection risk summary' });
    await expect(customView).toBeVisible();
    await customView.click();
    await expect(page.getByRole('heading', { level: 1, name: 'Inspection risk summary' })).toBeVisible();
    await expect(page.locator('[data-kis-component="generated-custom-view"]'))
      .toHaveAttribute('data-kis-surface', 'core.administrator.generated-custom-view');
    await expect(page.getByRole('heading', { name: 'View result' })).toBeVisible();
    await page.getByLabel('Rows available to the view').selectOption('25');
    await page.getByRole('button', { name: 'Run view' }).click();
    await expect(page).toHaveURL(/page_size=25/u);
    await expect(page.getByText('Policy-filtered inspection risk summary')).toBeVisible();
    await expect(page.getByText('BROWSER-INSPECT-001')).toBeVisible();
    await expect(page.getByText('BROWSER-POLICY-DENIED', { exact: true })).toHaveCount(0);
    await expect(page.getByText('Browser report restricted note', { exact: true })).toHaveCount(0);
    const restricted = page.locator('.kis-business-custom-object > div').filter({
      has: page.getByText('Restricted fields disclosed', { exact: true }),
    });
    await expect(restricted.locator('dd')).toHaveText('No');
    await expectBoundedGeneratedSurface('administrator-custom-view');
  });

  test('generated business detail and confirmation remain accessible and visually stable', async ({
    page,
  }, testInfo) => {
    await page.goto(
      `/administrator/business/${businessDefinitionHandle}/${windhoekOrderId}`,
    );
    await expect(page.getByRole('heading', { name: 'Record details' })).toBeVisible();
    await expectAccessible(page);
    await attachLiveInterfaceScreenshot(page, testInfo, 'business-record-detail');
    await preservePreSession6VisualSnapshot(page);
    await expect(page).toHaveScreenshot('administrator-generated-business-detail.png', {
      fullPage: true,
      mask: [page.locator('time')],
      maskColor: '#d9e2e8',
    });
    await page.getByRole('link', { name: 'Archive record' }).click();
    await expect(page.getByRole('heading', { name: 'Archive record' })).toBeVisible();
    const confirm = page.getByRole('button', { name: 'Archive record' });
    await expect(confirm).toBeDisabled();
    await page.getByRole('checkbox').check();
    await expect(confirm).toBeEnabled();
    await expectAccessible(page);
    await attachLiveInterfaceScreenshot(page, testInfo, 'business-record-confirmation');
    await preservePreSession6VisualSnapshot(page);
    await expect(page).toHaveScreenshot('administrator-generated-business-confirmation.png', {
      fullPage: true,
    });
  });

  test('declared document views render records as documents inside the shell', async ({
    page,
  }, testInfo) => {
    await page.goto(`/administrator/business/${browserInvoiceDefinition}/${browserInvoiceId}`);
    await expect(page.getByRole('heading', { level: 1, name: 'Browser invoice INV-BROWSER-001' }))
      .toBeVisible();
    const article = page.locator('.kis-business-document');
    await expect(article).toBeVisible();
    await expect(article.getByText('INV-BROWSER-001', { exact: true })).toBeVisible();
    expect(await article.innerText()).not.toMatch(/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}/u);
    const table = article.locator('.kis-business-document-table');
    await expect(table.getByRole('columnheader', { name: 'Description' })).toBeVisible();
    await expect(table.locator('tbody tr')).toHaveCount(3);
    await expect(article.locator('.kis-business-document-totals')).toContainText('Total');
    await expect(page.getByRole('link', { name: /Relations/ })).toBeVisible();
    await expectStylesLoaded(page);
    await expectAccessible(page);
    expect(await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth)).toBe(0);
    await attachLiveInterfaceScreenshot(page, testInfo, 'administrator-business-document-live');
    await expect(page).toHaveScreenshot('administrator-business-document.png', {
      fullPage: true,
    });
    await page.emulateMedia({ media: 'print' });
    await expect(page.locator('.kis-business-document-chrome').first()).toBeHidden();
    await expect(article).toBeVisible();
    await page.emulateMedia({ media: 'screen' });
  });

  test('generated relationship selectors and owned lines persist and reorder', async ({
    page,
    isMobile,
  }, testInfo) => {
    // KIS-EVIDENCE-BEGIN p6-002-record-relations-ui
    test.setTimeout(90_000);
    await page.goto(`/administrator/business/${businessDefinitionHandle}`);
    await page.getByRole('link', { name: /Create session 5 order/i }).click();
    await attachLiveInterfaceScreenshot(page, testInfo, 'business-record-form-live');
    await preservePreSession6VisualSnapshot(page);
    await expect(page).toHaveScreenshot('administrator-generated-business-form.png', {
      fullPage: true,
    });
    await expect(page.locator('[name="values[conditional_note]"]')).toHaveCount(0);
    await expect(page.locator('[name="values[display_name]"]')).toHaveCount(0);
    await expect(page.locator('[name="values[credential]"]')).toHaveAttribute('type', 'password');
    const name = `Relationship order ${testInfo.project.name} ${Date.now()}`;
    await fillSession5OrderForm(page, name);
    await page.getByRole('button', { name: 'Create record' }).click();
    await expectAdministratorRecordName(page, name);
    await expect(page.getByText('Stored conditional note', { exact: true })).toBeVisible();
    await expect(page.getByText('browser-secret-value', { exact: true })).toHaveCount(0);
    const recordUrl = new URL(page.url()).pathname;

    await page.getByRole('link', { name: 'Edit', exact: true }).click();
    await expect(page.locator('[name="values[conditional_note]"]')).toBeVisible();
    await expect(page.locator('[name="values[display_name]"]')).toHaveCount(0);
    await expect(page.locator('[name="values[credential]"]')).toHaveAttribute('type', 'password');
    await page.goto(recordUrl);

    let tags = page.locator('article.business-relation').filter({
      has: page.getByRole('heading', { name: 'Tags', exact: true }),
    });
    await tags.getByRole('link', { name: 'View and manage' }).click();
    tags = page.locator('article.business-relation').filter({
      has: page.getByRole('heading', { name: 'Tags', exact: true }),
    });
    await tags.getByRole('link', { name: 'Search available records' }).click();
    await expect(page.getByRole('heading', { name: 'Choose tags' })).toBeVisible();
    await expect(page.locator('[data-kis-component="generated-choice-browser"]'))
      .toHaveAttribute('data-kis-surface', 'core.administrator.generated-choices');
    await expectAccessible(page);
    const choiceDiagnostics = await expectNoDocumentOverflow(page, {
      root: '#administrator-content',
      detectControlOverlaps: false,
    });
    expect(choiceDiagnostics.findings, JSON.stringify(choiceDiagnostics, null, 2)).toEqual([]);
    await attachLiveInterfaceScreenshot(page, testInfo, 'administrator-relationship-chooser');
    const choiceLayout = await page.locator('.business-choice-table').evaluate((table) => ({
      viewportWidth: window.innerWidth,
      rootOverflow: document.documentElement.scrollWidth - window.innerWidth,
      tableOverflow: table.scrollWidth - table.clientWidth,
    }));
    expect(choiceLayout.viewportWidth).toBe(isMobile ? 412 : 1440);
    expect(choiceLayout.rootOverflow).toBe(0);
    if (isMobile) {
      expect(choiceLayout.tableOverflow).toBeGreaterThan(0);
    }
    const windhoekChoice = page.getByRole('row', { name: /Windhoek relationship target/ });
    const walvisBayChoice = page.getByRole('row', { name: /Walvis Bay relationship target/ });
    await expect(windhoekChoice).toBeVisible();
    await expect(walvisBayChoice).toBeVisible();
    const chooseWindhoek = windhoekChoice.getByRole('link', { name: 'Choose' });
    await chooseWindhoek.click();
    await tags.locator('select[name="target_record_id"]').selectOption(windhoekTargetId);
    await tags.getByRole('button', { name: 'Add relationship' }).click();
    await expect(tags.locator('.business-related-records > li').filter({
      hasText: 'Windhoek relationship target',
    })).toBeVisible();

    await page.getByRole('link', { name: 'Back to record' }).click();
    let lines = page.locator('article.business-relation').filter({
      has: page.getByRole('heading', { name: 'Lines', exact: true }),
    });
    await lines.getByRole('link', { name: 'View and manage' }).click();
    lines = page.locator('article.business-relation').filter({
      has: page.getByRole('heading', { name: 'Lines', exact: true }),
    });
    let lineForm = lines.locator('form.business-owned-line-form');
    await lineForm.locator('[name="target_values[description]"]').fill('Browser line one');
    await lineForm.locator('[name="target_values[units]"]').fill('1.000');
    await lineForm.getByRole('button', { name: 'Add Neutral line' }).click();
    lineForm = lines.locator('form.business-owned-line-form');
    await lineForm.locator('[name="target_values[description]"]').fill('Browser line two');
    await lineForm.locator('[name="target_values[units]"]').fill('2.000');
    await lineForm.getByRole('button', { name: 'Add Neutral line' }).click();

    const orderedItems = lines.locator('[data-business-order-list] li');
    await expect(orderedItems).toHaveCount(2);
    const originalIds = await orderedItems.evaluateAll((items) =>
      items.map((item) => item.getAttribute('data-record-id') ?? ''),
    );
    const firstLineId = originalIds[0];
    const secondLineId = originalIds[1];
    if (!firstLineId || !secondLineId) {
      throw new Error('The generated owned-line order is incomplete.');
    }
    await orderedItems.nth(1).getByRole('button', { name: /^Move .* up$/ }).click();
    const orderControls = lines.locator('select[name="ordered_record_ids[]"]');
    await expect(orderControls).toHaveCount(2);
    await expect.poll(() => orderControls.evaluateAll((controls) =>
      controls.map((control) => (control as HTMLSelectElement).value),
    )).toEqual([secondLineId, firstLineId]);
    await lines.getByRole('button', { name: 'Save order' }).click();
    await expect(lines.locator('[data-business-order-list] li').first())
      .toHaveAttribute('data-record-id', secondLineId);
    await expectAccessible(page);
    const ownedLineDiagnostics = await expectNoDocumentOverflow(page, {
      root: '#administrator-content',
      detectControlOverlaps: false,
    });
    expect(ownedLineDiagnostics.findings, JSON.stringify(ownedLineDiagnostics, null, 2)).toEqual([]);
    await attachLiveInterfaceScreenshot(page, testInfo, 'administrator-owned-lines');
    // KIS-EVIDENCE-END p6-002-record-relations-ui
  });

  test('generated business forms create, update and retain history without JavaScript', async ({
    browser,
  }, testInfo) => {
    // KIS-EVIDENCE-BEGIN p6-002-history-ui
    test.setTimeout(120_000);
    const context = await browser.newContext({ javaScriptEnabled: false });
    const page = await context.newPage();
    try {
      await signIn(page);
      await page.goto(
        '/administrator/business-definitions?definition=kumwe.asset-inspection-example.inspection&tab=fields',
      );
      await expect(page.locator('html')).not.toHaveClass(/\bjs\b/);
      await expect(page.locator('[data-kis-tab-panel]')).toHaveCount(7);
      for (const panel of await page.locator('[data-kis-tab-panel]').all()) await expect(panel).toBeVisible();
      await expect(page.getByRole('tab', { name: 'Fields' })).toHaveAttribute('href', /tab=fields/);
      await page.goto('/administrator/business-schema-plans?tab=operations');
      await expect(page.locator('[data-kis-tab-panel]')).toHaveCount(6);
      for (const panel of await page.locator('[data-kis-tab-panel]').all()) await expect(panel).toBeVisible();

      await page.goto(`/administrator/business/${businessDefinitionHandle}`);
      await expect(page.locator('html')).not.toHaveClass(/\bjs\b/);
      await page.getByRole('link', { name: /Create session 5 order/i }).click();
      const form = page.locator('form.business-record-form');
      await expect(form).toHaveAttribute('method', 'post');
      await expect(form.locator('input[name="_csrf"]')).not.toHaveValue('');
      await expect(form.locator('input[name="operation_id"]')).not.toHaveValue('');
      await expect(form.getByRole('button', { name: 'Create record' })).toBeVisible();
      const name = `No JS administrator order ${testInfo.project.name} ${Date.now()}`;
      await fillSession5OrderForm(page, name);
      await form.getByRole('button', { name: 'Create record' }).click();
      await expect(page).toHaveURL(new RegExp(
        `/administrator/business/${businessDefinitionHandle}/[^?]+\\?saved=1&completed_operation=`,
      ));
      await expectAdministratorRecordName(page, name);
      await expect(page.getByText('browser-secret-value', { exact: true })).toHaveCount(0);
      await page.getByRole('link', { name: 'View operation status' }).click();
      await expect(page.getByRole('heading', { level: 1, name: 'Operation status' })).toBeVisible();
      await expect(page.getByRole('heading', { name: 'Operation completed' })).toBeVisible();
      await page.getByRole('link', { name: 'Return to record', exact: true }).click();
      await page.getByRole('link', { name: 'Edit', exact: true }).click();
      const updatedName = `${name} updated`;
      await page.locator('[name="values[name]"]').fill(updatedName);
      await page.locator('[name="values[credential]"]').fill('browser-secret-updated');
      await page.getByRole('button', { name: 'Save changes' }).click();
      await expectAdministratorRecordName(page, updatedName);
      await page.getByRole('link', { name: 'History', exact: true }).click();
      await expect(page.getByRole('heading', { level: 1, name: 'Record history' })).toBeVisible();
      await expect(page.getByText('update', { exact: true }).first()).toBeVisible();
    } finally {
      await context.close();
    }
    // KIS-EVIDENCE-END p6-002-history-ui
  });

  test('generated business bulk lifecycle completes without JavaScript', async ({
    browser,
  }, testInfo) => {
    // KIS-EVIDENCE-BEGIN p6-002-bulk-ui
    test.setTimeout(120_000);
    const context = await browser.newContext({ javaScriptEnabled: false });
    const page = await context.newPage();
    try {
      await signIn(page);
      await page.goto(`/administrator/business/${businessDefinitionHandle}?new=1`);
      const name = `No JS administrator bulk ${testInfo.project.name} ${Date.now()}`;
      await fillSession5OrderForm(page, name);
      await page.getByRole('button', { name: 'Create record' }).click();
      await expectAdministratorRecordName(page, name);
      await page.goto(`/administrator/business/${businessDefinitionHandle}`);
      await page.getByLabel('Search records').fill(name);
      await page.getByRole('button', { name: 'Apply', exact: true }).click();
      await expectAdministratorRecordRow(page, name);
      await page.locator('input[name="bulk_records[]"]:visible').first().check();
      await page.getByLabel('Bulk operation').selectOption('archive');
      await page.getByRole('button', { name: 'Review bulk operation' }).click();
      await page.getByRole('checkbox').check();
      await page.getByRole('button', { name: 'Archive selected records' }).click();
      await expect(page).toHaveURL(new RegExp(
        `/administrator/business/${businessDefinitionHandle}\\?saved=1&bulk_count=1$`,
      ));
      await expect(page.getByText('The bulk operation completed for 1 record.')).toBeVisible();
      await page.getByLabel('Search records').fill(name);
      await page.getByText('Filters, sorting and lifecycle', { exact: true }).click();
      await expect(page.getByLabel('Include archived')).toBeVisible();
      await page.getByLabel('Include archived').check();
      await page.getByRole('button', { name: 'Apply', exact: true }).click();
      await expectAdministratorRecordRow(page, name);
      await page.locator('input[name="bulk_records[]"]:visible').first().check();
      await page.getByLabel('Bulk operation').selectOption('restore');
      await page.getByRole('button', { name: 'Review bulk operation' }).click();
      await page.getByRole('checkbox').check();
      await page.getByRole('button', { name: 'Restore selected records' }).click();
      await expect(page.getByText('The bulk operation completed for 1 record.')).toBeVisible();
    } finally {
      await context.close();
    }
    // KIS-EVIDENCE-END p6-002-bulk-ui
  });

  test('typed component navigation opens an accessible graphical page', async ({
    page,
    isMobile,
  }, testInfo) => {
    // KIS-EVIDENCE-BEGIN p6-003-extension-use-ui
    await ensureAnnouncementsActive(page);
    await page.goto('/administrator');
    if (isMobile) {
      await page.getByRole('button', { name: 'Open administrator navigation' }).click();
    }
    const workspace = page.locator('.navigation-group').filter({
      has: page.getByRole('heading', { name: 'Announcements', exact: true }),
    });
    const announcements = workspace.getByRole('link', { name: 'Announcements' });
    await expect(announcements).toBeVisible();
    if (isMobile) {
      await announcements.focus();
      await announcements.press('Enter');
    } else {
      await announcements.click();
    }
    await expect(page).toHaveURL(/\/administrator\/extensions\/kumwe\/announcements-example$/);
    await expect(page.getByRole('heading', { level: 1, name: 'Announcements' })).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Component announcements' })).toBeVisible();
    await expect(page.getByText('Contribution contract active')).toBeVisible();
    await expectStylesLoaded(page);
    await expectAccessible(page);
    const diagnostics = await expectNoDocumentOverflow(page, {
      root: '#administrator-content',
      detectControlOverlaps: false,
    });
    expect(diagnostics.findings, JSON.stringify(diagnostics, null, 2)).toEqual([]);
    await attachLiveInterfaceScreenshot(page, testInfo, 'announcements-contribution');
    // KIS-EVIDENCE-END p6-003-extension-use-ui
  });

  test('extensions screen maps each contribution to a linked destination', async ({ page }) => {
    await ensureAnnouncementsActive(page);

    const announcements = page.locator('article').filter({ hasText: 'kumwe/announcements-example' }).first();
    const announcementsMap = announcements.locator('.kis-phase-five-contribution-map');
    await expect(announcementsMap.getByRole('heading', { name: 'Where this extension appears' })).toBeVisible();
    const screenLink = announcementsMap.locator('a[href="/administrator/extensions/kumwe/announcements-example"]');
    await expect(screenLink).toHaveText('Announcements');
    await expect(announcementsMap.locator(
      'a[href="/administrator/business/kumwe.announcements-example.category"]',
    )).toHaveText('Announcement categories');
    await expect(announcementsMap.locator('a[href="/administrator/access"]'))
      .toHaveText('kumwe.announcements-example.manage');
    await expect(announcementsMap.getByText('granted to no role automatically').first()).toBeVisible();

    const inspection = page.locator('article').filter({ hasText: 'kumwe/asset-inspection-example' }).first();
    const inspectionMap = inspection.locator('.kis-phase-five-contribution-map');
    await expect(inspectionMap.locator(
      'a[href="/administrator/extensions/kumwe/asset-inspection-example"]',
    )).toHaveText('Asset inspection example');
    await expect(inspectionMap.locator(
      'a[href="/portal/extensions/kumwe/asset-inspection-example"]',
    )).toHaveText('Inspection status');
    await expect(inspectionMap.locator(
      'a[href="/administrator/reports/kumwe.asset-inspection-example.inspection-summary"]',
    )).toHaveText('Asset inspection example summary');
    await expect(inspectionMap.getByText('kumwe.asset-inspection-example.review-overdue-daily')).toBeVisible();
    await expect(inspectionMap.getByText('15 2 * * *', { exact: false }).first()).toBeVisible();
    await expect(
      inspectionMap.getByText('kumwe.asset-inspection-example.inspection-mutation-validator'),
    ).toBeVisible();
    await expectStylesLoaded(page);
    await expectAccessible(page);
    const diagnostics = await expectNoDocumentOverflow(page, {
      root: '#administrator-content',
      detectControlOverlaps: false,
    });
    expect(diagnostics.findings, JSON.stringify(diagnostics, null, 2)).toEqual([]);

    await screenLink.click();
    await expect(page).toHaveURL(/\/administrator\/extensions\/kumwe\/announcements-example$/);
    await expect(page.getByRole('heading', { level: 1, name: 'Announcements' })).toBeVisible();

    await page.goto('/administrator/extensions');
    await inspectionMap.locator(
      'a[href="/administrator/business/kumwe.asset-inspection-example.inspection"]',
    ).click();
    await expect(page).toHaveURL(/\/administrator\/business\/kumwe\.asset-inspection-example\.inspection$/);
  });

  test('component navigation and guarded route are unavailable without its capability', async ({
    page,
    isMobile,
  }) => {
    await ensureAnnouncementsActive(page);
    await page.context().clearCookies();
    await signIn(page, limitedEmail, limitedPassword);
    if (isMobile) {
      await page.getByRole('button', { name: 'Open administrator navigation' }).click();
    }
    await expect(page.getByRole('link', { name: 'Announcements' })).toHaveCount(0);
    await expect(page.getByRole('link', { name: 'Schema plans', exact: true })).toHaveCount(0);
    const schemaResponse = await page.goto('/administrator/business-schema-plans');
    expect(schemaResponse?.status()).toBe(403);
    const generatedBusinessResponse = await page.goto(
      `/administrator/business/${businessDefinitionHandle}`,
    );
    expect(generatedBusinessResponse?.status()).toBe(403);
    expect(generatedBusinessResponse?.headers()['content-type'] ?? '').toContain('text/html');
    await expect(
      page.getByRole('heading', { name: 'You do not have access to this screen' }),
    ).toBeVisible();
    await expect(page.getByText('Windhoek order', { exact: true })).toHaveCount(0);
    await expect(page.getByText('Walvis Bay order', { exact: true })).toHaveCount(0);
    const response = await page.goto('/administrator/extensions/kumwe/announcements-example');
    expect(response?.status()).toBe(403);
  });

  test('disabling removes component navigation and reactivation restores it', async ({
    page,
    isMobile,
  }, testInfo) => {
    // KIS-EVIDENCE-BEGIN p6-003-extension-reactivation-ui
    test.setTimeout(120_000);
    let dashboardMutated = false;
    let extensionDisabled = false;
    await ensureAnnouncementsActive(page);
    await resetPersonalAdministratorDashboard(page);
    try {
      try {
        await page.goto(announcementsDashboardHref);
        const customization = page.locator('#dashboard-customization');
        await expect(customization).toHaveAttribute('open', '');
        const personalDashboard = customization.locator('.kis-dashboard-preference-scope').filter({
          has: page.locator('input[name="scope"][value="user"]'),
        }).first();
        const widgetForm = personalDashboard.locator('form').filter({
          has: page.locator('button[value="dashboard-cards.save"]'),
        });
        const widgetChoice = widgetForm.locator('.kis-dashboard-choice').filter({
          has: page.locator(
            'input[type="hidden"][value="kumwe.announcements-example.navigation"]',
          ),
        });
        for (const checkbox of await widgetForm.locator('input[type="checkbox"]').all()) {
          await checkbox.uncheck();
        }
        await widgetChoice.locator('input[type="checkbox"]').check();
        await widgetChoice.locator('input[type="number"]').fill('1');
        await widgetForm.getByRole('button', { name: 'Save widgets' }).click();
        dashboardMutated = true;

        const shortcutForm = personalDashboard.locator('form').filter({
          has: page.locator('button[value="navigation-shortcuts.save"]'),
        });
        const shortcutChoice = shortcutForm.locator('.kis-dashboard-choice').filter({
          has: page.locator(
            'input[type="hidden"][value="kumwe.announcements-example.navigation"]',
          ),
        });
        for (const checkbox of await shortcutForm.locator('input[type="checkbox"]').all()) {
          await checkbox.uncheck();
        }
        await shortcutChoice.locator('input[type="checkbox"]').check();
        await shortcutChoice.locator('input[type="number"]').fill('1');
        await shortcutForm.getByRole('button', { name: 'Save quick links' }).click();

        await expect(page.locator(
          '[data-kis-dashboard-widget="kumwe.announcements-example.navigation"]',
        )).toBeVisible();
        await expect(page.locator(
          '.kis-dashboard-shortcut-list a[href="/administrator/extensions/kumwe/announcements-example"]',
        )).toBeVisible();

        await page.goto('/administrator/extensions');
        const extension = page.locator('article').filter({
          hasText: 'kumwe/announcements-example',
        }).first();
        await extension.getByRole('button', { name: 'Disable' }).click();
        extensionDisabled = true;
        await expect(page).toHaveURL(/\/administrator\/extensions$/);
        await waitForAnnouncementsStatus(page, 'disabled');
        await expect(extension).toContainText(/component · 2\.0\.0 · disabled/);

        await expect.poll(async () => {
          await page.goto(announcementsDashboardPollHref);
          return page.getByRole('link', { name: 'Announcements', exact: true }).count();
        }, {
          message: 'the disabled extension navigation to leave the local signed runtime map',
          timeout: 25_000,
        }).toBe(0);
        if (isMobile) {
          await page.getByRole('button', { name: 'Open administrator navigation' }).click();
        }
        await expect(page.getByRole('link', { name: 'Announcements' })).toHaveCount(0);
        await expect(page.locator(
          '[data-kis-dashboard-widget="kumwe.announcements-example.navigation"]',
        )).toHaveCount(0);
        await expect(page.locator(
          '.kis-dashboard-shortcut-list a[href="/administrator/extensions/kumwe/announcements-example"]',
        )).toHaveCount(0);
        await expect(page.locator(
          '[data-kis-dashboard-widget="core.dashboard.content-summary"]',
        )).toBeVisible();
        await expect(page.getByText(
          'This dashboard was adjusted to the current permitted catalogue and preference limits.',
          { exact: true },
        )).toBeVisible();
        await expect(personalDashboard.locator(
          'input[type="hidden"][name^="item_"]'
            + '[value="kumwe.announcements-example.navigation"]',
        )).toHaveCount(0);
      } finally {
        if (testInfo.status !== 'timedOut' && extensionDisabled && !page.isClosed()) {
          await ensureAnnouncementsActive(page);
          extensionDisabled = false;
        }
      }
      await expect.poll(async () => {
        await page.goto(announcementsDashboardPollHref);
        return page.locator(
          '[data-kis-dashboard-widget="kumwe.announcements-example.navigation"]',
        ).count();
      }, {
        message: 'the reactivated extension workflow to recover its stored dashboard selection',
        timeout: 25_000,
      }).toBe(1);
      await expect(page.locator(
        '.kis-dashboard-shortcut-list a[href="/administrator/extensions/kumwe/announcements-example"]',
      )).toBeVisible();
      const extensionDashboardChoices = page.locator('.kis-dashboard-preference-scope').filter({
        has: page.locator('input[name="scope"][value="user"]'),
      }).first().locator(
        'input[type="hidden"][name^="item_"]'
          + '[value="kumwe.announcements-example.navigation"]',
      );
      await expect(extensionDashboardChoices).toHaveCount(2);
      if (isMobile) {
        await page.getByRole('button', { name: 'Open administrator navigation' }).click();
      }
      await expect(page.getByRole('link', { name: 'Announcements', exact: true })).toBeVisible();
    } finally {
      try {
        if (testInfo.status !== 'timedOut' && extensionDisabled && !page.isClosed()) {
          await ensureAnnouncementsActive(page);
        }
      } finally {
        if (
          testInfo.status !== 'timedOut'
          && dashboardMutated
          && !page.isClosed()
        ) {
          await resetPersonalAdministratorDashboard(page);
        }
      }
    }
    // KIS-EVIDENCE-END p6-003-extension-reactivation-ui
  });
});
