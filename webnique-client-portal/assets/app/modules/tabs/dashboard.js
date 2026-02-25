// assets/app/modules/tabs/dashboard.js
/**
 * Dashboard Tab v3.0 - Clean Analytics Dashboard
 */

import { el, pill, escapeHtml } from "../ui.js";
import { initFirebase, getThreads } from "../services/firebase.js";

let activeInstance = null;

function createDashboardInstance() {
  return {
    mounted: false,
    destroyed: false,
    threadsUnsubscribe: null,
    analyticsData: null,
    threadsData: [],
    containers: { main: null, side: null },
  };
}

export function renderDashboard(main, side, state, shell) {
  console.log("[Dashboard] Mounting");
  
  if (activeInstance) cleanup(activeInstance);
  
  const instance = createDashboardInstance();
  activeInstance = instance;
  
  instance.containers.main = main;
  instance.containers.side = side;
  
  if (main) main.innerHTML = "";
  if (side) side.innerHTML = "";
  if (side?.parentElement) side.parentElement.style.display = "block";
  if (main?.parentElement) main.parentElement.style.gridColumn = "";
  
  shell?.setStatus?.("Loading...", "neutral");
  
  instance.mounted = true;
  initializeTab(instance, state, shell);
  
  return () => cleanup(instance);
}

async function initializeTab(instance, state, shell) {
  const { main } = instance.containers;
  
  if (!main) return;
  
  main.innerHTML = '<div style="padding:60px;text-align:center"><div class="wnq-spinner"></div></div>';
  
  try {
    const firebaseReady = await initFirebase();
    
    if (state.clientId) {
      try {
        const analytics = await fetchAnalytics(state);
        instance.analyticsData = analytics;
        console.log("[Dashboard] ✅ Analytics loaded");
      } catch (e) {
        console.warn("[Dashboard] Analytics failed:", e.message);
      }
    }
    
    if (firebaseReady && state.clientId) {
      try {
        instance.threadsData = await getThreads(state.clientId);
      } catch (e) {
        console.warn("[Dashboard] Threads failed");
      }
    }
    
    if (!instance.destroyed) {
      renderInterface(instance, state);
      shell?.setStatus?.("Ready", "good");
    }
  } catch (e) {
    console.error("[Dashboard] Error:", e);
    if (!instance.destroyed) {
      main.innerHTML = `<div class="wnq-alert wnq-alert-danger" style="margin:20px"><strong>Error:</strong> ${escapeHtml(e.message)}</div>`;
    }
  }
}

async function fetchAnalytics(state) {
  const cfg = window.WNQ_PORTAL || {};
  
  if (!cfg.nonce) throw new Error("No nonce");
  
  let url = cfg.ajaxUrl || '/wp-admin/admin-ajax.php';
  if (!cfg.ajaxUrl && cfg.restUrl) {
    try {
      const u = new URL(cfg.restUrl);
      url = `${u.origin}/wp-admin/admin-ajax.php`;
    } catch (e) {}
  }
  
  console.log("[Dashboard] 📡 Calling:", url);
  
  const form = new URLSearchParams();
  form.append('action', 'wnq_get_analytics_data');
  form.append('nonce', cfg.nonce);
  form.append('client_id', state.clientId);
  form.append('date_range', '30');
  
  const res = await fetch(url, {
    method: 'POST',
    credentials: 'same-origin',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: form.toString(),
  });
  
  console.log("[Dashboard] 📥 Status:", res.status);
  
  if (!res.ok) {
    const txt = await res.text();
    console.error("[Dashboard] Response:", txt.substring(0, 200));
    throw new Error(`HTTP ${res.status}`);
  }
  
  const data = await res.json();
  console.log("[Dashboard] 📦 Data:", data);
  
  if (data.success === false) {
    throw new Error(data.data?.message || 'Failed');
  }
  
  return data.success ? data.data : data;
}

