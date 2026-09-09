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
 */

const MOUNT_SELECTOR = '[data-kumwe-studio][data-studio-module-url]';

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
  let surface: Surface = 'studio';

  const labels = {
    showForm: toggle.dataset.labelForm ?? 'Use the structured form',
    showStudio: toggle.dataset.labelStudio ?? 'Use the page builder',
    failed: status.dataset.messageFailed ?? 'The Studio page builder could not start; the structured form remains available.',
    ready: status.dataset.messageReady ?? 'The Studio page builder is ready.',
  };

  const show = (next: Surface): void => {
    surface = next;
    region.dataset.studioSurface = next;
    mount.hidden = next !== 'studio';
    formShell.hidden = next !== 'form';
    toggle.textContent = next === 'studio' ? labels.showForm : labels.showStudio;
    toggle.setAttribute('aria-pressed', next === 'form' ? 'true' : 'false');
  };

  const fail = (error: unknown): void => {
    status.textContent = labels.failed;
    status.dataset.studioLaunchState = 'failed';
    region.dataset.studioSurface = 'form';
    mount.hidden = true;
    formShell.hidden = false;
    toggle.hidden = true;
    console.error('Studio page builder failed to mount.', error);
  };

  toggle.addEventListener('click', () => {
    show(surface === 'studio' ? 'form' : 'studio');
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

  status.dataset.studioLaunchState = 'loading';
  try {
    const imported: unknown = await import(/* @vite-ignore */ moduleUrl);
    if (!isStudioBrowserModule(imported)) {
      throw new TypeError('The Studio browser module does not export the hosted runtime.');
    }
    const registry = new imported.StudioAuthoringControlRegistry({ strictContentSecurityPolicy: true });
    const report = await imported.autoMountStudio({
      hosted: () => ({ authoringControlRegistry: registry }),
    });
    if (report.failures.length > 0 || report.handles.length === 0) {
      fail(report.failures[0]?.error ?? new Error('No Studio target was mounted.'));
      return;
    }
    status.textContent = labels.ready;
    status.dataset.studioLaunchState = 'ready';
    show('studio');
  } catch (error) {
    fail(error);
  }
}
