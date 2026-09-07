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
                if (!match) row.querySelectorAll('input[type="checkbox"]').forEach(function (checkbox) { checkbox.checked = false; });
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

    // All evidence remains available without JavaScript; enhancement adds local search and pages.
    root.querySelectorAll('.wnq-table-scroll table, .wnq-intel-grid').forEach(function (collection, index) {
        var table = collection.tagName === 'TABLE';
        var items = Array.from(table ? collection.querySelectorAll('tbody > tr') : collection.children);
        if (items.length < 2 || collection.querySelector('[data-wnq-filter-row]')) return;
        var host = table ? collection.parentElement : collection;
        var title = collection.closest('.wnq-module');
        var heading = title && title.querySelector('h3');
        var name = heading ? heading.textContent : 'Evidence';
        if (table) {
            host.tabIndex = 0;
            host.setAttribute('role','region');
            host.setAttribute('aria-label',name+' table. Scroll horizontally for more columns.');
        }
        var toolbar = document.createElement('div');
        toolbar.className = 'wnq-evidence-tools';
        var label = document.createElement('label');
        label.textContent = 'Search ' + name;
        var input = document.createElement('input');
        input.type = 'search'; input.placeholder = 'Search this report';
        input.id = 'wnq-evidence-search-' + index; label.htmlFor = input.id;
        var sizeLabel = document.createElement('label'); sizeLabel.textContent = 'Per page';
        var size = document.createElement('select');
        [10,25,50,100].forEach(function (number) { var option = document.createElement('option'); option.value=number; option.textContent=number; size.appendChild(option); });
        size.value = '10'; sizeLabel.appendChild(size);
        var status = document.createElement('span'); status.setAttribute('role','status'); status.setAttribute('aria-live','polite');
        var previous = document.createElement('button'); previous.type='button'; previous.className='button'; previous.textContent='Previous'; previous.setAttribute('aria-label','Previous page of '+name);
        var next = document.createElement('button'); next.type='button'; next.className='button'; next.textContent='Next'; next.setAttribute('aria-label','Next page of '+name);
        [label,input,sizeLabel,status,previous,next].forEach(function (node) { toolbar.appendChild(node); });
        host.before(toolbar);
        var empty = document.createElement('p'); empty.className='wnq-empty-state'; empty.textContent='No records match this search.'; empty.hidden=true; host.after(empty);
        var page=0;
        var searchable=items.map(function (item) { return item.textContent.toLocaleLowerCase(); });
        function renderPage() {
            var query=input.value.trim().toLocaleLowerCase();
            var matches=items.filter(function (item,i) { return !query || searchable[i].includes(query); });
            var count=Number(size.value); page=Math.min(page,Math.max(0,Math.ceil(matches.length/count)-1));
            var shown=new Set(matches.slice(page*count,(page+1)*count));
            items.forEach(function (item) {
                item.hidden=!shown.has(item);
                // A bulk review must never include checked records hidden by a page or filter change.
                if (item.hidden) item.querySelectorAll('input[type="checkbox"]').forEach(function (checkbox) { checkbox.checked=false; });
            });
            status.textContent=matches.length ? (page*count+1)+'–'+Math.min((page+1)*count,matches.length)+' of '+matches.length : '0 records';
            previous.disabled=page===0; next.disabled=(page+1)*count>=matches.length; empty.hidden=matches.length!==0;
        }
        input.addEventListener('input',function () { page=0; renderPage(); });
        size.addEventListener('change',function () { page=0; renderPage(); });
        previous.addEventListener('click',function () { page--; renderPage(); });
        next.addEventListener('click',function () { page++; renderPage(); });
        renderPage();
    });

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
            window.history.replaceState(null,'','#'+tab.getAttribute('aria-controls'));
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

    root.querySelectorAll('a[href^="#"]').forEach(function (link) {
        link.addEventListener('click', function () {
            var target = document.getElementById(link.getAttribute('href').slice(1));
            var workspace = workspaceForTarget(target);
            if (workspace) {
                activateWorkspace(workspace, false);
            }
        });
    });

    window.addEventListener('hashchange', function () {
        var target=document.getElementById(window.location.hash.slice(1));
        var workspace=workspaceForTarget(target);
        if (workspace) activateWorkspace(workspace,false);
    });

    root.classList.add('is-enhanced');
    var initialTarget = window.location.hash ? document.getElementById(window.location.hash.slice(1)) : null;
    activateWorkspace(workspaceForTarget(initialTarget) || 'overview', false);
})();