function renderInterface(instance, state) {
  const { main, side } = instance.containers;
  if (!main) return;
  
  main.innerHTML = "";
  
  // Header
  const header = el("div", { style: { marginBottom: "30px", paddingBottom: "20px", borderBottom: "2px solid #e5e7eb" } });
  const greeting = new Date().getHours() < 12 ? "Good morning" : new Date().getHours() < 18 ? "Good afternoon" : "Good evening";
  header.appendChild(el("h1", { text: `${greeting}, ${state.clientId || "there"}!`, style: { fontSize: "32px", fontWeight: "900", color: "#111827", marginBottom: "8px" } }));
  header.appendChild(el("p", { text: "Here's how your website is performing.", style: { fontSize: "16px", color: "#6b7280" } }));
  main.appendChild(header);
  
  // Analytics or placeholder
  if (instance.analyticsData?.overview) {
    main.appendChild(createStats(instance.analyticsData));
    
    if (instance.analyticsData.key_events?.length > 0) {
      main.appendChild(createEvents(instance.analyticsData.key_events));
    }
    
    const grid = el("div", { style: { display: "grid", gridTemplateColumns: "1fr 1fr", gap: "20px", marginTop: "24px" } });
    if (instance.analyticsData.top_pages?.length > 0) grid.appendChild(createPages(instance.analyticsData.top_pages));
    grid.appendChild(createActivity(instance.threadsData));
    main.appendChild(grid);
  } else {
    main.appendChild(createPlaceholder());
  }
  
  // Sidebar
  if (side) renderSidebar(side, state);
}

function createStats(data) {
  const sec = el("div", { style: { marginBottom: "32px" } });
  sec.appendChild(el("h2", { text: "📊 Website Performance (Last 30 Days)", style: { fontSize: "20px", fontWeight: "800", color: "#111827", marginBottom: "16px" } }));
  
  const grid = el("div", { style: { display: "grid", gridTemplateColumns: "repeat(auto-fit, minmax(220px, 1fr))", gap: "20px" } });
  
  [
    { label: "VISITORS", value: data.overview.total_users || 0, icon: "👥", color: "#0d539e" },
    { label: "PAGE VIEWS", value: data.overview.page_views || 0, icon: "👁️", color: "#6b7280" },
    { label: "SESSIONS", value: data.overview.sessions || 0, icon: "🔄", color: "#3b82f6" },
    { label: "BOUNCE RATE", value: (data.overview.bounce_rate || 0).toFixed(1) + "%", icon: "📊", color: "#10b981" },
  ].forEach(s => {
    const card = el("div", { style: { background: "#fff", border: "1px solid #e5e7eb", borderRadius: "12px", padding: "24px", boxShadow: "0 1px 3px rgba(0,0,0,0.1)" } });
    card.appendChild(el("div", { text: s.icon, style: { fontSize: "36px", marginBottom: "12px" } }));
    card.appendChild(el("div", { text: s.label, style: { fontSize: "12px", fontWeight: "700", color: "#6b7280", marginBottom: "8px" } }));
    card.appendChild(el("div", { text: typeof s.value === 'number' ? s.value.toLocaleString() : s.value, style: { fontSize: "48px", fontWeight: "900", color: s.color } }));
    grid.appendChild(card);
  });
  
  sec.appendChild(grid);
  return sec;
}

function createEvents(events) {
  const sec = el("div", { style: { marginBottom: "32px" } });
  sec.appendChild(el("h2", { text: "🎯 Key Events", style: { fontSize: "20px", fontWeight: "800", color: "#111827", marginBottom: "16px" } }));
  
  const grid = el("div", { style: { display: "grid", gridTemplateColumns: "repeat(auto-fit, minmax(180px, 1fr))", gap: "16px" } });
  
  const icons = {
    phone_click: { icon: "📞", color: "#3b82f6" },
    email_click: { icon: "✉️", color: "#8b5cf6" },
    social_click: { icon: "🌐", color: "#06b6d4" },
    contact_page_visit: { icon: "📝", color: "#f59e0b" },
    generate_lead: { icon: "📋", color: "#10b981" },
  };
  
  events.forEach(e => {
    const cfg = icons[e.event_name] || { icon: "📊", color: "#6b7280" };
    const card = el("div", { style: { background: "#fff", border: "1px solid #e5e7eb", borderLeft: `4px solid ${cfg.color}`, borderRadius: "12px", padding: "20px", display: "flex", alignItems: "center", gap: "12px" } });
    card.appendChild(el("div", { text: cfg.icon, style: { fontSize: "32px" } }));
    const content = el("div");
    content.appendChild(el("div", { text: e.display_name, style: { fontSize: "12px", fontWeight: "700", color: "#6b7280", textTransform: "uppercase", marginBottom: "4px" } }));
    content.appendChild(el("div", { text: e.count.toLocaleString(), style: { fontSize: "24px", fontWeight: "900", color: cfg.color } }));
    card.appendChild(content);
    grid.appendChild(card);
  });
  
  sec.appendChild(grid);
  return sec;
}

