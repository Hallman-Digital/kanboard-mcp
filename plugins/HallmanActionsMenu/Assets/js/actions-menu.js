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
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', enhanceTaskSidebar, {once: true});
  } else enhanceTaskSidebar();
  document.addEventListener('kanboard:page:loaded', enhanceTaskSidebar);
})();
