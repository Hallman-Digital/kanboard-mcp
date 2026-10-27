/* Wrap the native actions list without replacing any permissions, links or modal handlers. */
(function () {
  'use strict';
  function enhanceTaskSidebar() {
    if (!new URLSearchParams(window.location.search).get('task_id')) return;
    document.querySelectorAll('.sidebar.sidebar-icons').forEach(function (sidebar) {
      if (sidebar.querySelector('.hallman-actions-disclosure')) return;
      const headings = Array.from(sidebar.children).filter(function (node) {
        return node.classList && node.classList.contains('sidebar-title');
      });
      const heading = headings.find(function (node) {
        const h = node.querySelector('h2');
        return h && h.textContent.trim().toLowerCase() === 'actions';
      });
      if (!heading) return;
      const list = heading.nextElementSibling;
      if (!list || list.tagName !== 'UL') return;
      const details = document.createElement('details');
      details.className = 'hallman-actions-disclosure';
      const summary = document.createElement('summary');
      summary.className = 'hallman-actions-trigger';
      summary.textContent = heading.querySelector('h2').textContent.trim();
      summary.setAttribute('aria-label', 'Show or hide task actions');
      details.appendChild(summary);
      details.appendChild(list);
      heading.replaceWith(details);
    });
  }

  // Relocate existing permission-checked modal links into their matching sections.
  // Move, don't clone: this preserves Kanboard's delegated event handlers.
  function addSectionActions() {
    const sidebar = document.querySelector('.sidebar.sidebar-icons');
    const menu = sidebar && sidebar.querySelector('.hallman-actions-disclosure > ul');
    if (!menu) return;
    const sections = Array.from(document.querySelectorAll('details.accordion-section')).filter(function (section) {
      return section.querySelector(':scope > summary.accordion-title');
    });
    const routes = [
      { heading: 'description', controller: 'TaskModificationController', action: 'edit', label: 'Edit description' },
      { heading: 'sub-tasks', controller: 'SubtaskController', action: 'create', label: 'Add subtask' },
      { heading: 'internal links', controller: 'TaskInternalLinkController', action: 'create', label: 'Add link' },
      { heading: 'external links', controller: 'TaskExternalLinkController', action: 'find', label: 'Add link' },
      { heading: 'attachments', controller: 'TaskFileController', action: 'create', label: 'Attach file' },
      { heading: 'comments', controller: 'CommentController', action: 'create', label: 'Add comment' }
    ];
    routes.forEach(function (route) {
      const section = sections.find(function (node) {
        return node.querySelector(':scope > summary').textContent.trim().toLowerCase() === route.heading;
      });
      if (!section || section.querySelector(':scope > .hallman-section-action')) return;
      const link = Array.from(menu.querySelectorAll('li > a')).find(function (candidate) {
        const url = new URL(candidate.href, window.location.href);
        return url.searchParams.get('controller') === route.controller && url.searchParams.get('action') === route.action;
      });
      if (!link) return; // Respect permissions: don't create a link if Kanboard omitted it.
      const origin = link.closest('li');
      link.classList.add('hallman-section-action');
      link.setAttribute('aria-label', route.label);
      // Existing icon is reused. Replace only its text nodes.
      Array.from(link.childNodes).forEach(function (node) {
        if (node.nodeType === Node.TEXT_NODE) node.remove();
      });
      link.appendChild(document.createTextNode(' ' + route.label));
      if (route.controller === 'TaskExternalLinkController') {
        link.classList.add('js-modal-medium');
      }
      section.appendChild(link);
      // Empty source row is removed only after the action is moved successfully.
      if (origin && !origin.querySelector('a, button, input')) origin.remove();
    });

    // Keep clipboard screenshot capture next to the normal attachment action.
    const attachmentSection = sections.find(function (node) {
      return node.querySelector(':scope > summary').textContent.trim().toLowerCase() === 'attachments';
    });
    if (attachmentSection && !attachmentSection.querySelector('.hallman-screenshot-action')) {
      const screenshot = Array.from(menu.querySelectorAll('li > a')).find(function (candidate) {
        const url = new URL(candidate.href, window.location.href);
        return url.searchParams.get('controller') === 'TaskPopoverController'
          && url.searchParams.get('action') === 'screenshot';
      });
      if (screenshot) {
        const origin = screenshot.closest('li');
        const actions = attachmentSection.querySelector(':scope > .hallman-section-action');
        let group = attachmentSection.querySelector(':scope > .hallman-section-actions');
        if (!group) {
          group = document.createElement('div');
          group.className = 'hallman-section-actions';
          if (actions) group.appendChild(actions);
          attachmentSection.querySelector(':scope > summary.accordion-title').appendChild(group);
          group.addEventListener('click', function (event) { event.stopPropagation(); });
        }
        screenshot.classList.add('hallman-section-action', 'hallman-screenshot-action');
        screenshot.setAttribute('aria-label', 'Paste screenshot');
        Array.from(screenshot.childNodes).forEach(function (node) {
          if (node.nodeType === Node.TEXT_NODE) node.remove();
        });
        screenshot.appendChild(document.createTextNode(' Screenshot'));
        group.appendChild(screenshot);
        if (origin && !origin.querySelector('a, button, input')) origin.remove();
      }
    }
  }


  // The summary panel follows the task's real Kanboard column, not its task ID.
  function colorTaskSummary() {
    const panel = document.querySelector('#task-summary .task-summary-container');
    if (!panel) return;

    // Read the visible column label rather than relying on task IDs or task colors.
    const rows = Array.from(panel.querySelectorAll('.task-summary-column li'));
    const row = rows.find(function (li) {
      return /^\\s*column\\s*:/i.test(li.textContent.trim());
    });
    if (!row) return;

    const name = row.textContent.replace(/^\\s*column\\s*:/i, '').trim().toLowerCase().replace(/\\s+/g, ' ');
    const workflows = [
      { names: ['backlog', 'to do', 'todo', 'new', 'open'], key: 'backlog', bg: '#EEF0F3', border: '#6B7280' },
      { names: ['ready', 'queued', 'planned'], key: 'ready', bg: '#E8F1FF', border: '#3B82F6' },
      { names: ['wip', 'in-progress', 'in progress', 'doing', 'working'], key: 'progress', bg: '#FFF3D9', border: '#F59E0B' },
      { names: ['blocked', 'on hold', 'on-hold'], key: 'blocked', bg: '#FDE8E8', border: '#EF4444' },
      { names: ['done', 'complete', 'completed', 'finished', 'closed'], key: 'done', bg: '#E2F7EF', border: '#10B981' }
    ];
    const workflow = workflows.find(function (item) { return item.names.includes(name); });

    if (!workflow) {
      delete panel.dataset.hallmanWorkflow;
      panel.style.removeProperty('background-color');
      panel.style.removeProperty('border-color');
      return;
    }

    panel.dataset.hallmanWorkflow = workflow.key;
    panel.style.setProperty('background-color', workflow.bg, 'important');
    panel.style.setProperty('border-color', workflow.border, 'important');
  }
  function normalizeAttachmentActions() {
    const section = Array.from(document.querySelectorAll('details.accordion-section')).find(function (node) {
      const summary = node.querySelector(':scope > summary.accordion-title');
      return summary && summary.textContent.trim().toLowerCase().startsWith('attachments');
    });
    if (!section) return;
    const summary = section.querySelector(':scope > summary.accordion-title');
    let group = section.querySelector(':scope > .hallman-section-actions');
    const standalone = section.querySelector(':scope > .hallman-section-action');
    if (!group && standalone) {
      group = document.createElement('div');
      group.className = 'hallman-section-actions';
      section.appendChild(group);
      group.appendChild(standalone);
    }
    if (group && group.parentElement !== summary) {
      summary.appendChild(group);
    }
    if (standalone && standalone.parentElement !== summary && standalone.parentElement !== group) {
      summary.appendChild(standalone);
    }
  }

  function enhanceTaskEditModal() {
    const form = document.querySelector('#modal-overlay .task-form-container');
    if (!form || form.dataset.hallmanTaskLayout === 'v2') return;
    const columns = Array.from(form.querySelectorAll(':scope > .task-form-secondary-column'));
    const main = form.querySelector(':scope > .task-form-main-column');
    if (!main || !columns.length) return;
    const tags = main.querySelector('#form-tags');
    if (!tags) return;

    // Relocate assignee, category, and priority into a compact block below Tags.
    const fields = [
      { id: 'owner_id', key: 'assignee' },
      { id: 'priority', key: 'priority' }
    ];
    const relocated = document.createElement('div');
    relocated.className = 'hallman-task-relocated-fields';
    fields.forEach(function (field) {
      const label = form.querySelector('label[for="form-' + field.id + '"]');
      const control = form.querySelector('#form-' + field.id);
      if (!label || !control) return;
      const wrapper = document.createElement('div');
      wrapper.className = 'hallman-task-field hallman-task-field-' + field.key;
      label.parentNode && wrapper.appendChild(label);
      wrapper.appendChild(control);
      if (field.id === 'owner_id') {
        const assignMe = form.querySelector('a.assign-me[data-target-id="form-owner_id"]');
        if (assignMe) wrapper.appendChild(assignMe.closest('small') || assignMe);
      }
      relocated.appendChild(wrapper);
    });
    if (relocated.children.length) {
      tags.insertAdjacentElement('afterend', relocated);
    }

    // Merge the date/time fields into the color column so Due Date follows Color,
    // rather than appearing in a third grid column or far below it.
    if (columns.length > 1) {
      const first = columns[0];
      const second = columns[1];
      Array.from(second.childNodes).forEach(function (node) { first.appendChild(node); });
      second.remove();
    }
    form.dataset.hallmanTaskLayout = 'v2';
  }

  function watchTaskEditModal() {
    enhanceTaskEditModal();
    const observer = new MutationObserver(function () { enhanceTaskEditModal(); });
    observer.observe(document.body, { childList: true, subtree: true });
  }

  function enhance() {
    colorTaskSummary();
    enhanceTaskSidebar();
    enhanceTaskEditModal();
    addSectionActions();
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', enhance, {once: true});
  } else enhance();
  document.addEventListener('kanboard:page:loaded', enhance);
  if (document.body) {
    const actionObserver = new MutationObserver(function () { normalizeAttachmentActions(); });
    actionObserver.observe(document.body, {childList:true, subtree:true});
  }
  if (document.body) watchTaskEditModal();
})();
