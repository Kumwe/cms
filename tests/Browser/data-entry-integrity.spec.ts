import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page, type TestInfo } from '@playwright/test';
import { preferStructuredContentForm } from './support/studio-authoring';

const administratorEmail = process.env.KUMWE_BROWSER_ADMIN_EMAIL ?? 'browser-administrator@kumwe.test';
const administratorPassword = process.env.KUMWE_BROWSER_ADMIN_PASSWORD ?? 'browser administrator password';
const portalEmail = process.env.KUMWE_BROWSER_PORTAL_EMAIL ?? 'browser-portal@kumwe.test';
const portalPassword = process.env.KUMWE_BROWSER_PORTAL_PASSWORD ?? 'browser portal password';
const businessDefinitionHandle = 'site.default.session5_order';

async function signInToAdministrator(page: Page): Promise<void> {
  await page.goto('/administrator/login');
  await page.getByLabel('Email address').fill(administratorEmail);
  await page.getByLabel('Password').fill(administratorPassword);
  await page.getByRole('button', { name: 'Sign in to Kumwe' }).click();
  await expect(page).toHaveURL(/\/administrator$/u);
}

async function signInToPortal(page: Page): Promise<void> {
  await page.goto('/portal/login');
  await page.getByLabel('Email address').fill(portalEmail);
  await page.getByLabel('Password').fill(portalPassword);
  await page.getByLabel('Workspace').fill('north');
  await page.getByRole('button', { name: 'Sign in' }).click();
  await expect(page).toHaveURL(/\/portal$/u);
}

/** Complete every required control of the neutral generated order form. */
async function fillOrderForm(page: Page, name: string, credential: string): Promise<void> {
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
  await page.locator('[name="values[scheduled_for][instant]"]').fill('2026-08-10T11:14:15.123456Z');
  await page.locator('[name="values[scheduled_for][timezone]"]').fill('Africa/Windhoek');
  await page.locator('[name="values[credential]"]').fill(credential);
}

/** Author one order through the generated form and return the record path it landed on. */
async function createOrder(page: Page, basePath: string, name: string): Promise<string> {
  await page.goto(`${basePath}/${businessDefinitionHandle}`);
  await page.getByRole('link', { name: /Create session 5 order/i }).click();
  await fillOrderForm(page, name, 'retention-secret-value');
  await page.getByRole('button', { name: 'Create record' }).click();
  await expect(page).toHaveURL(new RegExp(`${basePath}/${businessDefinitionHandle}/[^?]+\\?`, 'u'));

  return new URL(page.url()).pathname;
}

function unique(testInfo: TestInfo, label: string): string {
  return `${label} ${testInfo.project.name} ${Date.now()}`;
}

async function expectAccessible(page: Page): Promise<void> {
  const scan = await new AxeBuilder({ page })
    .withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa'])
    .analyze();
  expect(scan.violations, JSON.stringify(scan.violations, null, 2)).toEqual([]);
}

