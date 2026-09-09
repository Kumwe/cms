/**
 * Start module for the contextual Studio mount on the Content editor.
 *
 * The pinned Studio browser module never scans the document on its own: importing it has no side
 * effect, and `autoMountStudio()` is the opt-in that discovers `[data-kumwe-studio]` targets and
 * mounts the Producer-emitted deployment next to each one. This module is that opt-in. It imports
 * the module from the exact URL PHP resolved (the page carries a `modulepreload` with the manifest
 * integrity for the same URL, so the module map already holds the integrity-checked bytes), mounts
 * every target, and owns what the shell deliberately leaves to the host: swapping between the
 * page builder and the structured form, dirty-state confirmation, and navigation when the shell
 * asks to return.
 *
 * The page builder is the default surface. An editor who switched to the structured form is
 * remembered across navigations, and `?surface=form` or `?surface=studio` names the surface
 * explicitly. A page that opens on the form defers the mount until the editor asks for the page
 * builder: a mount nobody looks at costs the module download and would leave a second copy of
 * every content field in the document.
 *
 * `autoMountStudio()` resolves only once a session is open. On a create target that offers more
 * than a blank start, Studio first attaches its create-source chooser to the mount and awaits the
 * editor's choice, so the surface is brought in front as soon as Studio attaches its first element
 * rather than when the mount promise settles; a hidden chooser could never be answered.
 *
 * The status element reports the launch through `data-studio-launch-state`: `pending` as rendered,
 * then `deferred` or `loading`, then `ready` or `failed`; Studio's own `error` and `saved` follow.
 */

const MOUNT_SELECTOR = '[data-kumwe-studio][data-studio-module-url]';

/** The elements Studio attaches to the mount: the create-source chooser, then the contextual shell. */
const STUDIO_ELEMENT_SELECTOR = 'kumwe-studio-hosted-start, kumwe-studio-contextual';

/** Where the editor's last surface choice is remembered. */
const SURFACE_PREFERENCE_KEY = 'kumwe.studio.surface';

interface StudioAutoMountFailure {
  readonly configurationElementId?: string;
  readonly error: unknown;
  readonly phase: 'configuration' | 'runtime';
  readonly target: HTMLElement;
}

interface StudioMountHandle {
  readonly element: HTMLElement;
  readonly target: HTMLElement;
  dispose(): Promise<void> | void;
}

interface StudioAutoMountReport {
  readonly discoveredTargetCount: number;
  readonly failures: readonly StudioAutoMountFailure[];
  readonly handles: readonly StudioMountHandle[];
  dispose(): Promise<void> | void;
}

interface StudioBrowserModule {
  readonly StudioAuthoringControlRegistry: new (options: { strictContentSecurityPolicy: boolean }) => unknown;
  autoMountStudio(options: {
    hosted: () => { authoringControlRegistry: unknown };
  }): Promise<StudioAutoMountReport>;
}

interface ReturnRequestDetail {
  readonly returnContext?: { key: string };
}

interface SaveCompleteDetail {
  readonly result?: {
    readonly session?: {
      readonly extensions?: Record<string, unknown>;
    };
  };
}

type Surface = 'studio' | 'form';

function isStudioBrowserModule(candidate: unknown): candidate is StudioBrowserModule {
  return (
    typeof candidate === 'object' &&
    candidate !== null &&
    typeof (candidate as { autoMountStudio?: unknown }).autoMountStudio === 'function' &&
    typeof (candidate as { StudioAuthoringControlRegistry?: unknown }).StudioAuthoringControlRegistry === 'function'
  );
}

function isSurface(candidate: unknown): candidate is Surface {
  return candidate === 'studio' || candidate === 'form';
}

function requestedSurface(): Surface | null {
  const requested = new URL(window.location.href).searchParams.get('surface');
  return isSurface(requested) ? requested : null;
}

function rememberedSurface(): Surface | null {
  try {
    const remembered = window.localStorage.getItem(SURFACE_PREFERENCE_KEY);
    return isSurface(remembered) ? remembered : null;
  } catch {
    return null;
  }
}

function rememberSurface(surface: Surface): void {
  try {
    window.localStorage.setItem(SURFACE_PREFERENCE_KEY, surface);
  } catch {
    // Storage may be unavailable; the choice then lasts for this page only.
  }
}

function returnPathOf(detail: SaveCompleteDetail | undefined): string | undefined {
  const extension = detail?.result?.session?.extensions?.['kumwe.app/return'];
  if (typeof extension !== 'object' || extension === null) return undefined;
  const path = (extension as { path?: unknown }).path;
  return typeof path === 'string' && path.startsWith('/administrator/') ? path : undefined;
}

