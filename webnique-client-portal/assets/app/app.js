(function () {
  "use strict";
  const root = document.getElementById("wnq-portal-root");
  const cfg = window.WNQ_PORTAL || {};
  if (!root) return;

  const state = { active: "overview", cache: {}, clientId: cfg.clientId || "" };
  const tabs = [
    ["overview", "Overview"], ["reports", "Reports"], ["customers", "CRM & Jobs"],
    ["messages", "Messages"], ["billing", "Billing"],
    ["learning", "Learning"], ["profile", "Business Profile"]
  ];
  const esc = (value) => String(value ?? "").replace(/[&<>"']/g, (char) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#039;" }[char]));
  const money = (value) => new Intl.NumberFormat("en-US", { style: "currency", currency: "USD", maximumFractionDigits: 0 }).format(Number(value || 0));
  const date = (value) => value ? new Date(`${value}`.replace(" ", "T")).toLocaleDateString() : "Not set";
  const api = async (path, options = {}) => {
    const requestUrl = new URL(`${cfg.restUrl.replace(/\/$/, "")}${path}`);
    if (cfg.isAdmin && state.clientId) requestUrl.searchParams.set("client_id", state.clientId);
    const response = await fetch(requestUrl.toString(), {
      credentials: "same-origin",
      headers: { "X-WP-Nonce": cfg.nonce, "Content-Type": "application/json" },
      ...options,
    });
    const data = await response.json().catch(() => ({ ok: false, error: "Invalid server response." }));
    if (!response.ok) throw new Error(data.error || "Request failed.");
    return data.data ?? data;
  };
  const status = (tone, label) => `<span class="wnq-status is-${esc(tone)}"><i></i>${esc(label)}</span>`;
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
      <aside class="wnq-sidebar"><div class="wnq-brand"><strong>Golden Web Marketing</strong><span>Client Portal</span></div>
      ${viewAs()}
      <nav>${tabs.map(([key, label]) => `<button type="button" data-tab="${key}">${label}</button>`).join("")}</nav>
      <div class="wnq-sidebar-foot"><span>Signed in as</span><strong>${esc(cfg.user?.name || "Client")}</strong></div></aside>
      <main class="wnq-main"><div id="wnq-view">${empty("Loading dashboard...")}</div></main></div>`;
    root.querySelectorAll("[data-tab]").forEach((button) => button.addEventListener("click", () => show(button.dataset.tab)));
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
    view.innerHTML = empty(`Loading ${key}...`);
    try {
      const renderers = { overview, reports, customers, messages, billing, learning, profile };
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
    const totals = rows.reduce((sum, row) => ({ jobs: sum.jobs + Number(row.job_count || 0), revenue: sum.revenue + Number(row.final_value || 0), cost: sum.cost + Number(row.job_cost || 0) }), { jobs: 0, revenue: 0, cost: 0 });
    view.innerHTML = `${heading("CRM & Job History", "Customers and Jobs", "Track leads, completed work, revenue, costs, profit, follow-ups, and marketing work in one place.")}
      <div class="wnq-metrics">${metric("Customers", rows.length, "CRM contacts")}${metric("Jobs", totals.jobs, "Recorded jobs")}${metric("Revenue", money(totals.revenue), "Recorded revenue")}${metric("Profit", money(totals.revenue - totals.cost), "Revenue minus costs", totals.revenue - totals.cost >= 0 ? "positive" : "negative")}</div>
      <section class="wnq-panel"><div class="wnq-panel-head"><h2>Monthly Job Performance</h2></div>${performanceChart(performance)}</section>
      <div class="wnq-toolbar"><button class="wnq-button" id="wnq-add-customer">Add Customer / Job</button></div>
      <div id="wnq-customer-form"></div>
      <div class="wnq-panel wnq-table-wrap"><div class="wnq-panel-head"><h2>Customer & Job Records</h2></div><table><thead><tr><th>Customer</th><th>Service / Source</th><th>Status</th><th>Job Date</th><th>Jobs</th><th>Revenue</th><th>Profit</th><th></th></tr></thead><tbody>
      ${rows.length ? rows.map((row) => `<tr><td><strong>${esc(row.name)}</strong><small>${esc(row.phone || row.email || "")}</small></td><td>${esc(row.service || "Not set")}<small>${esc(row.lead_source || "Source not set")}</small></td><td>${status(["completed","won","closed"].includes(row.status) ? "green" : row.status === "lost" ? "red" : "yellow", row.status)}</td><td>${date(row.job_date || row.follow_up_date)}</td><td>${esc(row.job_count)}</td><td>${money(row.final_value)}</td><td>${trend(Number(row.final_value || 0) - Number(row.job_cost || 0))}</td><td><button class="wnq-link" data-edit='${esc(JSON.stringify(row))}'>Edit</button></td></tr>`).join("") : `<tr><td colspan="8">${empty("Add your first customer or job to begin.")}</td></tr>`}
      </tbody></table></div>
      <div class="wnq-panel"><div class="wnq-panel-head"><h2>Marketing Work History</h2></div>${workRows.length ? workRows.map((row) => `<div class="wnq-work-item"><div>${status(row.status === "done" ? "green" : "yellow", row.status)}<strong>${esc(row.title)}</strong></div><span>${row.due_date ? `Due ${date(row.due_date)}` : "No due date"}</span></div>`).join("") : empty("No marketing work items yet.")}</div>`;
    const formRoot = view.querySelector("#wnq-customer-form");
    const openForm = (row = {}) => {
      formRoot.innerHTML = customerForm(row);
      formRoot.querySelector("form").addEventListener("submit", async (event) => {
        event.preventDefault();
        const values = Object.fromEntries(new FormData(event.currentTarget));
        await api("/portal/customers", { method: "POST", body: JSON.stringify(values) });
        delete state.cache.customers; delete state.cache.overview; show("customers", true);
      });
      formRoot.querySelector("[data-cancel]").addEventListener("click", () => formRoot.innerHTML = "");
    };
    view.querySelector("#wnq-add-customer").addEventListener("click", () => openForm());
    view.querySelectorAll("[data-edit]").forEach((button) => button.addEventListener("click", () => openForm(JSON.parse(button.dataset.edit))));
  }
  const customerForm = (row) => `<form class="wnq-panel wnq-form"><input type="hidden" name="id" value="${esc(row.id || "")}">
    <div class="wnq-panel-head"><h2>${row.id ? "Edit" : "Add"} Customer</h2></div>
    ${field("name", "Customer Name", row.name, true)}${field("phone", "Phone", row.phone)}${field("email", "Email", row.email, false, "email")}
    ${field("address", "Address", row.address)}${field("service", "Service / Job Type", row.service)}${field("lead_source", "Lead Source", row.lead_source)}
    <label><span>Status</span><select name="status">${["new","contacted","estimate_sent","scheduled","completed","won","lost","closed"].map((v) => `<option value="${v}" ${row.status === v ? "selected" : ""}>${v.replace("_", " ")}</option>`).join("")}</select></label>
    ${field("follow_up_date", "Follow-up Date", row.follow_up_date, false, "date")}${field("job_date", "Job Date", row.job_date, false, "date")}${field("job_count", "Job Count", row.job_count || 0, false, "number")}
    ${field("estimated_value", "Estimated Value", row.estimated_value || 0, false, "number")}${field("final_value", "Final Revenue", row.final_value || 0, false, "number")}${field("job_cost", "Job Cost", row.job_cost || 0, false, "number")}
    <label class="is-wide"><span>Notes</span><textarea name="notes" rows="3">${esc(row.notes || "")}</textarea></label>
    <div class="wnq-form-actions"><button class="wnq-button">Save Customer</button><button type="button" class="wnq-button is-secondary" data-cancel>Cancel</button></div></form>`;
  const field = (name, label, value = "", required = false, type = "text") => `<label><span>${esc(label)}</span><input type="${type}" name="${name}" value="${esc(value)}" ${required ? "required" : ""} ${type === "number" ? 'min="0" step="0.01"' : ""}></label>`;

  async function messages(view, refresh) {
    const rows = await load("messages", refresh);
    const messagesTab = root.querySelector('[data-tab="messages"]');
    if (messagesTab) messagesTab.textContent = "Messages";
    view.innerHTML = `${heading("Communication", "Messages", "Send questions and updates directly to Golden Web Marketing.")}
      <form class="wnq-panel wnq-message-form"><label><span>Subject</span><input name="subject" placeholder="What is this about?"></label><label><span>Message</span><textarea name="message" rows="4" required></textarea></label><button class="wnq-button">Send Message</button></form>
      <div class="wnq-panel">${rows.length ? rows.map((row) => `<article class="wnq-message"><div>${status(row.sender_role === "admin" ? "green" : "yellow", row.sender_role === "admin" ? "Golden Web Marketing" : "You")}<time>${date(row.created_at)}</time></div><strong>${esc(row.subject || "Message")}</strong><p>${esc(row.message)}</p></article>`).join("") : empty("No messages yet.")}</div>`;
    view.querySelector("form").addEventListener("submit", async (event) => {
      event.preventDefault(); await api("/portal/messages", { method: "POST", body: JSON.stringify(Object.fromEntries(new FormData(event.currentTarget))) });
      delete state.cache.messages; delete state.cache.overview; show("messages", true);
    });
  }

  async function billing(view, refresh) {
    const data = await load("overview", refresh); const client = data.client || {};
    view.innerHTML = `${heading("Account", "Billing", "Your plan and billing status at a glance.")}
      <div class="wnq-health"><div><span>Billing status</span>${status(data.health.billing.tone, data.health.billing.label)}</div><div><span>Plan</span><strong>${esc(client.tier || "Not set")}</strong></div><div><span>Billing cycle</span><strong>${esc(client.billing_cycle || "Not set")}</strong></div></div>
      <div class="wnq-panel"><div class="wnq-billing-total"><span>Monthly service rate</span><strong>${money(client.monthly_rate)}</strong></div><p>Last payment: ${date(client.last_payment_date)}</p><p class="wnq-note">Stripe payment management will appear here once Stripe is connected.</p></div>`;
  }

  function learning(view) {
    const lessons = [["Request More Reviews","A simple process for asking happy customers for Google reviews."],["Take Better Project Photos","Capture useful before, during, and after photos."],["Follow Up With Customers","Use simple follow-ups to keep opportunities moving."],["Understand Your Reports","Know which marketing numbers deserve your attention."]];
    view.innerHTML = `${heading("Resources", "Learning Center", "Short guides that help you get more value from your marketing.")}
      <div class="wnq-learning">${lessons.map(([title, copy]) => `<article><span>Guide</span><h2>${esc(title)}</h2><p>${esc(copy)}</p><button class="wnq-link" disabled>Coming soon</button></article>`).join("")}</div>`;
  }

  async function profile(view, refresh) {
    const client = await load("profile", refresh);
    view.innerHTML = `${heading("Business", "Business Profile", "The information Golden Web Marketing uses for your account.")}
      <div class="wnq-panel wnq-profile">${profileRow("Business", client.company || client.name)}${profileRow("Phone", client.phone)}${profileRow("Email", client.email)}${profileRow("Website", client.website)}${profileRow("Address", [client.business_address, client.city, client.state].filter(Boolean).join(", "))}${profileRow("Services", (client.active_services || []).join(", "))}</div>
      <p class="wnq-note">Send a message to request profile changes.</p>`;
  }
  const profileRow = (label, value) => `<div><span>${esc(label)}</span><strong>${esc(value || "Not set")}</strong></div>`;

  shell();
  show("overview");
})();
