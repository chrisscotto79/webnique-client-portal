/**
 * SEO Audit Tab
 * Displays grades, graphs, recommendations, category breakdowns, and archives.
 */

import { el, escapeHtml } from "../ui.js";

let activeInstance = null;

function createInstance() {
  return {
    mounted: false,
    destroyed: false,
    auditData: null,
    reportsData: null,
    activeSection: "overview",
    containers: { main: null, side: null },
  };
}

export function renderAudit(main, side, state, shell) {
  if (activeInstance) cleanupInstance(activeInstance);
  const instance = createInstance();
  activeInstance = instance;
  instance.containers.main = main;
  instance.containers.side = side;

  if (main) main.innerHTML = "";
  if (side) {
    side.innerHTML = "";
    if (side.parentElement) side.parentElement.style.display = "none";
  }
  if (main?.parentElement) main.parentElement.style.gridColumn = "span 12";

  shell?.setStatus?.("Loading audit…", "neutral");
  instance.mounted = true;
  loadAudit(instance, state, shell);
  return () => cleanupInstance(instance);
}

async function loadAudit(instance, state, shell) {
  const { main } = instance.containers;
  if (!main) return;

  main.innerHTML = `<div style="padding:60px;text-align:center"><div class="wnq-spinner"></div><p style="margin-top:16px;color:#6b7280;">Analyzing your SEO…</p></div>`;

  try {
    const cfg = window.WNQ_PORTAL || {};
    const restBase = (cfg.restUrl || "").replace(/\/$/, "");
    const nonce = cfg.nonce || "";
    const headers = { "X-WP-Nonce": nonce, "Content-Type": "application/json" };

    const [auditRes, reportsRes] = await Promise.all([
      fetch(`${restBase}/seo-audit`, { credentials: "same-origin", headers }).then(r => r.json()),
      fetch(`${restBase}/seo-reports`, { credentials: "same-origin", headers }).then(r => r.json()),
    ]);

    if (!instance.destroyed) {
      instance.auditData = auditRes?.ok ? auditRes : null;
      instance.reportsData = reportsRes?.ok ? reportsRes : null;
      renderInterface(instance, state);
      shell?.setStatus?.("Ready", "good");
    }
  } catch (e) {
    if (!instance.destroyed && main) {
      main.innerHTML = `<div class="wnq-alert wnq-alert-danger" style="margin:20px"><strong>Error:</strong> ${escapeHtml(e.message)}</div>`;
    }
  }
}

// ── Grade helpers ─────────────────────────────────────────────────────────────

function scoreToGrade(score) {
  if (score >= 90) return { letter: "A+", color: "#059669", bg: "#d1fae5" };
  if (score >= 80) return { letter: "A",  color: "#10b981", bg: "#d1fae5" };
  if (score >= 70) return { letter: "B",  color: "#3b82f6", bg: "#dbeafe" };
  if (score >= 60) return { letter: "C",  color: "#f59e0b", bg: "#fef3c7" };
  if (score >= 50) return { letter: "D",  color: "#f97316", bg: "#ffedd5" };
  return               { letter: "F",  color: "#ef4444", bg: "#fee2e2" };
}

function scoreLabel(score) {
  if (score >= 90) return "Excellent";
  if (score >= 80) return "Very Good";
  if (score >= 70) return "Good";
  if (score >= 60) return "Needs Work";
  if (score >= 50) return "Poor";
  return "Critical";
}

// ── Radar chart (Canvas) ─────────────────────────────────────────────────────