export async function setupStudioLaunch(): Promise<void> {
  const mount = document.querySelector<HTMLElement>(MOUNT_SELECTOR);
  const region = mount?.closest<HTMLElement>('[data-studio-authoring-region]') ?? null;
  const form = document.querySelector<HTMLElement>('[data-studio-authoring-fallback-form]');
  const formShell = form?.closest<HTMLElement>('.editor-grid') ?? form;
  const status = region?.querySelector<HTMLElement>('[data-studio-launch-status]') ?? null;
  const toggle = region?.querySelector<HTMLButtonElement>('[data-studio-surface-toggle]') ?? null;
  if (mount === null || region === null || formShell === null || status === null || toggle === null) return;

  const moduleUrl = mount.dataset.studioModuleUrl ?? '';
  const returnPath = mount.dataset.studioReturnPath ?? '/administrator/content';
  let currentReturnPath = returnPath;

  const labels = {
    showForm: toggle.dataset.labelForm ?? 'Use the structured form',
    showStudio: toggle.dataset.labelStudio ?? 'Use the page builder',
    deferred: status.dataset.messageDeferred ?? 'The page builder loads when you switch to it.',
    failed: status.dataset.messageFailed ?? 'The Studio page builder could not start; the structured form remains available.',
    loading: status.textContent ?? '',
    ready: status.dataset.messageReady ?? 'The Studio page builder is ready.',
  };

  const requested = requestedSurface();
  if (requested !== null) rememberSurface(requested);
  let surface: Surface = requested ?? rememberedSurface() ?? 'studio';

  const show = (next: Surface): void => {
    surface = next;
    region.dataset.studioSurface = next;
    mount.hidden = next !== 'studio';
    formShell.hidden = next !== 'form';
    toggle.textContent = next === 'studio' ? labels.showForm : labels.showStudio;
    toggle.setAttribute('aria-pressed', next === 'form' ? 'true' : 'false');
  };

  let revealed = false;
  const reveal = (): void => {
    if (revealed) return;
    revealed = true;
    if (status.dataset.studioLaunchState === 'loading') {
      status.textContent = labels.ready;
      status.dataset.studioLaunchState = 'ready';
    }
    toggle.hidden = false;
    show(surface);
  };

  const attached = new MutationObserver(() => {
    if (mount.querySelector(STUDIO_ELEMENT_SELECTOR) === null) return;
    attached.disconnect();
    reveal();
  });

  const fail = (error: unknown): void => {
    attached.disconnect();
    status.textContent = labels.failed;
    status.dataset.studioLaunchState = 'failed';
    show('form');
    toggle.hidden = true;
    console.error('Studio page builder failed to mount.', error);
  };

  let launched = false;
  const launch = async (): Promise<void> => {
    if (launched) return;
    launched = true;
    status.textContent = labels.loading;
    status.dataset.studioLaunchState = 'loading';
    try {
      const imported: unknown = await import(/* @vite-ignore */ moduleUrl);
      if (!isStudioBrowserModule(imported)) {
        throw new TypeError('The Studio browser module does not export the hosted runtime.');
      }
      const registry = new imported.StudioAuthoringControlRegistry({ strictContentSecurityPolicy: true });
      attached.observe(mount, { childList: true });
      const report = await imported.autoMountStudio({
        hosted: () => ({ authoringControlRegistry: registry }),
      });
      attached.disconnect();
      if (report.failures.length > 0 || report.handles.length === 0) {
        fail(report.failures[0]?.error ?? new Error('No Studio target was mounted.'));
        return;
      }
      reveal();
    } catch (error) {
      fail(error);
    }
  };

  toggle.addEventListener('click', () => {
    const next: Surface = surface === 'studio' ? 'form' : 'studio';
    rememberSurface(next);
    show(next);
    if (next === 'studio') void launch();
  });

  mount.addEventListener('studio-contextual-return-request', (event) => {
    const detail = (event as CustomEvent<ReturnRequestDetail>).detail;
    if (detail?.returnContext === undefined) return;
    const dirty = mount.querySelector<HTMLElement>('kumwe-studio-contextual')?.getAttribute('data-dirty') === 'true';
    if (dirty && !window.confirm(status.dataset.messageDiscard ?? 'Discard unsaved changes and return?')) {
      event.preventDefault();
      return;
    }
    window.location.assign(currentReturnPath);
  });

  mount.addEventListener('studio-contextual-save-complete', (event) => {
    const next = returnPathOf((event as CustomEvent<SaveCompleteDetail>).detail);
    if (next !== undefined) currentReturnPath = next;
    status.textContent = status.dataset.messageSaved ?? 'Saved.';
    status.dataset.studioLaunchState = 'saved';
  });

  mount.addEventListener('studio-host-error', (event) => {
    const detail = (event as CustomEvent<{ error?: { message?: { defaultMessage?: string } } }>).detail;
    status.textContent = detail?.error?.message?.defaultMessage ?? labels.failed;
    status.dataset.studioLaunchState = 'error';
  });

  if (surface === 'form') {
    status.textContent = labels.deferred;
    status.dataset.studioLaunchState = 'deferred';
    toggle.hidden = false;
    show('form');
    return;
  }
  await launch();
}
