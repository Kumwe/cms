import './styles.css';
import './components/command-palette';
import './components/field-builder';
import './components/workflow-builder';
import './components/menu-tree';
import './components/schema-form';
import './components/media-picker';
import './components/job-fields';
import './components/rich-text';
import './components/presentation-schemes';
import './components/business-definition-editor';
import './components/business-surface';
import './components/dirty-form';
import './components/repeatable-group';
import { setupPolicyStepFlows } from './components/policy-step-flow';
import '../interface-standard/kis-tabs';
import '../interface-standard/kis-master-detail';
import '../interface-standard/kis-drawer';
import { setupCopyValues } from '../interface-standard/copy-value';
import { setupValidationReveal } from '../interface-standard/reveal-validation';

document.documentElement.classList.add('js');

const focusableSelector = 'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

function setupNavigation(): void {
  const shell = document.querySelector<HTMLElement>('[data-administrator-shell]');
  const toggle = document.querySelector<HTMLButtonElement>('[data-navigation-toggle]');
  const backdrop = document.querySelector<HTMLButtonElement>('[data-navigation-backdrop]');
  if (!shell || !toggle) return;

  const setOpen = (open: boolean): void => {
    shell.toggleAttribute('data-navigation-open', open);
    toggle.setAttribute('aria-expanded', String(open));
    document.body.classList.toggle('navigation-locked', open);
    if (open) {
      shell.querySelector<HTMLElement>(focusableSelector)?.focus();
    } else {
      toggle.focus();
    }
  };

  toggle.addEventListener('click', () => setOpen(!shell.hasAttribute('data-navigation-open')));
  backdrop?.addEventListener('click', () => setOpen(false));
  shell.querySelectorAll<HTMLAnchorElement>('.administrator-navigation a').forEach((link) => {
    link.addEventListener('click', () => setOpen(false));
  });
  window.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && shell.hasAttribute('data-navigation-open')) setOpen(false);
  });
}

function setupConfirmations(): void {
  document.querySelectorAll<HTMLElement>('[data-confirm]').forEach((element) => {
    element.addEventListener('click', (event) => {
      const message = element.dataset.confirm ?? 'Continue with this action?';
      if (!window.confirm(message)) event.preventDefault();
    });
  });
}

function setupDismissibleNotices(): void {
  document.querySelectorAll<HTMLButtonElement>('[data-dismiss-notice]').forEach((button) => {
    button.addEventListener('click', () => button.closest<HTMLElement>('[role="status"], [role="alert"]')?.remove());
  });
}

function setupContentTypeSelector(): void {
  const selector = document.querySelector<HTMLSelectElement>('[data-content-type-selector]');
  if (!selector) return;
  selector.addEventListener('change', () => {
    const url = new URL(window.location.href);
    url.searchParams.set('content_type', selector.value);
    window.location.assign(url);
  });
}

function setupSlugSuggestion(): void {
  const title = document.querySelector<HTMLInputElement>('[data-title-input]');
  const slug = document.querySelector<HTMLInputElement>('[data-slug-input]');
  if (!title || !slug) return;
  let userEdited = slug.value.trim() !== '';
  slug.addEventListener('input', () => { userEdited = slug.value.trim() !== ''; });
  title.addEventListener('input', () => {
    if (userEdited) return;
    slug.value = title.value
      .normalize('NFKD')
      .replace(/[\u0300-\u036f]/g, '')
      .toLowerCase()
      .replace(/[^a-z0-9]+/g, '-')
      .replace(/^-+|-+$/g, '')
      .slice(0, 160);
  });
}

function setupNavigationTargets(): void {
  document.querySelectorAll<HTMLFormElement>('[data-navigation-target-form]').forEach((form) => {
    const type = form.querySelector<HTMLSelectElement>('[data-navigation-target-type]');
    const contentField = form.querySelector<HTMLElement>('[data-navigation-content-field]');
    const urlField = form.querySelector<HTMLElement>('[data-navigation-url-field]');
    const content = form.querySelector<HTMLSelectElement>('[data-navigation-content]');
    const url = urlField?.querySelector<HTMLInputElement>('input');
    const title = form.querySelector<HTMLInputElement>('[data-navigation-title]');
    const slug = form.querySelector<HTMLInputElement>('[data-navigation-slug]');
    if (!type || !contentField || !urlField || !content || !url) return;

    const sync = (): void => {
      const contentTarget = type.value === 'content';
      contentField.hidden = type.value === 'url';
      urlField.hidden = contentTarget;
      content.disabled = type.value === 'url';
      url.disabled = contentTarget;
      content.required = contentTarget;
      url.required = !contentTarget;
    };
    type.addEventListener('change', sync);
    content.addEventListener('change', () => {
      const option = content.selectedOptions[0];
      if (!option || option.value === '') return;
      if (title && title.value.trim() === '') title.value = option.textContent?.split(' · ')[0]?.trim() ?? '';
      if (slug && slug.value.trim() === '') slug.value = option.dataset.slug ?? '';
    });
    sync();
  });
}

setupNavigation();
setupConfirmations();
setupDismissibleNotices();
setupContentTypeSelector();
setupSlugSuggestion();
setupCopyValues();
setupValidationReveal();
setupNavigationTargets();
setupPolicyStepFlows();

if (document.querySelector('[data-studio-composition]') !== null) {
  void import('./components/studio-composition').then(({ setupStudioComposition }) => setupStudioComposition());
}

if (document.querySelector('[data-kumwe-studio][data-studio-module-url]') !== null) {
  void import('./components/studio-launch').then(({ setupStudioLaunch }) => setupStudioLaunch());
}