function drawRadarChart(canvas, scores) {
  const ctx = canvas.getContext("2d");
  const w = canvas.width;
  const h = canvas.height;
  const cx = w / 2;
  const cy = h / 2;
  const r = Math.min(cx, cy) - 40;

  ctx.clearRect(0, 0, w, h);

  const labels = ["On-Page\nSEO", "Links", "Performance", "Usability", "Social"];
  const values = [
    (scores.on_page_seo ?? 0) / 100,
    (scores.links ?? 0) / 100,
    (scores.performance ?? 0) / 100,
    (scores.usability ?? 0) / 100,
    (scores.social ?? 0) / 100,
  ];
  const n = labels.length;

  function angle(i) { return (Math.PI * 2 * i) / n - Math.PI / 2; }
  function point(i, radius) {
    return { x: cx + radius * Math.cos(angle(i)), y: cy + radius * Math.sin(angle(i)) };
  }

  // Grid circles
  ctx.strokeStyle = "#e5e7eb";
  ctx.lineWidth = 1;
  [0.25, 0.5, 0.75, 1].forEach(frac => {
    ctx.beginPath();
    for (let i = 0; i < n; i++) {
      const p = point(i, r * frac);
      i === 0 ? ctx.moveTo(p.x, p.y) : ctx.lineTo(p.x, p.y);
    }
    ctx.closePath();
    ctx.stroke();
  });

  // Grid spokes
  for (let i = 0; i < n; i++) {
    const p = point(i, r);
    ctx.beginPath();
    ctx.moveTo(cx, cy);
    ctx.lineTo(p.x, p.y);
    ctx.strokeStyle = "#e5e7eb";
    ctx.stroke();
  }

  // Data polygon
  ctx.beginPath();
  values.forEach((v, i) => {
    const p = point(i, r * v);
    i === 0 ? ctx.moveTo(p.x, p.y) : ctx.lineTo(p.x, p.y);
  });
  ctx.closePath();
  ctx.fillStyle = "rgba(13,83,158,0.18)";
  ctx.fill();
  ctx.strokeStyle = "#0d539e";
  ctx.lineWidth = 2;
  ctx.stroke();

  // Data points
  values.forEach((v, i) => {
    const p = point(i, r * v);
    ctx.beginPath();
    ctx.arc(p.x, p.y, 4, 0, Math.PI * 2);
    ctx.fillStyle = "#0d539e";
    ctx.fill();
  });

  // Labels
  ctx.fillStyle = "#374151";
  ctx.font = "bold 11px system-ui, sans-serif";
  ctx.textAlign = "center";
  ctx.textBaseline = "middle";
  labels.forEach((lbl, i) => {
    const p = point(i, r + 28);
    const lines = lbl.split("\n");
    lines.forEach((line, li) => {
      ctx.fillText(line, p.x, p.y + (li - (lines.length - 1) / 2) * 14);
    });
  });
}

// ── Bar chart for category scores ────────────────────────────────────────────

function createCategoryBars(scores) {
  const cats = [
    { key: "on_page_seo", label: "On-Page SEO" },
    { key: "links",       label: "Links" },
    { key: "performance", label: "Performance" },
    { key: "usability",   label: "Usability" },
    { key: "social",      label: "Social" },
  ];

  const wrap = el("div", { style: { display: "flex", flexDirection: "column", gap: "12px" } });

  cats.forEach(cat => {
    const score = scores[cat.key] ?? 0;
    const grade = scoreToGrade(score);

    const row = el("div");
    const top = el("div", { style: { display: "flex", justifyContent: "space-between", marginBottom: "4px" } });
    top.appendChild(el("span", { text: cat.label, style: { fontSize: "13px", fontWeight: "600", color: "#374151" } }));
    const right = el("div", { style: { display: "flex", gap: "8px", alignItems: "center" } });
    right.appendChild(el("span", {
      text: grade.letter,
      style: { fontSize: "12px", fontWeight: "700", color: grade.color, background: grade.bg, padding: "2px 8px", borderRadius: "12px" }
    }));
    right.appendChild(el("span", { text: `${score}`, style: { fontSize: "13px", fontWeight: "700", color: grade.color } }));
    top.appendChild(right);
    row.appendChild(top);

    const track = el("div", { style: { background: "#f3f4f6", borderRadius: "99px", height: "8px", overflow: "hidden" } });
    const fill = el("div", {
      style: {
        height: "100%",
        width: `${score}%`,
        background: grade.color,
        borderRadius: "99px",
        transition: "width 0.8s ease",
      }
    });
    track.appendChild(fill);
    row.appendChild(track);
    wrap.appendChild(row);
  });

  return wrap;
}

