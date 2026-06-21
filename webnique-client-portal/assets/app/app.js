(function () {
  "use strict";
  const root = document.getElementById("wnq-portal-root");
  const cfg = window.WNQ_PORTAL || {};
  if (!root) return;

  const state = { active: "overview", cache: {}, clientId: cfg.clientId || "" };
  const tabs = [
    ["overview", "Overview"], ["reports", "Reports"], ["customers", "CRM & Jobs"],
    ["ads", "Ads"], ["messages", "Support"], ["requests", "Requests"], ["billing", "Billing"],
    ["learning", "Learning"], ["profile", "Business Profile"]
  ];
  const esc = (value) => String(value ?? "").replace(/[&<>"']/g, (char) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#039;" }[char]));
  const money = (value) => new Intl.NumberFormat("en-US", { style: "currency", currency: "USD", maximumFractionDigits: 0 }).format(Number(value || 0));
  const date = (value) => value ? new Date(`${value}`.replace(" ", "T")).toLocaleDateString() : "Not set";
  const attr = (value) => esc(JSON.stringify(value));
  const activeLabel = (key) => tabs.find(([tab]) => tab === key)?.[1] || "Dashboard";
  const api = async (path, options = {}) => {
    const requestUrl = new URL(`${cfg.restUrl.replace(/\/$/, "")}${path}`);
    if (cfg.isAdmin && state.clientId) requestUrl.searchParams.set("client_id", state.clientId);
    const isForm = options.body instanceof FormData;
    const response = await fetch(requestUrl.toString(), {
      credentials: "same-origin",
      headers: { "X-WP-Nonce": cfg.nonce, ...(isForm ? {} : { "Content-Type": "application/json" }) },
      ...options,
    });
    const data = await response.json().catch(() => ({ ok: false, error: "Invalid server response." }));
    if (!response.ok) throw new Error(data.error || "Request failed.");
    return data.data ?? data;
  };
  const status = (tone, label) => `<span class="wnq-status is-${esc(tone)}"><i></i>${esc(label)}</span>`;
  const humanize = (value) => String(value || "").replaceAll("_", " ");
  const empty = (message) => `<div class="wnq-empty">${esc(message)}</div>`;
  const heading = (eyebrow, title, copy = "") => `<header class="wnq-page-head"><span>${esc(eyebrow)}</span><h1>${esc(title)}</h1>${copy ? `<p>${esc(copy)}</p>` : ""}</header>`;
  const trend = (value) => `<strong class="${Number(value) >= 0 ? "wnq-positive" : "wnq-negative"}">${money(value)}</strong>`;
  const performanceChart = (rows = []) => {
    const max = Math.max(1, ...rows.map((row) => Math.max(Math.abs(Number(row.profit || 0)), Number(row.jobs || 0))));
    return `<div class="wnq-chart">${rows.map((row) => {
      const height = Math.max(4, Math.round((Math.abs(Number(row.profit || 0)) / max) * 100));
      return `<div class="wnq-chart-column"><span class="wnq-chart-bar ${Number(row.profit) < 0 ? "is-negative" : ""}" style="height:${height}%"></span><strong>${esc(row.label)}</strong><small>${esc(row.jobs)} jobs</small><small>${trend(row.profit)}</small></div>`;
    }).join("")}</div>`;
  };
  const viewAs = () => {
    if (!cfg.isAdmin || !Array.isArray(cfg.viewAsClients) || !cfg.viewAsClients.length) return "";
    return `<div class="wnq-view-as"><label for="wnq-view-as"><span>Admin Preview</span><strong>View as client/user</strong></label>
      <select id="wnq-view-as">${cfg.viewAsClients.map((client) => `<option value="${esc(client.clientId)}" ${client.clientId === state.clientId ? "selected" : ""}>${esc(client.label)}</option>`).join("")}</select></div>`;
  };
  const shell = () => {
    root.innerHTML = `<div class="wnq-portal">
      <aside class="wnq-sidebar"><div class="wnq-brand"><img src="${esc(cfg.logoUrl || "")}" alt="Golden Web Marketing"><span>Client Portal</span></div>
      ${viewAs()}
      <nav>${tabs.map(([key, label]) => `<button type="button" data-tab="${key}">${label}</button>`).join("")}</nav>
      <div class="wnq-sidebar-foot"><span>Signed in as</span><strong>${esc(cfg.user?.name || "Client")}</strong></div></aside>
      <main class="wnq-main"><div class="wnq-topbar"><div><span>Golden Web Marketing</span><strong id="wnq-top-title">Overview</strong></div><button type="button" class="wnq-button is-secondary" id="wnq-refresh-view">Refresh</button></div><div id="wnq-view">${empty("Loading dashboard...")}</div></main></div>`;
    root.querySelectorAll("[data-tab]").forEach((button) => button.addEventListener("click", () => show(button.dataset.tab)));
    root.querySelector("#wnq-refresh-view")?.addEventListener("click", () => show(state.active, true));
    root.querySelector("#wnq-view-as")?.addEventListener("change", (event) => {
      state.clientId = event.currentTarget.value;
      state.cache = {};
      show("overview", true);
    });
  };
  const setActive = (key) => root.querySelectorAll("[data-tab]").forEach((button) => button.classList.toggle("is-active", button.dataset.tab === key));
  const load = async (resource, refresh = false) => {
    if (!refresh && state.cache[resource]) return state.cache[resource];
    state.cache[resource] = await api(`/portal/${resource}`);
    return state.cache[resource];
  };
  const show = async (key, refresh = false) => {
    state.active = key; setActive(key);
    const view = root.querySelector("#wnq-view");
    const topTitle = root.querySelector("#wnq-top-title");
    if (topTitle) topTitle.textContent = activeLabel(key);
    view.innerHTML = empty(`Loading ${key}...`);
    try {
      const renderers = { overview, reports, customers, ads, messages, requests, billing, learning, profile };
      await renderers[key](view, refresh);
    } catch (error) {
      view.innerHTML = `<div class="wnq-error"><strong>Unable to load this section.</strong><p>${esc(error.message)}</p></div>`;
    }
  };

  async function overview(view, refresh) {
    const data = await load("overview", refresh);
    const crm = data.customers || {};
    const actions = data.actions || [];
    const current = data.performance?.[data.performance.length - 1] || {};
    const messagesTab = root.querySelector('[data-tab="messages"]');
    if (messagesTab) messagesTab.innerHTML = `Messages${data.unread_messages ? `<b>${esc(data.unread_messages)}</b>` : ""}`;
    view.innerHTML = `${heading("Dashboard", `Welcome back, ${data.client?.company || data.client?.name || "there"}`, "Here is what is happening and what needs attention.")}
      <div class="wnq-health">
        <div><span>Account</span>${status(data.health.overall, data.health.overall === "green" ? "On track" : "Action needed")}</div>
        <div><span>Billing</span>${status(data.health.billing.tone, data.health.billing.label)}</div>
        <div><span>Work</span>${status(data.health.work.tone, data.health.work.message)}</div>
      </div>
      <div class="wnq-metrics">
        ${metric("Customers", crm.total || 0, "Active CRM records")}
        ${metric("Jobs this month", current.jobs || 0, "Completed and recorded")}
        ${metric("Revenue this month", money(current.revenue), "Recorded job revenue")}
        ${metric("Profit this month", money(current.profit), "Revenue minus job cost", Number(current.profit) >= 0 ? "positive" : "negative")}
      </div>
      <section class="wnq-panel"><div class="wnq-panel-head"><h2>Jobs & Profit by Month</h2><span class="${Number(current.profit) >= 0 ? "wnq-positive" : "wnq-negative"}">${money(current.profit || 0)} this month</span></div>${performanceChart(data.performance)}</section>
      <section class="wnq-grid-2">
        <div class="wnq-panel"><div class="wnq-panel-head"><h2>Action Items</h2></div>${actions.length ? actions.map((item) => `<button class="wnq-action" data-go="${esc(item.type)}"><strong>${esc(item.label)}</strong><span>Review</span></button>`).join("") : empty("Nothing needs your attention.")}</div>
        <div class="wnq-panel"><div class="wnq-panel-head"><h2>Latest Report</h2></div>${data.latest_report ? `<strong>${esc(data.latest_report.report_type || "Monthly")} report</strong><p>${date(data.latest_report.period_start)} through ${date(data.latest_report.period_end)}</p><button class="wnq-button" data-go="reports">View reports</button>` : empty("Your first report will appear here.")}</div>
      </section>`;
    view.querySelectorAll("[data-go]").forEach((button) => button.addEventListener("click", () => show(button.dataset.go)));
  }
  const metric = (label, value, note, tone = "") => `<div class="wnq-metric"><span>${esc(label)}</span><strong class="${tone ? `wnq-${tone}` : ""}">${esc(value)}</strong><small>${esc(note)}</small></div>`;

  async function reports(view, refresh) {
    const rows = await load("reports", refresh);
    view.innerHTML = `${heading("Results", "Reports", "A simple archive of your marketing reports.")}
      <div class="wnq-panel"><div class="wnq-list-head"><span>Report</span><span>Period</span><span>Status</span></div>
      ${rows.length ? rows.map((row) => `<div class="wnq-list-row"><strong>${esc(row.report_type || "Monthly")} Report</strong><span>${date(row.period_start)} - ${date(row.period_end)}</span><span>${status(row.status === "ready" || row.status === "sent" ? "green" : "yellow", row.status || "Draft")} <a class="wnq-link" href="${esc(row.view_url)}" target="_blank" rel="noopener">View Full Report</a> <a class="wnq-link" href="${esc(row.pdf_url)}">Download PDF</a></span></div>`).join("") : empty("No SEO OS reports are available yet.")}</div>`;
  }

  async function customers(view, refresh) {
    const [rows, workRows, performance] = await Promise.all([load("customers", refresh), load("work", refresh), load("performance", refresh)]);
    const today = new Date().toISOString().slice(0, 10);
    const wonStatuses = ["completed", "won", "closed"];
    const lostStatuses = ["lost", "canceled"];
    const jobStatuses = ["scheduled", "in_progress", "completed", "won", "closed", "canceled"];
    const crmSearch = sessionStorage.getItem("wnqCrmSearch") || "";
    const statusFilter = sessionStorage.getItem("wnqCrmStatus") || "all";
    const visibleRows = filterCrmRows(rows, crmSearch, statusFilter);
    const totals = crmTotals(visibleRows);
    const leads = visibleRows.filter((row) => !jobStatuses.includes(row.status) && row.record_type !== "job");
    const jobs = visibleRows.filter((row) => jobStatuses.includes(row.status) || row.record_type === "job");
    const upcoming = jobs.filter((row) => row.job_date && row.job_date >= today && ![...wonStatuses, ...lostStatuses].includes(row.status));
    const overdue = visibleRows.filter((row) => row.follow_up_date && row.follow_up_date < today && ![...wonStatuses, ...lostStatuses].includes(row.status));
    const completed = visibleRows.filter((row) => wonStatuses.includes(row.status));
    const lost = visibleRows.filter((row) => lostStatuses.includes(row.status));
    const avgJob = totals.jobs ? totals.revenue / totals.jobs : 0;
    const closeRate = visibleRows.length ? Math.round((completed.length / visibleRows.length) * 100) : 0;
    const topServices = topBy(visibleRows, "service", "final_value");
    const topCustomers = topBy(visibleRows, "name", "final_value");
    const activeCrmTab = sessionStorage.getItem("wnqCrmTab") || "dashboard";
    const crmNotice = sessionStorage.getItem("wnqCrmNotice") || "";
    if (crmNotice) sessionStorage.removeItem("wnqCrmNotice");
    const statusOptions = ["all", "lead", "customer", "job", "new", "contacted", "estimate_sent", "scheduled", "in_progress", "completed", "won", "lost", "closed", "canceled"];
    view.innerHTML = `${heading("CRM & Job History", "Customers and Jobs", "Track leads, completed work, revenue, costs, profit, follow-ups, and marketing work in one place.")}
      ${crmNotice ? `<div class="wnq-success-inline">${esc(crmNotice)}</div>` : ""}
      <div class="wnq-crm-overview">
        <div class="wnq-metrics">${metric("Records", visibleRows.length, `${rows.length} total in CRM`)}${metric("Jobs", totals.jobs, "Recorded jobs")}${metric("Revenue", money(totals.revenue), "Recorded revenue")}${metric("Profit", money(totals.revenue - totals.cost), "Revenue minus costs", totals.revenue - totals.cost >= 0 ? "positive" : "negative")}</div>
        <div class="wnq-crm-summary">
          ${crmPill("Open Leads", leads.length, "Needs follow-up", "yellow")}
          ${crmPill("Upcoming Jobs", upcoming.length, "Scheduled work", "green")}
          ${crmPill("Overdue", overdue.length, "Follow-ups due", overdue.length ? "red" : "green")}
          ${crmPill("Completed", completed.length, "Won or closed", "green")}
          ${crmPill("Lost / Canceled", lost.length, "Not moving forward", lost.length ? "red" : "yellow")}
        </div>
      </div>
      <div class="wnq-crm-controls">
        <label><span>Search CRM</span><input type="search" id="wnq-crm-search" value="${esc(crmSearch)}" placeholder="Name, service, source, address, notes"></label>
        <label><span>Status / Type</span><select id="wnq-crm-status">${statusOptions.map((option) => `<option value="${esc(option)}" ${statusFilter === option ? "selected" : ""}>${esc(option === "all" ? "All records" : humanize(option))}</option>`).join("")}</select></label>
        <button type="button" class="wnq-button is-secondary" id="wnq-crm-apply">Apply Filters</button>
        <button type="button" class="wnq-link" id="wnq-crm-clear">Clear</button>
        <button type="button" class="wnq-button" id="wnq-add-customer">Add Customer / Job</button>
      </div>
      <p class="wnq-crm-filter-note">Showing ${esc(visibleRows.length)} of ${esc(rows.length)} CRM records.</p>
      <div class="wnq-subnav">${["dashboard","leads","jobs","calendar","followups","reports","settings"].map((key) => `<button type="button" data-crm-tab="${key}" class="${key === activeCrmTab ? "is-active" : ""}">${esc(humanize(key))}</button>`).join("")}</div>
      <div id="wnq-customer-form"></div>
      <div class="wnq-crm-tab" data-crm-panel="dashboard">${crmDashboard({ performance, upcoming, overdue, totals, avgJob, closeRate, topServices, topCustomers })}</div>
      <div class="wnq-crm-tab" data-crm-panel="leads">${crmTable(leads, "Lead Pipeline", "No leads are recorded yet.")}</div>
      <div class="wnq-crm-tab" data-crm-panel="jobs">${crmTable(jobs, "Job Management", "No jobs are recorded yet.")}</div>
      <div class="wnq-crm-tab" data-crm-panel="calendar">${crmCalendar(upcoming)}</div>
      <div class="wnq-crm-tab" data-crm-panel="followups">${crmTable(overdue, "Overdue Follow-ups", "No overdue follow-ups.")}</div>
      <div class="wnq-crm-tab" data-crm-panel="reports">${crmReports({ rows: visibleRows, totals, avgJob, closeRate, topServices, topCustomers, completed, lost })}</div>
      <div class="wnq-crm-tab" data-crm-panel="settings">${crmSettings()}</div>
      <div class="wnq-panel"><div class="wnq-panel-head"><h2>Marketing Work History</h2></div>${workRows.length ? workRows.map((row) => `<div class="wnq-work-item"><div>${status(row.status === "done" ? "green" : "yellow", row.status)}<strong>${esc(row.title)}</strong></div><span>${row.due_date ? `Due ${date(row.due_date)}` : "No due date"}</span></div>`).join("") : empty("No marketing work items yet.")}</div>`;
    const formRoot = view.querySelector("#wnq-customer-form");
    const applyFilters = () => {
      sessionStorage.setItem("wnqCrmSearch", view.querySelector("#wnq-crm-search")?.value || "");
      sessionStorage.setItem("wnqCrmStatus", view.querySelector("#wnq-crm-status")?.value || "all");
      show("customers");
    };
    const setCrmTab = (key) => {
      sessionStorage.setItem("wnqCrmTab", key);
      view.querySelectorAll("[data-crm-tab]").forEach((button) => button.classList.toggle("is-active", button.dataset.crmTab === key));
      view.querySelectorAll("[data-crm-panel]").forEach((panel) => panel.classList.toggle("is-active", panel.dataset.crmPanel === key));
    };
    view.querySelector("#wnq-crm-apply")?.addEventListener("click", applyFilters);
    view.querySelector("#wnq-crm-search")?.addEventListener("keydown", (event) => { if (event.key === "Enter") { event.preventDefault(); applyFilters(); } });
    view.querySelector("#wnq-crm-status")?.addEventListener("change", applyFilters);
    view.querySelector("#wnq-crm-clear")?.addEventListener("click", () => {
      sessionStorage.removeItem("wnqCrmSearch");
      sessionStorage.removeItem("wnqCrmStatus");
      show("customers");
    });
    view.querySelectorAll("[data-crm-tab]").forEach((button) => button.addEventListener("click", () => setCrmTab(button.dataset.crmTab)));
    setCrmTab(activeCrmTab);
    const openForm = (row = {}) => {
      formRoot.innerHTML = customerForm(row);
      formRoot.scrollIntoView({ behavior: "smooth", block: "start" });
      formRoot.querySelector("form").addEventListener("submit", async (event) => {
        event.preventDefault();
        const form = event.currentTarget;
        const submit = form.querySelector('[type="submit"]');
        if (submit) { submit.disabled = true; submit.textContent = "Saving..."; }
        try {
          await api("/portal/customers", { method: "POST", body: new FormData(form) });
          sessionStorage.setItem("wnqCrmNotice", "CRM record saved.");
          delete state.cache.customers; delete state.cache.overview; delete state.cache.performance; show("customers", true);
        } catch (error) {
          formRoot.insertAdjacentHTML("afterbegin", `<div class="wnq-error"><strong>Record was not saved.</strong><p>${esc(error.message)}</p></div>`);
          if (submit) { submit.disabled = false; submit.textContent = "Save Record"; }
        }
      });
      formRoot.querySelector("[data-cancel]").addEventListener("click", () => formRoot.innerHTML = "");
    };
    view.querySelector("#wnq-add-customer")?.addEventListener("click", () => openForm());
    view.querySelectorAll("[data-edit]").forEach((button) => button.addEventListener("click", () => openForm(JSON.parse(button.dataset.edit))));
  }
  const crmTotals = (rows) => rows.reduce((sum, row) => ({ jobs: sum.jobs + Number(row.job_count || 0), revenue: sum.revenue + Number(row.final_value || 0), cost: sum.cost + Number(row.job_cost || 0) }), { jobs: 0, revenue: 0, cost: 0 });
  const filterCrmRows = (rows, search, statusFilter) => {
    const query = String(search || "").trim().toLowerCase();
    return rows.filter((row) => {
      const matchesStatus = !statusFilter || statusFilter === "all" || row.status === statusFilter || row.record_type === statusFilter;
      const matchesSearch = !query || [row.name, row.phone, row.email, row.address, row.job_address, row.service, row.crew, row.lead_source, row.status, row.notes, row.internal_notes].join(" ").toLowerCase().includes(query);
      return matchesStatus && matchesSearch;
    });
  };
  const crmPill = (label, value, note, tone = "yellow") => `<div class="wnq-crm-pill is-${esc(tone)}"><span>${esc(label)}</span><strong>${esc(value)}</strong><small>${esc(note)}</small></div>`;
  const topBy = (rows, key, amountKey) => Object.values(rows.reduce((map, row) => {
    const label = row[key] || "Not set";
    map[label] = map[label] || { label, total: 0, count: 0 };
    map[label].total += Number(row[amountKey] || 0);
    map[label].count += 1;
    return map;
  }, {})).sort((a, b) => b.total - a.total).slice(0, 5);
  const crmDashboard = ({ performance, upcoming, overdue, totals, avgJob, closeRate, topServices, topCustomers }) => `<section class="wnq-panel"><div class="wnq-panel-head"><h2>Monthly Business Summary</h2><span>${trend(totals.revenue - totals.cost)} profit</span></div>${performanceChart(performance)}</section><div class="wnq-grid-2"><section class="wnq-panel">${miniList("Upcoming Jobs", upcoming, "No upcoming jobs.")}</section><section class="wnq-panel">${miniList("Overdue Follow-ups", overdue, "No overdue follow-ups.")}</section></div><div class="wnq-metrics">${metric("Average Job Value", money(avgJob), "Revenue divided by job count")}${metric("Close Rate", `${closeRate}%`, "Won or completed records")}${metric("Top Service", topServices[0]?.label || "Not set", "Highest recorded revenue")}${metric("Top Customer", topCustomers[0]?.label || "Not set", "Highest recorded revenue")}</div>`;
  const miniList = (title, rows, fallback) => `<div class="wnq-panel-head"><h2>${esc(title)}</h2></div>${rows.length ? rows.slice(0, 5).map((row) => `<div class="wnq-work-item"><div>${crmStatus(row.status)}<strong>${esc(row.name)}</strong></div><span>${date(row.job_date || row.follow_up_date)} · ${esc(row.service || "Service not set")}</span></div>`).join("") : empty(fallback)}`;
  const crmTable = (rows, title, fallback) => `<div class="wnq-panel wnq-table-wrap wnq-crm-table-panel"><div class="wnq-panel-head"><div><h2>${esc(title)}</h2><small>${esc(rows.length)} record${rows.length === 1 ? "" : "s"}</small></div></div><table class="wnq-crm-table"><thead><tr><th>Customer</th><th>Service / Source</th><th>Status</th><th>Schedule</th><th>Money</th><th>Job Info</th><th></th></tr></thead><tbody>${rows.length ? rows.map((row) => {
    const profit = Number(row.final_value || 0) - Number(row.job_cost || 0);
    return `<tr><td><strong>${esc(row.name)}</strong><small>${esc([row.phone, row.email].filter(Boolean).join(" · ") || "No contact saved")}</small><small>${esc(row.address || "")}</small></td><td><strong>${esc(row.service || "Not set")}</strong><small>${esc(row.lead_source || "Source not set")}</small></td><td>${crmStatus(row.status)}</td><td><span>${date(row.job_date || row.follow_up_date || row.reminder_date)}</span><small>${row.completion_date ? `Completed ${date(row.completion_date)}` : "No completion date"}</small></td><td><strong>${money(row.final_value)}</strong><small>Cost ${money(row.job_cost)} · ${trend(profit)} profit</small></td><td><small>${esc(row.job_address || row.address || "Address not set")}</small><small>${esc(row.crew ? `Crew: ${row.crew}` : `${row.job_count || 0} job(s)`)}</small></td><td><button class="wnq-link" data-edit='${attr(row)}'>Edit</button></td></tr>`;
  }).join("") : `<tr><td colspan="7">${empty(fallback)}</td></tr>`}</tbody></table></div>`;
  const crmCalendar = (rows) => `<div class="wnq-panel"><div class="wnq-panel-head"><h2>Calendar & Scheduling</h2></div>${rows.length ? [...rows].sort((a, b) => String(a.job_date || "").localeCompare(String(b.job_date || ""))).map((row) => `<article class="wnq-schedule-item"><time>${date(row.job_date)}</time><div><strong>${esc(row.name)}</strong><span>${esc(row.service || "Job")} · ${esc(row.job_address || row.address || "Address not set")}</span></div>${crmStatus(row.status)}</article>`).join("") : empty("No upcoming jobs are scheduled.")}</div>`;
  const crmReports = ({ rows, totals, avgJob, closeRate, topServices, topCustomers, completed, lost }) => `<div class="wnq-grid-2"><section class="wnq-panel"><div class="wnq-panel-head"><h2>Top Services</h2></div>${topServices.length ? topServices.map((item) => `<div class="wnq-work-item"><strong>${esc(item.label)}</strong><span>${money(item.total)} · ${esc(item.count)} records</span></div>`).join("") : empty("No service data yet.")}</section><section class="wnq-panel"><div class="wnq-panel-head"><h2>Top Customers</h2></div>${topCustomers.length ? topCustomers.map((item) => `<div class="wnq-work-item"><strong>${esc(item.label)}</strong><span>${money(item.total)} · ${esc(item.count)} records</span></div>`).join("") : empty("No customer data yet.")}</section></div><div class="wnq-metrics">${metric("Records", rows.length, "Visible CRM entries")}${metric("Completed", completed.length, "Won or closed")}${metric("Lost / Canceled", lost.length, "Not moving forward")}${metric("Average Job", money(avgJob), "Average value")}${metric("Close Rate", `${closeRate}%`, "Won/completed")}${metric("Profit", money(totals.revenue - totals.cost), "Visible records", totals.revenue - totals.cost >= 0 ? "positive" : "negative")}</div>`;
  const crmSettings = () => `<section class="wnq-panel"><div class="wnq-panel-head"><h2>Business Settings</h2></div><p class="wnq-note">Use this CRM to track customers, leads, jobs, revenue, expenses, profit, service history, follow-up reminders, job addresses, crew assignments, notes, and before/after photos. User roles and permissions are controlled by the WordPress user attached to this portal account.</p></section>`;
  const crmStatus = (value) => {
    const key = value || "new";
    return status(["completed","won","closed"].includes(key) ? "green" : ["lost","canceled"].includes(key) ? "red" : "yellow", humanize(key));
  };
  const fileList = (label, items = []) => Array.isArray(items) && items.length ? `<div class="wnq-existing-files is-wide"><span>${esc(label)}</span>${items.map((item) => `<a href="${esc(item.url)}" target="_blank" rel="noopener">${esc(item.name)}</a>`).join("")}</div>` : "";
  const customerForm = (row = {}) => {
    const currentType = row.record_type || "customer";
    const currentStatus = row.status || "new";
    return `<form class="wnq-panel wnq-form wnq-crm-form" enctype="multipart/form-data"><input type="hidden" name="id" value="${esc(row.id || "")}">
      <div class="wnq-panel-head"><div><span class="wnq-eyebrow">${row.id ? "Edit record" : "New record"}</span><h2>${row.id ? "Update Customer / Job" : "Add Customer / Job"}</h2></div></div>
      <fieldset class="wnq-crm-form-section"><legend>Contact</legend>
        <label><span>Record Type</span><select name="record_type">${["lead","customer","job"].map((v) => `<option value="${v}" ${currentType === v ? "selected" : ""}>${humanize(v)}</option>`).join("")}</select></label>
        ${field("name", "Customer Name", row.name, true)}${field("phone", "Phone", row.phone)}${field("email", "Email", row.email, false, "email")}${field("address", "Customer Address", row.address)}${field("lead_source", "Lead Source", row.lead_source)}
      </fieldset>
      <fieldset class="wnq-crm-form-section"><legend>Job & Schedule</legend>
        ${field("service", "Service / Job Type", row.service)}${field("job_address", "Job Address", row.job_address)}${field("crew", "Crew / Employee Assignment", row.crew)}
        <label><span>Status</span><select name="status">${["new","contacted","estimate_sent","scheduled","in_progress","completed","won","lost","closed","canceled"].map((v) => `<option value="${v}" ${currentStatus === v ? "selected" : ""}>${humanize(v)}</option>`).join("")}</select></label>
        ${field("follow_up_date", "Follow-up Date", row.follow_up_date, false, "date")}${field("reminder_date", "Reminder Date", row.reminder_date, false, "date")}${field("job_date", "Job Date", row.job_date, false, "date")}${field("completion_date", "Completion Date", row.completion_date, false, "date")}
      </fieldset>
      <fieldset class="wnq-crm-form-section"><legend>Revenue & Profit</legend>
        ${field("job_count", "Job Count", row.job_count || 0, false, "number")}${field("estimated_value", "Estimated Value", row.estimated_value || 0, false, "number")}${field("final_value", "Final Revenue", row.final_value || 0, false, "number")}${field("job_cost", "Job Cost", row.job_cost || 0, false, "number")}
      </fieldset>
      <fieldset class="wnq-crm-form-section"><legend>Notes & Files</legend>
        <label class="is-wide"><span>Customer Notes / Service History</span><textarea name="notes" rows="3">${esc(row.notes || "")}</textarea></label>
        <label class="is-wide"><span>Internal Notes</span><textarea name="internal_notes" rows="3">${esc(row.internal_notes || "")}</textarea></label>
        <label class="is-wide"><span>Lost Lead / Cancellation Reason</span><textarea name="lost_reason" rows="2">${esc(row.lost_reason || "")}</textarea></label>
        ${fileList("Saved files", row.files)}${fileList("Before photos", row.before_photos)}${fileList("After photos", row.after_photos)}
        <label class="is-wide wnq-upload"><span>Files & Photos</span><input type="file" name="attachments[]" multiple accept="image/*,.pdf,.doc,.docx,.txt"><small>Upload estimates, invoices, signed docs, or job photos.</small></label>
        <label class="wnq-upload"><span>Before Photos</span><input type="file" name="before_photos[]" multiple accept="image/*"></label><label class="wnq-upload"><span>After Photos</span><input type="file" name="after_photos[]" multiple accept="image/*"></label>
      </fieldset>
      <div class="wnq-form-actions"><button class="wnq-button" type="submit">Save Record</button><button type="button" class="wnq-button is-secondary" data-cancel>Cancel</button></div></form>`;
  };
  const field = (name, label, value = "", required = false, type = "text") => `<label><span>${esc(label)}</span><input type="${type}" name="${name}" value="${esc(value)}" ${required ? "required" : ""} ${type === "number" ? 'min="0" step="0.01"' : ""}></label>`;

  async function ads(view, refresh) {
    const data = await load("ads", refresh);
    view.innerHTML = `${heading("Ads", "Google Ads", "Read-only campaign visibility for spend, clicks, conversions, and lead quality.")}
      <div class="wnq-health">
        <div><span>API Status</span>${status(data.configured ? "green" : "yellow", data.configured ? "Connected" : "Setup needed")}</div>
        <div><span>Access Mode</span><strong>${esc(humanize(data.mode || "read_only"))}</strong></div>
        <div><span>Access Level</span><strong>${esc(humanize(data.access_level || "test"))}</strong></div>
      </div>
      <div class="wnq-metrics">${metric("Spend", money(data.summary?.spend || 0), "Selected period")}${metric("Clicks", data.summary?.clicks || 0, "Ad clicks")}${metric("Conversions", data.summary?.conversions || 0, "Tracked leads")}${metric("Cost / Conversion", money(data.summary?.cost_per_conversion || 0), "Spend per lead")}</div>
      <section class="wnq-panel"><div class="wnq-panel-head"><h2>Account Match</h2>${status(data.customer_id ? "green" : "yellow", data.customer_id ? "Matched" : "Waiting")}</div><p><strong>${esc(data.matched_account_name || "No Ads account matched yet")}</strong>${data.customer_id ? ` · ${esc(data.customer_id)}` : ""}${data.match_score ? ` · ${esc(data.match_score)}% match` : ""}</p>${data.errors?.length ? `<div class="wnq-error"><strong>Google Ads API message</strong><p>${esc(data.errors[0])}</p></div>` : ""}</section>
      ${cfg.isAdmin ? adsSettingsForm(data) : adsClientNotice(data)}
      <section class="wnq-panel wnq-table-wrap"><div class="wnq-panel-head"><h2>Campaigns</h2></div><table><thead><tr><th>Campaign</th><th>Status</th><th>Spend</th><th>Clicks</th><th>Impr.</th><th>CTR</th><th>Conversions</th></tr></thead><tbody>${data.campaigns?.length ? data.campaigns.map((row) => `<tr><td><strong>${esc(row.name)}</strong></td><td>${status(row.status === "enabled" ? "green" : "yellow", row.status)}</td><td>${money(row.spend)}</td><td>${esc(row.clicks)}</td><td>${esc(row.impressions)}</td><td>${esc(Math.round(Number(row.ctr || 0) * 10000) / 100)}%</td><td>${esc(row.conversions)}</td></tr>`).join("") : `<tr><td colspan="7">${empty("Google Ads reporting is ready for setup. No campaign data is being pulled yet.")}</td></tr>`}</tbody></table></section>`;
    const form = view.querySelector("#wnq-ads-settings");
    if (form) {
      form.addEventListener("submit", async (event) => {
        event.preventDefault();
        const result = await api("/portal/ads-settings", { method: "POST", body: JSON.stringify(Object.fromEntries(new FormData(form))) });
        state.cache.ads = result; show("ads", true);
      });
    }
  }

  const adsSettingsForm = (data) => `<form class="wnq-panel wnq-form" id="wnq-ads-settings"><div class="wnq-panel-head"><div><span class="wnq-eyebrow">Admin Setup</span><h2>Google Ads Read-only Connection</h2></div></div>
    ${field("customer_id", "Google Ads Customer ID", data.customer_id || "")}${field("manager_customer_id", "Manager Account ID", data.manager_customer_id || "")}${field("service_account_email", "Service Account Email", data.service_account_email || "")}
    ${field("api_key", "API Key", data.has_api_key ? "Saved" : "", false, "password")}${field("developer_token", "Developer Token", data.has_developer_token ? "Saved" : "", false, "password")}${field("oauth_client_id", "OAuth Client ID", data.has_oauth ? "Saved" : "")}
    ${field("oauth_client_secret", "OAuth Client Secret", data.has_oauth ? "Saved" : "", false, "password")}${field("refresh_token", "OAuth Refresh Token", data.has_oauth ? "Saved" : "", false, "password")}
    <div class="is-wide wnq-ads-requirements"><strong>Still needed for live API pulls</strong>${(data.requirements || []).map((item) => `<span>${esc(item)}</span>`).join("")}<p class="wnq-note">The API key is stored server-side. The frontend only receives saved/not-saved flags.</p></div>
    <div class="wnq-form-actions"><button class="wnq-button">Save Ads Setup</button></div></form>`;
  const adsClientNotice = (data) => `<section class="wnq-panel"><div class="wnq-panel-head"><h2>Ads Access</h2>${status(data.configured ? "green" : "yellow", data.configured ? "Connected" : "Pending")}</div><p class="wnq-note">Once Golden Web Marketing connects your Google Ads account, this tab will show read-only campaign results and reporting.</p></section>`;

  async function messages(view, refresh) {
    const tickets = await load("tickets", refresh);
    const messagesTab = root.querySelector('[data-tab="messages"]');
    if (messagesTab) messagesTab.textContent = "Support";
    view.innerHTML = `${heading("Support", "Support Tickets", "Create a request, track its status, and keep every reply together.")}
      <div class="wnq-toolbar wnq-ticket-toolbar"><input type="search" id="wnq-ticket-search" placeholder="Search tickets"><select id="wnq-ticket-filter"><option value="all">All tickets</option><option value="open">Open</option><option value="in_progress">In progress</option><option value="waiting">Waiting</option><option value="resolved">Resolved</option><option value="closed">Closed</option></select><button class="wnq-button" id="wnq-new-ticket">New Support Ticket</button></div><div id="wnq-ticket-compose"></div>
      <div class="wnq-ticket-layout"><div class="wnq-ticket-list"></div><div id="wnq-ticket-thread">${empty("Select a ticket to view the conversation.")}</div></div>`;
    const compose = view.querySelector("#wnq-ticket-compose");
    const list = view.querySelector(".wnq-ticket-list");
    const renderList = () => {
      const query = view.querySelector("#wnq-ticket-search").value.toLowerCase();
      const filter = view.querySelector("#wnq-ticket-filter").value;
      const filtered = tickets.filter((ticket) => (filter === "all" || ticket.ticket_status === filter) && `${ticket.subject} ${ticket.ticket_key} ${ticket.category}`.toLowerCase().includes(query));
      list.innerHTML = filtered.length ? filtered.map(ticketCard).join("") : empty("No tickets match this search.");
      list.querySelectorAll("[data-ticket]").forEach((button) => button.addEventListener("click", () => openTicket(button.dataset.ticket, tickets, view)));
    };
    renderList();
    view.querySelector("#wnq-ticket-search").addEventListener("input", renderList);
    view.querySelector("#wnq-ticket-filter").addEventListener("change", renderList);
    view.querySelector("#wnq-new-ticket").addEventListener("click", () => {
      compose.innerHTML = ticketForm();
      bindTicketForm(compose.querySelector("form"));
    });
  }

  const ticketCard = (ticket) => `<button type="button" class="wnq-ticket-card ${ticket.unread ? "is-unread" : ""}" data-ticket="${esc(ticket.ticket_key)}"><div><strong>${esc(ticket.subject)}</strong>${status(ticket.ticket_status === "resolved" || ticket.ticket_status === "closed" ? "green" : "yellow", humanize(ticket.ticket_status))}</div><p>${esc(ticket.messages?.[ticket.messages.length - 1]?.message || "")}</p><small>${esc(ticket.ticket_key.toUpperCase())} · ${esc(humanize(ticket.category))} · Updated ${date(ticket.updated_at)}</small><em>${esc(ticket.response_time)}</em></button>`;
  const ticketForm = (ticket = {}) => `<form class="wnq-panel wnq-ticket-form" enctype="multipart/form-data"><input type="hidden" name="ticket_key" value="${esc(ticket.ticket_key || "")}">${ticket.ticket_key ? `<input type="hidden" name="subject" value="${esc(ticket.subject)}"><input type="hidden" name="category" value="${esc(ticket.category)}"><input type="hidden" name="priority" value="${esc(ticket.priority)}">` : `${field("subject", "Subject", "", true)}<label><span>Category</span><select name="category"><option value="general">General Support</option><option value="website">Website Update</option><option value="seo">SEO / Report</option><option value="billing">Billing</option><option value="training">Training</option></select></label><label><span>Priority</span><select name="priority"><option value="normal">Normal</option><option value="low">Low</option><option value="high">High</option><option value="urgent">Urgent</option></select></label>`}<label class="is-wide"><span>${ticket.ticket_key ? "Reply" : "How can we help?"}</span><textarea name="message" rows="5" required></textarea></label><label class="is-wide wnq-upload"><span>Add screenshots or files</span><input type="file" name="attachments[]" multiple accept="image/*,.pdf,.doc,.docx,.txt"><small>Up to 5 files, 10 MB each.</small></label><div class="wnq-form-actions"><button class="wnq-button">${ticket.ticket_key ? "Send Reply" : "Create Ticket"}</button></div></form>`;
  const bindTicketForm = (form) => form.addEventListener("submit", async (event) => {
    event.preventDefault();
    await api("/portal/messages", { method: "POST", body: new FormData(form) });
    delete state.cache.tickets; delete state.cache.overview; show("messages", true);
  });
  const attachments = (items = []) => items.length ? `<div class="wnq-attachments">${items.map((item) => `<a href="${esc(item.url)}" target="_blank" rel="noopener">${esc(item.name)}</a>`).join("")}</div>` : "";
  const openTicket = async (key, tickets, view) => {
    const summary = tickets.find((item) => item.ticket_key === key);
    if (!summary) return;
    const ticket = await api(`/portal/tickets/${encodeURIComponent(key)}`);
    if (!ticket) return;
    const index = tickets.findIndex((item) => item.ticket_key === key);
    if (index >= 0) tickets[index] = ticket;
    view.querySelector(`[data-ticket="${key}"]`)?.classList.remove("is-unread");
    const thread = view.querySelector("#wnq-ticket-thread");
    const closed = ["resolved", "closed"].includes(ticket.ticket_status);
    thread.innerHTML = `<section class="wnq-panel wnq-ticket-thread"><div class="wnq-panel-head"><div><span class="wnq-eyebrow">${esc(ticket.ticket_key.toUpperCase())}</span><h2>${esc(ticket.subject)}</h2><small>${esc(ticket.response_time)}</small></div>${status(closed ? "green" : "yellow", humanize(ticket.ticket_status))}</div><div class="wnq-thread-messages">${ticket.messages.map((message) => `<article class="${message.sender_role === "admin" ? "is-support" : "is-client"}"><div><strong>${message.sender_role === "admin" ? "Golden Web Marketing" : "You"}</strong><time>${date(message.created_at)}</time></div><p>${esc(message.message)}</p>${attachments(message.attachments)}</article>`).join("")}</div>${closed ? `<button class="wnq-button" id="wnq-reopen-ticket">Reopen Ticket</button>` : ticketForm(ticket)}</section>`;
    if (closed) {
      thread.querySelector("#wnq-reopen-ticket").addEventListener("click", async () => {
        const data = new FormData(); data.set("ticket_key", ticket.ticket_key); data.set("subject", ticket.subject); data.set("category", ticket.category); data.set("priority", ticket.priority); data.set("ticket_status", "open"); data.set("message", "Ticket reopened by client.");
        await api("/portal/messages", { method: "POST", body: data }); delete state.cache.tickets; delete state.cache.overview; show("messages", true);
      });
    } else bindTicketForm(thread.querySelector("form"));
  };

  async function requests(view, refresh) {
    const data = await load("requests", refresh);
    const types = data.types || {};
    view.innerHTML = `${heading("Request Center", "What can we help with?", "Submit structured requests so our team has everything needed to get started.")}
      <div class="wnq-request-types">${Object.entries(types).map(([key, item]) => `<button type="button" data-request-type="${esc(key)}"><strong>${esc(item.label)}</strong><span>${esc(item.description)}</span></button>`).join("")}</div>
      <div id="wnq-service-request-form"></div><section class="wnq-panel"><div class="wnq-panel-head"><h2>Your Requests</h2></div><div class="wnq-service-request-list">${data.items.length ? data.items.map(requestCard).join("") : empty("No requests submitted yet.")}</div></section>`;
    view.querySelectorAll("[data-request-type]").forEach((button) => button.addEventListener("click", () => openRequestForm(button.dataset.requestType, types, view)));
  }

  const requestCard = (item) => `<article><div><span>${esc(item.request_key.toUpperCase())}</span><strong>${esc(item.title)}</strong><p>${esc(item.details || "")}</p><small>${esc(humanize(item.request_type))} · Submitted ${date(item.created_at)}</small>${attachments(item.attachments)}</div>${status(item.status === "completed" ? "green" : item.status === "declined" ? "red" : "yellow", humanize(item.status))}</article>`;
  const requestFields = {
    website_update: [["page_url", "Page URL", "url"], ["requested_change", "What should change?", "textarea"]],
    new_page: [["page_type", "Page Type", "text"], ["primary_service", "Primary Service", "text"], ["target_city", "Target City", "text"]],
    blog: [["topic", "Topic", "text"], ["target_keywords", "Target Keywords", "text"]],
    report_question: [["report_period", "Report Month", "month"], ["question", "What would you like explained?", "textarea"]],
    strategy_call: [["preferred_date", "Preferred Date", "date"], ["preferred_time", "Preferred Time", "time"], ["goals", "What should we discuss?", "textarea"]],
  };
  const openRequestForm = (type, types, view) => {
    const rootForm = view.querySelector("#wnq-service-request-form");
    const dynamic = (requestFields[type] || []).map(([name, label, inputType]) => inputType === "textarea" ? `<label class="is-wide"><span>${esc(label)}</span><textarea name="${esc(name)}" rows="4"></textarea></label>` : field(name, label, "", false, inputType)).join("");
    rootForm.innerHTML = `<form class="wnq-panel wnq-form wnq-service-request-form" enctype="multipart/form-data"><input type="hidden" name="request_type" value="${esc(type)}"><div class="wnq-panel-head"><div><span class="wnq-eyebrow">New Request</span><h2>${esc(types[type]?.label || "Request")}</h2></div></div>${field("title", "Request Title", "", true)}<label><span>Priority</span><select name="priority"><option value="normal">Normal</option><option value="low">Low</option><option value="high">High</option><option value="urgent">Urgent</option></select></label>${dynamic}<label class="is-wide"><span>Additional Details</span><textarea name="details" rows="4"></textarea></label><label class="is-wide wnq-upload"><span>Attachments</span><input type="file" name="attachments[]" multiple accept="image/*,.pdf,.doc,.docx,.txt"><small>Up to 5 files, 10 MB each.</small></label><div class="wnq-form-actions"><button class="wnq-button">Submit Request</button><button type="button" class="wnq-button is-secondary" data-cancel>Cancel</button></div></form>`;
    const form = rootForm.querySelector("form");
    form.querySelector("[data-cancel]").addEventListener("click", () => rootForm.innerHTML = "");
    form.addEventListener("submit", async (event) => {
      event.preventDefault(); const data = new FormData(form); const requestData = {};
      (requestFields[type] || []).forEach(([name]) => { requestData[name] = data.get(name) || ""; data.delete(name); });
      data.set("request_data", JSON.stringify(requestData));
      await api("/portal/requests", { method: "POST", body: data }); delete state.cache.requests; show("requests", true);
    });
  };

  async function billing(view, refresh) {
    const data = await load("overview", refresh); const client = data.client || {};
    view.innerHTML = `${heading("Account", "Billing", "Your plan and billing status at a glance.")}
      <div class="wnq-health"><div><span>Billing status</span>${status(data.health.billing.tone, data.health.billing.label)}</div><div><span>Plan</span><strong>${esc(client.tier || "Not set")}</strong></div><div><span>Billing cycle</span><strong>${esc(client.billing_cycle || "Not set")}</strong></div></div>
      <div class="wnq-panel"><div class="wnq-billing-total"><span>Monthly service rate</span><strong>${money(client.monthly_rate)}</strong></div><p>Last payment: ${date(client.last_payment_date)}</p><p class="wnq-note">Stripe payment management will appear here once Stripe is connected.</p></div>`;
  }

  async function learning(view, refresh) {
    const data = await load("learning", refresh);
    view.innerHTML = `${heading("Resources", "Learning Center", "Practical courses for improving your marketing, sales, and customer experience.")}
      <section><div class="wnq-section-head"><div><span class="wnq-eyebrow">Courses</span><h2>Recommended for your business</h2></div></div><div class="wnq-learning">${data.courses.map((course) => `<article><span>${esc(course.category)} · ${esc(course.duration)}</span><h2>${esc(course.title)}</h2><p>${esc(course.description)}</p><button class="wnq-link" disabled>Course coming soon</button></article>`).join("")}</div></section>
      <section class="wnq-panel"><div class="wnq-panel-head"><div><span class="wnq-eyebrow">Requests</span><h2>Request a guide or course</h2></div></div><form class="wnq-learning-request wnq-form"><label><span>Request Type</span><select name="request_type"><option value="topic">New Topic</option><option value="course">New Course</option><option value="help">One-on-One Help</option></select></label>${field("title", "What would you like help with?", "", true)}<label class="is-wide"><span>Details</span><textarea name="details" rows="4"></textarea></label><div class="wnq-form-actions"><button class="wnq-button">Submit Request</button></div></form>${data.requests.length ? `<div class="wnq-request-list">${data.requests.map((row) => `<div><strong>${esc(row.title)}</strong>${status(row.status === "completed" ? "green" : "yellow", row.status)}<small>${date(row.created_at)}</small></div>`).join("")}</div>` : ""}</section>`;
    view.querySelector("form").addEventListener("submit", async (event) => {
      event.preventDefault(); await api("/portal/learning-requests", { method: "POST", body: JSON.stringify(Object.fromEntries(new FormData(event.currentTarget))) });
      delete state.cache.learning; show("learning", true);
    });
  }

  async function profile(view, refresh) {
    const client = await load("profile", refresh);
    view.innerHTML = `${heading("Business", "Business Profile", "Keep the public business information used across your account accurate.")}
      <form class="wnq-panel wnq-form wnq-profile-form">${field("company", "Business Name", client.company || client.name, true)}${field("phone", "Phone", client.phone)}${field("email", "Email", client.email, true, "email")}${field("website", "Website", client.website, false, "url")}${field("business_address", "Business Address", client.business_address)}${field("city", "City", client.city)}${field("state", "State", client.state)}<label class="is-wide"><span>Services</span><textarea name="active_services" rows="4" placeholder="One service per line">${esc((client.active_services || []).join("\n"))}</textarea></label><div class="wnq-form-actions"><button class="wnq-button">Save Business Profile</button><span id="wnq-profile-status"></span></div></form>`;
    view.querySelector("form").addEventListener("submit", async (event) => {
      event.preventDefault();
      const result = await api("/portal/profile", { method: "POST", body: JSON.stringify(Object.fromEntries(new FormData(event.currentTarget))) });
      state.cache.profile = result; delete state.cache.overview;
      view.querySelector("#wnq-profile-status").textContent = "Profile saved.";
    });
  }

  shell();
  show("overview");
})();
