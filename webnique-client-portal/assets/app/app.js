(function () {
  "use strict";
  const root = document.getElementById("wnq-portal-root");
  const cfg = window.WNQ_PORTAL || {};
  if (!root) return;

  const state = { active: "overview", cache: {}, clientId: cfg.clientId || "", notificationKeys: null, notificationTimer: 0, soundUnlocked: false };
  const canSeePrivate = !!cfg.isAdmin;
  const tabs = [
    ["overview", "Overview"], ["opportunities", "Opportunities"], ["leads", "Leads"], ["jobs", "Jobs"], ["calendar", "Calendar"],
    ["followups", "Follow-ups"], ["notifications", "Notifications"], ["reports", "SEO Reports"], ["crm-reports", "CRM Reports"],
    ["messages", "Support"], ["requests", "Requests"], ["ads", "Ads"], ["billing", "Billing"], ["learning", "Learning Center"], ["settings", "Settings"]
  ];
  const navGroups = [
    ["Workspace", ["overview", "opportunities", "leads", "jobs", "calendar", "followups"]],
    ["Insights", ["reports", "crm-reports", "ads"]],
    ["Account", ["notifications", "messages", "requests", "billing", "learning", "settings"]],
  ];
  const crmRoutes = { overview: "dashboard", opportunities: "opportunities", leads: "leads", jobs: "jobs", calendar: "calendar", followups: "followups", "crm-reports": "reports" };
  const statusLabels = { new: "New Lead", contacted: "Contacted", quoted: "Quoted", scheduled: "Scheduled", in_progress: "In Progress", completed: "Completed", lost: "Lost / Canceled", canceled: "Lost / Canceled" };
  const leadSourceOptions = ["Google Ads", "Google Business Profile", "Organic Search", "Website Form", "Phone Call", "Referral", "Facebook", "Instagram", "Other"];
  const esc = (value) => String(value ?? "").replace(/[&<>"']/g, (char) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#039;" }[char]));
  const money = (value) => new Intl.NumberFormat("en-US", { style: "currency", currency: "USD", maximumFractionDigits: 0 }).format(Number(value || 0));
  const isoDate = (value = new Date()) => {
    const parsed = value instanceof Date ? value : new Date(value);
    if (Number.isNaN(parsed.getTime())) return "";
    return `${parsed.getFullYear()}-${String(parsed.getMonth() + 1).padStart(2, "0")}-${String(parsed.getDate()).padStart(2, "0")}`;
  };
  const parseDateValue = (value) => {
    if (!value) return null;
    const raw = String(value);
    const normalized = /^\d{4}-\d{2}-\d{2}$/.test(raw) ? `${raw}T00:00:00` : raw.replace(" ", "T");
    const parsed = new Date(normalized);
    return Number.isNaN(parsed.getTime()) ? null : parsed;
  };
  const date = (value) => parseDateValue(value)?.toLocaleDateString() || "Not set";
  const reportMonth = (start, end = "") => parseDateValue(start || end)?.toLocaleString(undefined, { month: "long" }) || "Not set";
  const titleCase = (value) => humanize(value).toLowerCase().replace(/\b[a-z]/g, (char) => char.toUpperCase());
  const reportTypeLabel = (value) => {
    const key = String(value || "monthly").toLowerCase().replaceAll("_", " ").trim();
    if (!key || ["monthly", "current month", "previous month"].includes(key)) return "Monthly";
    if (key === "last 30 days") return "Last 30 Days";
    return titleCase(key);
  };
  const reportTitle = (row = {}) => `${reportTypeLabel(row.report_type)} Report`;
  const attr = (value) => esc(JSON.stringify(value));
  const activeLabel = (key) => tabs.find(([tab]) => tab === key)?.[1] || "Dashboard";
  const navIcon = (key) => ({ overview: "grid", opportunities: "pipeline", leads: "users", jobs: "briefcase", calendar: "calendar", followups: "check", notifications: "bell", reports: "chart", "crm-reports": "chart", messages: "support", requests: "request", ads: "target", billing: "receipt", learning: "book", settings: "gear" }[key] || "dot");
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
      <nav>${navGroups.map(([group, keys]) => `<section class="wnq-nav-group"><small>${esc(group)}</small>${keys.map((key) => { const label = activeLabel(key); return `<button type="button" data-tab="${key}"><i class="wnq-nav-icon is-${esc(navIcon(key))}" aria-hidden="true"></i><span>${esc(label)}</span></button>`; }).join("")}</section>`).join("")}</nav>
      <div class="wnq-sidebar-foot"><span>Signed in as</span><strong>${esc(cfg.user?.name || "Client")}</strong><small>Portal v${esc(cfg.version || "unknown")}</small></div></aside>
      <main class="wnq-main"><div class="wnq-topbar"><div><span>Golden Web Marketing <em>v${esc(cfg.version || "unknown")}</em></span><strong id="wnq-top-title">Overview</strong></div><div class="wnq-topbar-actions"><button type="button" class="wnq-button is-secondary" id="wnq-fullscreen-toggle" title="Open the portal in full screen">Full Screen</button><button type="button" class="wnq-button is-secondary" id="wnq-refresh-view">Refresh</button></div></div><div id="wnq-view">${empty("Loading dashboard...")}</div></main></div>`;
    root.querySelectorAll("[data-tab]").forEach((button) => button.addEventListener("click", () => show(button.dataset.tab)));
    root.querySelector("#wnq-refresh-view")?.addEventListener("click", () => show(state.active, true));
    root.querySelector("#wnq-fullscreen-toggle")?.addEventListener("click", toggleFullscreen);
    root.querySelector("#wnq-view-as")?.addEventListener("change", (event) => {
      state.clientId = event.currentTarget.value;
      state.cache = {};
      state.notificationKeys = null;
      show("overview", true);
    });
    document.addEventListener("fullscreenchange", syncFullscreenButton);
    refreshNotificationBadge();
    if (!state.notificationTimer) state.notificationTimer = window.setInterval(() => refreshNotificationBadge(true, true), 60000);
  };
  const syncFullscreenButton = () => {
    const button = root.querySelector("#wnq-fullscreen-toggle");
    const active = document.fullscreenElement === root || root.classList.contains("is-fullscreen-fallback");
    root.classList.toggle("is-fullscreen", active);
    if (button) button.textContent = active ? "Exit Full Screen" : "Full Screen";
  };
  const toggleFullscreen = async () => {
    try {
      if (document.fullscreenElement === root) await document.exitFullscreen();
      else if (root.requestFullscreen) await root.requestFullscreen();
      else root.classList.toggle("is-fullscreen-fallback");
      syncFullscreenButton();
    } catch (error) {
      root.classList.toggle("is-fullscreen-fallback");
      syncFullscreenButton();
    }
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
        refreshNotificationBadge(refresh);
        return;
      }
      const renderers = { reports, customers, ads, messages, requests, billing, learning, profile, settings, notifications, "seo-reports": reports };
      await (renderers[key] || ((target, reload) => customers(target, reload, { forceTab: "dashboard", hideSubnav: true })))(view, refresh);
      refreshNotificationBadge(refresh);
    } catch (error) {
      view.innerHTML = `<div class="wnq-error"><strong>Unable to load this section.</strong><p>${esc(error.message)}</p></div>`;
    }
  };

  const notificationKeys = (items = []) => items.map((item) => [item.type, item.title, item.message, item.date].join("|")).sort();
  const unlockNotificationSound = () => { state.soundUnlocked = true; };
  const playNotificationSound = () => {
    if (!state.soundUnlocked) return;
    const AudioContext = window.AudioContext || window.webkitAudioContext;
    if (!AudioContext) return;
    const context = new AudioContext();
    const ring = (frequency, start, duration) => {
      const oscillator = context.createOscillator();
      const gain = context.createGain();
      oscillator.type = "sine";
      oscillator.frequency.setValueAtTime(frequency, start);
      gain.gain.setValueAtTime(0.0001, start);
      gain.gain.exponentialRampToValueAtTime(0.16, start + 0.02);
      gain.gain.exponentialRampToValueAtTime(0.0001, start + duration);
      oscillator.connect(gain).connect(context.destination);
      oscillator.start(start);
      oscillator.stop(start + duration);
    };
    const now = context.currentTime;
    ring(880, now, 0.32);
    ring(1174, now + 0.18, 0.42);
    window.setTimeout(() => context.close(), 900);
  };
  document.addEventListener("pointerdown", unlockNotificationSound, { once: true });
  document.addEventListener("keydown", unlockNotificationSound, { once: true });
  const refreshNotificationBadge = async (refresh = false, announce = false) => {
    try {
      const data = await load("notifications", refresh);
      const keys = notificationKeys(data.items || []);
      const hasNewItem = state.notificationKeys !== null && keys.some((key) => !state.notificationKeys.includes(key));
      if (announce && hasNewItem && data.settings?.sound_enabled !== false) playNotificationSound();
      state.notificationKeys = keys;
      const button = root.querySelector('[data-tab="notifications"]');
      if (!button) return;
      button.querySelector("b")?.remove();
      if (Number(data.attention_count || 0) > 0) button.insertAdjacentHTML("beforeend", `<b>${esc(data.attention_count)}</b>`);
    } catch (error) {
      // Notification availability should never block the rest of the portal.
    }
  };

  async function overview(view, refresh) {
    const data = await load("overview", refresh);
    const crm = data.customers || {};
    const actions = data.actions || [];
    const current = data.performance?.[data.performance.length - 1] || {};
    const messagesTab = root.querySelector('[data-tab="messages"]');
    if (messagesTab) {
      const label = messagesTab.querySelector("span");
      if (label) label.textContent = "Support";
      messagesTab.querySelector("b")?.remove();
      if (data.unread_messages) messagesTab.insertAdjacentHTML("beforeend", `<b>${esc(data.unread_messages)}</b>`);
    }
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
        <div class="wnq-panel"><div class="wnq-panel-head"><h2>Latest Report</h2></div>${data.latest_report ? `<strong>${esc(reportTitle(data.latest_report))}</strong><p>${reportMonth(data.latest_report.period_start, data.latest_report.period_end)}</p><button class="wnq-button" data-go="reports">View reports</button>` : empty("Your first report will appear here.")}</div>
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
    if (["all", "custom"].includes(range)) return null;
    const now = new Date();
    const start = new Date(now);
    if (range === "month") start.setDate(1);
    if (range === "30") start.setDate(now.getDate() - 30);
    if (range === "90") start.setDate(now.getDate() - 90);
    return { from: isoDate(start), to: isoDate(now) };
  };

  async function reports(view, refresh) {
    const rows = await load("reports", refresh);
    view.innerHTML = `${heading("Results", "Monthly SEO Reports", "Review the SEO OS report archive, open the full report, or download the PDF. CRM reports live in the separate CRM Reports section.")}
      <div class="wnq-panel"><div class="wnq-list-head"><span>Report</span><span>Period</span><span>Status</span></div>
      ${rows.length ? rows.map((row) => `<div class="wnq-list-row"><strong>${esc(reportTitle(row))}</strong><span>${reportMonth(row.period_start, row.period_end)}</span><span>${status(row.status === "ready" || row.status === "sent" ? "green" : "yellow", row.status || "Draft")} ${row.view_url ? `<a class="wnq-link" href="${esc(row.view_url)}" target="_blank" rel="noopener">View Full Report</a>` : ""} ${row.pdf_url ? `<a class="wnq-link" href="${esc(row.pdf_url)}">Download PDF</a>` : ""}</span></div>`).join("") : empty("No SEO OS reports are available yet.")}</div>`;
  }

  async function customers(view, refresh, options = {}) {
    const [savedRows, workRows, performance, portalSettings] = await Promise.all([load("customers", refresh), load("work", refresh), load("performance", refresh), load("settings", refresh)]);
    state.portalSettings = portalSettings;
    const today = isoDate();
    const upcomingLimit = new Date();
    upcomingLimit.setDate(upcomingLimit.getDate() + 30);
    const upcomingLimitIso = isoDate(upcomingLimit);
    const wonStatuses = ["completed"];
    const lostStatuses = ["lost", "canceled"];
    const leadStatuses = ["new", "contacted", "quoted"];
    const jobStatuses = ["scheduled", "in_progress", "completed", "canceled"];
    const pipelineStages = portalSettings.crm?.pipeline_stages?.length ? portalSettings.crm.pipeline_stages : [
      { key: "new", label: "New Lead", color: "#D7B846" }, { key: "contacted", label: "Contacted", color: "#4B7BEC" },
      { key: "quote-sent", label: "Quote Sent", color: "#8E63CE" }, { key: "follow-up", label: "Follow-Up", color: "#E58A2B" },
    ];
    const pipelineKeys = pipelineStages.map((stage) => stage.key);
    const rows = savedRows.map((row) => {
      const legacyJob = row.record_type === "job" || jobStatuses.includes(row.status) || Boolean(row.job_date) || Number(row.job_count || 0) > 0 || Number(row.final_value || 0) > 0;
      const mappedStage = row.status === "contacted" ? "contacted" : row.status === "quoted" ? "quote-sent" : pipelineKeys[0];
      const pipelineStage = pipelineKeys.includes(row.pipeline_stage) ? row.pipeline_stage : (pipelineKeys.includes(mappedStage) ? mappedStage : pipelineKeys[0]);
      return { ...row, record_type: legacyJob ? "job" : "lead", job_count: legacyJob ? 1 : 0, pipeline_stage: pipelineStage };
    });
    const filters = {
      search: sessionStorage.getItem("wnqCrmSearch") || "",
      status: sessionStorage.getItem("wnqCrmStatus") || "all",
      from: sessionStorage.getItem("wnqCrmFrom") || "",
      to: sessionStorage.getItem("wnqCrmTo") || "",
      service: sessionStorage.getItem("wnqCrmService") || "all",
      source: sessionStorage.getItem("wnqCrmSource") || "all",
      crew: sessionStorage.getItem("wnqCrmCrew") || "all",
    };
    const activeCrmTab = options.forceTab || sessionStorage.getItem("wnqCrmTab") || "dashboard";
    const routeStatusOptions = {
      leads: ["all", "new", "contacted", "quoted", "lost"],
      jobs: ["all", "scheduled", "in_progress", "completed", "canceled", "lost"],
      followups: ["all", "new", "contacted", "quoted", "scheduled", "in_progress"],
    };
    if (routeStatusOptions[activeCrmTab] && !routeStatusOptions[activeCrmTab].includes(filters.status)) {
      filters.status = "all";
    }
    if (activeCrmTab === "opportunities") filters.status = "all";
    if (["jobs", "calendar", "followups"].includes(activeCrmTab)) filters.source = "all";
    if (["opportunities", "leads", "jobs", "calendar"].includes(activeCrmTab)) filters.crew = "all";
    const crmRange = sessionStorage.getItem("wnqCrmRange") || "month";
    const calendarMonth = sessionStorage.getItem("wnqCrmMonth") || isoDate().slice(0, 7);
    const rangeBounds = crmRangeBounds(crmRange);
    if (rangeBounds && ["dashboard", "reports"].includes(activeCrmTab) && !filters.from && !filters.to) {
      filters.from = rangeBounds.from;
      filters.to = rangeBounds.to;
    }
    const visibleRows = filterCrmRows(rows, filters);
    const calendarRows = filterCrmRows(rows, { ...filters, search: "", status: "all", source: "all", from: "", to: "" });
    const totals = crmTotals(visibleRows);
    const isFinished = (row) => [...wonStatuses, ...lostStatuses].includes(row.status);
    const leadRows = visibleRows.filter((row) => row.record_type === "lead");
    const leads = leadRows.filter((row) => leadStatuses.includes(row.status) && !isFinished(row));
    const jobs = visibleRows.filter((row) => row.record_type === "job");
    const activeJobs = jobs.filter((row) => ["scheduled", "in_progress"].includes(row.status));
    const scheduledJobs = calendarRows.filter((row) => row.record_type === "job" && row.job_date);
    const upcoming = scheduledJobs.filter((row) => row.job_date >= today && row.job_date <= upcomingLimitIso && !lostStatuses.includes(row.status));
    const followupRows = calendarRows.filter((row) => row.follow_up_date && ![...wonStatuses, ...lostStatuses].includes(row.status));
    const overdue = followupRows.filter((row) => row.follow_up_date < today);
    const completed = jobs.filter((row) => wonStatuses.includes(row.status));
    const canceledJobs = jobs.filter((row) => lostStatuses.includes(row.status));
    const convertedJobs = jobs.filter((row) => !lostStatuses.includes(row.status));
    const lost = leadRows.filter((row) => lostStatuses.includes(row.status));
    const avgJob = totals.jobs ? totals.revenue / totals.jobs : 0;
    const leadBase = leads.length + convertedJobs.length + lost.length;
    const closeRate = convertedJobs.length + lost.length ? Math.round((convertedJobs.length / (convertedJobs.length + lost.length)) * 100) : 0;
    const topServices = topBy(visibleRows, "service", "final_value");
    const topCustomers = topBy(visibleRows, "name", "final_value");
    const topSources = countBy(visibleRows, "lead_source");
    const completedRevenue = completed.reduce((sum, row) => sum + Number(row.final_value || 0), 0);
    const openOpportunities = leads.length;
    const crmNotice = sessionStorage.getItem("wnqCrmNotice") || "";
    if (crmNotice) sessionStorage.removeItem("wnqCrmNotice");
    const serviceOptions = [...new Set([...(portalSettings.crm?.services || []), ...optionsFrom(rows, "service")])].filter(Boolean).sort();
    const sourceOptions = [...new Set([...(portalSettings.crm?.lead_sources || []), ...optionsFrom(rows, "lead_source")])].filter(Boolean).sort();
    const crewOptions = optionsFrom(rows, "crew");
    const context = {
      activeCrmTab, rows, visibleRows, calendarRows, followupRows, performance, leads, leadRows, activeJobs, jobs, convertedJobs, scheduledJobs, upcoming, overdue, completed, canceledJobs, lost,
      totals, completedRevenue, avgJob, closeRate, leadBase, openOpportunities, topServices, topCustomers, topSources,
      workRows, filters, serviceOptions, sourceOptions, crewOptions, crmRange, calendarMonth, portalSettings, pipelineStages
    };
    view.innerHTML = `
      ${crmNotice ? `<div class="wnq-success-inline">${esc(crmNotice)}</div>` : ""}
      ${canSeePrivate && totals.revenue === 0 && totals.cost > 0 && activeCrmTab === "dashboard" ? `<p class="wnq-crm-finance-note">Costs are logged, but no revenue has been tracked yet. Profit will update once completed job revenue is added.</p>` : ""}
      ${crmRoutePage(context)}`;
    const formRoot = view.querySelector("#wnq-customer-form");
    const applyFilters = () => {
      sessionStorage.setItem("wnqCrmSearch", view.querySelector("#wnq-crm-search")?.value || "");
      sessionStorage.setItem("wnqCrmStatus", view.querySelector("#wnq-crm-status")?.value || "all");
      sessionStorage.setItem("wnqCrmFrom", view.querySelector("#wnq-crm-from")?.value || "");
      sessionStorage.setItem("wnqCrmTo", view.querySelector("#wnq-crm-to")?.value || "");
      sessionStorage.setItem("wnqCrmService", view.querySelector("#wnq-crm-service")?.value || "all");
      sessionStorage.setItem("wnqCrmSource", view.querySelector("#wnq-crm-source")?.value || "all");
      sessionStorage.setItem("wnqCrmCrew", view.querySelector("#wnq-crm-crew")?.value || "all");
      if (activeCrmTab === "reports" && (view.querySelector("#wnq-crm-from")?.value || view.querySelector("#wnq-crm-to")?.value)) sessionStorage.setItem("wnqCrmRange", "custom");
      if (view.querySelector("#wnq-crm-month")) sessionStorage.setItem("wnqCrmMonth", view.querySelector("#wnq-crm-month").value || "");
      show(state.active);
    };
    view.querySelector("#wnq-crm-apply")?.addEventListener("click", applyFilters);
    view.querySelectorAll("[data-crm-export]").forEach((button) => button.addEventListener("click", () => exportCrmReport(context)));
    view.querySelector("#wnq-crm-search")?.addEventListener("keydown", (event) => { if (event.key === "Enter") { event.preventDefault(); applyFilters(); } });
    ["#wnq-crm-status", "#wnq-crm-from", "#wnq-crm-to", "#wnq-crm-service", "#wnq-crm-source", "#wnq-crm-crew", "#wnq-crm-month"].forEach((selector) => view.querySelector(selector)?.addEventListener("change", applyFilters));
    view.querySelector("#wnq-crm-clear")?.addEventListener("click", () => {
      ["wnqCrmSearch", "wnqCrmStatus", "wnqCrmFrom", "wnqCrmTo", "wnqCrmService", "wnqCrmSource", "wnqCrmCrew", "wnqCrmMonth"].forEach((key) => sessionStorage.removeItem(key));
      show(state.active);
    });
    view.querySelector("#wnq-crm-header-refresh")?.addEventListener("click", () => show(state.active, true));
    view.querySelector("#wnq-crm-range")?.addEventListener("change", (event) => {
      sessionStorage.setItem("wnqCrmRange", event.currentTarget.value || "month");
      sessionStorage.removeItem("wnqCrmFrom");
      sessionStorage.removeItem("wnqCrmTo");
      show(state.active);
    });
    const shiftCalendarMonth = (amount) => {
      const [year, month] = (sessionStorage.getItem("wnqCrmMonth") || calendarMonth).split("-").map(Number);
      const next = new Date(year, month - 1 + amount, 1);
      sessionStorage.setItem("wnqCrmMonth", `${next.getFullYear()}-${String(next.getMonth() + 1).padStart(2, "0")}`);
      show("calendar");
    };
    view.querySelector("#wnq-calendar-prev")?.addEventListener("click", () => shiftCalendarMonth(-1));
    view.querySelector("#wnq-calendar-next")?.addEventListener("click", () => shiftCalendarMonth(1));
    view.querySelector("#wnq-calendar-today")?.addEventListener("click", () => {
      sessionStorage.setItem("wnqCrmMonth", isoDate().slice(0, 7));
      show("calendar");
    });
    view.querySelectorAll("[data-crm-jump]").forEach((button) => button.addEventListener("click", () => {
      if (button.dataset.crmJump === "reports") {
        show("crm-reports");
        return;
      }
      const targetRoute = Object.entries(crmRoutes).find(([, panel]) => panel === button.dataset.crmJump)?.[0] || "overview";
      show(targetRoute);
    }));
    const clearVisibilityFilters = () => {
      ["wnqCrmSearch", "wnqCrmStatus", "wnqCrmFrom", "wnqCrmTo", "wnqCrmService", "wnqCrmSource", "wnqCrmCrew"].forEach((key) => sessionStorage.removeItem(key));
    };
    const openForm = (row = {}) => {
      if (!formRoot) return;
      formRoot.innerHTML = customerForm(row, portalSettings);
      formRoot.scrollIntoView({ behavior: "smooth", block: "start" });
      formRoot.querySelector("form").addEventListener("submit", async (event) => {
        event.preventDefault();
        const form = event.currentTarget;
        const submit = form.querySelector('[type="submit"]');
        const submitLabel = submit?.textContent || "Save";
        const name = form.elements.namedItem("name");
        if (!String(name?.value || "").trim()) {
          formStatus(form, "red", "Contact name is required before this can be saved.");
          name?.focus();
          return;
        }
        formStatus(form, "yellow", "Saving CRM record...");
        if (submit) { submit.disabled = true; submit.textContent = "Saving..."; }
        try {
          const saved = await api("/portal/customers", { method: "POST", body: formBody(form) });
          if (!saved?.id) throw new Error("The server did not return the saved CRM record.");
          const destination = saved.record_type === "job" ? "jobs" : (state.active === "opportunities" ? "opportunities" : "leads");
          clearVisibilityFilters();
          sessionStorage.setItem("wnqCrmNotice", `${saved.record_type === "job" ? "Job" : "Lead"} saved successfully.`);
          delete state.cache.customers; delete state.cache.overview; delete state.cache.performance; delete state.cache.notifications;
          show(destination, true);
        } catch (error) {
          formStatus(form, "red", `Record was not saved. ${error.message}`);
          if (submit) { submit.disabled = false; submit.textContent = submitLabel; }
        }
      });
      formRoot.querySelector("[data-cancel]").addEventListener("click", () => { formRoot.innerHTML = ""; });
    };
    view.querySelector("#wnq-add-customer")?.addEventListener("click", () => openForm());
    view.querySelector("#wnq-add-lead")?.addEventListener("click", () => openForm({ record_type: "lead", status: "new" }));
    view.querySelector("#wnq-add-job")?.addEventListener("click", () => openForm({ record_type: "job", status: "scheduled" }));
    view.querySelectorAll("[data-edit]").forEach((button) => button.addEventListener("click", () => openForm(JSON.parse(button.dataset.edit))));
    const moveOpportunity = async (id, pipelineStage) => {
      const saved = await api(`/portal/opportunities/${Number(id || 0)}/stage`, { method: "POST", body: JSON.stringify({ pipeline_stage: pipelineStage }) });
      if (!saved?.id) throw new Error("The updated opportunity was not returned by the server.");
      delete state.cache.customers; delete state.cache.overview;
      sessionStorage.setItem("wnqCrmNotice", "Opportunity moved successfully.");
      show("opportunities", true);
    };
    view.querySelectorAll("[data-opportunity-stage]").forEach((select) => select.addEventListener("change", async () => {
      const previous = select.dataset.currentStage || pipelineStages[0]?.key || "new";
      select.disabled = true;
      try {
        await moveOpportunity(select.dataset.opportunityStage, select.value);
      } catch (error) {
        select.value = previous;
        select.disabled = false;
        window.alert(error.message);
      }
    }));
    const opportunityBoard = view.querySelector(".wnq-opportunity-board");
    if (opportunityBoard) {
      const clearDragState = () => {
        opportunityBoard.querySelectorAll(".is-dragging, .is-drag-over, .is-drop-pending").forEach((element) => element.classList.remove("is-dragging", "is-drag-over", "is-drop-pending"));
      };
      opportunityBoard.querySelectorAll("[data-opportunity-card]").forEach((card) => {
        card.addEventListener("dragstart", (event) => {
          if (event.target.closest("button, select, input, textarea, a") || !event.dataTransfer) {
            event.preventDefault();
            return;
          }
          card.classList.add("is-dragging");
          event.dataTransfer.effectAllowed = "move";
          event.dataTransfer.setData("text/plain", card.dataset.opportunityCard || "");
        });
        card.addEventListener("dragend", clearDragState);
      });
      opportunityBoard.querySelectorAll("[data-pipeline-stage]").forEach((column) => {
        column.addEventListener("dragover", (event) => {
          event.preventDefault();
          if (event.dataTransfer) event.dataTransfer.dropEffect = "move";
          opportunityBoard.querySelectorAll(".is-drag-over").forEach((element) => element.classList.remove("is-drag-over"));
          column.classList.add("is-drag-over");
        });
        column.addEventListener("dragleave", (event) => {
          if (!column.contains(event.relatedTarget)) column.classList.remove("is-drag-over");
        });
        column.addEventListener("drop", async (event) => {
          event.preventDefault();
          const id = Number(event.dataTransfer?.getData("text/plain") || 0);
          const card = opportunityBoard.querySelector(`[data-opportunity-card="${id}"]`);
          const nextStage = column.dataset.pipelineStage || "";
          const currentStage = card?.dataset.currentStage || "";
          clearDragState();
          if (!id || !nextStage || nextStage === currentStage) return;
          column.classList.add("is-drop-pending");
          try {
            await moveOpportunity(id, nextStage);
          } catch (error) {
            clearDragState();
            window.alert(error.message);
          }
        });
      });
    }
    view.querySelectorAll("[data-convert-lead]").forEach((button) => button.addEventListener("click", async () => {
      const id = Number(button.dataset.convertLead || 0);
      if (!id || !window.confirm("Convert this lead into a scheduled job? You can complete the job details next.")) return;
      button.disabled = true;
      button.textContent = "Converting...";
      try {
        const saved = await api(`/portal/leads/${id}/convert`, { method: "POST", body: JSON.stringify({}) });
        if (!saved?.id) throw new Error("The converted job was not returned by the server.");
        clearVisibilityFilters();
        sessionStorage.setItem("wnqCrmNotice", "Lead converted to a job. Add the schedule and job details when ready.");
        delete state.cache.customers; delete state.cache.overview; delete state.cache.performance; delete state.cache.notifications;
        show("jobs", true);
      } catch (error) {
        button.disabled = false;
        button.textContent = "Convert to Job";
        window.alert(error.message);
      }
    }));
    view.querySelectorAll("[data-followup]").forEach((button) => button.addEventListener("click", async () => {
      const row = JSON.parse(button.dataset.followup);
      const nextDate = window.prompt("Next follow-up date (YYYY-MM-DD). Leave blank to clear this follow-up.", "");
      if (nextDate === null) return;
      await api("/portal/customers", { method: "POST", body: JSON.stringify({ ...recordPayload(row), follow_up_date: nextDate || "" }) });
      sessionStorage.setItem("wnqCrmNotice", nextDate ? "Follow-up updated." : "Follow-up completed.");
      delete state.cache.customers; delete state.cache.overview; delete state.cache.performance; delete state.cache.notifications; show(state.active, true);
    }));
    bindPipelineEditor(view, pipelineStages);
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
    id: row.id || "", record_type: row.record_type || "lead", pipeline_stage: row.pipeline_stage || "new", name: row.name || "", phone: row.phone || "", email: row.email || "",
    address: row.address || "", job_address: row.job_address || "", service: row.service || "", crew: row.crew || "",
    lead_source: row.lead_source || "", status: row.status || "new", follow_up_date: row.follow_up_date || "",
    reminder_date: row.reminder_date || "", job_date: row.job_date || "", completion_date: row.completion_date || "",
    job_count: row.job_count || 0, estimated_value: row.estimated_value || 0, final_value: row.final_value || 0,
    job_cost: row.job_cost || 0, notes: row.notes || "", internal_notes: row.internal_notes || "", lost_reason: row.lost_reason || ""
  });
  const crmRoutePage = (ctx) => {
    if (ctx.activeCrmTab === "dashboard") {
      return `${crmHeader(ctx.crmRange)}${crmKpis(ctx)}${crmDashboard(ctx)}`;
    }
    if (ctx.activeCrmTab === "opportunities") {
      const activeOpportunities = ctx.leadRows.filter((row) => row.status !== "lost");
      return `${crmPageHeader("Opportunities", "Track each open opportunity from first contact to booked work.", `<button type="button" class="wnq-button is-secondary" id="wnq-customize-pipeline">Customize Pipeline</button><button type="button" class="wnq-button" id="wnq-add-lead">Add Opportunity</button>`)}
        ${crmOpportunityControls(ctx)}<p class="wnq-crm-filter-note">${esc(activeOpportunities.length)} active opportunit${activeOpportunities.length === 1 ? "y" : "ies"} across ${esc(ctx.pipelineStages.length)} stages.</p><div id="wnq-pipeline-editor" hidden>${pipelineEditor()}</div><div id="wnq-customer-form"></div>${opportunityBoard(activeOpportunities, ctx.pipelineStages)}`;
    }
    if (ctx.activeCrmTab === "leads") {
      return `${crmPageHeader("Leads", "Track new inquiries, contacted leads, quoted leads, and lost leads.", `<button type="button" class="wnq-button" id="wnq-add-lead">Add Lead</button>`)}
        ${crmLeadControls(ctx)}<p class="wnq-crm-filter-note">Showing ${esc(ctx.leadRows.length)} lead record${ctx.leadRows.length === 1 ? "" : "s"}.</p><div id="wnq-customer-form"></div>${leadPipelineTable(ctx.leadRows, ctx.pipelineStages)}`;
    }
    if (ctx.activeCrmTab === "jobs") {
      return `${crmPageHeader("Jobs", "Track scheduled, active, completed, and canceled jobs.", `<button type="button" class="wnq-button" id="wnq-add-job">Add Job</button>`)}
        ${crmJobControls(ctx)}<p class="wnq-crm-filter-note">Showing ${esc(ctx.jobs.length)} job record${ctx.jobs.length === 1 ? "" : "s"}.</p><div id="wnq-customer-form"></div>${crmJobsPanel(ctx.activeJobs, ctx.completed, ctx.canceledJobs)}`;
    }
    if (ctx.activeCrmTab === "calendar") {
      return `${crmPageHeader("Calendar", "View upcoming scheduled jobs and follow-ups.", `<button type="button" class="wnq-button" id="wnq-add-job">Add Job</button>`)}
        ${crmCalendarControls(ctx)}<div id="wnq-customer-form"></div>${crmCalendar(ctx.scheduledJobs, ctx.followupRows, ctx.calendarMonth)}`;
    }
    if (ctx.activeCrmTab === "followups") {
      return `${crmPageHeader("Follow-ups", "Track overdue follow-ups and upcoming customer touchpoints.")}${crmFollowupControls(ctx)}<div id="wnq-customer-form"></div>${crmFollowups(ctx.overdue)}`;
    }
    if (ctx.activeCrmTab === "reports") {
      return `${crmPageHeader("Reports", "Choose a date range, review the complete CRM overview, and export the records you need.", `<button type="button" class="wnq-button is-secondary" id="wnq-crm-header-refresh">Refresh</button>`)}
        ${crmReportControls(ctx)}${crmReports(ctx)}`;
    }
    return `${crmPageHeader("CRM", "Track leads, jobs, and client activity.")}${empty("Choose a CRM section from the sidebar.")}`;
  };
  const crmPageHeader = (title, copy, actions = "") => `<header class="wnq-crm-head is-simple"><div><span class="wnq-eyebrow">Golden Web Marketing</span><h1>${esc(title)}</h1><p>${esc(copy)}</p></div>${actions ? `<div class="wnq-crm-head-actions">${actions}</div>` : ""}</header>`;
  const crmOptions = (items, selected) => items.map((item) => Array.isArray(item) ? item : [item, item]).map(([value, label]) => `<option value="${esc(value)}" ${selected === value ? "selected" : ""}>${esc(label)}</option>`).join("");
  const crmServiceSelect = (ctx) => `<label><span>Service</span><select id="wnq-crm-service"><option value="all">All services</option>${crmOptions(ctx.serviceOptions, ctx.filters.service)}</select></label>`;
  const crmSourceSelect = (ctx) => `<label><span>Source</span><select id="wnq-crm-source"><option value="all">All sources</option>${crmOptions(ctx.sourceOptions, ctx.filters.source)}</select></label>`;
  const crmDateControls = (ctx) => `${fieldControl("wnq-crm-from", "From", ctx.filters.from, "date")}${fieldControl("wnq-crm-to", "To", ctx.filters.to, "date")}`;
  const fieldControl = (id, label, value = "", type = "text") => `<label><span>${esc(label)}</span><input type="${esc(type)}" id="${esc(id)}" value="${esc(value)}"></label>`;
  const crmApplyButtons = (primary = "") => `<button type="button" class="wnq-button is-secondary" id="wnq-crm-apply">Apply Filters</button><button type="button" class="wnq-link" id="wnq-crm-clear">Clear</button>${primary}`;
  const crmOpportunityControls = (ctx) => `<div class="wnq-crm-controls is-focused">${fieldControl("wnq-crm-search", "Search opportunities", ctx.filters.search, "search")}${crmSourceSelect(ctx)}${crmServiceSelect(ctx)}${crmDateControls(ctx)}${crmApplyButtons()}</div>`;
  const crmLeadControls = (ctx) => `<div class="wnq-crm-controls is-focused">${fieldControl("wnq-crm-search", "Search", ctx.filters.search, "search")}<label><span>Status</span><select id="wnq-crm-status">${crmOptions([["all", "All leads"], ["new", "New Lead"], ["contacted", "Contacted"], ["quoted", "Quoted"], ["lost", "Lost"]], ctx.filters.status)}</select></label>${crmSourceSelect(ctx)}${crmServiceSelect(ctx)}${crmDateControls(ctx)}${crmApplyButtons()}</div>`;
  const crmJobControls = (ctx) => `<div class="wnq-crm-controls is-focused">${fieldControl("wnq-crm-search", "Search", ctx.filters.search, "search")}<label><span>Job Status</span><select id="wnq-crm-status">${crmOptions([["all", "All jobs"], ["scheduled", "Scheduled"], ["in_progress", "In Progress"], ["completed", "Completed"], ["canceled", "Canceled"]], ctx.filters.status)}</select></label>${crmServiceSelect(ctx)}${crmDateControls(ctx)}${crmApplyButtons()}</div>`;
  const crmFollowupControls = (ctx) => `<div class="wnq-crm-controls is-focused"><label><span>Status</span><select id="wnq-crm-status">${crmOptions([["all", "All follow-ups"], ["new", "New"], ["contacted", "Contacted"], ["quoted", "Quoted"], ["scheduled", "Scheduled"]], ctx.filters.status)}</select></label>${crmDateControls(ctx)}<label><span>Assigned User</span><select id="wnq-crm-crew"><option value="all">Any user</option>${crmOptions(ctx.crewOptions, ctx.filters.crew)}</select></label>${crmApplyButtons()}</div>`;
  const crmCalendarControls = (ctx) => `<div class="wnq-calendar-toolbar"><div class="wnq-calendar-navigation"><button type="button" class="wnq-button is-secondary" id="wnq-calendar-prev" aria-label="Previous month">&#8249;</button><button type="button" class="wnq-button is-secondary" id="wnq-calendar-today">Today</button><button type="button" class="wnq-button is-secondary" id="wnq-calendar-next" aria-label="Next month">&#8250;</button></div><div class="wnq-crm-controls is-compact"><label><span>Month</span><input type="month" id="wnq-crm-month" value="${esc(ctx.calendarMonth)}"></label>${crmServiceSelect(ctx)}${crmApplyButtons()}</div></div>`;
  const crmReportControls = (ctx) => `<div class="wnq-crm-controls is-compact wnq-report-controls"><label><span>Date Range</span><select id="wnq-crm-range"><option value="month" ${ctx.crmRange === "month" ? "selected" : ""}>This Month</option><option value="30" ${ctx.crmRange === "30" ? "selected" : ""}>Last 30 Days</option><option value="90" ${ctx.crmRange === "90" ? "selected" : ""}>Last 90 Days</option><option value="all" ${ctx.crmRange === "all" ? "selected" : ""}>All Time</option><option value="custom" ${ctx.crmRange === "custom" ? "selected" : ""}>Custom Range</option></select></label>${crmDateControls(ctx)}${crmServiceSelect(ctx)}${crmSourceSelect(ctx)}${crmApplyButtons(`<button type="button" class="wnq-button" data-crm-export>Export CSV</button>`)}</div>`;
  const pipelineStage = (row, stages) => stages.find((stage) => stage.key === row.pipeline_stage) || stages[0] || { key: "new", label: "New Lead", color: "#D7B846" };
  const pipelineBadge = (row, stages) => { const stage = pipelineStage(row, stages); return `<span class="wnq-pipeline-badge" style="--stage-color:${esc(stage.color)}">${esc(stage.label)}</span>`; };
  const pipelineSelect = (row, stages) => `<select class="wnq-stage-select" data-opportunity-stage="${esc(row.id)}" data-current-stage="${esc(row.pipeline_stage)}" aria-label="Move ${esc(row.name)} to another pipeline stage">${stages.map((stage) => `<option value="${esc(stage.key)}" ${stage.key === row.pipeline_stage ? "selected" : ""}>${esc(stage.label)}</option>`).join("")}</select>`;
  const leadPipelineTable = (rows, stages) => `<div class="wnq-panel wnq-table-wrap wnq-crm-table-panel"><div class="wnq-panel-head"><div><h2>Lead Directory</h2><small>${esc(rows.length)} lead${rows.length === 1 ? "" : "s"}</small></div></div><table class="wnq-crm-table is-fit"><thead><tr><th>Contact</th><th>Service / Source</th><th>Pipeline Stage</th><th>Lead Status</th><th>Estimate</th><th>Next Follow-up</th><th>Action</th></tr></thead><tbody>${rows.length ? rows.map((row) => `<tr><td data-label="Contact"><strong>${esc(row.name)}</strong>${crmContact(row)}</td><td data-label="Service / Source"><strong>${esc(row.service || "Not set")}</strong><small>${esc(row.lead_source || "Source not set")}</small></td><td data-label="Pipeline Stage">${pipelineBadge(row, stages)}</td><td data-label="Lead Status">${crmStatus(row.status)}</td><td data-label="Estimate"><strong>${money(row.estimated_value)}</strong></td><td data-label="Next Follow-up">${date(row.follow_up_date || row.reminder_date)}</td><td data-label="Action"><div class="wnq-row-actions"><button type="button" class="wnq-button is-compact" data-convert-lead="${esc(row.id)}">Convert to Job</button><button type="button" class="wnq-link" data-edit='${attr(row)}'>Edit</button></div></td></tr>`).join("") : `<tr><td colspan="7">${empty("No leads are recorded yet. Add a lead to begin your pipeline.")}</td></tr>`}</tbody></table></div>`;
  const opportunityBoard = (rows, stages) => `<div class="wnq-opportunity-board">${stages.map((stage) => {
    const stageRows = rows.filter((row) => row.pipeline_stage === stage.key);
    const value = stageRows.reduce((sum, row) => sum + Number(row.estimated_value || 0), 0);
    return `<section class="wnq-opportunity-column" data-pipeline-stage="${esc(stage.key)}" style="--stage-color:${esc(stage.color)}"><header><div><span></span><strong>${esc(stage.label)}</strong><small>${esc(stageRows.length)} opportunit${stageRows.length === 1 ? "y" : "ies"}</small></div><em>${money(value)}</em></header><div class="wnq-opportunity-list">${stageRows.length ? stageRows.map((row) => `<article class="wnq-opportunity-card" draggable="true" data-opportunity-card="${esc(row.id)}" data-current-stage="${esc(row.pipeline_stage)}"><div class="wnq-opportunity-card-head"><strong>${esc(row.name)}</strong><button type="button" class="wnq-icon-button" data-edit='${attr(row)}' title="Edit opportunity" aria-label="Edit ${esc(row.name)}">&#9998;</button></div><p>${esc(row.service || "Service not set")}</p><div class="wnq-opportunity-meta"><span>${money(row.estimated_value)}</span><small>${row.follow_up_date ? `Follow up ${date(row.follow_up_date)}` : "No follow-up set"}</small></div>${pipelineSelect(row, stages)}<button type="button" class="wnq-button is-compact" data-convert-lead="${esc(row.id)}">Convert to Job</button></article>`).join("") : `<div class="wnq-opportunity-empty">Drop an opportunity here.</div>`}</div></section>`;
  }).join("")}</div>`;
  const pipelineEditor = () => `<section class="wnq-panel wnq-pipeline-editor-panel"><div class="wnq-panel-head"><div><span class="wnq-eyebrow">Pipeline Builder</span><h2>Opportunity Stages</h2><small>Shared across this client account</small></div><button type="button" class="wnq-icon-button" id="wnq-close-pipeline" aria-label="Close pipeline builder">&#10005;</button></div><div id="wnq-pipeline-stage-rows"></div><div class="wnq-form-actions"><button type="button" class="wnq-button is-secondary" id="wnq-add-pipeline-stage">Add Stage</button><button type="button" class="wnq-button" id="wnq-save-pipeline">Save Pipeline</button><span id="wnq-pipeline-status"></span></div></section>`;
  const bindPipelineEditor = (view, initialStages) => {
    const editor = view.querySelector("#wnq-pipeline-editor");
    const toggle = view.querySelector("#wnq-customize-pipeline");
    if (!editor || !toggle) return;
    let draft = initialStages.map((stage) => ({ ...stage }));
    const rowsRoot = editor.querySelector("#wnq-pipeline-stage-rows");
    const render = () => {
      rowsRoot.innerHTML = draft.map((stage, index) => `<div class="wnq-pipeline-stage-row" data-stage-index="${index}"><span class="wnq-stage-handle" aria-hidden="true">&#8942;&#8942;</span><input type="color" value="${esc(stage.color || "#D7B846")}" aria-label="Stage color"><input type="text" value="${esc(stage.label)}" maxlength="50" aria-label="Stage name"><div><button type="button" class="wnq-icon-button" data-stage-up="${index}" ${index === 0 ? "disabled" : ""} aria-label="Move stage up">&#8593;</button><button type="button" class="wnq-icon-button" data-stage-down="${index}" ${index === draft.length - 1 ? "disabled" : ""} aria-label="Move stage down">&#8595;</button><button type="button" class="wnq-icon-button is-danger" data-stage-remove="${index}" ${draft.length === 1 ? "disabled" : ""} aria-label="Remove stage">&#10005;</button></div></div>`).join("");
    };
    const readInputs = () => rowsRoot.querySelectorAll("[data-stage-index]").forEach((row) => {
      const index = Number(row.dataset.stageIndex);
      draft[index].color = row.querySelector('input[type="color"]').value;
      draft[index].label = row.querySelector('input[type="text"]').value.trim();
    });
    toggle.addEventListener("click", () => { editor.hidden = false; toggle.disabled = true; render(); editor.scrollIntoView({ behavior: "smooth", block: "start" }); });
    editor.querySelector("#wnq-close-pipeline")?.addEventListener("click", () => { editor.hidden = true; toggle.disabled = false; });
    editor.querySelector("#wnq-add-pipeline-stage")?.addEventListener("click", () => {
      readInputs();
      if (draft.length >= 12) return;
      draft.push({ key: `stage-${Date.now()}`, label: `Stage ${draft.length + 1}`, color: "#6E7F80" });
      render();
    });
    rowsRoot.addEventListener("click", (event) => {
      const button = event.target.closest("button");
      if (!button) return;
      readInputs();
      if (button.dataset.stageRemove !== undefined && draft.length > 1) draft.splice(Number(button.dataset.stageRemove), 1);
      if (button.dataset.stageUp !== undefined) { const index = Number(button.dataset.stageUp); if (index > 0) [draft[index - 1], draft[index]] = [draft[index], draft[index - 1]]; }
      if (button.dataset.stageDown !== undefined) { const index = Number(button.dataset.stageDown); if (index < draft.length - 1) [draft[index + 1], draft[index]] = [draft[index], draft[index + 1]]; }
      render();
    });
    editor.querySelector("#wnq-save-pipeline")?.addEventListener("click", async () => {
      readInputs();
      const statusTarget = editor.querySelector("#wnq-pipeline-status");
      const save = editor.querySelector("#wnq-save-pipeline");
      if (draft.some((stage) => !stage.label)) { statusTarget.textContent = "Every stage needs a name."; return; }
      if (new Set(draft.map((stage) => stage.label.toLowerCase())).size !== draft.length) { statusTarget.textContent = "Stage names must be unique."; return; }
      save.disabled = true; save.textContent = "Saving..."; statusTarget.textContent = "";
      try {
        const saved = await api("/portal/settings", { method: "POST", body: JSON.stringify({ crm: { pipeline_stages: draft } }) });
        state.cache.settings = saved; state.portalSettings = saved; delete state.cache.customers; delete state.cache.overview;
        sessionStorage.setItem("wnqCrmNotice", "Opportunity pipeline saved.");
        show("opportunities", true);
      } catch (error) {
        statusTarget.textContent = error.message; save.disabled = false; save.textContent = "Save Pipeline";
      }
    });
  };
  const crmDashboard = ({ rows, performance, leads, convertedJobs, completed, lost, upcoming, overdue, totals, completedRevenue, avgJob, closeRate, topServices, topCustomers, topSources, workRows, pipelineStages }) => {
    const openCount = leads.length;
    const totalOps = openCount + convertedJobs.length + lost.length;
    const donutTotal = totalOps;
    const openPct = donutTotal ? Math.round((openCount / donutTotal) * 100) : 0;
    const wonPct = donutTotal ? Math.round((convertedJobs.length / donutTotal) * 100) : 0;
    const lostPct = donutTotal ? Math.max(0, 100 - openPct - wonPct) : 0;
    const pipeline = pipelineRows(rows.filter((row) => row.record_type === "lead" && row.status !== "lost"), pipelineStages);
    return `<div class="wnq-dashboard-grid">
      <section class="wnq-panel wnq-dashboard-card is-opportunity"><div class="wnq-panel-head"><h2>Opportunity Overview</h2><button type="button" class="wnq-link" data-crm-jump="opportunities">View Pipeline</button></div><div class="wnq-donut-layout"><div class="wnq-donut ${donutTotal ? "" : "is-empty"}" style="--open:${openPct};--won:${wonPct};--lost:${lostPct}"><strong>${esc(donutTotal)}</strong><span>Total</span></div><div class="wnq-donut-legend">${donutLegend("Open", openCount, openPct, "green")}${donutLegend("Won", convertedJobs.length, wonPct, "gold")}${donutLegend("Lost", lost.length, lostPct, "red")}</div></div></section>
      <section class="wnq-panel wnq-dashboard-card"><div class="wnq-panel-head"><h2>Pipeline Snapshot</h2><button type="button" class="wnq-link" data-crm-jump="opportunities">View Pipeline</button></div>${pipelineSnapshot(pipeline)}</section>
      <section class="wnq-panel wnq-dashboard-card"><div class="wnq-panel-head"><h2>Tasks / Follow-ups</h2><button type="button" class="wnq-link" data-crm-jump="followups">View All Tasks</button></div>${taskList(overdue, upcoming)}</section>
      <section class="wnq-panel wnq-dashboard-card"><div class="wnq-panel-head"><h2>Revenue Trend</h2><span>${canSeePrivate ? `${trend(totals.revenue - totals.cost)} net` : `${money(totals.revenue)} tracked`}</span></div>${performanceChart(performance)}</section>
      <section class="wnq-panel wnq-dashboard-card"><div class="wnq-panel-head"><h2>Lead Source Report</h2><button type="button" class="wnq-link" data-crm-jump="reports">View Reports</button></div>${leadSourceTable(rows, topSources)}</section>
      <section class="wnq-panel wnq-dashboard-card"><div class="wnq-panel-head"><h2>Recent Activity</h2><button type="button" class="wnq-link" data-crm-jump="reports">View All Activity</button></div>${recentActivity(rows, workRows)}</section>
      <section class="wnq-panel wnq-dashboard-card is-wide"><div class="wnq-panel-head"><h2>Business Snapshot</h2></div><div class="wnq-metrics">${metric("Average Job Value", money(avgJob), "Revenue divided by jobs")}${metric("Close Rate", `${closeRate}%`, "Converted vs lost leads")}${metric("Top Service", topServices[0]?.label || "Not set", "Highest tracked revenue")}${metric("Top Customer", topCustomers[0]?.label || "Not set", "Highest tracked revenue")}</div></section>
    </div>`;
  };
  const donutLegend = (label, count, percent, tone) => `<div class="wnq-donut-row"><i class="is-${esc(tone)}"></i><span>${esc(label)}</span><strong>${esc(count)} (${esc(percent)}%)</strong></div>`;
  const pipelineRows = (rows, stages) => stages.map((stage) => {
    const stageRows = rows.filter((row) => row.pipeline_stage === stage.key);
    return { label: stage.label, color: stage.color, count: stageRows.length, value: stageRows.reduce((sum, row) => sum + Number(row.estimated_value || 0), 0) };
  });
  const pipelineSnapshot = (items) => {
    const max = Math.max(1, ...items.map((item) => item.count));
    return `<div class="wnq-pipeline">${items.map((item) => `<div class="wnq-pipeline-row"><span>${esc(item.label)}</span><div><i style="width:${Math.max(8, Math.round((item.count / max) * 100))}%;--stage-color:${esc(item.color || "#D7B846")}"></i></div><strong>${esc(item.count)}</strong><em>${money(item.value)}</em></div>`).join("")}</div>`;
  };
  const taskList = (overdue, upcoming) => {
    const today = isoDate();
    const tomorrow = new Date();
    tomorrow.setDate(tomorrow.getDate() + 1);
    const tomorrowIso = isoDate(tomorrow);
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
  const miniList = (title, rows, fallback) => `<div class="wnq-panel-head"><h2>${esc(title)}</h2></div>${rows.length ? rows.slice(0, 5).map((row) => `<div class="wnq-work-item"><div>${crmStatus(row.status)}<strong>${esc(row.name)}</strong></div><span>${date(row.job_date || row.follow_up_date)} · ${esc(row.service || "Service not set")}</span></div>`).join("") : empty(fallback)}`;
  const crmTable = (rows, title, fallback, mode = "general") => `<div class="wnq-panel wnq-table-wrap wnq-crm-table-panel"><div class="wnq-panel-head"><div><h2>${esc(title)}</h2><small>${esc(rows.length)} record${rows.length === 1 ? "" : "s"}</small></div></div><table class="wnq-crm-table"><thead><tr><th>Customer</th><th>Service / Source</th><th>Status</th><th>Schedule</th><th>${mode === "leads" ? "Estimate" : "Revenue"}</th><th>Job Info</th><th></th></tr></thead><tbody>${rows.length ? rows.map((row) => {
    const profit = Number(row.final_value || 0) - Number(row.job_cost || 0);
    const moneyNote = canSeePrivate && row.job_cost !== undefined ? `<small>Cost ${money(row.job_cost)} · ${trend(profit)} net</small>` : `<small>${row.final_value ? "Revenue saved" : "No revenue saved"}</small>`;
    return `<tr><td><strong>${esc(row.name)}</strong>${crmContact(row)}<small>${esc(row.address || "")}</small></td><td><strong>${esc(row.service || "Not set")}</strong><small>${esc(row.lead_source || "Source not set")}</small></td><td>${crmStatus(row.status)}</td><td><span>${date(row.job_date || row.follow_up_date || row.reminder_date)}</span><small>${row.completion_date ? `Completed ${date(row.completion_date)}` : row.follow_up_date ? `Next follow-up ${date(row.follow_up_date)}` : "No date set"}</small></td><td><strong>${money(mode === "leads" ? row.estimated_value : row.final_value)}</strong>${moneyNote}</td><td><small>${esc(row.job_address || row.address || "Address not set")}</small><small>${esc(row.crew ? `Assigned: ${row.crew}` : `Service: ${row.service || "Not set"}`)}</small></td><td><button class="wnq-link" data-edit='${attr(row)}'>Edit</button></td></tr>`;
  }).join("") : `<tr><td colspan="7">${empty(fallback)}</td></tr>`}</tbody></table></div>`;
  const crmJobsPanel = (active, completed, canceled = []) => `<div class="wnq-grid-2 wnq-job-sections"><section>${activeJobsTable(active)}</section><section>${completedJobsTable(completed)}</section>${canceled.length ? `<section>${canceledJobsTable(canceled)}</section>` : ""}</div>`;
  const activeJobsTable = (rows) => `<div class="wnq-panel wnq-table-wrap wnq-crm-table-panel"><div class="wnq-panel-head"><div><h2>Active Jobs</h2><small>${esc(rows.length)} active job${rows.length === 1 ? "" : "s"}</small></div></div><table class="wnq-crm-table is-fit"><thead><tr><th>Customer</th><th>Service</th><th>Status</th><th>Scheduled Date</th><th>Address</th><th>Revenue</th><th>Action</th></tr></thead><tbody>${rows.length ? rows.map((row) => `<tr><td data-label="Customer"><strong>${esc(row.name)}</strong>${crmContact(row)}</td><td data-label="Service">${esc(row.service || "Not set")}</td><td data-label="Status">${crmStatus(row.status)}</td><td data-label="Scheduled Date">${date(row.job_date || row.reminder_date)}</td><td data-label="Address"><small>${esc(row.job_address || row.address || "Address not set")}</small></td><td data-label="Revenue"><strong>${money(row.final_value || row.estimated_value)}</strong><small>${Number(row.final_value || 0) ? "Final revenue" : "Estimated"}</small></td><td data-label="Action"><button class="wnq-link" data-edit='${attr(row)}'>Edit</button></td></tr>`).join("") : `<tr><td colspan="7">${empty("No upcoming jobs are scheduled yet. Scheduled jobs will appear here.")}</td></tr>`}</tbody></table></div>`;
  const completedJobsTable = (rows) => `<div class="wnq-panel wnq-table-wrap wnq-crm-table-panel"><div class="wnq-panel-head"><div><h2>Completed Jobs</h2><small>${esc(rows.length)} completed job${rows.length === 1 ? "" : "s"}</small></div></div><table class="wnq-crm-table is-fit"><thead><tr><th>Customer</th><th>Service</th><th>Completed Date</th><th>Revenue</th><th>Profit</th><th>Action</th></tr></thead><tbody>${rows.length ? rows.map((row) => {
    const profit = Number(row.final_value || 0) - Number(row.job_cost || 0);
    return `<tr><td data-label="Customer"><strong>${esc(row.name)}</strong>${crmContact(row)}</td><td data-label="Service">${esc(row.service || "Not set")}</td><td data-label="Completed Date">${date(row.completion_date || row.job_date)}</td><td data-label="Revenue"><strong>${money(row.final_value)}</strong></td><td data-label="Profit">${canSeePrivate ? trend(profit) : "<span>Private</span>"}</td><td data-label="Action"><button class="wnq-link" data-edit='${attr(row)}'>Edit</button></td></tr>`;
  }).join("") : `<tr><td colspan="6">${empty("No completed jobs yet.")}</td></tr>`}</tbody></table></div>`;
  const canceledJobsTable = (rows) => `<div class="wnq-panel wnq-table-wrap wnq-crm-table-panel"><div class="wnq-panel-head"><div><h2>Canceled Jobs</h2><small>${esc(rows.length)} canceled job${rows.length === 1 ? "" : "s"}</small></div></div><table class="wnq-crm-table is-fit"><thead><tr><th>Contact</th><th>Service</th><th>Status</th><th>Reason</th><th>Action</th></tr></thead><tbody>${rows.map((row) => `<tr><td data-label="Contact"><strong>${esc(row.name)}</strong>${crmContact(row)}</td><td data-label="Service">${esc(row.service || "Not set")}</td><td data-label="Status">${crmStatus(row.status)}</td><td data-label="Reason"><small>${esc(row.lost_reason || "No reason saved")}</small></td><td data-label="Action"><button type="button" class="wnq-link" data-edit='${attr(row)}'>Edit</button></td></tr>`).join("")}</tbody></table></div>`;
  const crmFollowups = (rows) => `<div class="wnq-panel wnq-table-wrap wnq-crm-table-panel"><div class="wnq-panel-head"><div><h2>Follow-ups Due</h2><small>${esc(rows.length)} follow-up${rows.length === 1 ? "" : "s"} need attention</small></div></div><table class="wnq-crm-table is-fit wnq-followup-table"><thead><tr><th>Customer</th><th>Service</th><th>Status</th><th>Follow-up Date</th><th>Notes</th><th>Action</th></tr></thead><tbody>${rows.length ? rows.map((row) => `<tr><td data-label="Customer"><strong>${esc(row.name)}</strong>${crmContact(row)}</td><td data-label="Service">${esc(row.service || "Not set")}</td><td data-label="Status">${crmStatus(row.status)}</td><td data-label="Follow-up Date">${date(row.follow_up_date)}</td><td data-label="Notes"><small>${esc(row.notes || "No notes saved")}</small></td><td data-label="Action"><button class="wnq-link" data-followup='${attr(row)}'>Mark Complete</button><button class="wnq-link" data-followup='${attr(row)}'>Reschedule</button><button class="wnq-link" data-edit='${attr(row)}'>Edit</button></td></tr>`).join("") : `<tr><td colspan="6">${empty("No overdue follow-ups. When a next follow-up date passes, it will appear here.")}</td></tr>`}</tbody></table></div>`;
  const crmCalendar = (jobs, followups = [], month = "") => {
    const selectedMonth = /^\d{4}-\d{2}$/.test(month) ? month : isoDate().slice(0, 7);
    const [year, monthNumber] = selectedMonth.split("-").map(Number);
    const firstWeekday = new Date(year, monthNumber - 1, 1).getDay();
    const daysInMonth = new Date(year, monthNumber, 0).getDate();
    const cellCount = Math.ceil((firstWeekday + daysInMonth) / 7) * 7;
    const today = isoDate();
    const items = [
      ...jobs.map((row) => ({ ...row, calendar_type: "job", calendar_date: row.job_date })),
      ...followups.map((row) => ({ ...row, calendar_type: "follow-up", calendar_date: row.follow_up_date })),
    ].filter((row) => row.calendar_date && String(row.calendar_date).slice(0, 7) === selectedMonth);
    const grouped = items.sort((a, b) => String(a.calendar_date || "").localeCompare(String(b.calendar_date || ""))).reduce((map, row) => {
      const key = row.calendar_date || "unscheduled";
      map[key] = map[key] || [];
      map[key].push(row);
      return map;
    }, {});
    const monthLabel = new Date(year, monthNumber - 1, 1).toLocaleString(undefined, { month: "long", year: "numeric" });
    const weekdays = ["Sun", "Mon", "Tue", "Wed", "Thu", "Fri", "Sat"];
    const cells = Array.from({ length: cellCount }, (_, index) => {
      const dayNumber = index - firstWeekday + 1;
      if (dayNumber < 1 || dayNumber > daysInMonth) return `<div class="wnq-calendar-cell is-outside" aria-hidden="true"></div>`;
      const dayKey = `${selectedMonth}-${String(dayNumber).padStart(2, "0")}`;
      const dayItems = grouped[dayKey] || [];
      return `<div class="wnq-calendar-cell ${dayKey === today ? "is-today" : ""}"><header><span>${dayNumber}</span>${dayItems.length ? `<small>${esc(dayItems.length)} item${dayItems.length === 1 ? "" : "s"}</small>` : ""}</header><div class="wnq-calendar-events">${dayItems.slice(0, 3).map((row) => `<button type="button" class="wnq-calendar-event is-${esc(row.calendar_type)}" data-edit='${attr(row)}'><strong>${esc(row.name)}</strong><span>${esc(row.calendar_type === "job" ? row.service || "Scheduled job" : "Follow-up")}</span></button>`).join("")}${dayItems.length > 3 ? `<small class="wnq-calendar-more">+${esc(dayItems.length - 3)} more</small>` : ""}</div></div>`;
    }).join("");
    return `<section class="wnq-panel wnq-calendar-panel"><div class="wnq-panel-head"><div><h2>${esc(monthLabel)}</h2><small>${esc(items.length)} scheduled item${items.length === 1 ? "" : "s"}</small></div><div class="wnq-calendar-legend"><span class="is-job">Job</span><span class="is-follow-up">Follow-up</span></div></div><div class="wnq-calendar-grid">${weekdays.map((day) => `<div class="wnq-calendar-weekday">${esc(day)}</div>`).join("")}${cells}</div>${!items.length ? `<p class="wnq-calendar-empty">Nothing is scheduled this month. Add a job date or follow-up date to place it on the calendar.</p>` : ""}</section>`;
  };
  const crmReports = (ctx) => {
    const today = isoDate();
    const openLeads = ctx.leadRows.filter((row) => ["new", "contacted", "quoted"].includes(row.status));
    const upcomingFollowups = ctx.visibleRows.filter((row) => row.follow_up_date && row.follow_up_date >= today && !["completed", "lost", "canceled"].includes(row.status));
    const reportOverdue = ctx.visibleRows.filter((row) => row.follow_up_date && row.follow_up_date < today && !["completed", "lost", "canceled"].includes(row.status));
    const reportScheduledJobs = ctx.jobs.filter((row) => row.job_date);
    const reportUpcomingJobs = reportScheduledJobs.filter((row) => row.job_date >= today && !["lost", "canceled"].includes(row.status));
    const calendarRows = [
      ...reportScheduledJobs.map((row) => ({ ...row, report_type: "Job", report_date: row.job_date })),
      ...upcomingFollowups.map((row) => ({ ...row, report_type: "Follow-up", report_date: row.follow_up_date })),
      ...reportOverdue.map((row) => ({ ...row, report_type: "Overdue Follow-up", report_date: row.follow_up_date })),
    ].filter((row) => row.report_date).sort((a, b) => String(a.report_date).localeCompare(String(b.report_date)));
    const reportProfit = ctx.totals.revenue - ctx.totals.cost;
    return `<nav class="wnq-report-nav" aria-label="CRM report sections"><a href="#wnq-crm-report-overview">Overview</a><a href="#wnq-crm-report-leads">Leads</a><a href="#wnq-crm-report-jobs">Jobs</a><a href="#wnq-crm-report-calendar">Calendar</a><a href="#wnq-crm-report-followups">Follow-ups</a></nav>
      <section class="wnq-panel wnq-crm-report-section" id="wnq-crm-report-overview"><div class="wnq-panel-head"><div><span class="wnq-eyebrow">CRM Report</span><h2>Business Overview</h2><small>${ctx.filters.from || ctx.filters.to ? `${date(ctx.filters.from)} through ${date(ctx.filters.to)}` : "All available CRM activity"}</small></div><button type="button" class="wnq-button is-secondary" data-crm-export>Export CSV</button></div><div class="wnq-metrics">${metric("Total Records", ctx.visibleRows.length, "Leads and jobs")}${metric("Open Leads", openLeads.length, "Need follow-up")}${metric("Job Records", ctx.jobs.length, "Scheduled and completed")}${metric("Completed Jobs", ctx.completed.length, "Won or closed")}${metric("Revenue", money(ctx.totals.revenue), "Tracked CRM revenue")}${metric("Close Rate", `${ctx.closeRate}%`, "Converted vs lost")}${metric("Overdue", reportOverdue.length, "Follow-ups requiring action", reportOverdue.length ? "negative" : "")}</div></section>
      <section class="wnq-panel wnq-crm-report-section" id="wnq-crm-report-leads"><div class="wnq-panel-head"><div><span class="wnq-eyebrow">CRM Report</span><h2>Leads</h2><small>Lead records, sources, estimates, and pipeline movement.</small></div></div><div class="wnq-metrics">${metric("Lead Records", ctx.leadRows.length, "Visible leads")}${metric("Open Leads", openLeads.length, "Need follow-up")}${metric("Lost Leads", ctx.lost.length, "Not moving forward")}${metric("Close Rate", `${ctx.closeRate}%`, "Converted vs lost leads")}</div>${leadSourceTable(ctx.visibleRows, ctx.topSources)}${crmReportTable("Lead Detail", [
        ["Customer", (row) => `<strong>${esc(row.name)}</strong>${crmContact(row)}`],
        ["Source", (row) => esc(row.lead_source || "Not set")],
        ["Pipeline", (row) => pipelineBadge(row, ctx.pipelineStages)],
        ["Status", (row) => crmStatus(row.status)],
        ["Estimate", (row) => money(row.estimated_value)],
        ["Next Follow-up", (row) => date(row.follow_up_date || row.reminder_date)],
      ], ctx.leadRows, "No lead records are available for this report.")}</section>
      <section class="wnq-panel wnq-crm-report-section" id="wnq-crm-report-jobs"><div class="wnq-panel-head"><div><span class="wnq-eyebrow">CRM Report</span><h2>Jobs</h2><small>Scheduled, active, completed, canceled, revenue, and profit tracking.</small></div></div><div class="wnq-metrics">${metric("Job Records", ctx.jobs.length, "Visible jobs")}${metric("Upcoming Jobs", reportUpcomingJobs.length, "Scheduled ahead")}${metric("Completed Jobs", ctx.completed.length, "Won or closed")}${metric("Revenue", money(ctx.totals.revenue), "Visible records")}${canSeePrivate ? metric("Profit", money(reportProfit), "Revenue minus costs", reportProfit >= 0 ? "positive" : "negative") : ""}${metric("Average Job Value", money(ctx.avgJob), "Revenue per job")}</div>${crmReportTable("Job Detail", [
        ["Customer", (row) => `<strong>${esc(row.name)}</strong>${crmContact(row)}`],
        ["Service", (row) => esc(row.service || "Not set")],
        ["Status", (row) => crmStatus(row.status)],
        ["Scheduled", (row) => date(row.job_date)],
        ["Revenue", (row) => `<strong>${money(row.final_value || row.estimated_value)}</strong>`],
        ["Profit", (row) => canSeePrivate ? trend(Number(row.final_value || 0) - Number(row.job_cost || 0)) : "Private"],
      ], ctx.jobs, "No job records are available for this report.")}</section>
      <section class="wnq-panel wnq-crm-report-section" id="wnq-crm-report-calendar"><div class="wnq-panel-head"><div><span class="wnq-eyebrow">CRM Report</span><h2>Calendar</h2><small>Scheduled jobs and follow-up dates from CRM records.</small></div></div><div class="wnq-metrics">${metric("Calendar Items", calendarRows.length, "Jobs and follow-ups")}${metric("Scheduled Jobs", reportScheduledJobs.length, "With job dates")}${metric("Upcoming Jobs", reportUpcomingJobs.length, "Next scheduled work")}${metric("Overdue Follow-ups", reportOverdue.length, "Require action", reportOverdue.length ? "negative" : "")}</div>${crmReportTable("Calendar Detail", [
        ["Date", (row) => date(row.report_date)],
        ["Type", (row) => esc(row.report_type)],
        ["Customer", (row) => `<strong>${esc(row.name)}</strong>`],
        ["Service", (row) => esc(row.service || "Not set")],
        ["Address", (row) => esc(row.job_address || row.address || "Not set")],
        ["Status", (row) => crmStatus(row.status)],
      ], calendarRows, "No scheduled jobs or follow-ups are available for this report.")}</section>
      <section class="wnq-panel wnq-crm-report-section" id="wnq-crm-report-followups"><div class="wnq-panel-head"><div><span class="wnq-eyebrow">CRM Report</span><h2>Follow-ups</h2><small>Overdue and upcoming customer touchpoints.</small></div></div><div class="wnq-metrics">${metric("Overdue", reportOverdue.length, "Past due", reportOverdue.length ? "negative" : "")}${metric("Upcoming", upcomingFollowups.length, "Scheduled follow-ups")}${metric("Open Leads", openLeads.length, "Need nurturing")}${metric("Completed Jobs", ctx.completed.length, "Closed records")}</div>${crmReportTable("Follow-up Detail", [
        ["Customer", (row) => `<strong>${esc(row.name)}</strong>${crmContact(row)}`],
        ["Service", (row) => esc(row.service || "Not set")],
        ["Status", (row) => crmStatus(row.status)],
        ["Follow-up Date", (row) => date(row.follow_up_date)],
        ["Notes", (row) => esc(row.notes || "No notes saved")],
      ], [...reportOverdue, ...upcomingFollowups], "No follow-ups are available for this report.")}</section>`;
  };
  const crmReportTable = (title, columns, rows, fallback) => `<div class="wnq-table-wrap wnq-crm-table-panel wnq-crm-report-table"><div class="wnq-panel-head"><div><h2>${esc(title)}</h2><small>${esc(rows.length)} record${rows.length === 1 ? "" : "s"}</small></div></div><table class="wnq-crm-table is-fit"><thead><tr>${columns.map(([label]) => `<th>${esc(label)}</th>`).join("")}</tr></thead><tbody>${rows.length ? rows.slice(0, 25).map((row) => `<tr>${columns.map(([label, render]) => `<td data-label="${esc(label)}">${render(row)}</td>`).join("")}</tr>`).join("") : `<tr><td colspan="${esc(columns.length)}">${empty(fallback)}</td></tr>`}</tbody></table></div>`;
  const csvCell = (value) => {
    let cell = String(value ?? "").replace(/\r?\n/g, " ");
    if (/^[=+\-@]/.test(cell)) cell = `'${cell}`;
    return `"${cell.replaceAll('"', '""')}"`;
  };
  const exportCrmReport = (ctx) => {
    const openLeads = ctx.leadRows.filter((row) => ["new", "contacted", "quoted"].includes(row.status));
    const summary = [
      ["Golden Web Marketing CRM Report"],
      ["Account", cfg.clientLabel || state.clientId || "Client"],
      ["Generated", new Date().toLocaleString()],
      ["Date From", ctx.filters.from || "All time"],
      ["Date To", ctx.filters.to || "All time"],
      [],
      ["Overview"],
      ["Total Records", ctx.visibleRows.length],
      ["Open Leads", openLeads.length],
      ["Job Records", ctx.jobs.length],
      ["Completed Jobs", ctx.completed.length],
      ["Overdue Follow-ups", ctx.visibleRows.filter((row) => row.follow_up_date && row.follow_up_date < isoDate() && !["completed", "lost", "canceled"].includes(row.status)).length],
      ["Tracked Revenue", money(ctx.totals.revenue)],
      ["Close Rate", `${ctx.closeRate}%`],
      [],
      ["Record Type", "Contact", "Phone", "Email", "Service", "Lead Source", "Pipeline Stage", "Status", "Job Date", "Follow-Up Date", "Completion Date", "Estimated Revenue", "Final Revenue", "Address", "Notes"],
    ];
    const details = ctx.visibleRows.map((row) => [row.record_type, row.name, row.phone, row.email, row.service, row.lead_source, row.pipeline_stage, row.status, row.job_date, row.follow_up_date, row.completion_date, money(row.estimated_value), money(row.final_value), row.job_address || row.address, row.notes]);
    const csv = [...summary, ...details].map((row) => row.map(csvCell).join(",")).join("\r\n");
    const blob = new Blob(["\ufeff", csv], { type: "text/csv;charset=utf-8" });
    const url = URL.createObjectURL(blob);
    const link = document.createElement("a");
    link.href = url;
    link.download = `crm-report-${ctx.filters.from || "all"}-to-${ctx.filters.to || isoDate()}.csv`;
    document.body.appendChild(link);
    link.click();
    link.remove();
    window.setTimeout(() => URL.revokeObjectURL(url), 1000);
  };
  const sourceBars = (items) => {
    const max = Math.max(1, ...items.map((item) => Number(item.count || 0)));
    return `<div class="wnq-source-list">${items.map((item) => `<div class="wnq-source-row"><span>${esc(item.label)}</span><strong>${esc(item.count)}</strong><i style="width:${Math.max(8, Math.round((Number(item.count || 0) / max) * 100))}%"></i></div>`).join("")}</div>`;
  };
  const crmStatus = (value) => {
    const key = value || "new";
    return status(["completed"].includes(key) ? "green" : ["lost","canceled"].includes(key) ? "red" : "yellow", statusLabels[key] || humanize(key));
  };
  const isImageAttachment = (item = {}) => String(item.type || "").startsWith("image/") || /\.(avif|gif|jpe?g|png|webp)$/i.test(String(item.name || ""));
  const fileList = (label, items = []) => Array.isArray(items) && items.length ? `<div class="wnq-existing-files is-wide"><span>${esc(label)}</span><div class="wnq-file-preview-grid">${items.map((item) => isImageAttachment(item) ? `<a class="wnq-file-preview" href="${esc(item.url)}" target="_blank" rel="noopener" title="Open ${esc(item.name)}"><img src="${esc(item.preview_url || item.url)}" alt="${esc(item.name)}" loading="lazy"><small>${esc(item.name)}</small></a>` : `<a class="wnq-file-chip" href="${esc(item.url)}" target="_blank" rel="noopener">${esc(item.name)}</a>`).join("")}</div></div>` : "";
  const customerForm = (row = {}, settings = {}) => {
    const currentType = row.record_type === "job" ? "job" : "lead";
    const isJob = currentType === "job";
    const currentStatus = row.status || (isJob ? "scheduled" : "new");
    const configuredSources = settings.crm?.lead_sources?.length ? settings.crm.lead_sources : leadSourceOptions;
    const configuredStages = settings.crm?.pipeline_stages?.length ? settings.crm.pipeline_stages : [{ key: "new", label: "New Lead", color: "#D7B846" }];
    const currentPipelineStage = configuredStages.some((stage) => stage.key === row.pipeline_stage) ? row.pipeline_stage : configuredStages[0].key;
    const sourceValue = row.lead_source || "";
    const sourceChoices = sourceValue && !configuredSources.includes(sourceValue) ? [...configuredSources, sourceValue] : configuredSources;
    const serviceChoices = [...new Set([...(settings.crm?.services || []), row.service || ""])].filter(Boolean);
    const defaultFollowup = (() => {
      if (row.follow_up_date || row.id || isJob) return row.follow_up_date || "";
      const next = new Date();
      next.setDate(next.getDate() + Number(settings.crm?.default_follow_up_days || 2));
      return isoDate(next);
    })();
    const allowedStatuses = isJob
      ? [["scheduled", "Scheduled"], ["in_progress", "In Progress"], ["completed", "Completed"], ["canceled", "Canceled"]]
      : [["new", "New Lead"], ["contacted", "Contacted"], ["quoted", "Quote Sent"], ["lost", "Lost"]];
    return `<form class="wnq-panel wnq-form wnq-crm-form" enctype="multipart/form-data"><input type="hidden" name="id" value="${esc(row.id || "")}"><input type="hidden" name="record_type" value="${esc(currentType)}">
      <div class="wnq-panel-head"><div><span class="wnq-eyebrow">${row.id ? `Edit ${currentType}` : `New ${currentType}`}</span><h2>${row.id ? `Update ${isJob ? "Job" : "Lead"}` : `Add ${isJob ? "Job" : "Lead"}`}</h2></div><span class="wnq-form-type is-${esc(currentType)}">${isJob ? "Job record" : "Lead record"}</span></div>
      <fieldset class="wnq-crm-form-section"><legend>Contact</legend>
        ${field("name", "Contact Name", row.name, true)}${field("phone", "Phone", row.phone)}${field("email", "Email", row.email, false, "email")}${field("address", "Contact Address", row.address)}
        <label><span>Lead Source</span><select name="lead_source"><option value="">Select source</option>${sourceChoices.map((value) => `<option value="${esc(value)}" ${sourceValue === value ? "selected" : ""}>${esc(value)}</option>`).join("")}</select></label>
      </fieldset>
      <fieldset class="wnq-crm-form-section"><legend>${isJob ? "Job & Schedule" : "Opportunity"}</legend>
        <label><span>Service ${isJob ? "/ Job Type" : "Needed"}</span><input type="text" name="service" value="${esc(row.service || "")}" list="wnq-service-list"><datalist id="wnq-service-list">${serviceChoices.map((value) => `<option value="${esc(value)}"></option>`).join("")}</datalist></label>
        <label><span>Status</span><select name="status">${allowedStatuses.map(([value, label]) => `<option value="${esc(value)}" ${currentStatus === value ? "selected" : ""}>${esc(label)}</option>`).join("")}</select></label>
        ${isJob ? `${field("job_address", "Job Address", row.job_address || row.address)}${field("crew", "Crew / Employee Assignment", row.crew)}${field("job_date", "Scheduled Date", row.job_date, false, "date")}${field("completion_date", "Completion Date", row.completion_date, false, "date")}` : `<label><span>Pipeline Stage</span><select name="pipeline_stage">${configuredStages.map((stage) => `<option value="${esc(stage.key)}" ${stage.key === currentPipelineStage ? "selected" : ""}>${esc(stage.label)}</option>`).join("")}</select></label>${field("follow_up_date", "Next Follow-Up Date", defaultFollowup, false, "date")}${field("reminder_date", "Reminder Date", row.reminder_date, false, "date")}`}
      </fieldset>
      <fieldset class="wnq-crm-form-section"><legend>${isJob ? "Revenue & Profit" : "Estimated Value"}</legend>
        ${moneyField("estimated_value", "Estimated Revenue", row.estimated_value || 0)}${isJob ? `${moneyField("final_value", "Final Revenue", row.final_value || 0)}${canSeePrivate ? moneyField("job_cost", "Job Costs", row.job_cost || 0) : ""}` : ""}
      </fieldset>
      <fieldset class="wnq-crm-form-section"><legend>Notes & Files</legend>
        <label class="is-wide"><span>${isJob ? "Job Notes / Service History" : "Lead Notes"}</span><textarea name="notes" rows="3">${esc(row.notes || "")}</textarea></label>
        ${canSeePrivate ? `<label class="is-wide"><span>Internal Notes</span><textarea name="internal_notes" rows="3">${esc(row.internal_notes || "")}</textarea></label>` : ""}
        <label class="is-wide"><span>${isJob ? "Cancellation Reason" : "Lost Lead Reason"}</span><textarea name="lost_reason" rows="2">${esc(row.lost_reason || "")}</textarea></label>
        ${fileList("Saved files", row.files)}${fileList("Before photos", row.before_photos)}${fileList("After photos", row.after_photos)}
        <label class="is-wide wnq-upload"><span>Files & Photos</span><input type="file" name="attachments[]" multiple accept="image/*,.pdf,.doc,.docx,.txt"><small>Upload estimates, invoices, signed docs, or job photos.</small></label>
        <label class="wnq-upload"><span>Before Photos</span><input type="file" name="before_photos[]" multiple accept="image/*"></label><label class="wnq-upload"><span>After Photos</span><input type="file" name="after_photos[]" multiple accept="image/*"></label>
      </fieldset>
      <div class="wnq-form-actions"><button class="wnq-button" type="submit">Save ${isJob ? "Job" : "Lead"}</button><button type="button" class="wnq-button is-secondary" data-cancel>Cancel</button></div></form>`;
  };
  const field = (name, label, value = "", required = false, type = "text", hint = "") => `<label><span>${esc(label)}</span><input type="${type}" name="${name}" value="${esc(value)}" ${required ? "required" : ""} ${type === "number" ? 'min="0" step="0.01"' : ""}>${hint ? `<small>${esc(hint)}</small>` : ""}</label>`;
  const moneyField = (name, label, value = "") => `<label class="wnq-money-field"><span>${esc(label)}</span><div><b>$</b><input type="number" name="${esc(name)}" value="${esc(value)}" min="0" step="0.01" inputmode="decimal"></div></label>`;

  async function ads(view, refresh) {
    if (!canSeePrivate) {
      view.innerHTML = `${heading("Google Ads", "Ads", "Campaign reporting is being prepared for your account.")}
        <section class="wnq-panel wnq-coming-soon wnq-ads-client-hold"><div class="wnq-panel-head"><div><span class="wnq-eyebrow">Coming Soon</span><h2>Ads reporting is in progress</h2></div>${status("yellow", "Coming soon")}</div><p>Golden Web Marketing is validating the reporting connection before making Google Ads performance available inside client accounts.</p><div class="wnq-ads-client-steps"><span>Manager access</span><span>Read-only reports</span><span>Client-safe dashboard</span></div></section>`;
      return;
    }
    const resource = refresh ? await api("/portal/ads?refresh=1") : await load("ads");
    state.cache.ads = resource;
    const summary = resource.summary || {};
    const rows = resource.campaigns || [];
    const percent = (value) => `${(Number(value || 0) * 100).toFixed(1)}%`;
    const enabledCampaigns = rows.filter((row) => String(row.status || "").toLowerCase() === "enabled").length;
    const campaignColumns = ["Campaign", "Status", "Clicks", "Impressions", "CTR", "Conversions", "Spend"];
    const campaignRow = (row) => [esc(row.name), status(row.status === "enabled" ? "green" : "yellow", titleCase(row.status)), Number(row.clicks || 0).toLocaleString(), Number(row.impressions || 0).toLocaleString(), percent(row.ctr), Number(row.conversions || 0).toFixed(1), money(row.spend)];
    state.adsSection = resource.configured ? (state.adsSection || "overview") : "connection";
    view.innerHTML = `${heading("Internal Reporting", "Google Ads", "Read-only performance from the Google Ads accounts linked to your manager account.")}
      <section class="wnq-ads-hero"><div><span class="wnq-eyebrow">Reporting Workspace</span><h2>${esc(adsStateTitle(resource))}</h2><p>${esc(adsStateCopy(resource))}</p></div><div class="wnq-ads-hero-actions"><button type="button" class="wnq-button is-secondary" id="wnq-refresh-ads">Refresh Ads Data</button>${status(adsStateTone(resource), titleCase(resource.data_status || "setup needed"))}</div></section>
      <div class="wnq-ads-account-bar"><div><span>Client account</span><strong>${esc(resource.matched_account_name || "Not matched")}</strong><small>${esc(resource.customer_id || "No customer ID")} ${resource.time_zone ? `· ${esc(resource.time_zone)}` : ""}</small></div><div><span>MCC accounts</span><strong>${Number(resource.available_accounts_count || 0).toLocaleString()}</strong><small>Available for matching</small></div><div><span>API status</span><strong>${esc(resource.access_status_label || titleCase(resource.access_level || "test"))}</strong><small>${esc(resource.reporting_window || "Last 30 days")} · ${esc(date(resource.last_checked))}</small></div></div>
      <nav class="wnq-ads-nav" aria-label="Google Ads reports">
        ${[["overview", "Overview"], ["campaigns", "Campaigns"], ["search", "Search Insights"], ["pages", "Pages & Devices"], ["connection", "Connection"]].map(([key, label]) => `<button type="button" data-ads-section="${key}" class="${state.adsSection === key ? "is-active" : ""}" aria-selected="${state.adsSection === key ? "true" : "false"}">${label}</button>`).join("")}
      </nav>
      ${resource.errors?.length ? adsDiagnostics(resource, true) : ""}
      <div class="wnq-ads-sections">
        <section class="wnq-ads-section ${state.adsSection === "overview" ? "is-active" : ""}" data-ads-panel="overview">
          ${resource.configured ? `<section class="wnq-metrics wnq-ads-metrics">${metric("Clicks", Number(summary.clicks || 0).toLocaleString(), resource.reporting_window || "Last 30 days")}${metric("Impressions", Number(summary.impressions || 0).toLocaleString(), resource.reporting_window || "Last 30 days")}${metric("CTR", percent(summary.ctr), "Click-through rate")}${metric("Conversions", Number(summary.conversions || 0).toFixed(1), "Tracked actions")}${metric("Conversion Rate", percent(summary.conversion_rate), "Conversions per click")}${metric("Campaigns", rows.length.toLocaleString(), `${enabledCampaigns} enabled`)}</section>
            <section class="wnq-ads-internal-strip"><div><span>Internal cost visibility</span><strong>${money(summary.spend)}</strong><small>Total spend for admin reporting only</small></div><div><span>Cost / conversion</span><strong>${money(summary.cost_per_conversion)}</strong><small>Based on tracked conversions</small></div></section>
            ${!resource.has_report_data ? adsEmptyReport(resource) : ""}
            <div class="wnq-ads-overview-grid">${adsTable("Campaign Snapshot", campaignColumns, rows.slice(0, 6), campaignRow, "No campaign performance was returned for the last 30 days.")}${adsTable("Device Performance", ["Device", "Clicks", "Impressions", "Conversions"], resource.devices || [], (row) => [esc(titleCase(row.device)), row.clicks, row.impressions, Number(row.conversions || 0).toFixed(1)], "No device data was returned.")}</div>` : `<section class="wnq-panel wnq-ads-empty-report"><div><span class="wnq-eyebrow">Setup required</span><h2>Connect an Ads account to begin</h2><p>Open Connection to review the account link and credentials checklist.</p></div><button type="button" class="wnq-button" data-open-ads-connection>Open Connection</button></section>`}
        </section>
        <section class="wnq-ads-section ${state.adsSection === "campaigns" ? "is-active" : ""}" data-ads-panel="campaigns">${adsTable("Campaign Performance", campaignColumns, rows, campaignRow, "No campaign performance was returned for the last 30 days.")}</section>
        <section class="wnq-ads-section ${state.adsSection === "search" ? "is-active" : ""}" data-ads-panel="search"><div class="wnq-ads-stack">${adsTable("Search Terms", ["Search term", "Campaign", "Clicks", "Impressions", "Conversions"], resource.search_terms || [], (row) => [esc(row.term), esc(row.campaign), row.clicks, row.impressions, Number(row.conversions || 0).toFixed(1)], "No search-term activity was returned.")}${adsTable("Keywords", ["Keyword", "Match", "Campaign", "Clicks", "Conversions"], resource.keywords || [], (row) => [esc(row.keyword), esc(titleCase(row.match_type)), esc(row.campaign), row.clicks, Number(row.conversions || 0).toFixed(1)], "No keyword activity was returned.")}</div></section>
        <section class="wnq-ads-section ${state.adsSection === "pages" ? "is-active" : ""}" data-ads-panel="pages"><div class="wnq-ads-report-grid">${adsTable("Landing Pages", ["Landing page", "Clicks", "Impressions", "Conversions"], resource.landing_pages || [], (row) => [`<a href="${esc(row.url)}" target="_blank" rel="noopener">${esc(row.url || "Not set")}</a>`, row.clicks, row.impressions, Number(row.conversions || 0).toFixed(1)], "No landing-page activity was returned.")}${adsTable("Device Performance", ["Device", "Clicks", "Impressions", "Conversions"], resource.devices || [], (row) => [esc(titleCase(row.device)), row.clicks, row.impressions, Number(row.conversions || 0).toFixed(1)], "No device data was returned.")}</div></section>
        <section class="wnq-ads-section ${state.adsSection === "connection" ? "is-active" : ""}" data-ads-panel="connection">${adsProgress(resource)}${adsDiagnostics(resource)}${adsSettingsForm(resource)}</section>
      </div>`;
    view.querySelector("#wnq-refresh-ads")?.addEventListener("click", () => show("ads", true));
    const selectAdsSection = (key) => {
      state.adsSection = key;
      view.querySelectorAll("[data-ads-section]").forEach((button) => {
        const active = button.dataset.adsSection === key;
        button.classList.toggle("is-active", active);
        button.setAttribute("aria-selected", active ? "true" : "false");
      });
      view.querySelectorAll("[data-ads-panel]").forEach((panel) => panel.classList.toggle("is-active", panel.dataset.adsPanel === key));
    };
    view.querySelectorAll("[data-ads-section]").forEach((button) => button.addEventListener("click", () => selectAdsSection(button.dataset.adsSection)));
    view.querySelector("[data-open-ads-connection]")?.addEventListener("click", () => selectAdsSection("connection"));
    view.querySelectorAll("[data-ads-table-toggle]").forEach((button) => button.addEventListener("click", () => {
      const section = button.closest(".wnq-ads-table");
      const expanded = section?.classList.toggle("is-expanded");
      button.textContent = expanded ? "Show fewer" : `Show all ${button.dataset.total}`;
      button.setAttribute("aria-expanded", expanded ? "true" : "false");
    }));
    const form = view.querySelector("#wnq-ads-settings");
    form?.addEventListener("submit", async (event) => {
      event.preventDefault();
      const submit = form.querySelector('[type="submit"]');
      submit.disabled = true; submit.textContent = "Saving...";
      try {
        await api("/portal/ads-settings", { method: "POST", body: JSON.stringify(formObject(form)) });
        delete state.cache.ads;
        sessionStorage.setItem("wnqAdsNotice", "Client Ads account saved and reporting refreshed.");
        show("ads", true);
      } catch (error) {
        formStatus(form, "yellow", error.message);
        submit.disabled = false; submit.textContent = "Save Account Link";
      }
    });
  }

  const adsStateTone = (data) => data.errors?.length ? "red" : data.configured ? "green" : "yellow";
  const adsStateTitle = (data) => ({
    report_ready: "Ads report is connected",
    connected_empty: "Connected, but no recent activity",
    account_link_needed: "Choose the client Ads account",
    api_attention: "Google Ads needs attention",
    setup_needed: "Finish the Google Ads setup",
  }[data.data_status] || (data.configured ? "Ads report is connected" : "Finish the Google Ads setup"));
  const adsStateCopy = (data) => ({
    report_ready: "Performance data is being pulled from the matched Google Ads account.",
    connected_empty: "The API connection worked, but Google returned no activity for the current reporting window.",
    account_link_needed: "The MCC is connected. Pick the right child account below or let the portal auto-match by client name.",
    api_attention: "The account link exists, but Google returned an API message while pulling report data.",
    setup_needed: "Save the global OAuth credentials in WordPress settings, then link this client to a Google Ads account.",
  }[data.data_status] || "Use this internal screen to connect client Ads accounts and review read-only reporting.");
  const adsProgress = (data) => `<div class="wnq-setup-checks wnq-ads-progress">${(data.setup_checks || []).map((check) => `<span class="${check.ok ? "is-ok" : "is-needed"}">${esc(check.label)}</span>`).join("")}</div>`;
  const adsEmptyReport = (data) => `<section class="wnq-panel wnq-ads-empty-report"><div><span class="wnq-eyebrow">No recent activity</span><h2>Google returned an empty report</h2><p>This usually means the selected account had no clicks or impressions during ${esc(data.reporting_window || "the selected window")}, or the account has no enabled campaigns yet.</p></div></section>`;
  const adsTable = (title, columns, rows = [], render, fallback) => {
    const previewLimit = 12;
    const hasMore = rows.length > previewLimit;
    const body = rows.length
      ? rows.map((row, rowIndex) => `<tr class="${rowIndex >= previewLimit ? "wnq-ads-row-extra" : ""}">${render(row).map((value, index) => `<td data-label="${esc(columns[index])}">${value}</td>`).join("")}</tr>`).join("")
      : `<tr><td colspan="${columns.length}">${empty(fallback)}</td></tr>`;
    return `<section class="wnq-panel wnq-table-wrap wnq-ads-table"><div class="wnq-panel-head"><div><h2>${esc(title)}</h2><small>${esc(rows.length)} row${rows.length === 1 ? "" : "s"} · Last 30 days</small></div></div><table><thead><tr>${columns.map((label) => `<th>${esc(label)}</th>`).join("")}</tr></thead><tbody>${body}</tbody></table>${hasMore ? `<div class="wnq-ads-table-footer"><span>Showing ${previewLimit} of ${rows.length}</span><button type="button" class="wnq-button is-secondary" data-ads-table-toggle data-total="${rows.length}" aria-expanded="false">Show all ${rows.length}</button></div>` : ""}</section>`;
  };

  const adsDiagnostics = (data, errorsOnly = false) => {
    const messages = errorsOnly ? [...(data.errors || [])].filter(Boolean) : [...(data.errors || []), ...(data.diagnostics || [])].filter(Boolean);
    return messages.length ? `<section class="wnq-ads-diagnostics ${data.errors?.length ? "is-error" : ""}"><strong>Google Ads setup message</strong>${messages.map((message) => `<p>${esc(message)}</p>`).join("")}</section>` : "";
  };
  const adsSettingsForm = (data) => {
    const notice = sessionStorage.getItem("wnqAdsNotice") || "";
    if (notice) sessionStorage.removeItem("wnqAdsNotice");
    const accounts = data.available_accounts || [];
    const currentExists = accounts.some((account) => String(account.customer_id) === String(data.customer_id));
    return `<form class="wnq-panel wnq-form wnq-ads-link-form" id="wnq-ads-settings"><div class="wnq-panel-head"><div><span class="wnq-eyebrow">Account Link</span><h2>Client Ads Account</h2><small>Global OAuth credentials are managed in the WordPress portal settings.</small></div></div>
    ${notice ? `<div class="wnq-form-status is-green">${esc(notice)}</div>` : ""}
    <label><span>Google Ads Client Account</span><select name="customer_id"><option value="">Auto-match by client name</option>${data.customer_id && !currentExists ? `<option value="${esc(data.customer_id)}" selected>${esc(data.matched_account_name || data.customer_id)}</option>` : ""}${accounts.map((account) => `<option value="${esc(account.customer_id)}" ${String(account.customer_id) === String(data.customer_id) ? "selected" : ""}>${esc(account.name || "Unnamed account")} · ${esc(account.customer_id)}</option>`).join("")}</select><small>${esc(accounts.length)} client account${accounts.length === 1 ? "" : "s"} available under the MCC.</small></label>
    <label><span>Manager Account</span><input type="text" value="${esc(data.manager_customer_id || "Not configured")}" readonly><small>Inherited from WordPress settings</small></label>
    <div class="is-wide wnq-ads-requirements"><strong>Connection checklist</strong>${(data.setup_checks || []).map((item) => `<span class="${item.ok ? "is-ok" : "is-needed"}">${esc(item.label)}: ${item.ok ? "Ready" : "Needed"}</span>`).join("")}<p class="wnq-note">API keys and service accounts are not used for this OAuth connection.</p></div>
    <div class="wnq-form-actions"><button class="wnq-button" type="submit">Save Account Link</button></div></form>`;
  };
  const adsClientNotice = (data) => `<section class="wnq-panel"><div class="wnq-panel-head"><h2>Ads Access</h2>${status(data.configured ? "green" : "yellow", data.configured ? "Connected" : "Pending")}</div><p class="wnq-note">Once Golden Web Marketing connects your Google Ads account, this tab will show read-only campaign results and reporting.</p></section>`;

  async function messages(view, refresh) {
    const tickets = await load("tickets", refresh);
    const messagesTab = root.querySelector('[data-tab="messages"]');
    if (messagesTab?.querySelector("span")) messagesTab.querySelector("span").textContent = "Support";
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
    delete state.cache.notifications;
    refreshNotificationBadge(true);
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
    const notice = sessionStorage.getItem("wnqRequestNotice") || "";
    if (notice) sessionStorage.removeItem("wnqRequestNotice");
    view.innerHTML = `${heading("Request Center", "What can we help with?", "Submit structured requests so our team has everything needed to get started.")}
      ${notice ? `<div class="wnq-success-inline">${esc(notice)}</div>` : ""}
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

  async function notifications(view, refresh) {
    const data = await load("notifications", refresh);
    const items = data.items || [];
    view.innerHTML = `${heading("Activity", "Notifications", "See support replies, overdue follow-ups, upcoming jobs, and newly available reports in one place.")}
      <div class="wnq-notification-summary"><div><span>Needs attention</span><strong>${esc(data.attention_count || 0)}</strong><small>Items requiring action</small></div><button type="button" class="wnq-button is-secondary" data-notification-route="settings">Notification Settings</button></div>
      <section class="wnq-panel wnq-notification-panel"><div class="wnq-panel-head"><div><h2>Latest Updates</h2><small>${esc(items.length)} notification${items.length === 1 ? "" : "s"}</small></div></div><div class="wnq-notification-list">${items.length ? items.map((item) => `<article class="wnq-notification-item is-${esc(item.tone || "gold")}"><span class="wnq-notification-icon is-${esc(item.type || "notice")}" aria-hidden="true"></span><div><strong>${esc(item.title)}</strong><p>${esc(item.message)}</p><small>${date(item.date)}</small></div><button type="button" class="wnq-link" data-notification-route="${esc(item.route || "overview")}">${esc(item.action || "View")}</button></article>`).join("") : empty("You are all caught up. New portal activity will appear here.")}</div></section>`;
    view.querySelectorAll("[data-notification-route]").forEach((button) => button.addEventListener("click", () => show(button.dataset.notificationRoute)));
  }

  const settingsToggle = (name, label, copy, checked) => `<label class="wnq-settings-toggle"><input type="checkbox" name="${esc(name)}" value="1" ${checked ? "checked" : ""}><span><strong>${esc(label)}</strong><small>${esc(copy)}</small></span></label>`;
  async function settings(view, refresh) {
    const [data, client] = await Promise.all([load("settings", refresh), load("profile", refresh)]);
    const notice = sessionStorage.getItem("wnqSettingsNotice") || "";
    if (notice) sessionStorage.removeItem("wnqSettingsNotice");
    const crm = data.crm || {};
    const notificationSettings = data.notifications || {};
    view.innerHTML = `${heading("Portal", "Settings", "Update your business details and the lists, reminders, and notifications used throughout the portal.")}
      ${notice ? `<div class="wnq-success-inline">${esc(notice)}</div>` : ""}
      <form class="wnq-settings-form" id="wnq-connected-settings">
        <section class="wnq-panel"><div class="wnq-panel-head"><div><span class="wnq-eyebrow">Business Profile</span><h2>Business Information</h2></div><small>Used across the client portal</small></div><div class="wnq-form wnq-settings-fields">${field("company", "Business Name", client.company || client.name, true)}${field("phone", "Phone", client.phone)}${field("email", "Email", client.email, true, "email")}${field("website", "Website", client.website, false, "url")}${field("business_address", "Business Address", client.business_address)}${field("city", "City", client.city)}${field("state", "State", client.state)}</div></section>
        <section class="wnq-panel"><div class="wnq-panel-head"><div><span class="wnq-eyebrow">CRM Defaults</span><h2>Lead and Job Settings</h2></div><small>Connected to CRM forms and filters</small></div><div class="wnq-form wnq-settings-fields"><label class="is-wide"><span>Services</span><textarea name="services" rows="5" placeholder="One service per line">${esc((crm.services || []).join("\n"))}</textarea><small>These choices appear in lead and job forms.</small></label><label class="is-wide"><span>Lead Sources</span><textarea name="lead_sources" rows="5" placeholder="One source per line">${esc((crm.lead_sources || []).join("\n"))}</textarea><small>These choices appear in lead forms and CRM reports.</small></label>${field("default_follow_up_days", "Default Follow-Up Delay (days)", crm.default_follow_up_days ?? 2, false, "number", "New leads receive this follow-up date automatically.")}</div></section>
        <section class="wnq-panel"><div class="wnq-panel-head"><div><span class="wnq-eyebrow">Notifications</span><h2>What should appear in Notifications?</h2></div></div><div class="wnq-settings-toggles">${settingsToggle("support_replies", "Support replies", "Notify me when Golden Web Marketing replies to a ticket.", notificationSettings.support_replies)}${settingsToggle("overdue_followups", "Overdue follow-ups", "Flag open leads and jobs whose follow-up date has passed.", notificationSettings.overdue_followups)}${settingsToggle("upcoming_jobs", "Upcoming jobs", "Show jobs scheduled during the next seven days.", notificationSettings.upcoming_jobs)}${settingsToggle("new_reports", "New reports", "Show the latest available Monthly SEO Report.", notificationSettings.new_reports)}${settingsToggle("sound_enabled", "Notification sound", "Play a short ring when a new attention item arrives while the portal is open.", notificationSettings.sound_enabled)}</div></section>
        <div class="wnq-settings-save"><button type="submit" class="wnq-button">Save Portal Settings</button><span id="wnq-settings-status"></span></div>
      </form>`;
    const form = view.querySelector("#wnq-connected-settings");
    form.addEventListener("submit", async (event) => {
      event.preventDefault();
      const submit = form.querySelector('[type="submit"]');
      const statusTarget = view.querySelector("#wnq-settings-status");
      const values = new FormData(form);
      submit.disabled = true;
      submit.textContent = "Saving...";
      statusTarget.textContent = "Connecting settings across the portal...";
      const list = (name) => String(values.get(name) || "").split(/[\n,]+/).map((value) => value.trim()).filter(Boolean);
      const profileBody = {
        company: values.get("company") || "", phone: values.get("phone") || "", email: values.get("email") || "", website: values.get("website") || "",
        business_address: values.get("business_address") || "", city: values.get("city") || "", state: values.get("state") || "", active_services: list("services").join("\n"),
      };
      const settingsBody = {
        crm: { services: list("services"), lead_sources: list("lead_sources"), default_follow_up_days: Number(values.get("default_follow_up_days") || 0) },
        notifications: { support_replies: values.has("support_replies"), overdue_followups: values.has("overdue_followups"), upcoming_jobs: values.has("upcoming_jobs"), new_reports: values.has("new_reports"), sound_enabled: values.has("sound_enabled") },
      };
      try {
        const [savedProfile, savedSettings] = await Promise.all([
          api("/portal/profile", { method: "POST", body: JSON.stringify(profileBody) }),
          api("/portal/settings", { method: "POST", body: JSON.stringify(settingsBody) }),
        ]);
        state.cache.profile = savedProfile;
        state.cache.settings = savedSettings;
        state.portalSettings = savedSettings;
        delete state.cache.customers; delete state.cache.overview; delete state.cache.notifications;
        sessionStorage.setItem("wnqSettingsNotice", "Portal settings saved and connected successfully.");
        show("settings", true);
      } catch (error) {
        statusTarget.textContent = error.message;
        submit.disabled = false;
        submit.textContent = "Save Portal Settings";
      }
    });
  }
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
      const submit = form.querySelector('[type="submit"]');
      submit.disabled = true; submit.textContent = "Submitting...";
      formStatus(form, "yellow", "Sending your request to Golden Web Marketing...");
      try {
        const saved = await api("/portal/requests", { method: "POST", body: data });
        if (!saved?.id) throw new Error("The server did not confirm the request.");
        delete state.cache.requests;
        sessionStorage.setItem("wnqRequestNotice", "Request submitted. Golden Web Marketing has been notified.");
        show("requests", true);
      } catch (error) {
        formStatus(form, "yellow", `Request was not sent. ${error.message}`);
        submit.disabled = false; submit.textContent = "Submit Request";
      }
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