// ── Recommendations list ─────────────────────────────────────────────────────

function createRecommendations(recommendations) {
  const section = el("div", { style: { marginBottom: "32px" } });
  section.appendChild(el("h2", {
    text: "Recommendations",
    style: { fontSize: "22px", fontWeight: "800", color: "#111827", marginBottom: "16px" }
  }));

  if (!recommendations || !recommendations.length) {
    const good = el("div", { style: { background: "#d1fae5", border: "1px solid #6ee7b7", borderRadius: "12px", padding: "20px", textAlign: "center", color: "#065f46" } });
    good.appendChild(el("div", { text: "✅", style: { fontSize: "40px", marginBottom: "8px" } }));
    good.appendChild(el("div", { text: "No open issues found!", style: { fontSize: "16px", fontWeight: "700" } }));
    section.appendChild(good);
    return section;
  }

  const priorityColors = {
    High:   { color: "#ef4444", bg: "#fee2e2", border: "#fca5a5" },
    Medium: { color: "#f59e0b", bg: "#fef3c7", border: "#fcd34d" },
    Low:    { color: "#6b7280", bg: "#f3f4f6", border: "#d1d5db" },
  };

  recommendations.forEach(rec => {
    const pStyle = priorityColors[rec.priority] || priorityColors.Low;
    const card = el("div", {
      style: {
        display: "flex",
        alignItems: "center",
        justifyContent: "space-between",
        padding: "14px 16px",
        marginBottom: "8px",
        background: "#fff",
        border: `1px solid #e5e7eb`,
        borderLeft: `4px solid ${pStyle.color}`,
        borderRadius: "10px",
        boxShadow: "0 1px 3px rgba(0,0,0,0.06)",
      }
    });

    const left = el("div", { style: { flex: "1" } });
    left.appendChild(el("div", { text: rec.label, style: { fontSize: "14px", fontWeight: "600", color: "#111827", marginBottom: "4px" } }));
    left.appendChild(el("span", {
      text: rec.category,
      style: { fontSize: "12px", color: "#6b7280", background: "#f3f4f6", padding: "2px 8px", borderRadius: "99px" }
    }));
    card.appendChild(left);

    card.appendChild(el("span", {
      text: `${rec.priority} Priority`,
      style: {
        fontSize: "11px", fontWeight: "700", color: pStyle.color,
        background: pStyle.bg, border: `1px solid ${pStyle.border}`,
        padding: "4px 10px", borderRadius: "99px", whiteSpace: "nowrap", marginLeft: "12px"
      }
    }));

    section.appendChild(card);
  });

  return section;
}

// ── Site stats summary ────────────────────────────────────────────────────────

function createSiteStats(stats) {
  const section = el("div", { style: { marginBottom: "32px" } });
  section.appendChild(el("h2", {
    text: "On-Page SEO Findings",
    style: { fontSize: "22px", fontWeight: "800", color: "#111827", marginBottom: "16px" }
  }));

  const grid = el("div", { style: { display: "grid", gridTemplateColumns: "repeat(auto-fit, minmax(160px, 1fr))", gap: "16px" } });

  const items = [
    { label: "Total Pages", value: stats.total_pages ?? 0, icon: "📄", color: "#0d539e" },
    { label: "Missing H1", value: stats.missing_h1 ?? 0, icon: "⚠️", color: stats.missing_h1 > 0 ? "#ef4444" : "#10b981" },
    { label: "Missing Alt", value: stats.missing_alt ?? 0, icon: "🖼️", color: stats.missing_alt > 0 ? "#f59e0b" : "#10b981" },
    { label: "Thin Content", value: stats.thin_content ?? 0, icon: "📝", color: stats.thin_content > 0 ? "#f59e0b" : "#10b981" },
    { label: "No Schema", value: stats.no_schema ?? 0, icon: "🔖", color: stats.no_schema > 0 ? "#f59e0b" : "#10b981" },
    { label: "No Internal Links", value: stats.no_internal_links ?? 0, icon: "🔗", color: stats.no_internal_links > 0 ? "#f59e0b" : "#10b981" },
  ];

  items.forEach(item => {
    const card = el("div", {
      style: {
        background: "#fff", border: "1px solid #e5e7eb", borderRadius: "12px",
        padding: "20px", textAlign: "center", boxShadow: "0 1px 3px rgba(0,0,0,0.06)"
      }
    });
    card.appendChild(el("div", { text: item.icon, style: { fontSize: "28px", marginBottom: "8px" } }));
    card.appendChild(el("div", {
      text: item.value.toLocaleString(),
      style: { fontSize: "32px", fontWeight: "900", color: item.color, marginBottom: "4px" }
    }));
    card.appendChild(el("div", { text: item.label, style: { fontSize: "12px", fontWeight: "600", color: "#6b7280" } }));
    grid.appendChild(card);
  });

  section.appendChild(grid);

  if (stats.last_synced) {
    const sync = el("p", {
      text: `Last site sync: ${stats.last_synced}`,
      style: { fontSize: "12px", color: "#9ca3af", marginTop: "12px" }
    });
    section.appendChild(sync);
  }

  return section;
}

