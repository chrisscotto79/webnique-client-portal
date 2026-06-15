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
    const tickets = await load("tickets", refresh);
    const messagesTab = root.querySelector('[data-tab="messages"]');
    if (messagesTab) messagesTab.textContent = "Messages";
    view.innerHTML = `${heading("Support", "Support Tickets", "Create a request, track its status, and keep every reply together.")}
      <div class="wnq-toolbar"><button class="wnq-button" id="wnq-new-ticket">New Support Ticket</button></div><div id="wnq-ticket-compose"></div>
      <div class="wnq-ticket-layout"><div class="wnq-ticket-list">${tickets.length ? tickets.map(ticketCard).join("") : empty("No support tickets yet.")}</div><div id="wnq-ticket-thread">${empty("Select a ticket to view the conversation.")}</div></div>`;
    const compose = view.querySelector("#wnq-ticket-compose");
    view.querySelector("#wnq-new-ticket").addEventListener("click", () => {
      compose.innerHTML = ticketForm();
      bindTicketForm(compose.querySelector("form"));
    });
    view.querySelectorAll("[data-ticket]").forEach((button) => button.addEventListener("click", () => openTicket(button.dataset.ticket, tickets, view)));
  }

  const ticketCard = (ticket) => `<button type="button" class="wnq-ticket-card ${ticket.unread ? "is-unread" : ""}" data-ticket="${esc(ticket.ticket_key)}"><div><strong>${esc(ticket.subject)}</strong>${status(ticket.ticket_status === "resolved" || ticket.ticket_status === "closed" ? "green" : "yellow", humanize(ticket.ticket_status))}</div><p>${esc(ticket.messages?.[ticket.messages.length - 1]?.message || "")}</p><small>${esc(ticket.ticket_key.toUpperCase())} · ${esc(humanize(ticket.category))} · Updated ${date(ticket.updated_at)}</small></button>`;
  const ticketForm = (ticket = {}) => `<form class="wnq-panel wnq-ticket-form"><input type="hidden" name="ticket_key" value="${esc(ticket.ticket_key || "")}">${ticket.ticket_key ? `<input type="hidden" name="subject" value="${esc(ticket.subject)}"><input type="hidden" name="category" value="${esc(ticket.category)}"><input type="hidden" name="priority" value="${esc(ticket.priority)}">` : `${field("subject", "Subject", "", true)}<label><span>Category</span><select name="category"><option value="general">General Support</option><option value="website">Website Update</option><option value="seo">SEO / Report</option><option value="billing">Billing</option><option value="training">Training</option></select></label><label><span>Priority</span><select name="priority"><option value="normal">Normal</option><option value="low">Low</option><option value="high">High</option><option value="urgent">Urgent</option></select></label>`}<label class="is-wide"><span>${ticket.ticket_key ? "Reply" : "How can we help?"}</span><textarea name="message" rows="5" required></textarea></label><div class="wnq-form-actions"><button class="wnq-button">${ticket.ticket_key ? "Send Reply" : "Create Ticket"}</button></div></form>`;
  const bindTicketForm = (form) => form.addEventListener("submit", async (event) => {
    event.preventDefault();
    await api("/portal/messages", { method: "POST", body: JSON.stringify(Object.fromEntries(new FormData(form))) });
    delete state.cache.tickets; delete state.cache.overview; show("messages", true);
  });
  const openTicket = (key, tickets, view) => {
    const ticket = tickets.find((item) => item.ticket_key === key);
    if (!ticket) return;
    const thread = view.querySelector("#wnq-ticket-thread");
    thread.innerHTML = `<section class="wnq-panel wnq-ticket-thread"><div class="wnq-panel-head"><div><span class="wnq-eyebrow">${esc(ticket.ticket_key.toUpperCase())}</span><h2>${esc(ticket.subject)}</h2></div>${status(ticket.ticket_status === "resolved" || ticket.ticket_status === "closed" ? "green" : "yellow", humanize(ticket.ticket_status))}</div><div class="wnq-thread-messages">${ticket.messages.map((message) => `<article class="${message.sender_role === "admin" ? "is-support" : "is-client"}"><div><strong>${message.sender_role === "admin" ? "Golden Web Marketing" : "You"}</strong><time>${date(message.created_at)}</time></div><p>${esc(message.message)}</p></article>`).join("")}</div>${ticketForm(ticket)}</section>`;
    bindTicketForm(thread.querySelector("form"));
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
