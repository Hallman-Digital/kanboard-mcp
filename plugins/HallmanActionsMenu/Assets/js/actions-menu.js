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
      const label = document.createElement('span');
      label.className = 'hallman-section-action-label';
      label.textContent = route.label;
      link.classList.add('hallman-section-action');
      link.setAttribute('aria-label', route.label);
      // Existing icon is reused. Replace only its text nodes.
      Array.from(link.childNodes).forEach(function (node) {
        if (node.nodeType === Node.TEXT_NODE) node.remove();
      });
      link.appendChild(label);
      section.appendChild(link);
      // Empty source row is removed only after the action is moved successfully.
      if (origin && !origin.querySelector('a, button, input')) origin.remove();
    });
  }

  function enhance() {
    enhanceTaskSidebar();
    addSectionActions();
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', enhance, {once: true});
  } else enhance();
  document.addEventListener('kanboard:page:loaded', enhance);
})();