// ── Keywords table ────────────────────────────────────────────────────────────

function createKeywordsTable(keywords) {
  if (!keywords || !keywords.length) return el("div");

  const section = el("div", { style: { marginBottom: "32px" } });
  section.appendChild(el("h2", {
    text: "Keyword Rankings",
    style: { fontSize: "22px", fontWeight: "800", color: "#111827", marginBottom: "16px" }
  }));

  const tbl = el("table", { style: { width: "100%", borderCollapse: "collapse" } });

  // Header
  const thead = el("thead");
  const hr = el("tr", { style: { background: "#f9fafb" } });
  ["Keyword", "Position", "Impressions", "Clicks", "Cluster"].forEach(h => {
    hr.appendChild(el("th", {
      text: h,
      style: { padding: "12px 16px", textAlign: "left", fontSize: "12px", fontWeight: "700", color: "#6b7280", textTransform: "uppercase", borderBottom: "2px solid #e5e7eb" }
    }));
  });
  thead.appendChild(hr);
  tbl.appendChild(thead);

  const tbody = el("tbody");
  keywords.slice(0, 15).forEach((kw, i) => {
    const pos = kw.current_position;
    const posColor = !pos ? "#9ca3af" : pos <= 3 ? "#059669" : pos <= 10 ? "#0d539e" : pos <= 20 ? "#f59e0b" : "#ef4444";
    const row = el("tr", { style: { background: i % 2 === 0 ? "#fff" : "#f9fafb" } });
    row.appendChild(el("td", { text: kw.keyword, style: { padding: "12px 16px", fontSize: "14px", fontWeight: "600", color: "#111827", borderBottom: "1px solid #f3f4f6" } }));
    row.appendChild(el("td", {
      text: pos ? `#${pos}` : "—",
      style: { padding: "12px 16px", fontSize: "14px", fontWeight: "700", color: posColor, borderBottom: "1px solid #f3f4f6" }
    }));
    row.appendChild(el("td", { text: (kw.impressions || 0).toLocaleString(), style: { padding: "12px 16px", fontSize: "14px", color: "#374151", borderBottom: "1px solid #f3f4f6" } }));
    row.appendChild(el("td", { text: (kw.clicks || 0).toLocaleString(), style: { padding: "12px 16px", fontSize: "14px", color: "#374151", borderBottom: "1px solid #f3f4f6" } }));
    row.appendChild(el("td", {
      text: kw.cluster || "—",
      style: { padding: "12px 16px", fontSize: "12px", color: "#6b7280", background: kw.cluster ? "#f3f4f6" : "transparent", borderRadius: "99px", borderBottom: "1px solid #f3f4f6" }
    }));
    tbody.appendChild(row);
  });
  tbl.appendChild(tbody);
  section.appendChild(tbl);
  return section;
}

// ── Archives ─────────────────────────────────────────────────────────────────

