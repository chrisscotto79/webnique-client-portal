(function () {
    'use strict';

    var reducedMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    document.querySelectorAll('[data-wnq-table-search]').forEach(function (input) {
        var body = document.getElementById(input.getAttribute('data-wnq-table-search'));
        if (!body) {
            return;
        }
        var rows = Array.prototype.slice.call(body.querySelectorAll('[data-wnq-filter-row]'));
        var count = input.closest('.wnq-table-toolbar').querySelector('[data-wnq-table-count]');
        input.addEventListener('input', function () {
            var query = input.value.trim().toLocaleLowerCase();
            var visible = 0;
            rows.forEach(function (row) {
                var match = !query || row.textContent.toLocaleLowerCase().indexOf(query) !== -1;
                row.hidden = !match;
                if (match) {
                    visible += 1;
                }
            });
            if (count) {
                count.textContent = visible + (visible === 1 ? ' record' : ' records');
            }
        });
    });

    document.querySelectorAll('[data-wnq-row-link]').forEach(function (row) {
        function openRow(event) {
            if (event.target.closest('a,button,input,select,textarea')) {
                return;
            }
            if (event.type === 'keydown' && event.key !== 'Enter' && event.key !== ' ') {
                return;
            }
            if (event.type === 'keydown') {
                event.preventDefault();
            }
            window.location.href = row.getAttribute('data-wnq-row-link');
        }
        row.addEventListener('click', openRow);
        row.addEventListener('keydown', openRow);
    });

    var root = document.querySelector('.wnq-ppc-intelligence');
    if (!root) {
        return;
    }

    var tabs = Array.prototype.slice.call(root.querySelectorAll('[data-wnq-workspace-tab]'));
    var panels = Array.prototype.slice.call(root.querySelectorAll('[data-wnq-workspace]'));
    if (!tabs.length || !panels.length) {
        return;
    }

    function activateWorkspace(name, moveFocus) {
        var activeTab = null;
        tabs.forEach(function (tab) {
            var active = tab.getAttribute('data-wnq-workspace-tab') === name;
            tab.setAttribute('aria-selected', active ? 'true' : 'false');
            tab.tabIndex = active ? 0 : -1;
            tab.classList.toggle('is-active', active);
            if (active) {
                activeTab = tab;
            }
        });
        panels.forEach(function (panel) {
            var active = panel.getAttribute('data-wnq-workspace') === name;
            panel.hidden = !active;
            panel.classList.toggle('is-active', active);
        });
        if (moveFocus && activeTab) {
            activeTab.focus();
        }
    }

    function workspaceForTarget(target) {
        var panel = target ? target.closest('[data-wnq-workspace]') : null;
        return panel ? panel.getAttribute('data-wnq-workspace') : '';
    }

    tabs.forEach(function (tab, index) {
        tab.addEventListener('click', function () {
            activateWorkspace(tab.getAttribute('data-wnq-workspace-tab'), false);
        });
        tab.addEventListener('keydown', function (event) {
            var next = index;
            if (event.key === 'ArrowRight') {
                next = (index + 1) % tabs.length;
            } else if (event.key === 'ArrowLeft') {
                next = (index - 1 + tabs.length) % tabs.length;
            } else if (event.key === 'Home') {
                next = 0;
            } else if (event.key === 'End') {
                next = tabs.length - 1;
            } else {
                return;
            }
            event.preventDefault();
            activateWorkspace(tabs[next].getAttribute('data-wnq-workspace-tab'), true);
        });
    });

    root.querySelectorAll('[data-wnq-open-workspace]').forEach(function (button) {
        button.addEventListener('click', function () {
            activateWorkspace(button.getAttribute('data-wnq-open-workspace'), false);
            var targetId = button.getAttribute('data-wnq-scroll-target');
            var severity = button.getAttribute('data-wnq-finding-filter');
            var findingRows = Array.prototype.slice.call(root.querySelectorAll('[data-wnq-finding]'));
            var clear = root.querySelector('.wnq-clear-finding-filter');
            if (severity) {
                findingRows.forEach(function (row) {
                    row.classList.toggle('is-filtered-out', row.getAttribute('data-severity') !== severity);
                });
                if (clear) {
                    clear.hidden = false;
                }
                targetId = 'ppc-attention';
            }
            var target = targetId ? document.getElementById(targetId) : root.querySelector('.wnq-workspace-tabs');
            if (target) {
                target.scrollIntoView({ behavior: reducedMotion ? 'auto' : 'smooth', block: 'start' });
            }
        });
    });

    var clearFindingFilter = root.querySelector('.wnq-clear-finding-filter');
    if (clearFindingFilter) {
        clearFindingFilter.addEventListener('click', function () {
            root.querySelectorAll('[data-wnq-finding]').forEach(function (row) {
                row.classList.remove('is-filtered-out');
            });
            clearFindingFilter.hidden = true;
        });
    }

    root.querySelectorAll('[data-wnq-toggle-evidence]').forEach(function (button) {
        button.addEventListener('click', function () {
            var details = button.closest('.wnq-finding-row').querySelector('details');
            details.open = !details.open;
        });
    });

    root.querySelectorAll('.wnq-module-nav a[href^="#"]').forEach(function (link) {
        link.addEventListener('click', function () {
            var target = document.querySelector(link.getAttribute('href'));
            var workspace = workspaceForTarget(target);
            if (workspace) {
                activateWorkspace(workspace, false);
            }
        });
    });

    root.classList.add('is-enhanced');
    var initialTarget = window.location.hash ? document.getElementById(window.location.hash.slice(1)) : null;
    activateWorkspace(workspaceForTarget(initialTarget) || 'overview', false);
})();
