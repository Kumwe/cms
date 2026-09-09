import { expect, type BrowserContext, type Page } from '@playwright/test';

/** The storage key the Content editor's start module reads for the surface an editor last chose. */
export const STUDIO_SURFACE_PREFERENCE_KEY = 'kumwe.studio.surface';

/** The settled outcomes of the Studio launch on a Content editor page. */
export type StudioLaunchOutcome = 'deferred' | 'ready' | 'failed' | 'error' | 'saved' | 'fallback';

/**
 * Make every page in the context open Content items on the structured form.
 *
 * The page builder is the Content editor's default surface: once the pinned Studio module has mounted,
 * the structured form is hidden behind the surface toggle. Journeys that prove the structured form must
 * say so before they navigate, exactly as an editor who pressed "Use the structured form" once would be
 * remembered; the page then opens on the form and defers the Studio mount until the page builder is
 * asked for, so neither the swap that follows the asynchronous mount nor a second copy of the content
 * fields inside the mount can race their field interactions. The preference is set on the context so a
 * second tab opened during the journey honours it as well.
 */
export async function preferStructuredContentForm(context: BrowserContext): Promise<void> {
  await context.addInitScript((key: string) => {
    try {
      window.localStorage.setItem(key, 'form');
    } catch {
      // Storage is unavailable in this profile; the launch module then keeps the page builder.
    }
  }, STUDIO_SURFACE_PREFERENCE_KEY);
}

/**
 * Wait until the Content editor's Studio launch has settled and answer how.
 *
 * A page without the launch region carries the structured-editor fallback notice instead; a page with
 * it moves the status from `pending` either to `deferred` (the form is in front and Studio waits for
 * the toggle) or through `loading` to `ready`, `failed` or `error`. Asserting layout or accessibility
 * before that point measures whichever surface the race has reached.
 */
export async function awaitStudioLaunchSettled(page: Page): Promise<StudioLaunchOutcome> {
  if ((await page.locator('[data-studio-authoring-fallback]').count()) > 0) {
    return 'fallback';
  }
  const status = page.locator('[data-studio-launch-status]');
  await expect(status).toHaveAttribute('data-studio-launch-state', /^(deferred|ready|failed|error|saved)$/u, {
    timeout: 30_000,
  });
  const state = await status.getAttribute('data-studio-launch-state');
  if (state === 'deferred' || state === 'ready' || state === 'failed' || state === 'error' || state === 'saved') {
    return state;
  }
  throw new Error(`The Studio launch settled with the unexpected state "${state ?? 'null'}".`);
}