function createArchives(reports) {
  const section = el("div", { style: { marginBottom: "32px" } });
  section.appendChild(el("h2", {
    text: "Report Archives",
    style: { fontSize: "22px", fontWeight: "800", color: "#111827", marginBottom: "16px" }
  }));

  if (!reports || !reports.length) {
    const empty = el("div", {
      style: { background: "#f9fafb", border: "1px dashed #d1d5db", borderRadius: "12px", padding: "40px", textAlign: "center", color: "#9ca3af" }
    });
    empty.appendChild(el("div", { text: "📁", style: { fontSize: "40px", marginBottom: "8px" } }));
    empty.appendChild(el("div", { text: "No reports generated yet.", style: { fontSize: "14px" } }));
    section.appendChild(empty);
    return section;
  }

  reports.forEach(report => {
    const card = el("div", {
      style: {
        display: "flex", alignItems: "center", justifyContent: "space-between",
        padding: "16px 20px", marginBottom: "8px",
        background: "#fff", border: "1px solid #e5e7eb", borderRadius: "12px",
        boxShadow: "0 1px 3px rgba(0,0,0,0.06)"
      }
    });

    const left = el("div");
    left.appendChild(el("div", { text: report.title, style: { fontSize: "15px", fontWeight: "700", color: "#111827", marginBottom: "4px" } }));
    left.appendChild(el("div", {
      text: `${report.period_start} → ${report.period_end}`,
      style: { fontSize: "12px", color: "#6b7280" }
    }));
    card.appendChild(left);

    const right = el("div", { style: { display: "flex", gap: "12px", alignItems: "center" } });

    if (report.health_score !== null && report.health_score !== undefined) {
      const grade = scoreToGrade(report.health_score);
      right.appendChild(el("span", {
        text: `Score: ${report.health_score}`,
        style: { fontSize: "13px", fontWeight: "700", color: grade.color, background: grade.bg, padding: "4px 12px", borderRadius: "99px" }
      }));
    }

    const statusColors = { ready: "#10b981", pending: "#f59e0b", failed: "#ef4444" };
    right.appendChild(el("span", {
      text: report.status,
      style: { fontSize: "12px", fontWeight: "600", color: statusColors[report.status] ?? "#6b7280" }
    }));

    card.appendChild(right);
    section.appendChild(card);
  });

  return section;
}

// ── Suggestions panel ─────────────────────────────────────────────────────────

function createSuggestions(auditData) {
  const suggestions = [];
  const stats = auditData?.site_stats || {};
  const scores = auditData?.category_scores || {};

  if ((stats.missing_h1 ?? 0) > 0) {
    suggestions.push({ icon: "💡", text: `Add H1 tags to ${stats.missing_h1} page(s) to improve search engine understanding.`, priority: "High" });
  }
  if ((stats.no_schema ?? 0) > 0) {
    suggestions.push({ icon: "💡", text: `${stats.no_schema} page(s) lack Schema markup. Adding JSON-LD can boost rich results.`, priority: "Medium" });
  }
  if ((stats.thin_content ?? 0) > 0) {
    suggestions.push({ icon: "💡", text: `${stats.thin_content} page(s) have thin content (<300 words). Expand these to rank better.`, priority: "Medium" });
  }
  if ((stats.missing_alt ?? 0) > 0) {
    suggestions.push({ icon: "💡", text: `${stats.missing_alt} image(s) are missing alt attributes. Fix these for accessibility and image SEO.`, priority: "Low" });
  }
  if ((scores.links ?? 100) < 70) {
    suggestions.push({ icon: "💡", text: "Your internal linking score is low. Build more links between your pages to spread authority.", priority: "Medium" });
  }
  if ((scores.social ?? 100) < 70) {
    suggestions.push({ icon: "💡", text: "Connect your social profiles and add Open Graph tags to improve social sharing.", priority: "Low" });
  }
  if (!suggestions.length) {
    suggestions.push({ icon: "🎉", text: "Great work! Your site looks healthy. Keep monitoring regularly.", priority: "None" });
  }

  const section = el("div", { style: { marginBottom: "32px" } });
  section.appendChild(el("h2", { text: "Suggestions", style: { fontSize: "22px", fontWeight: "800", color: "#111827", marginBottom: "16px" } }));

  suggestions.forEach(s => {
    const priorityColors = { High: "#ef4444", Medium: "#f59e0b", Low: "#6b7280", None: "#10b981" };
    const card = el("div", {
      style: {
        display: "flex", gap: "12px", alignItems: "flex-start",
        padding: "14px 16px", marginBottom: "8px",
        background: "#fffbeb", border: "1px solid #fde68a", borderRadius: "10px",
      }
    });
    card.appendChild(el("span", { text: s.icon, style: { fontSize: "20px", lineHeight: "1.4" } }));
    const text = el("div", { style: { flex: "1" } });
    text.appendChild(el("p", { text: s.text, style: { fontSize: "14px", color: "#374151", margin: "0 0 4px 0" } }));
    if (s.priority !== "None") {
      text.appendChild(el("span", {
        text: `${s.priority} priority`,
        style: { fontSize: "11px", fontWeight: "700", color: priorityColors[s.priority] }
      }));
    }
    card.appendChild(text);
    section.appendChild(card);
  });

  return section;
}

