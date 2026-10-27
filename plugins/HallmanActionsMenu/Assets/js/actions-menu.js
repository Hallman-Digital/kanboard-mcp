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
        return url.searchParams.get('controller') === 'TaskFileController'
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
          attachmentSection.appendChild(group);
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
    const row = Array.from(panel.querySelectorAll('.task-summary-column li')).find(function (li) {
      const label = li.querySelector('strong');
      return label && /^column\s*:/i.test(label.textContent.trim());
    });
    if (!row) return;
    const value = row.querySelector('span');
    const name = (value ? value.textContent : row.textContent.replace(/^column\s*:/i, '')).trim().toLowerCase();
    const mapping = {
      'backlog': 'backlog',
      'ready': 'ready',
      'wip': 'progress',
      'in-progress': 'progress',
      'in progress': 'progress',
      'blocked': 'blocked',
      'done': 'done'
    };
    if (mapping[name]) panel.dataset.hallmanWorkflow = mapping[name];
    else delete panel.dataset.hallmanWorkflow;
  }

  function enhance() {
    colorTaskSummary();
    enhanceTaskSidebar();
    addSectionActions();
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', enhance, {once: true});
  } else enhance();
  document.addEventListener('kanboard:page:loaded', enhance);
})();