function createPages(pages) {
  const sec = el("div", { style: { background: "#fff", border: "1px solid #e5e7eb", borderRadius: "12px", padding: "24px", boxShadow: "0 1px 3px rgba(0,0,0,0.1)" } });
  sec.appendChild(el("h3", { text: "📄 Top Pages", style: { fontSize: "18px", fontWeight: "800", color: "#111827", marginBottom: "16px" } }));
  
  const tbl = el("table", { style: { width: "100%", borderCollapse: "collapse" } });
  const thead = el("thead");
  const hr = el("tr");
  hr.appendChild(el("th", { text: "Page", style: { textAlign: "left", padding: "12px 8px", fontSize: "12px", fontWeight: "700", color: "#6b7280", textTransform: "uppercase", borderBottom: "2px solid #e5e7eb" } }));
  hr.appendChild(el("th", { text: "Views", style: { textAlign: "right", padding: "12px 8px", fontSize: "12px", fontWeight: "700", color: "#6b7280", textTransform: "uppercase", borderBottom: "2px solid #e5e7eb" } }));
  thead.appendChild(hr);
  tbl.appendChild(thead);
  
  const tbody = el("tbody");
  pages.slice(0, 5).forEach((p, i) => {
    const row = el("tr");
    const pc = el("td", { style: { padding: "14px 8px", borderBottom: i < 4 ? "1px solid #f3f4f6" : "none" } });
    pc.appendChild(el("code", { text: p.path.length > 40 ? p.path.substring(0, 40) + "..." : p.path, style: { background: "#f3f4f6", padding: "4px 8px", borderRadius: "4px", fontSize: "13px" } }));
    row.appendChild(pc);
    row.appendChild(el("td", { text: p.views.toLocaleString(), style: { textAlign: "right", padding: "14px 8px", fontSize: "15px", fontWeight: "700", color: "#111827", borderBottom: i < 4 ? "1px solid #f3f4f6" : "none" } }));
    tbody.appendChild(row);
  });
  tbl.appendChild(tbody);
  sec.appendChild(tbl);
  return sec;
}

function createActivity(threads) {
  const sec = el("div", { style: { background: "#fff", border: "1px solid #e5e7eb", borderRadius: "12px", padding: "24px", boxShadow: "0 1px 3px rgba(0,0,0,0.1)" } });
  sec.appendChild(el("h3", { text: "📋 Recent Activity", style: { fontSize: "18px", fontWeight: "800", color: "#111827", marginBottom: "16px" } }));
  
  if (!threads || !threads.length) {
    sec.appendChild(el("p", { text: "No recent activity.", style: { color: "#9ca3af", textAlign: "center", padding: "20px 0" } }));
    return sec;
  }
  
  [...threads].sort((a, b) => new Date(b.last_updated || 0) - new Date(a.last_updated || 0)).slice(0, 5).forEach((t, i) => {
    const item = el("div", { style: { display: "flex", alignItems: "center", gap: "12px", padding: "14px 0", borderBottom: i < 4 ? "1px solid #f3f4f6" : "none" } });
    item.appendChild(el("div", { text: t.status === "closed" ? "✅" : "📝", style: { fontSize: "24px", width: "40px", height: "40px", display: "flex", alignItems: "center", justifyContent: "center", background: "#f9fafb", borderRadius: "8px" } }));
    const content = el("div", { style: { flex: "1" } });
    content.appendChild(el("div", { text: escapeHtml(t.subject || "Untitled"), style: { fontSize: "14px", fontWeight: "600", color: "#111827", marginBottom: "4px" } }));
    content.appendChild(el("div", { text: "Recently", style: { fontSize: "13px", color: "#6b7280" } }));
    item.appendChild(content);
    item.appendChild(pill(t.status === "closed" ? "Closed" : "Open", t.status === "closed" ? "neutral" : "good"));
    sec.appendChild(item);
  });
  
  return sec;
}