// ── Section nav tabs ──────────────────────────────────────────────────────────

function createSectionNav(instance, state) {
  const sections = [
    { key: "overview", label: "Overview" },
    { key: "findings", label: "Findings" },
    { key: "recommendations", label: "Recommendations" },
    { key: "keywords", label: "Keywords" },
    { key: "suggestions", label: "Suggestions" },
    { key: "archives", label: "Archives" },
  ];

  const nav = el("div", {
    style: {
      display: "flex", gap: "8px", marginBottom: "28px",
      borderBottom: "2px solid #e5e7eb", paddingBottom: "0", flexWrap: "wrap"
    }
  });

  const buttons = new Map();

  function activate(key) {
    instance.activeSection = key;
    buttons.forEach((btn, k) => {
      const active = k === key;
      btn.style.color = active ? "#0d539e" : "#6b7280";
      btn.style.borderBottom = active ? "2px solid #0d539e" : "2px solid transparent";
      btn.style.fontWeight = active ? "700" : "600";
      btn.style.marginBottom = "-2px";
    });
    renderSection(instance, state);
  }

  sections.forEach(sec => {
    const btn = el("button", {
      type: "button",
      text: sec.label,
      style: {
        padding: "10px 16px", background: "none", border: "none",
        borderBottom: "2px solid transparent", cursor: "pointer",
        fontSize: "14px", fontWeight: "600", color: "#6b7280",
        marginBottom: "-2px", transition: "all 0.15s ease",
      }
    });
    btn.addEventListener("click", () => activate(sec.key));
    buttons.set(sec.key, btn);
    nav.appendChild(btn);
  });

  // Activate initial
  const initBtn = buttons.get(instance.activeSection);
  if (initBtn) {
    initBtn.style.color = "#0d539e";
    initBtn.style.borderBottom = "2px solid #0d539e";
    initBtn.style.fontWeight = "700";
    initBtn.style.marginBottom = "-2px";
  }

  instance._sectionNav = { activate, buttons };
  return nav;
}

