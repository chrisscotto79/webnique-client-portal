(function () {
  "use strict";
  const root = document.getElementById("wnq-portal-root");
  const cfg = window.WNQ_PORTAL || {};
  if (!root) return;

  const state = { active: "overview", cache: {}, clientId: cfg.clientId || "" };
  const canSeePrivate = !!cfg.isAdmin;
  const tabs = [
    ["overview", "Overview"], ["leads", "Leads"], ["jobs", "Jobs"], ["calendar", "Calendar"],
    ["followups", "Follow-ups"], ["crm-reports", "Reports"], ["marketing-work", "Marketing Work"],
    ["ads", "Ads"], ["billing", "Billing"], ["settings", "Settings"]
  ];
  const crmRoutes = { overview: "dashboard", leads: "leads", jobs: "jobs", calendar: "calendar", followups: "followups", "crm-reports": "reports", "marketing-work": "marketing-work" };
  const statusLabels = { new: "New Lead", contacted: "Contacted", quoted: "Quoted", scheduled: "Scheduled", in_progress: "In Progress", completed: "Completed", lost: "Lost / Canceled", canceled: "Lost / Canceled" };
  const statusOptions = [["new", "New Lead"], ["contacted", "Contacted"], ["quoted", "Quoted"], ["scheduled", "Scheduled"], ["in_progress", "In Progress"], ["completed", "Completed"], ["lost", "Lost / Canceled"], ["canceled", "Canceled"]];
  const leadSourceOptions = ["Google Ads", "Google Business Profile", "Organic Search", "Website Form", "Phone Call", "Referral", "Facebook", "Instagram", "Other"];
  const workTypeOptions = [["seo", "SEO"], ["google_ads", "Google Ads"], ["website_update", "Website Update"], ["gbp", "Google Business Profile"], ["content", "Content"], ["tracking_analytics", "Tracking / Analytics"], ["technical_fix", "Technical Fix"], ["other", "Other"]];
  const esc = (value) => String(value ?? "").replace(/[&<>"']/g, (char) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#039;" }[char]));
  const money = (value) => new Intl.NumberFormat("en-US", { style: "currency", currency: "USD", maximumFractionDigits: 0 }).format(Number(value || 0));
  const date = (value) => {
    if (!value) return "Not set";
    const raw = String(value);
    const normalized = /^\d{4}-\d{2}-\d{2}$/.test(raw) ? `${raw}T00:00:00` : raw.replace(" ", "T");
    const parsed = new Date(normalized);
    return Number.isNaN(parsed.getTime()) ? "Not set" : parsed.toLocaleDateString();
  };
  const attr = (value) => esc(JSON.stringify(value));
  const activeLabel = (key) => tabs.find(([tab]) => tab === key)?.[1] || "Dashboard";
  const navIcon = (key) => ({ overview: "grid", leads: "users", jobs: "briefcase", calendar: "calendar", followups: "check", "crm-reports": "chart", "marketing-work": "megaphone", ads: "target", billing: "receipt", settings: "gear" }[key] || "dot");
  const api = async (path, options = {}) => {
    const requestUrl = new URL(`${cfg.restUrl.replace(/\/$/, "")}${path}`);
    if (cfg.isAdmin && state.clientId) requestUrl.searchParams.set("client_id", state.clientId);
    const method = String(options.method || "GET").toUpperCase();
    if (method === "GET") requestUrl.searchParams.set("_wnq", Date.now());
    const isForm = options.body instanceof FormData;
    const response = await fetch(requestUrl.toString(), {
      credentials: "same-origin",
      cache: "no-store",
      headers: { "X-WP-Nonce": cfg.nonce, ...(isForm ? {} : { "Content-Type": "application/json" }) },
      ...options,
    });
    const data = await response.json().catch(() => null);
    if (!data) {
      throw new Error(`Invalid server response (${response.status}). Please refresh and try again.`);
    }
    if (!response.ok || data.ok === false) {
      throw new Error(data.error || data.message || `Request failed (${response.status}).`);
    }
    return data.data ?? data;
  };
  const status = (tone, label) => `<span class="wnq-status is-${esc(tone)}"><i></i>${esc(label)}</span>`;
  const humanize = (value) => String(value || "").replaceAll("_", " ");
  const empty = (message) => `<div class="wnq-empty">${esc(message)}</div>`;
  const heading = (eyebrow, title, copy = "") => `<header class="wnq-page-head"><span>${esc(eyebrow)}</span><h1>${esc(title)}</h1>${copy ? `<p>${esc(copy)}</p>` : ""}</header>`;
  const trend = (value) => `<strong class="${Number(value) >= 0 ? "wnq-positive" : "wnq-negative"}">${money(value)}</strong>`;
  const formObject = (form) => {
    const data = {};
    new FormData(form).forEach((value, key) => {
      if (typeof File !== "undefined" && value instanceof File) return;
      data[key.replace(/\[\]$/, "")] = value;
    });
    return data;
  };
  const formHasFiles = (form) => Array.from(form.querySelectorAll('input[type="file"]')).some((input) => Array.from(input.files || []).some((file) => file && file.size > 0));
  const formBody = (form) => formHasFiles(form) ? new FormData(form) : JSON.stringify(formObject(form));
  const formStatus = (target, tone, message) => {
    const existing = target.querySelector(".wnq-form-status");
    if (existing) existing.remove();
    target.insertAdjacentHTML("afterbegin", `<div class="wnq-form-status is-${esc(tone)}">${esc(message)}</div>`);
  };
  const performanceChart = (rows = []) => {
    const amountForChart = (row) => canSeePrivate && row.profit !== null && row.profit !== undefined ? Number(row.profit || 0) : Number(row.revenue || 0);
    const max = Math.max(1, ...rows.map((row) => Math.max(Math.abs(amountForChart(row)), Number(row.jobs || 0))));
    return `<div class="wnq-chart">${rows.map((row) => {
      const amount = amountForChart(row);
      const height = Math.max(4, Math.round((Math.abs(amount) / max) * 100));
      return `<div class="wnq-chart-column"><span class="wnq-chart-bar ${amount < 0 ? "is-negative" : ""}" style="height:${height}%"></span><strong>${esc(row.label)}</strong><small>${esc(row.jobs)} jobs</small><small>${canSeePrivate ? trend(amount) : money(amount)}</small></div>`;
    }).join("")}</div>`;
  };
  const viewAs = () => {
    if (cfg.isAdmin && Array.isArray(cfg.viewAsClients) && cfg.viewAsClients.length) {
      return `<div class="wnq-view-as"><label for="wnq-view-as"><span>Admin Preview</span><strong>View as client/user</strong></label>
        <select id="wnq-view-as">${cfg.viewAsClients.map((client) => `<option value="${esc(client.clientId)}" ${client.clientId === state.clientId ? "selected" : ""}>${esc(client.label)}</option>`).join("")}</select></div>`;
    }
    return `<div class="wnq-view-as is-static"><span>Current Account</span><strong>${esc(cfg.clientLabel || state.clientId || "Client account")}</strong></div>`;
  };
  const shell = () => {
    root.innerHTML = `<div class="wnq-portal">
      <aside class="wnq-sidebar"><div class="wnq-brand"><img src="${esc(cfg.logoUrl || "")}" alt="Golden Web Marketing"><span>Client Portal</span></div>
      ${viewAs()}
      <nav>${tabs.map(([key, label]) => `<button type="button" data-tab="${key}"><i class="wnq-nav-icon is-${esc(navIcon(key))}" aria-hidden="true"></i><span>${esc(label)}</span></button>`).join("")}</nav>
      <div class="wnq-sidebar-foot"><span>Signed in as</span><strong>${esc(cfg.user?.name || "Client")}</strong><small>Portal v${esc(cfg.version || "unknown")}</small></div></aside>
      <main class="wnq-main"><div class="wnq-topbar"><div><span>Golden Web Marketing <em>v${esc(cfg.version || "unknown")}</em></span><strong id="wnq-top-title">Overview</strong></div><button type="button" class="wnq-button is-secondary" id="wnq-refresh-view">Refresh</button></div><div id="wnq-view">${empty("Loading dashboard...")}</div></main></div>`;
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
      if (Object.prototype.hasOwnProperty.call(crmRoutes, key)) {
        await customers(view, refresh, { forceTab: crmRoutes[key], hideSubnav: true });
        return;
      }
      const renderers = { reports, customers, ads, messages, requests, billing, learning, profile, settings: profile, "seo-reports": reports };
      await (renderers[key] || ((target, reload) => customers(target, reload, { forceTab: "dashboard", hideSubnav: true })))(view, refresh);
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
        ${metric("Leads & Jobs", crm.total || 0, "Tracked records")}
        ${metric("Jobs this month", current.jobs || 0, "Scheduled or completed work")}
        ${metric("Revenue Tracked", money(current.revenue), "Saved job revenue")}
        ${canSeePrivate ? metric("Net Tracked Profit", money(current.profit), "Revenue minus logged costs", Number(current.profit) >= 0 ? "positive" : "negative") : metric("Follow-ups Due", crm.new_count || 0, "Open leads to review")}
      </div>
      <section class="wnq-panel"><div class="wnq-panel-head"><h2>${canSeePrivate ? "Jobs & Profit by Month" : "Revenue & Jobs by Month"}</h2><span class="${Number(canSeePrivate ? current.profit : current.revenue) >= 0 ? "wnq-positive" : "wnq-negative"}">${canSeePrivate ? `${money(current.profit || 0)} net this month` : `${money(current.revenue || 0)} tracked this month`}</span></div>${performanceChart(data.performance)}</section>
      <section class="wnq-grid-2">
        <div class="wnq-panel"><div class="wnq-panel-head"><h2>Action Items</h2></div>${actions.length ? actions.map((item) => `<button class="wnq-action" data-go="${esc(item.type)}"><strong>${esc(item.label)}</strong><span>Review</span></button>`).join("") : empty("Nothing needs your attention.")}</div>
        <div class="wnq-panel"><div class="wnq-panel-head"><h2>Latest Report</h2></div>${data.latest_report ? `<strong>${esc(data.latest_report.report_type || "Monthly")} report</strong><p>${date(data.latest_report.period_start)} through ${date(data.latest_report.period_end)}</p><button class="wnq-button" data-go="reports">View reports</button>` : empty("Your first report will appear here.")}</div>
      </section>`;
    view.querySelectorAll("[data-go]").forEach((button) => button.addEventListener("click", () => show(button.dataset.go)));
  }
  const metric = (label, value, note, tone = "") => `<div class="wnq-metric"><span>${esc(label)}</span><strong class="${tone ? `wnq-${tone}` : ""}">${esc(value)}</strong><small>${esc(note)}</small></div>`;
  const crmHeader = (range = "month") => `<header class="wnq-crm-head"><div><span class="wnq-eyebrow">Golden Web Marketing</span><h1>CRM Dashboard</h1><p>Track leads, jobs, revenue, follow-ups, and marketing work in one place.</p></div><div class="wnq-crm-head-actions"><label><span>Date range</span><select id="wnq-crm-range"><option value="month" ${range === "month" ? "selected" : ""}>This Month</option><option value="30" ${range === "30" ? "selected" : ""}>Last 30 Days</option><option value="90" ${range === "90" ? "selected" : ""}>Last 90 Days</option><option value="all" ${range === "all" ? "selected" : ""}>All Time</option></select></label><button type="button" class="wnq-button is-secondary" id="wnq-crm-header-refresh">Refresh</button></div></header>`;
  const crmKpiCard = (icon, label, value, note, tone = "") => `<article class="wnq-kpi-card ${tone ? `is-${esc(tone)}` : ""}"><span class="wnq-kpi-icon is-${esc(icon)}" aria-hidden="true"></span><div><small>${esc(label)}</small><strong>${esc(value)}</strong><em>${esc(note)}</em></div></article>`;
  const crmKpis = ({ leadBase, totals, completedRevenue, openOpportunities, closeRate, upcoming, overdue }) => `<section class="wnq-kpi-grid">
    ${crmKpiCard("funnel", "Opportunity Status", leadBase || 0, "Total")}
    ${crmKpiCard("dollar", "Total Revenue", money(totals.revenue), "This Month")}
    ${crmKpiCard("trophy", "Won Revenue", money(completedRevenue), "This Month", "green")}
    ${crmKpiCard("briefcase", "Open Opportunities", openOpportunities || 0, "Active")}
    ${crmKpiCard("trend", "Close Rate", `${closeRate}%`, "This Month")}
    ${crmKpiCard("calendar", "Upcoming Jobs", upcoming.length, "Next 30 Days", "green")}
    ${crmKpiCard("clock", "Overdue Follow-ups", overdue.length, "Requires Action", overdue.length ? "red" : "")}
  </section>`;
  const crmRangeBounds = (range) => {
    if (range === "all") return null;
    const now = new Date();
    const start = new Date(now);
    if (range === "month") start.setDate(1);
    if (range === "30") start.setDate(now.getDate() - 30);
    if (range === "90") start.setDate(now.getDate() - 90);
    return { from: start.toISOString().slice(0, 10), to: now.toISOString().slice(0, 10) };
  };

  async function reports(view, refresh) {
    const rows = await load("reports", refresh);
    view.innerHTML = `${heading("Results", "Reports", "A simple archive of your marketing reports.")}
      <div class="wnq-panel"><div class="wnq-list-head"><span>Report</span><span>Period</span><span>Status</span></div>
      ${rows.length ? rows.map((row) => `<div class="wnq-list-row"><strong>${esc(row.report_type || "Monthly")} Report</strong><span>${date(row.period_start)} - ${date(row.period_end)}</span><span>${status(row.status === "ready" || row.status === "sent" ? "green" : "yellow", row.status || "Draft")} <a class="wnq-link" href="${esc(row.view_url)}" target="_blank" rel="noopener">View Full Report</a> <a class="wnq-link" href="${esc(row.pdf_url)}">Download PDF</a></span></div>`).join("") : empty("No SEO OS reports are available yet.")}</div>`;
  }

  async function customers(view, refresh, options = {}) {
    const [rows, workRows, performance] = await Promise.all([load("customers", refresh), load("work", refresh), load("performance", refresh)]);
    const today = new Date().toISOString().slice(0, 10);
    const wonStatuses = ["completed"];
    const lostStatuses = ["lost", "canceled"];
    const leadStatuses = ["new", "contacted", "quoted"];
    const jobStatuses = ["scheduled", "in_progress", "completed", "canceled"];
    const filters = {
      search: sessionStorage.getItem("wnqCrmSearch") || "",
      status: sessionStorage.getItem("wnqCrmStatus") || "all",
      from: sessionStorage.getItem("wnqCrmFrom") || "",
      to: sessionStorage.getItem("wnqCrmTo") || "",
      service: sessionStorage.getItem("wnqCrmService") || "all",
      source: sessionStorage.getItem("wnqCrmSource") || "all",
      crew: sessionStorage.getItem("wnqCrmCrew") || "all",
    };
    const crmRange = sessionStorage.getItem("wnqCrmRange") || "month";
    const rangeBounds = crmRangeBounds(crmRange);
    if (rangeBounds && !filters.from && !filters.to) {
      filters.from = rangeBounds.from;
      filters.to = rangeBounds.to;
    }
    const visibleRows = filterCrmRows(rows, filters);
    const totals = crmTotals(visibleRows);
    const isFinished = (row) => [...wonStatuses, ...lostStatuses].includes(row.status);
    const isJob = (row) => row.record_type === "job" || jobStatuses.includes(row.status) || Boolean(row.job_date) || Number(row.job_count || 0) > 0 || Number(row.final_value || 0) > 0;
    const leads = visibleRows.filter((row) => leadStatuses.includes(row.status) && !isFinished(row));
    const activeJobs = visibleRows.filter((row) => ["scheduled", "in_progress"].includes(row.status));
    const jobs = visibleRows.filter(isJob);
    const scheduledJobs = visibleRows.filter((row) => ["scheduled", "in_progress"].includes(row.status) && row.job_date);
    const upcoming = scheduledJobs.filter((row) => row.job_date >= today);
    const overdue = visibleRows.filter((row) => row.follow_up_date && row.follow_up_date < today && ![...wonStatuses, ...lostStatuses].includes(row.status));
    const completed = visibleRows.filter((row) => wonStatuses.includes(row.status));
    const lost = visibleRows.filter((row) => lostStatuses.includes(row.status));
    const avgJob = totals.jobs ? totals.revenue / totals.jobs : 0;
    const leadBase = completed.length + lost.length + activeJobs.length + leads.length;
    const closeRate = completed.length + lost.length ? Math.round((completed.length / (completed.length + lost.length)) * 100) : 0;
    const topServices = topBy(visibleRows, "service", "final_value");
    const topCustomers = topBy(visibleRows, "name", "final_value");
    const topSources = countBy(visibleRows, "lead_source");
    const completedRevenue = completed.reduce((sum, row) => sum + Number(row.final_value || 0), 0);
    const openOpportunities = leads.length + activeJobs.length;
    const activeCrmTab = options.forceTab || sessionStorage.getItem("wnqCrmTab") || "dashboard";
    const crmNotice = sessionStorage.getItem("wnqCrmNotice") || "";
    if (crmNotice) sessionStorage.removeItem("wnqCrmNotice");
    const filterOptions = [["all", "All records"], ["lead", "Lead records"], ["customer", "Customer records"], ["job", "Job records"], ...statusOptions];
    const serviceOptions = optionsFrom(rows, "service");
    const sourceOptions = optionsFrom(rows, "lead_source");
    const crewOptions = optionsFrom(rows, "crew");
    view.innerHTML = `${crmHeader(crmRange)}
      ${crmNotice ? `<div class="wnq-success-inline">${esc(crmNotice)}</div>` : ""}
      ${crmKpis({ leadBase, totals, completedRevenue, openOpportunities, closeRate, upcoming, overdue })}
      ${canSeePrivate && totals.revenue === 0 && totals.cost > 0 ? `<p class="wnq-crm-finance-note">Costs are logged, but no revenue has been tracked yet. Profit will update once completed job revenue is added.</p>` : ""}
      <div class="wnq-crm-controls">
        <label><span>Search</span><input type="search" id="wnq-crm-search" value="${esc(filters.search)}" placeholder="Name, service, source, address, notes"></label>
        <label><span>Status / Type</span><select id="wnq-crm-status">${filterOptions.map(([value, label]) => `<option value="${esc(value)}" ${filters.status === value ? "selected" : ""}>${esc(label)}</option>`).join("")}</select></label>
        <label><span>From</span><input type="date" id="wnq-crm-from" value="${esc(filters.from)}"></label>
        <label><span>To</span><input type="date" id="wnq-crm-to" value="${esc(filters.to)}"></label>
        <label><span>Service</span><select id="wnq-crm-service"><option value="all">All services</option>${serviceOptions.map((value) => `<option value="${esc(value)}" ${filters.service === value ? "selected" : ""}>${esc(value)}</option>`).join("")}</select></label>
        <label><span>Source</span><select id="wnq-crm-source"><option value="all">All sources</option>${sourceOptions.map((value) => `<option value="${esc(value)}" ${filters.source === value ? "selected" : ""}>${esc(value)}</option>`).join("")}</select></label>
        <label><span>Assigned</span><select id="wnq-crm-crew"><option value="all">Any crew/user</option>${crewOptions.map((value) => `<option value="${esc(value)}" ${filters.crew === value ? "selected" : ""}>${esc(value)}</option>`).join("")}</select></label>
        <button type="button" class="wnq-button is-secondary" id="wnq-crm-apply">Apply Filters</button>
        <button type="button" class="wnq-link" id="wnq-crm-clear">Clear</button>
        <button type="button" class="wnq-button" id="wnq-add-customer">Add Lead / Job</button>
      </div>
      <p class="wnq-crm-filter-note">Showing ${esc(visibleRows.length)} of ${esc(rows.length)} CRM records.</p>
      <div class="wnq-subnav ${options.hideSubnav ? "is-hidden" : ""}">${["dashboard","leads","jobs","calendar","followups","reports","marketing-work","settings"].map((key) => `<button type="button" data-crm-tab="${key}" class="${key === activeCrmTab ? "is-active" : ""}">${esc(humanize(key))}</button>`).join("")}</div>
      <div id="wnq-customer-form"></div>
      <div class="wnq-crm-tab" data-crm-panel="dashboard">${crmDashboard({ rows: visibleRows, performance, leads, activeJobs, completed, lost, upcoming, overdue, totals, completedRevenue, avgJob, closeRate, topServices, topCustomers, topSources, workRows })}</div>
      <div class="wnq-crm-tab" data-crm-panel="leads">${crmTable(leads, "Lead Pipeline", "No new leads are recorded yet. New inquiries will appear here once added.", "leads")}</div>
      <div class="wnq-crm-tab" data-crm-panel="jobs">${crmJobsPanel(activeJobs, completed)}</div>
      <div class="wnq-crm-tab" data-crm-panel="calendar">${crmCalendar(scheduledJobs)}</div>
      <div class="wnq-crm-tab" data-crm-panel="followups">${crmFollowups(overdue)}</div>
      <div class="wnq-crm-tab" data-crm-panel="reports">${crmReports({ rows: visibleRows, totals, avgJob, closeRate, topServices, topCustomers, topSources, completed, lost, performance })}</div>
      <div class="wnq-crm-tab" data-crm-panel="settings">${crmSettings()}</div>
      <div class="wnq-crm-tab" data-crm-panel="marketing-work"><section class="wnq-panel"><div class="wnq-panel-head"><h2>Marketing Work History</h2>${canSeePrivate ? `<button type="button" class="wnq-button is-secondary" id="wnq-add-work">Log Work</button>` : ""}</div><div id="wnq-work-form"></div>${marketingWorkList(workRows)}</section></div>`;
    const formRoot = view.querySelector("#wnq-customer-form");
    const applyFilters = () => {
      sessionStorage.setItem("wnqCrmSearch", view.querySelector("#wnq-crm-search")?.value || "");
      sessionStorage.setItem("wnqCrmStatus", view.querySelector("#wnq-crm-status")?.value || "all");
      sessionStorage.setItem("wnqCrmFrom", view.querySelector("#wnq-crm-from")?.value || "");
      sessionStorage.setItem("wnqCrmTo", view.querySelector("#wnq-crm-to")?.value || "");
      sessionStorage.setItem("wnqCrmService", view.querySelector("#wnq-crm-service")?.value || "all");
      sessionStorage.setItem("wnqCrmSource", view.querySelector("#wnq-crm-source")?.value || "all");
      sessionStorage.setItem("wnqCrmCrew", view.querySelector("#wnq-crm-crew")?.value || "all");
      show(state.active);
    };
    const setCrmTab = (key) => {
      sessionStorage.setItem("wnqCrmTab", key);
      if (formRoot) formRoot.innerHTML = "";
      view.querySelectorAll("[data-crm-tab]").forEach((button) => button.classList.toggle("is-active", button.dataset.crmTab === key));
      view.querySelectorAll("[data-crm-panel]").forEach((panel) => panel.classList.toggle("is-active", panel.dataset.crmPanel === key));
    };
    view.querySelector("#wnq-crm-apply")?.addEventListener("click", applyFilters);
    view.querySelector("#wnq-crm-search")?.addEventListener("keydown", (event) => { if (event.key === "Enter") { event.preventDefault(); applyFilters(); } });
    ["#wnq-crm-status", "#wnq-crm-from", "#wnq-crm-to", "#wnq-crm-service", "#wnq-crm-source", "#wnq-crm-crew"].forEach((selector) => view.querySelector(selector)?.addEventListener("change", applyFilters));
    view.querySelector("#wnq-crm-clear")?.addEventListener("click", () => {
      ["wnqCrmSearch", "wnqCrmStatus", "wnqCrmFrom", "wnqCrmTo", "wnqCrmService", "wnqCrmSource", "wnqCrmCrew"].forEach((key) => sessionStorage.removeItem(key));
      show(state.active);
    });
    view.querySelector("#wnq-crm-header-refresh")?.addEventListener("click", () => show(state.active, true));
    view.querySelector("#wnq-crm-range")?.addEventListener("change", (event) => {
      sessionStorage.setItem("wnqCrmRange", event.currentTarget.value || "month");
      sessionStorage.removeItem("wnqCrmFrom");
      sessionStorage.removeItem("wnqCrmTo");
      show(state.active);
    });
    view.querySelectorAll("[data-crm-tab]").forEach((button) => button.addEventListener("click", () => setCrmTab(button.dataset.crmTab)));
    view.querySelectorAll("[data-crm-jump]").forEach((button) => button.addEventListener("click", () => {
      const targetRoute = Object.entries(crmRoutes).find(([, panel]) => panel === button.dataset.crmJump)?.[0] || "overview";
      show(targetRoute);
    }));
    setCrmTab(activeCrmTab);
    const openForm = (row = {}) => {
      formRoot.innerHTML = customerForm(row);
      view.querySelectorAll("[data-crm-tab]").forEach((button) => button.classList.remove("is-active"));
      view.querySelectorAll("[data-crm-panel]").forEach((panel) => panel.classList.remove("is-active"));
      formRoot.scrollIntoView({ behavior: "smooth", block: "start" });
      formRoot.querySelector("form").addEventListener("submit", async (event) => {
        event.preventDefault();
        const form = event.currentTarget;
        const submit = form.querySelector('[type="submit"]');
        const name = form.elements.namedItem("name");
        if (!String(name?.value || "").trim()) {
          formStatus(form, "red", "Customer name is required before this can be saved.");
          name?.focus();
          return;
        }
        formStatus(form, "yellow", "Saving CRM record...");
        if (submit) { submit.disabled = true; submit.textContent = "Saving..."; }
        try {
          await api("/portal/customers", { method: "POST", body: formBody(form) });
          sessionStorage.setItem("wnqCrmNotice", "CRM record saved.");
          delete state.cache.customers; delete state.cache.overview; delete state.cache.performance; show(state.active, true);
        } catch (error) {
          formStatus(form, "red", `Record was not saved. ${error.message}`);
          if (submit) { submit.disabled = false; submit.textContent = "Save Record"; }
        }
      });
      formRoot.querySelector("[data-cancel]").addEventListener("click", () => setCrmTab(sessionStorage.getItem("wnqCrmTab") || "dashboard"));
    };
    view.querySelector("#wnq-add-customer")?.addEventListener("click", () => openForm());
    view.querySelectorAll("[data-edit]").forEach((button) => button.addEventListener("click", () => openForm(JSON.parse(button.dataset.edit))));
    view.querySelectorAll("[data-followup]").forEach((button) => button.addEventListener("click", async () => {
      const row = JSON.parse(button.dataset.followup);
      const nextDate = window.prompt("Next follow-up date (YYYY-MM-DD). Leave blank to clear this follow-up.", "");
      if (nextDate === null) return;
      await api("/portal/customers", { method: "POST", body: JSON.stringify({ ...recordPayload(row), follow_up_date: nextDate || "" }) });
      sessionStorage.setItem("wnqCrmNotice", nextDate ? "Follow-up updated." : "Follow-up completed.");
      delete state.cache.customers; delete state.cache.overview; delete state.cache.performance; show(state.active, true);
    }));
    const workFormRoot = view.querySelector("#wnq-work-form");
    view.querySelector("#wnq-add-work")?.addEventListener("click", () => {
      workFormRoot.innerHTML = marketingWorkForm();
      workFormRoot.querySelector("[data-cancel-work]")?.addEventListener("click", () => workFormRoot.innerHTML = "");
      workFormRoot.querySelector("form")?.addEventListener("submit", async (event) => {
        event.preventDefault();
        const form = event.currentTarget;
        formStatus(form, "yellow", "Saving marketing work...");
        try {
          await api("/portal/work", { method: "POST", body: JSON.stringify(formObject(form)) });
          sessionStorage.setItem("wnqCrmNotice", "Marketing work item saved.");
          delete state.cache.work; delete state.cache.overview; show(state.active, true);
        } catch (error) {
          formStatus(form, "red", `Marketing work was not saved. ${error.message}`);
        }
      });
    });
  }
  const crmTotals = (rows) => rows.reduce((sum, row) => ({ jobs: sum.jobs + Number(row.job_count || 0), revenue: sum.revenue + Number(row.final_value || 0), cost: sum.cost + Number(row.job_cost || 0) }), { jobs: 0, revenue: 0, cost: 0 });
  const filterCrmRows = (rows, filters = {}) => {
    const query = String(filters.search || "").trim().toLowerCase();
    return rows.filter((row) => {
      const statusFilter = filters.status || "all";
      const rowDate = row.job_date || row.follow_up_date || row.created_at || "";
      const matchesStatus = !statusFilter || statusFilter === "all" || row.status === statusFilter || row.record_type === statusFilter;
      const matchesDate = (!filters.from || rowDate >= filters.from) && (!filters.to || rowDate <= filters.to);
      const matchesService = !filters.service || filters.service === "all" || row.service === filters.service;
      const matchesSource = !filters.source || filters.source === "all" || row.lead_source === filters.source;
      const matchesCrew = !filters.crew || filters.crew === "all" || row.crew === filters.crew;
      const matchesSearch = !query || [row.name, row.phone, row.email, row.address, row.job_address, row.service, row.crew, row.lead_source, row.status, row.notes, row.internal_notes].join(" ").toLowerCase().includes(query);
      return matchesStatus && matchesDate && matchesService && matchesSource && matchesCrew && matchesSearch;
    });
  };
  const optionsFrom = (rows, key) => [...new Set(rows.map((row) => row[key]).filter(Boolean))].sort((a, b) => String(a).localeCompare(String(b)));
  const crmPill = (label, value, note, tone = "yellow") => `<div class="wnq-crm-pill is-${esc(tone)}"><span>${esc(label)}</span><strong>${esc(value)}</strong><small>${esc(note)}</small></div>`;
  const crmContact = (row) => `<small class="wnq-contact-links">${row.phone ? `<a href="tel:${esc(String(row.phone).replace(/[^0-9+]/g, ""))}">${esc(row.phone)}</a>` : ""}${row.email ? `<a href="mailto:${esc(row.email)}">${esc(row.email)}</a>` : ""}${!row.phone && !row.email ? "No contact saved" : ""}</small>`;
  const topBy = (rows, key, amountKey) => Object.values(rows.reduce((map, row) => {
    const label = row[key] || "Not set";
    map[label] = map[label] || { label, total: 0, count: 0 };
    map[label].total += Number(row[amountKey] || 0);
    map[label].count += 1;
    return map;
  }, {})).sort((a, b) => b.total - a.total).slice(0, 5);
  const countBy = (rows, key) => Object.values(rows.reduce((map, row) => {
    const label = row[key] || "Not set";
    map[label] = map[label] || { label, count: 0 };
    map[label].count += 1;
    return map;
  }, {})).sort((a, b) => b.count - a.count).slice(0, 6);
  const recordPayload = (row) => ({
    id: row.id || "", record_type: row.record_type || "lead", name: row.name || "", phone: row.phone || "", email: row.email || "",
    address: row.address || "", job_address: row.job_address || "", service: row.service || "", crew: row.crew || "",
    lead_source: row.lead_source || "", status: row.status || "new", follow_up_date: row.follow_up_date || "",
    reminder_date: row.reminder_date || "", job_date: row.job_date || "", completion_date: row.completion_date || "",
    job_count: row.job_count || 0, estimated_value: row.estimated_value || 0, final_value: row.final_value || 0,
    job_cost: row.job_cost || 0, notes: row.notes || "", internal_notes: row.internal_notes || "", lost_reason: row.lost_reason || ""
  });
  const crmDashboard = ({ rows, performance, leads, activeJobs, completed, lost, upcoming, overdue, totals, completedRevenue, avgJob, closeRate, topServices, topCustomers, topSources, workRows }) => {
    const openCount = leads.length + activeJobs.length;
    const totalOps = openCount + completed.length + lost.length;
    const donutTotal = totalOps || rows.length || 0;
    const openPct = donutTotal ? Math.round((openCount / donutTotal) * 100) : 0;
    const wonPct = donutTotal ? Math.round((completed.length / donutTotal) * 100) : 0;
    const lostPct = donutTotal ? Math.max(0, 100 - openPct - wonPct) : 0;
    const pipeline = pipelineRows(rows, completedRevenue);
    return `<div class="wnq-dashboard-grid">
      <section class="wnq-panel wnq-dashboard-card is-opportunity"><div class="wnq-panel-head"><h2>Opportunity Overview</h2><button type="button" class="wnq-link" data-crm-jump="leads">View Pipeline</button></div><div class="wnq-donut-layout"><div class="wnq-donut ${donutTotal ? "" : "is-empty"}" style="--open:${openPct};--won:${wonPct};--lost:${lostPct}"><strong>${esc(donutTotal)}</strong><span>Total</span></div><div class="wnq-donut-legend">${donutLegend("Open", openCount, openPct, "green")}${donutLegend("Won", completed.length, wonPct, "gold")}${donutLegend("Lost", lost.length, lostPct, "red")}</div></div></section>
      <section class="wnq-panel wnq-dashboard-card"><div class="wnq-panel-head"><h2>Pipeline Snapshot</h2><button type="button" class="wnq-link" data-crm-jump="leads">View Pipeline</button></div>${pipelineSnapshot(pipeline)}</section>
      <section class="wnq-panel wnq-dashboard-card"><div class="wnq-panel-head"><h2>Tasks / Follow-ups</h2><button type="button" class="wnq-link" data-crm-jump="followups">View All Tasks</button></div>${taskList(overdue, upcoming)}</section>
      <section class="wnq-panel wnq-dashboard-card"><div class="wnq-panel-head"><h2>Revenue Trend</h2><span>${canSeePrivate ? `${trend(totals.revenue - totals.cost)} net` : `${money(totals.revenue)} tracked`}</span></div>${performanceChart(performance)}</section>
      <section class="wnq-panel wnq-dashboard-card"><div class="wnq-panel-head"><h2>Lead Source Report</h2><button type="button" class="wnq-link" data-crm-jump="reports">View Reports</button></div>${leadSourceTable(rows, topSources)}</section>
      <section class="wnq-panel wnq-dashboard-card"><div class="wnq-panel-head"><h2>Recent Activity</h2><button type="button" class="wnq-link" data-crm-jump="reports">View All Activity</button></div>${recentActivity(rows, workRows)}</section>
      <section class="wnq-panel wnq-dashboard-card is-wide"><div class="wnq-panel-head"><h2>Marketing Work History</h2><button type="button" class="wnq-link" data-crm-jump="marketing-work">View All Work</button></div>${marketingWorkCards(workRows)}</section>
      <section class="wnq-panel wnq-dashboard-card is-wide"><div class="wnq-panel-head"><h2>Business Snapshot</h2></div><div class="wnq-metrics">${metric("Average Job Value", money(avgJob), "Revenue divided by jobs")}${metric("Close Rate", `${closeRate}%`, "Completed vs lost")}${metric("Top Service", topServices[0]?.label || "Not set", "Highest tracked revenue")}${metric("Top Customer", topCustomers[0]?.label || "Not set", "Highest tracked revenue")}</div></section>
    </div>`;
  };
  const donutLegend = (label, count, percent, tone) => `<div class="wnq-donut-row"><i class="is-${esc(tone)}"></i><span>${esc(label)}</span><strong>${esc(count)} (${esc(percent)}%)</strong></div>`;
  const pipelineRows = (rows, completedRevenue) => {
    const stageMap = [
      ["new", "New Lead"], ["contacted", "Contacted"], ["quoted", "Quote Sent"], ["scheduled", "Scheduled"], ["completed", "Won"]
    ];
    return stageMap.map(([statusKey, label]) => {
      const stageRows = rows.filter((row) => row.status === statusKey);
      const amountKey = statusKey === "completed" ? "final_value" : "estimated_value";
      const value = statusKey === "completed" ? completedRevenue : stageRows.reduce((sum, row) => sum + Number(row[amountKey] || 0), 0);
      return { label, count: stageRows.length, value };
    });
  };
  const pipelineSnapshot = (items) => {
    const max = Math.max(1, ...items.map((item) => item.count));
    return `<div class="wnq-pipeline">${items.map((item) => `<div class="wnq-pipeline-row"><span>${esc(item.label)}</span><div><i style="width:${Math.max(8, Math.round((item.count / max) * 100))}%"></i></div><strong>${esc(item.count)}</strong><em>${money(item.value)}</em></div>`).join("")}</div>`;
  };
  const taskList = (overdue, upcoming) => {
    const today = new Date().toISOString().slice(0, 10);
    const tomorrow = new Date();
    tomorrow.setDate(tomorrow.getDate() + 1);
    const tomorrowIso = tomorrow.toISOString().slice(0, 10);
    const tasks = [
      ...overdue.map((row) => ({ row, label: "Overdue", tone: "red", date: row.follow_up_date })),
      ...upcoming.map((row) => ({ row, label: row.job_date === today ? "Due Today" : row.job_date === tomorrowIso ? "Due Tomorrow" : "Upcoming", tone: row.job_date === today ? "yellow" : "blue", date: row.job_date }))
    ].slice(0, 4);
    return tasks.length ? `<div class="wnq-task-list">${tasks.map((item) => `<article class="wnq-task-row"><span class="wnq-task-icon"></span><div><strong>${esc(item.row.name)}</strong><small>${esc(item.row.service || "Follow-up")}</small></div><em class="is-${esc(item.tone)}">${esc(item.label)}</em></article>`).join("")}</div>` : empty("No follow-ups need attention right now.");
  };
  const leadSourceTable = (rows, topSources) => {
    const sourceRows = topSources.map((source) => {
      const matches = rows.filter((row) => (row.lead_source || "Not set") === source.label);
      const won = matches.filter((row) => row.status === "completed");
      return { source: source.label, leads: source.count, won: won.length, revenue: won.reduce((sum, row) => sum + Number(row.final_value || 0), 0) };
    });
    return sourceRows.length ? `<table class="wnq-source-table"><thead><tr><th>Source</th><th>Leads</th><th>Won</th><th>Revenue</th></tr></thead><tbody>${sourceRows.map((row) => `<tr><td>${esc(row.source)}</td><td>${esc(row.leads)}</td><td>${esc(row.won)}</td><td>${money(row.revenue)}</td></tr>`).join("")}<tr><td><strong>Total</strong></td><td><strong>${esc(sourceRows.reduce((sum, row) => sum + row.leads, 0))}</strong></td><td><strong>${esc(sourceRows.reduce((sum, row) => sum + row.won, 0))}</strong></td><td><strong>${money(sourceRows.reduce((sum, row) => sum + row.revenue, 0))}</strong></td></tr></tbody></table>` : empty("Lead sources will appear here after records are added.");
  };
  const recentActivity = (rows, workRows) => {
    const activities = [
      ...rows.slice(0, 3).map((row) => ({ title: row.status === "completed" ? "Job completed" : row.record_type === "job" ? "Job scheduled" : "New lead added", detail: row.name, date: row.updated_at || row.created_at || row.job_date })),
      ...workRows.slice(0, 2).map((row) => ({ title: row.status === "done" ? "Marketing work completed" : "Marketing work updated", detail: row.title, date: row.work_date || row.updated_at || row.created_at }))
    ].filter((item) => item.detail).slice(0, 5);
    return activities.length ? `<div class="wnq-activity-list">${activities.map((item) => `<article class="wnq-activity-row"><span></span><div><strong>${esc(item.title)}</strong><small>${esc(item.detail)}</small></div><time>${date(item.date)}</time></article>`).join("")}</div>` : empty("Recent CRM and marketing activity will appear here.");
  };
  const marketingWorkCards = (rows) => rows.length ? `<div class="wnq-work-card-grid">${rows.slice(0, 3).map((row) => `<article class="wnq-work-card"><span class="wnq-work-icon"></span><div><strong>${esc(row.title)}</strong><p>${esc(row.description || row.work_type_label || "Marketing work completed.")}</p><small>${date(row.work_date || row.due_date)} · ${esc(row.assigned_to || cfg.user?.name || "Golden Web Marketing")}</small></div><em>${esc(row.work_type_label || humanize(row.task_type || "Work"))}</em></article>`).join("")}</div>` : empty("Completed SEO, Ads, and website updates will appear here.");
  const miniList = (title, rows, fallback) => `<div class="wnq-panel-head"><h2>${esc(title)}</h2></div>${rows.length ? rows.slice(0, 5).map((row) => `<div class="wnq-work-item"><div>${crmStatus(row.status)}<strong>${esc(row.name)}</strong></div><span>${date(row.job_date || row.follow_up_date)} · ${esc(row.service || "Service not set")}</span></div>`).join("") : empty(fallback)}`;
  const crmTable = (rows, title, fallback, mode = "general") => `<div class="wnq-panel wnq-table-wrap wnq-crm-table-panel"><div class="wnq-panel-head"><div><h2>${esc(title)}</h2><small>${esc(rows.length)} record${rows.length === 1 ? "" : "s"}</small></div></div><table class="wnq-crm-table"><thead><tr><th>Customer</th><th>Service / Source</th><th>Status</th><th>Schedule</th><th>${mode === "leads" ? "Estimate" : "Revenue"}</th><th>Job Info</th><th></th></tr></thead><tbody>${rows.length ? rows.map((row) => {
    const profit = Number(row.final_value || 0) - Number(row.job_cost || 0);
    const moneyNote = canSeePrivate && row.job_cost !== undefined ? `<small>Cost ${money(row.job_cost)} · ${trend(profit)} net</small>` : `<small>${row.final_value ? "Revenue saved" : "No revenue saved"}</small>`;
    return `<tr><td><strong>${esc(row.name)}</strong>${crmContact(row)}<small>${esc(row.address || "")}</small></td><td><strong>${esc(row.service || "Not set")}</strong><small>${esc(row.lead_source || "Source not set")}</small></td><td>${crmStatus(row.status)}</td><td><span>${date(row.job_date || row.follow_up_date || row.reminder_date)}</span><small>${row.completion_date ? `Completed ${date(row.completion_date)}` : row.follow_up_date ? `Next follow-up ${date(row.follow_up_date)}` : "No date set"}</small></td><td><strong>${money(mode === "leads" ? row.estimated_value : row.final_value)}</strong>${moneyNote}</td><td><small>${esc(row.job_address || row.address || "Address not set")}</small><small>${esc(row.crew ? `Assigned: ${row.crew}` : `Service: ${row.service || "Not set"}`)}</small></td><td><button class="wnq-link" data-edit='${attr(row)}'>Edit</button></td></tr>`;
  }).join("") : `<tr><td colspan="7">${empty(fallback)}</td></tr>`}</tbody></table></div>`;
  const crmJobsPanel = (active, completed) => `<div class="wnq-grid-2"><section>${crmTable(active, "Active Jobs", "No upcoming jobs are scheduled yet. Scheduled jobs will appear here.", "jobs")}</section><section>${crmTable(completed, "Completed Jobs", "No completed jobs yet.", "jobs")}</section></div>`;
  const crmFollowups = (rows) => `<div class="wnq-panel wnq-table-wrap wnq-crm-table-panel"><div class="wnq-panel-head"><div><h2>Follow-ups Due</h2><small>${esc(rows.length)} overdue follow-up${rows.length === 1 ? "" : "s"}</small></div></div><table class="wnq-crm-table wnq-followup-table"><thead><tr><th>Customer</th><th>Service</th><th>Status</th><th>Follow-up Date</th><th>Notes</th><th>Action</th></tr></thead><tbody>${rows.length ? rows.map((row) => `<tr><td><strong>${esc(row.name)}</strong>${crmContact(row)}</td><td>${esc(row.service || "Not set")}</td><td>${crmStatus(row.status)}</td><td>${date(row.follow_up_date)}</td><td><small>${esc(row.notes || "No notes saved")}</small></td><td><button class="wnq-link" data-followup='${attr(row)}'>Complete Follow-Up</button><button class="wnq-link" data-edit='${attr(row)}'>Edit</button></td></tr>`).join("") : `<tr><td colspan="6">${empty("No overdue follow-ups. When a next follow-up date passes, it will appear here.")}</td></tr>`}</tbody></table></div>`;
  const crmCalendar = (rows) => {
    const grouped = [...rows].sort((a, b) => String(a.job_date || "").localeCompare(String(b.job_date || ""))).reduce((map, row) => {
      const key = row.job_date || "unscheduled";
      map[key] = map[key] || [];
      map[key].push(row);
      return map;
    }, {});
    return `<div class="wnq-panel"><div class="wnq-panel-head"><h2>Calendar & Scheduling</h2></div>${Object.keys(grouped).length ? Object.entries(grouped).map(([day, items]) => `<section class="wnq-calendar-day"><h3>${date(day)}</h3>${items.map((row) => `<article class="wnq-schedule-item"><time>${esc(row.job_time || "Time TBD")}</time><div><strong>${esc(row.name)}</strong><span>${esc(row.service || "Job")} · ${esc(row.job_address || row.address || "Address not set")}</span></div>${crmStatus(row.status)}</article>`).join("")}</section>`).join("") : empty("No upcoming jobs are scheduled yet. Scheduled jobs will appear here.")}</div>`;
  };
  const crmReports = ({ rows, totals, avgJob, closeRate, topServices, topCustomers, topSources, completed, lost, performance }) => `<div class="wnq-grid-2"><section class="wnq-panel"><div class="wnq-panel-head"><h2>Top Services</h2></div>${topServices.length ? topServices.map((item) => `<div class="wnq-work-item"><strong>${esc(item.label)}</strong><span>${money(item.total)} · ${esc(item.count)} records</span></div>`).join("") : empty("No service data yet.")}</section><section class="wnq-panel"><div class="wnq-panel-head"><h2>Lead Sources</h2></div>${topSources.length ? sourceBars(topSources) : empty("No lead source data yet.")}</section></div><div class="wnq-grid-2"><section class="wnq-panel"><div class="wnq-panel-head"><h2>Revenue by Month</h2></div>${performanceChart(performance)}</section><section class="wnq-panel"><div class="wnq-panel-head"><h2>Top Customers</h2></div>${topCustomers.length ? topCustomers.map((item) => `<div class="wnq-work-item"><strong>${esc(item.label)}</strong><span>${money(item.total)} · ${esc(item.count)} records</span></div>`).join("") : empty("No customer data yet.")}</section></div><div class="wnq-metrics">${metric("Records", rows.length, "Visible entries")}${metric("Completed Jobs", completed.length, "Closed as completed")}${metric("Lost / Canceled", lost.length, "Not moving forward")}${metric("Average Job Value", money(avgJob), "Average value")}${metric("Close Rate", `${closeRate}%`, "Completed vs lost")}${canSeePrivate ? metric("Net Tracked Profit", money(totals.revenue - totals.cost), "Visible records", totals.revenue - totals.cost >= 0 ? "positive" : "negative") : ""}</div>`;
  const sourceBars = (items) => {
    const max = Math.max(1, ...items.map((item) => Number(item.count || 0)));
    return `<div class="wnq-source-list">${items.map((item) => `<div class="wnq-source-row"><span>${esc(item.label)}</span><strong>${esc(item.count)}</strong><i style="width:${Math.max(8, Math.round((Number(item.count || 0) / max) * 100))}%"></i></div>`).join("")}</div>`;
  };
  const crmSettings = () => `<section class="wnq-panel"><div class="wnq-panel-head"><h2>Leads & Jobs Settings</h2></div><p class="wnq-note">Use this section to track leads, jobs, follow-ups, service history, job addresses, crew assignments, revenue, and customer notes. Admin users can also see costs, net tracked profit, internal notes, and private marketing work details.</p></section>`;
  const crmStatus = (value) => {
    const key = value || "new";
    return status(["completed"].includes(key) ? "green" : ["lost","canceled"].includes(key) ? "red" : "yellow", statusLabels[key] || humanize(key));
  };
  const marketingWorkList = (rows, compact = false) => rows.length ? rows.map((row) => `<div class="wnq-work-item"><div>${status(row.status === "done" ? "green" : "yellow", row.status === "done" ? "Completed" : humanize(row.status))}<div><strong>${esc(row.title)}</strong>${!compact && row.description ? `<small>${esc(row.description)}</small>` : ""}</div></div><span>${esc(row.work_type_label || humanize(row.task_type || "Other"))} · ${row.due_date ? date(row.due_date) : "No date"}</span></div>`).join("") : empty("No marketing work items yet. Completed SEO, Ads, and website updates will appear here.");
  const marketingWorkForm = () => `<form class="wnq-work-form wnq-form"><div class="wnq-panel-head"><div><span class="wnq-eyebrow">Admin Work Log</span><h2>Add Marketing Work</h2></div></div>${field("work_date", "Date", new Date().toISOString().slice(0, 10), false, "date")}<label><span>Work Type</span><select name="work_type">${workTypeOptions.map(([value, label]) => `<option value="${esc(value)}">${esc(label)}</option>`).join("")}</select></label><label><span>Status</span><select name="status"><option value="done">Completed</option><option value="in_progress">In Progress</option><option value="todo">Planned</option></select></label>${field("title", "Title", "", true)}${field("assigned_to", "Assigned To", cfg.user?.name || "")}<label class="is-wide"><span>Description</span><textarea name="description" rows="3" placeholder="Updated homepage SEO title and meta description"></textarea></label><label class="is-wide"><span>Internal Notes</span><textarea name="notes" rows="2"></textarea></label><div class="wnq-form-actions"><button class="wnq-button" type="submit">Save Work Item</button><button type="button" class="wnq-button is-secondary" data-cancel-work>Cancel</button></div></form>`;
  const fileList = (label, items = []) => Array.isArray(items) && items.length ? `<div class="wnq-existing-files is-wide"><span>${esc(label)}</span>${items.map((item) => `<a href="${esc(item.url)}" target="_blank" rel="noopener">${esc(item.name)}</a>`).join("")}</div>` : "";
  const customerForm = (row = {}) => {
    const currentType = row.record_type || "lead";
    const currentStatus = row.status || "new";
    const sourceValue = row.lead_source || "";
    const sourceChoices = sourceValue && !leadSourceOptions.includes(sourceValue) ? [...leadSourceOptions, sourceValue] : leadSourceOptions;
    return `<form class="wnq-panel wnq-form wnq-crm-form" enctype="multipart/form-data"><input type="hidden" name="id" value="${esc(row.id || "")}">
      <div class="wnq-panel-head"><div><span class="wnq-eyebrow">${row.id ? "Edit record" : "New record"}</span><h2>${row.id ? "Update Lead / Job" : "Add Lead / Job"}</h2></div></div>
      <fieldset class="wnq-crm-form-section"><legend>Contact</legend>
        <label><span>Record Type</span><select name="record_type">${["lead","customer","job"].map((v) => `<option value="${v}" ${currentType === v ? "selected" : ""}>${esc(v === "lead" ? "Lead" : v === "job" ? "Job" : "Customer")}</option>`).join("")}</select></label>
        ${field("name", "Customer Name", row.name, true)}${field("phone", "Phone", row.phone)}${field("email", "Email", row.email, false, "email")}${field("address", "Customer Address", row.address)}
        <label><span>Lead Source</span><select name="lead_source"><option value="">Select source</option>${sourceChoices.map((value) => `<option value="${esc(value)}" ${sourceValue === value ? "selected" : ""}>${esc(value)}</option>`).join("")}</select></label>
      </fieldset>
      <fieldset class="wnq-crm-form-section"><legend>Job & Schedule</legend>
        ${field("service", "Service / Job Type", row.service)}${field("job_address", "Job Address", row.job_address)}${field("crew", "Crew / Employee Assignment", row.crew)}
        <label><span>Status</span><select name="status">${statusOptions.map(([value, label]) => `<option value="${esc(value)}" ${currentStatus === value ? "selected" : ""}>${esc(label)}</option>`).join("")}</select></label>
        ${field("follow_up_date", "Next Follow-Up Date", row.follow_up_date, false, "date")}${field("reminder_date", "Reminder Date", row.reminder_date, false, "date")}${field("job_date", "Scheduled Date", row.job_date, false, "date")}${field("completion_date", "Completion Date", row.completion_date, false, "date")}
      </fieldset>
      <fieldset class="wnq-crm-form-section"><legend>Revenue & Profit</legend>
        ${countField("job_count", "Completed Job Quantity", row.job_count || 0, "Usually 0 until completed, then 1 for one finished job. Use more only when one record represents multiple completed jobs.")}${moneyField("estimated_value", "Estimated Revenue", row.estimated_value || 0)}${moneyField("final_value", "Final Revenue", row.final_value || 0)}${canSeePrivate ? moneyField("job_cost", "Job Costs / Marketing Costs", row.job_cost || 0) : ""}
      </fieldset>
      <fieldset class="wnq-crm-form-section"><legend>Notes & Files</legend>
        <label class="is-wide"><span>Customer Notes / Service History</span><textarea name="notes" rows="3">${esc(row.notes || "")}</textarea></label>
        ${canSeePrivate ? `<label class="is-wide"><span>Internal Notes</span><textarea name="internal_notes" rows="3">${esc(row.internal_notes || "")}</textarea></label>` : ""}
        <label class="is-wide"><span>Lost Lead / Cancellation Reason</span><textarea name="lost_reason" rows="2">${esc(row.lost_reason || "")}</textarea></label>
        ${fileList("Saved files", row.files)}${fileList("Before photos", row.before_photos)}${fileList("After photos", row.after_photos)}
        <label class="is-wide wnq-upload"><span>Files & Photos</span><input type="file" name="attachments[]" multiple accept="image/*,.pdf,.doc,.docx,.txt"><small>Upload estimates, invoices, signed docs, or job photos.</small></label>
        <label class="wnq-upload"><span>Before Photos</span><input type="file" name="before_photos[]" multiple accept="image/*"></label><label class="wnq-upload"><span>After Photos</span><input type="file" name="after_photos[]" multiple accept="image/*"></label>
      </fieldset>
      <div class="wnq-form-actions"><button class="wnq-button" type="submit">Save Record</button><button type="button" class="wnq-button is-secondary" data-cancel>Cancel</button></div></form>`;
  };
  const field = (name, label, value = "", required = false, type = "text", hint = "") => `<label><span>${esc(label)}</span><input type="${type}" name="${name}" value="${esc(value)}" ${required ? "required" : ""} ${type === "number" ? 'min="0" step="0.01"' : ""}>${hint ? `<small>${esc(hint)}</small>` : ""}</label>`;
  const countField = (name, label, value = "", hint = "") => `<label><span>${esc(label)}</span><input type="number" name="${esc(name)}" value="${esc(value)}" min="0" step="1" inputmode="numeric">${hint ? `<small>${esc(hint)}</small>` : ""}</label>`;
  const moneyField = (name, label, value = "") => `<label class="wnq-money-field"><span>${esc(label)}</span><div><b>$</b><input type="number" name="${esc(name)}" value="${esc(value)}" min="0" step="0.01" inputmode="decimal"></div></label>`;

  async function ads(view, refresh) {
    const data = await load("ads", refresh);
    view.innerHTML = `${heading("Ads", "Google Ads", "Read-only campaign visibility for spend, clicks, conversions, and lead quality.")}
      <div class="wnq-health">
        <div><span>API Status</span>${status(data.configured ? "green" : "yellow", data.configured ? "Connected" : "Setup needed")}</div>
        <div><span>Access Mode</span><strong>${esc(humanize(data.mode || "read_only"))}</strong></div>
        <div><span>Access Level</span><strong>${esc(humanize(data.access_level || "test"))}</strong></div>
      </div>
      <div class="wnq-metrics">${metric("Spend", money(data.summary?.spend || 0), "Selected period")}${metric("Clicks", data.summary?.clicks || 0, "Ad clicks")}${metric("Conversions", data.summary?.conversions || 0, "Tracked leads")}${metric("Cost / Conversion", money(data.summary?.cost_per_conversion || 0), "Spend per lead")}</div>
      <section class="wnq-panel"><div class="wnq-panel-head"><h2>Account Match</h2>${status(data.customer_id ? "green" : "yellow", data.customer_id ? "Matched" : "Waiting")}</div><p><strong>${esc(data.matched_account_name || "No Ads account matched yet")}</strong>${data.customer_id ? ` · ${esc(data.customer_id)}` : ""}${data.match_score ? ` · ${esc(data.match_score)}% match` : ""}</p>${adsDiagnostics(data)}</section>
      ${cfg.isAdmin ? adsSettingsForm(data) : adsClientNotice(data)}
      <section class="wnq-panel wnq-table-wrap"><div class="wnq-panel-head"><h2>Campaigns</h2></div><table><thead><tr><th>Campaign</th><th>Status</th><th>Spend</th><th>Clicks</th><th>Impr.</th><th>CTR</th><th>Conversions</th></tr></thead><tbody>${data.campaigns?.length ? data.campaigns.map((row) => `<tr><td><strong>${esc(row.name)}</strong></td><td>${status(row.status === "enabled" ? "green" : "yellow", row.status)}</td><td>${money(row.spend)}</td><td>${esc(row.clicks)}</td><td>${esc(row.impressions)}</td><td>${esc(Math.round(Number(row.ctr || 0) * 10000) / 100)}%</td><td>${esc(row.conversions)}</td></tr>`).join("") : `<tr><td colspan="7">${empty("Google Ads reporting is ready for setup. No campaign data is being pulled yet.")}</td></tr>`}</tbody></table></section>`;
    const form = view.querySelector("#wnq-ads-settings");
    if (form) {
      form.addEventListener("submit", async (event) => {
        event.preventDefault();
        const submit = form.querySelector('[type="submit"]');
        if (submit) { submit.disabled = true; submit.textContent = "Saving..."; }
        try {
          const result = await api("/portal/ads-settings", { method: "POST", body: JSON.stringify(formObject(form)) });
          state.cache.ads = result;
          sessionStorage.setItem("wnqAdsNotice", "Ads settings saved. Refreshing account match...");
          show("ads", true);
        } catch (error) {
          formStatus(form, "red", `Ads settings were not saved. ${error.message}`);
          if (submit) { submit.disabled = false; submit.textContent = "Save Ads Setup"; }
        }
      });
    }
  }

  const adsDiagnostics = (data) => {
    const messages = [...(data.errors || []), ...(data.diagnostics || [])].filter(Boolean);
    const checks = data.setup_checks || [];
    return `${messages.length ? `<div class="wnq-error"><strong>Google Ads setup message</strong>${messages.map((message) => `<p>${esc(message)}</p>`).join("")}</div>` : ""}${checks.length ? `<div class="wnq-setup-checks">${checks.map((check) => `<span class="${check.ok ? "is-ok" : "is-needed"}">${esc(check.label)}</span>`).join("")}</div>` : ""}`;
  };
  const adsSettingsForm = (data) => {
    const notice = sessionStorage.getItem("wnqAdsNotice") || "";
    if (notice) sessionStorage.removeItem("wnqAdsNotice");
    return `<form class="wnq-panel wnq-form" id="wnq-ads-settings"><div class="wnq-panel-head"><div><span class="wnq-eyebrow">Admin Setup</span><h2>Google Ads Read-only Connection</h2></div></div>
    ${notice ? `<div class="wnq-form-status is-green">${esc(notice)}</div>` : ""}
    ${field("customer_id", "Google Ads Customer ID", data.customer_id || "")}${field("manager_customer_id", "Manager Account ID", data.manager_customer_id || "")}${field("service_account_email", "Service Account Email", data.service_account_email || "")}
    ${field("api_key", "API Key", data.has_api_key ? "Saved" : "", false, "password")}${field("developer_token", "Developer Token", data.has_developer_token ? "Saved" : "", false, "password")}${field("oauth_client_id", "OAuth Client ID", data.has_oauth_client_id ? "Saved" : "")}
    ${field("oauth_client_secret", "OAuth Client Secret", data.has_oauth_client_secret ? "Saved" : "", false, "password")}${field("refresh_token", "OAuth Refresh Token", data.has_refresh_token ? "Saved" : "", false, "password")}
    <div class="is-wide wnq-ads-requirements"><strong>Still needed for live API pulls</strong>${(data.requirements || []).map((item) => `<span>${esc(item)}</span>`).join("")}<p class="wnq-note">The API key is stored server-side. The frontend only receives saved/not-saved flags.</p></div>
    <div class="wnq-form-actions"><button class="wnq-button" type="submit">Save Ads Setup</button></div></form>`;
  };
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