function createPlaceholder() {
  const ph = el("div", { style: { background: "linear-gradient(135deg, #667eea 0%, #764ba2 100%)", borderRadius: "16px", padding: "48px 40px", textAlign: "center", color: "#fff", marginBottom: "32px" } });
  ph.appendChild(el("div", { text: "📊", style: { fontSize: "64px", marginBottom: "20px" } }));
  ph.appendChild(el("h2", { text: "Analytics Not Configured", style: { fontSize: "28px", fontWeight: "900", marginBottom: "12px" } }));
  ph.appendChild(el("p", { text: "Contact support to enable analytics.", style: { fontSize: "16px", marginBottom: "24px", opacity: "0.9" } }));
  const btn = el("a", { href: "tel:+14439948595", style: { display: "inline-block", padding: "14px 32px", background: "#fff", color: "#667eea", borderRadius: "8px", textDecoration: "none", fontWeight: "700" } });
  btn.textContent = "📞 Call Support";
  ph.appendChild(btn);
  return ph;
}

function renderSidebar(side, state) {
  side.innerHTML = "";
  
  const acc = el("div", { style: { background: "linear-gradient(135deg, #0d539e 0%, #0a4380 100%)", borderRadius: "16px", padding: "24px", color: "#fff", marginBottom: "20px", boxShadow: "0 4px 12px rgba(13, 83, 158, 0.3)" } });
  acc.appendChild(el("div", { text: "ACCOUNT STATUS", style: { fontSize: "11px", fontWeight: "700", opacity: "0.8", marginBottom: "12px" } }));
  acc.appendChild(el("div", { text: "Active", style: { fontSize: "28px", fontWeight: "900", marginBottom: "16px" } }));
  
  [{ label: "Client", value: state.clientId || "N/A" }, { label: "Plan", value: "Standard" }].forEach(d => {
    const row = el("div", { style: { display: "flex", justifyContent: "space-between", padding: "10px 0", borderTop: "1px solid rgba(255,255,255,0.1)", fontSize: "14px" } });
    row.appendChild(el("span", { text: d.label, style: { opacity: "0.8" } }));
    row.appendChild(el("span", { text: d.value, style: { fontWeight: "600" } }));
    acc.appendChild(row);
  });
  side.appendChild(acc);
  
  const sup = el("div", { style: { background: "#fef3c7", border: "2px solid #fde047", borderRadius: "16px", padding: "20px" } });
  sup.appendChild(el("div", { text: "🚨", style: { fontSize: "32px", marginBottom: "12px" } }));
  sup.appendChild(el("h3", { text: "Emergency Support", style: { fontSize: "16px", fontWeight: "800", color: "#78350f", marginBottom: "8px" } }));
  sup.appendChild(el("p", { text: "Need immediate assistance?", style: { fontSize: "13px", color: "#92400e", marginBottom: "12px" } }));
  const dev = el("div", { style: { background: "#fff", padding: "12px", borderRadius: "8px", marginBottom: "12px" } });
  dev.innerHTML = '<div style="font-size:12px;color:#92400e;font-weight:600;margin-bottom:4px">Christopher Scotto</div><div style="font-size:11px;color:#a16207;margin-bottom:8px">Head Developer</div><a href="tel:+14439948595" style="font-size:16px;font-weight:700;color:#0d539e;text-decoration:none">📞 (443) 994-8595</a>';
  sup.appendChild(dev);
  const btn = el("a", { href: "tel:+14439948595", style: { display: "block", width: "100%", padding: "12px", background: "#f59e0b", color: "#fff", textAlign: "center", borderRadius: "8px", textDecoration: "none", fontWeight: "700" } });
  btn.textContent = "📞 Call Now";
  sup.appendChild(btn);
  side.appendChild(sup);
}

function cleanup(instance) {
  if (!instance || instance.destroyed) return;
  instance.destroyed = true;
  if (instance.threadsUnsubscribe) instance.threadsUnsubscribe();
  instance.containers = {};
  instance.analyticsData = null;
  instance.threadsData = [];
}

export function cleanupDashboard() {
  if (activeInstance) {
    cleanup(activeInstance);
    activeInstance = null;
  }
}