function renderSection(instance, state) {
  const content = instance._sectionContent;
  if (!content) return;
  content.innerHTML = "";

  const a = instance.auditData;
  const r = instance.reportsData;
  const key = instance.activeSection;

  if (key === "overview") {
    // Score header + radar + bars
    const overallScore = a?.overall_score ?? 0;
    const grade = scoreToGrade(overallScore);
    const scores = a?.category_scores || {};

    const header = el("div", {
      style: {
        background: `linear-gradient(135deg, ${grade.color}22 0%, ${grade.color}08 100%)`,
        border: `1px solid ${grade.color}44`,
        borderRadius: "16px", padding: "28px", marginBottom: "28px",
        display: "flex", gap: "24px", alignItems: "center", flexWrap: "wrap",
      }
    });

    const scoreBig = el("div", { style: { textAlign: "center", minWidth: "100px" } });
    scoreBig.appendChild(el("div", {
      text: grade.letter,
      style: { fontSize: "64px", fontWeight: "900", color: grade.color, lineHeight: "1" }
    }));
    scoreBig.appendChild(el("div", {
      text: `${overallScore}/100`,
      style: { fontSize: "18px", fontWeight: "700", color: grade.color, marginTop: "4px" }
    }));
    scoreBig.appendChild(el("div", {
      text: scoreLabel(overallScore),
      style: { fontSize: "13px", color: "#6b7280", marginTop: "4px" }
    }));
    header.appendChild(scoreBig);

    const info = el("div", { style: { flex: "1", minWidth: "220px" } });
    info.appendChild(el("h2", { text: "SEO Health Overview", style: { fontSize: "24px", fontWeight: "900", color: "#111827", marginBottom: "8px" } }));
    const ts = a?.generated_at ? `Report generated: ${a.generated_at}` : "Live audit data";
    info.appendChild(el("p", { text: ts, style: { color: "#6b7280", fontSize: "13px", margin: "0 0 16px 0" } }));

    // Mini bars in header
    info.appendChild(createCategoryBars(scores));
    header.appendChild(info);
    content.appendChild(header);

    // Radar chart
    const chartWrap = el("div", {
      style: { background: "#fff", border: "1px solid #e5e7eb", borderRadius: "16px", padding: "24px", marginBottom: "24px", textAlign: "center" }
    });
    chartWrap.appendChild(el("h3", { text: "Category Score Radar", style: { fontSize: "18px", fontWeight: "800", color: "#111827", marginBottom: "16px" } }));
    const canvas = el("canvas", { width: "380", height: "320" });
    canvas.style.maxWidth = "100%";
    chartWrap.appendChild(canvas);
    content.appendChild(chartWrap);

    // Draw after DOM insertion (use requestAnimationFrame)
    requestAnimationFrame(() => drawRadarChart(canvas, scores));

  } else if (key === "findings") {
    content.appendChild(createSiteStats(a?.site_stats || {}));

  } else if (key === "recommendations") {
    content.appendChild(createRecommendations(a?.recommendations || []));

  } else if (key === "keywords") {
    content.appendChild(createKeywordsTable(a?.keywords || []));

  } else if (key === "suggestions") {
    content.appendChild(createSuggestions(a));

  } else if (key === "archives") {
    content.appendChild(createArchives(r?.reports || []));
  }
}

// ── Main interface ────────────────────────────────────────────────────────────

function renderInterface(instance, state) {
  const { main } = instance.containers;
  if (!main) return;
  main.innerHTML = "";

  // Page header
  const pageHeader = el("div", { style: { marginBottom: "24px", paddingBottom: "20px", borderBottom: "2px solid #e5e7eb" } });
  pageHeader.appendChild(el("h1", {
    text: "SEO Audit Report",
    style: { fontSize: "28px", fontWeight: "900", color: "#111827", marginBottom: "6px" }
  }));
  const domain = state.client?.domain || state.clientId || "your site";
  pageHeader.appendChild(el("p", {
    text: `Analyzing: ${domain}`,
    style: { fontSize: "14px", color: "#6b7280", margin: "0" }
  }));
  main.appendChild(pageHeader);

  // Section nav
  const nav = createSectionNav(instance, state);
  main.appendChild(nav);

  // Section content container
  const sectionContent = el("div");
  instance._sectionContent = sectionContent;
  main.appendChild(sectionContent);

  // Render initial section
  renderSection(instance, state);
}

// ── Cleanup ───────────────────────────────────────────────────────────────────

function cleanupInstance(instance) {
  if (!instance || instance.destroyed) return;
  instance.destroyed = true;
  instance.containers = {};
  instance.auditData = null;
  instance.reportsData = null;
}

export function cleanupAudit() {
  if (activeInstance) {
    cleanupInstance(activeInstance);
    activeInstance = null;
  }
}