test.describe('data-entry integrity', () => {
  test('an administrator record edit survives a conflicting save by another actor', async ({
    page,
    context,
  }, testInfo) => {
    test.slow();
    await signInToAdministrator(page);
    const name = unique(testInfo, 'Retention admin order');
    const recordPath = await createOrder(page, '/administrator/business', name);

    // The operator opens the record and starts a long edit.
    await page.goto(`${recordPath}?edit=1`);
    const openedVersion = await page.locator('input[name="expected_version"]').inputValue();
    const typedName = `${name} rewritten by me`;
    await page.locator('[name="values[name]"]').fill(typedName);
    await page.locator('[name="values[quantity][amount]"]').fill('7.000000000000000000000000000000');
    await page.locator('[name="values[service_date]"]').fill('2026-08-11');
    await page.locator('[name="values[scheduled_for][timezone]"]').fill('Africa/Johannesburg');
    await page.locator('[name="values[credential]"]').fill('retention-secret-mine');

    // Somebody else saves the same record from another tab while that form is open.
    const other = await context.newPage();
    await other.goto(`${recordPath}?edit=1`);
    await other.locator('[name="values[name]"]').fill(`${name} moved by someone else`);
    await other.locator('[name="values[credential]"]').fill('retention-secret-theirs');
    await other.getByRole('button', { name: 'Save changes' }).click();
    await expect(other).toHaveURL(new RegExp(`${recordPath}(\\?|$)`, 'u'));
    await other.close();

    // The first operator saves. Nothing they typed may be lost.
    await page.getByRole('button', { name: 'Save changes' }).click();

    const conflict = page.locator('[data-kis-state="conflict"]');
    await expect(conflict).toBeVisible();
    await expect(conflict.getByRole('heading', { name: 'Another actor saved a newer version' }))
      .toBeVisible();
    await expect(page.locator('[name="values[name]"]')).toHaveValue(typedName);
    await expect(page.locator('[name="values[quantity][amount]"]'))
      .toHaveValue('7.000000000000000000000000000000');
    await expect(page.locator('[name="values[service_date]"]')).toHaveValue('2026-08-11');
    await expect(page.locator('[name="values[scheduled_for][timezone]"]'))
      .toHaveValue('Africa/Johannesburg');

    // The form now quotes the version the record actually carries, so saving again applies the work.
    const currentVersion = await page.locator('input[name="expected_version"]').inputValue();
    expect(Number(currentVersion)).toBeGreaterThan(Number(openedVersion));
    await expect(conflict.getByRole('link', { name: `Reload version ${currentVersion}` })).toBeVisible();
    await expectAccessible(page);

    // A write-only secret is deliberately never echoed back, so only that one control is re-entered.
    await page.locator('[name="values[credential]"]').fill('retention-secret-mine');
    await page.getByRole('button', { name: 'Save changes' }).click();
    await expect(page.locator('[data-kis-state="conflict"]')).toHaveCount(0);
    await page.goto(`${recordPath}?edit=1`);
    await expect(page.locator('[name="values[name]"]')).toHaveValue(typedName);
    await expect(page.locator('[name="values[service_date]"]')).toHaveValue('2026-08-11');
    await expect(page.locator('[name="values[scheduled_for][timezone]"]'))
      .toHaveValue('Africa/Johannesburg');
  });

  test('an administrator record edit survives a value the definition rejects', async ({
    page,
  }, testInfo) => {
    test.slow();
    await signInToAdministrator(page);
    const name = unique(testInfo, 'Retention rejected order');
    const recordPath = await createOrder(page, '/administrator/business', name);

    await page.goto(`${recordPath}?edit=1`);
    const typedName = `${name} rewritten by me`;
    await page.locator('[name="values[name]"]').fill(typedName);
    await page.locator('[name="values[service_date]"]').fill('2026-08-12');
    await page.locator('[name="values[credential]"]').fill('retention-secret-mine');
    // The definition's own minimum is the only thing rejecting this; no input attribute mirrors it.
    await page.locator('[name="values[amount]"]').fill('-1.000000000000000000000000000000');
    await page.getByRole('button', { name: 'Save changes' }).click();

    await expect(page.getByRole('heading', { name: 'The record could not be saved' })).toBeVisible();
    await expect(page.locator('[data-kis-state="conflict"]')).toHaveCount(0);
    await expect(page.locator('[name="values[name]"]')).toHaveValue(typedName);
    await expect(page.locator('[name="values[service_date]"]')).toHaveValue('2026-08-12');
    await expect(page.locator('[name="values[quantity][unit]"]')).toHaveValue('unit');
  });

  test('a portal record edit survives a conflict with JavaScript switched off', async ({
    browser,
  }, testInfo) => {
    test.slow();
    // The recovery must work where progressive enhancement cannot help: no scripting at all.
    const context = await browser.newContext({ javaScriptEnabled: false });
    const page = await context.newPage();
    try {
      await signInToPortal(page);
      const name = unique(testInfo, 'Retention portal order');
      const recordPath = await createOrder(page, '/portal/business', name);

      await page.goto(`${recordPath}?edit=1`);
      const typedName = `${name} rewritten by me`;
      await page.locator('[name="values[name]"]').fill(typedName);
      await page.locator('[name="values[service_date]"]').fill('2026-08-11');
      await page.locator('[name="values[credential]"]').fill('retention-secret-mine');

      const other = await context.newPage();
      await other.goto(`${recordPath}?edit=1`);
      await other.locator('[name="values[name]"]').fill(`${name} moved by someone else`);
      await other.locator('[name="values[credential]"]').fill('retention-secret-theirs');
      await other.getByRole('button', { name: 'Save changes' }).click();
      await expect(other).toHaveURL(new RegExp(`${recordPath}(\\?|$)`, 'u'));
      await other.close();

      await page.getByRole('button', { name: 'Save changes' }).click();

      const conflict = page.locator('[data-kis-state="conflict"]');
      await expect(conflict).toBeVisible();
      await expect(conflict.getByRole('heading', { name: 'Another actor saved a newer version' }))
        .toBeVisible();
      await expect(page.locator('[name="values[name]"]')).toHaveValue(typedName);
      await expect(page.locator('[name="values[service_date]"]')).toHaveValue('2026-08-11');
      await expect(page.locator('form.portal-business-form input[name="_csrf"]')).not.toHaveValue('');

      await page.locator('[name="values[credential]"]').fill('retention-secret-mine');
      await page.getByRole('button', { name: 'Save changes' }).click();
      await expect(page.locator('[data-kis-state="conflict"]')).toHaveCount(0);
      await page.goto(`${recordPath}?edit=1`);
      await expect(page.locator('[name="values[name]"]')).toHaveValue(typedName);
      await expect(page.locator('[name="values[service_date]"]')).toHaveValue('2026-08-11');
    } finally {
      await context.close();
    }
  });

  test('a content editor draft survives a conflicting save by another actor', async ({
    page,
    context,
  }) => {
    test.slow();
    await preferStructuredContentForm(context);
    await signInToAdministrator(page);
    const suffix = `${Date.now()}-${Math.floor(Math.random() * 10000)}`;
    const title = `Retention content ${suffix}`;
    const slug = `retention-content-${suffix}`;

    await page.goto('/administrator/content/new');
    await page.getByLabel('Title').fill(title);
    await page.getByLabel('URL slug').fill(slug);
    await page.getByRole('textbox', { name: 'Rich text editor' }).first().fill(`First body ${suffix}`);
    await page.getByRole('button', { name: 'Create draft' }).click();
    await expect(page).toHaveURL(/\/administrator\/content\/[0-9a-f-]+\/edit$/u);
    const editorPath = new URL(page.url()).pathname;

    // The editor rewrites the item at length.
    const rewrittenTitle = `${title} rewritten by me`;
    const rewrittenBody = `A long rewritten body ${suffix} that must not be lost.`;
    await page.getByLabel('Title').fill(rewrittenTitle);
    await page.getByRole('textbox', { name: 'Rich text editor' }).first().fill(rewrittenBody);

    // Another actor saves the same item from a second tab.
    const other = await context.newPage();
    await other.goto(editorPath);
    await other.getByLabel('Title').fill(`${title} moved by someone else`);
    await other.getByRole('button', { name: 'Save changes' }).click();
    await expect(other).toHaveURL(new RegExp(`${editorPath}$`, 'u'));
    await other.close();

    await page.getByRole('button', { name: 'Save changes' }).click();

    const conflict = page.locator('[data-kis-state="conflict"]');
    await expect(conflict).toBeVisible();
    await expect(conflict.getByRole('heading', { name: 'Another actor saved a newer version' }))
      .toBeVisible();
    await expect(page.getByLabel('Title')).toHaveValue(rewrittenTitle);
    await expect(page.getByLabel('URL slug')).toHaveValue(slug);
    await expect(page.locator('textarea[name="field__body"]')).toHaveValue(rewrittenBody);
    await expectAccessible(page);

    // Saving again applies the retained work to the version the other actor left behind.
    await page.getByRole('button', { name: 'Save changes' }).click();
    await expect(page).toHaveURL(new RegExp(`${editorPath}$`, 'u'));
    await expect(page.getByLabel('Title')).toHaveValue(rewrittenTitle);
    await expect(page.locator('textarea[name="field__body"]')).toHaveValue(rewrittenBody);
  });

  test('text the browser leaves unwrapped still reaches the required backing field', async ({ page }) => {
    // Engines disagree about what they leave at the top level of a contenteditable. Chromium wraps text
    // typed into an empty editor in a <div>; Firefox and WebKit leave a bare text node. Serializing only
    // element children dropped that text, so the required textarea stayed empty, native validation
    // refused the form, and the editor looked full while Create draft did nothing. Forcing the shape
    // here rather than typing means every engine exercises the path, not only the ones that produce it.
    await preferStructuredContentForm(page.context());
    await signInToAdministrator(page);
    await page.goto('/administrator/content/new');
    await expect(page.getByRole('textbox', { name: 'Rich text editor' }).first()).toBeVisible();

    const serialized = await page.evaluate(() => {
      const editor = document.querySelector('[data-rich-text-editor]');
      if (!(editor instanceof HTMLElement)) return '<no editor>';
      editor.replaceChildren(document.createTextNode('Left unwrapped by the browser'));
      editor.dispatchEvent(new Event('input', { bubbles: true }));
      const field = editor.closest('kumwe-rich-text')?.querySelector('textarea');
      return field instanceof HTMLTextAreaElement ? field.value : '<no textarea>';
    });

    expect(serialized).toBe('Left unwrapped by the browser');
  });
});
