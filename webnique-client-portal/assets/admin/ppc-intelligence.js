(function () {
    'use strict';

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
            root.querySelector('.wnq-workspace-tabs').scrollIntoView({ behavior: 'smooth', block: 'start' });
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
