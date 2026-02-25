// assets/app/modules/tabs/analytics.js
/**
 * Analytics Tab - Website Performance & Insights
 * 
 * Shows: Traffic stats, visitor trends, top pages, traffic sources
 * Data: Fetched from WordPress Analytics Admin via AJAX
 */

import { el, pill, button, escapeHtml } from "../ui.js";

// State
let currentPeriod = 30;
let currentChart = null;
let isLoading = false;

export function renderAnalytics(main, side, state, shell) {
  // Prevent multiple loads
  if (isLoading) {
    console.log("[Analytics] Already loading, skipping");
    return;
  }

  main.innerHTML = "";
  side.innerHTML = "";

  // RESTORE SHELL'S RIGHT SIDEBAR
  if (side && side.parentElement) {
    side.parentElement.style.display = "block";
  }
  
  // RESTORE MAIN AREA TO NORMAL WIDTH
  if (main && main.parentElement) {
    main.parentElement.style.gridColumn = "span 8";
  }

  if (shell?.setStatus) {
    shell.setStatus("Loading analytics...", "neutral");
  }

  // Load analytics
  loadAnalytics(main, side, state, shell, 30);
}

/**
 * Load analytics data
 */
async function loadAnalytics(main, side, state, shell, period) {
  if (isLoading) return;
  
  isLoading = true;
  currentPeriod = period;

  // Clear main
  main.innerHTML = "";

  // Show loading
  const loading = el("div", {
    style: { padding: "40px", textAlign: "center" },
  });
  loading.appendChild(el("div", { class: "wnq-spinner" }));
  loading.appendChild(
    el("p", {
      text: "Loading your analytics...",
      style: { marginTop: "16px", color: "#6b7280" },
    })
  );
  main.appendChild(loading);

  try {
    // Make AJAX request to WordPress
    const response = await fetch(window.wnqClientPortal.ajaxUrl, {
      method: "POST",
      headers: {
        "Content-Type": "application/x-www-form-urlencoded",
      },
      body: new URLSearchParams({
        action: "wnq_get_client_analytics",
        nonce: window.wnqClientPortal.analyticsNonce,
        date_range: period,
      }),
    });

    const result = await response.json();

    if (!result.success) {
      throw new Error(result.data?.message || "Failed to load analytics");
    }

    const data = result.data;

    // Clear loading
    main.innerHTML = "";

    // Render interface
    renderInterface(main, side, state, shell, data, period);

    if (shell?.setStatus) {
      shell.setStatus("Ready", "good");
    }

  } catch (error) {
    console.error("[Analytics] Load error:", error);
    main.innerHTML = "";
    showError(main, error.message);
    
    if (shell?.setStatus) {
      shell.setStatus("Error", "bad");
    }
  } finally {
    isLoading = false;
  }
}

/**
 * Render complete interface
 */
function renderInterface(main, side, state, shell, data, period) {
  // Header
  main.appendChild(createHeader(state, shell, period));

  // Overview stats
  main.appendChild(createOverviewCards(data.overview || {}));

  // Visitors chart
  if (data.visitors_over_time && data.visitors_over_time.length > 0) {
    main.appendChild(createVisitorsChart(data.visitors_over_time));
  }

  // Traffic sources & top pages
  const twoCol = el("div", {
    style: {
      display: "grid",
      gridTemplateColumns: "1fr 1fr",
      gap: "20px",
      marginBottom: "24px",
    },
  });

  if (data.traffic_sources && data.traffic_sources.length > 0) {
    twoCol.appendChild(createTrafficSources(data.traffic_sources));
  }

  if (data.top_pages && data.top_pages.length > 0) {
    twoCol.appendChild(createTopPages(data.top_pages));
  }

  if (twoCol.children.length > 0) {
    main.appendChild(twoCol);
  }

  // Sidebar
  renderSidebar(side, state, data);
}

/**
 * Create header
 */
function createHeader(state, shell, period) {
  const header = el("div", {
    style: {
      marginBottom: "24px",
      paddingBottom: "20px",
      borderBottom: "2px solid #e5e7eb",
      display: "flex",
      justifyContent: "space-between",
      alignItems: "flex-start",
    },
  });

  const titleSection = el("div");

  titleSection.appendChild(
    el("h1", {
      text: "📊 Analytics",
      style: {
        fontSize: "32px",
        fontWeight: "900",
        color: "#111827",
        marginBottom: "8px",
        letterSpacing: "-0.02em",
      },
    })
  );

  titleSection.appendChild(
    el("p", {
      text: "Track your website's performance and visitor behavior.",
      style: {
        fontSize: "16px",
        color: "#6b7280",
        margin: "0",
      },
    })
  );

  header.appendChild(titleSection);

  // Period selector
  const controls = el("div", { style: { display: "flex", gap: "8px" } });

  const periods = [
    { label: "7 Days", value: 7 },
    { label: "30 Days", value: 30 },
    { label: "90 Days", value: 90 },
  ];

  periods.forEach((p) => {
    const btn = el("button", {
      text: p.label,
      type: "button",
      style: {
        padding: "8px 16px",
        borderRadius: "8px",
        border: "1px solid #e5e7eb",
        background: period === p.value ? "#0d539e" : "white",
        color: period === p.value ? "white" : "#6b7280",
        fontSize: "14px",
        fontWeight: "600",
        cursor: "pointer",
        transition: "all 0.2s ease",
      },
    });

    btn.addEventListener("click", () => {
      loadAnalytics(
        document.querySelector('[data-wnq-main]'),
        document.querySelector('[data-wnq-side]'),
        state,
        shell,
        p.value
      );
    });

    controls.appendChild(btn);
  });

  header.appendChild(controls);

  return header;
}

/**
 * Create overview cards
 */
function createOverviewCards(overview) {
  const section = el("div", { style: { marginBottom: "32px" } });

  section.appendChild(
    el("h2", {
      text: "Overview",
      style: {
        fontSize: "20px",
        fontWeight: "800",
        color: "#111827",
        marginBottom: "16px",
      },
    })
  );

  const grid = el("div", {
    style: {
      display: "grid",
      gridTemplateColumns: "repeat(auto-fit, minmax(200px, 1fr))",
      gap: "20px",
    },
  });

  const stats = [
    {
      label: "TOTAL VISITORS",
      value: (overview.total_users || 0).toLocaleString(),
      icon: "👥",
      color: "#0d539e",
    },
    {
      label: "PAGE VIEWS",
      value: (overview.page_views || 0).toLocaleString(),
      icon: "📄",
      color: "#10b981",
    },
    {
      label: "SESSIONS",
      value: (overview.sessions || 0).toLocaleString(),
      icon: "📊",
      color: "#f59e0b",
    },
    {
      label: "BOUNCE RATE",
      value: (overview.bounce_rate || 0).toFixed(1) + "%",
      icon: "📈",
      color: "#6b7280",
    },
  ];

  stats.forEach((stat) => {
    grid.appendChild(createStatCard(stat));
  });

  section.appendChild(grid);
  return section;
}

/**
 * Create stat card
 */
function createStatCard({ label, value, icon, color }) {
  const card = el("div", {
    style: {
      background: "white",
      border: "1px solid #e5e7eb",
      borderRadius: "12px",
      padding: "24px",
      boxShadow: "0 1px 3px rgba(0,0,0,0.1)",
      transition: "all 0.2s ease",
      cursor: "default",
    },
  });

  card.addEventListener("mouseenter", () => {
    card.style.transform = "translateY(-2px)";
    card.style.boxShadow = "0 4px 6px rgba(0,0,0,0.1)";
  });

  card.addEventListener("mouseleave", () => {
    card.style.transform = "translateY(0)";
    card.style.boxShadow = "0 1px 3px rgba(0,0,0,0.1)";
  });

  card.appendChild(
    el("div", {
      text: icon,
      style: { fontSize: "32px", marginBottom: "12px" },
    })
  );

  card.appendChild(
    el("div", {
      text: label,
      style: {
        fontSize: "12px",
        fontWeight: "700",
        color: "#6b7280",
        letterSpacing: "0.05em",
        marginBottom: "8px",
      },
    })
  );

  card.appendChild(
    el("div", {
      text: value,
      style: {
        fontSize: "48px",
        fontWeight: "900",
        color: color,
        lineHeight: "1",
      },
    })
  );

  return card;
}

/**
 * Create visitors chart
 */
function createVisitorsChart(data) {
  const section = el("div", { style: { marginBottom: "32px" } });

  section.appendChild(
    el("h2", {
      text: "Visitors Over Time",
      style: {
        fontSize: "20px",
        fontWeight: "800",
        color: "#111827",
        marginBottom: "16px",
      },
    })
  );

  const card = el("div", {
    style: {
      background: "white",
      border: "1px solid #e5e7eb",
      borderRadius: "12px",
      padding: "24px",
      boxShadow: "0 1px 3px rgba(0,0,0,0.1)",
    },
  });

  const canvas = el("canvas", {
    id: "analytics-visitors-chart",
    style: { maxHeight: "300px" },
  });

  card.appendChild(canvas);
  section.appendChild(card);

  // Render chart after DOM update
  setTimeout(() => {
    renderChart(canvas, data);
  }, 100);

  return section;
}

/**
 * Render Chart.js chart
 */
function renderChart(canvas, data) {
  if (typeof Chart === "undefined") {
    console.warn("[Analytics] Chart.js not loaded");
    canvas.parentElement.appendChild(
      el("p", {
        text: "Chart library not loaded. Please refresh the page.",
        style: { color: "#ef4444", textAlign: "center", padding: "20px" },
      })
    );
    return;
  }

  if (currentChart) {
    currentChart.destroy();
  }

  const ctx = canvas.getContext("2d");

  currentChart = new Chart(ctx, {
    type: "line",
    data: {
      labels: data.map((d) => d.date),
      datasets: [
        {
          label: "Visitors",
          data: data.map((d) => d.users),
          borderColor: "#0d539e",
          backgroundColor: "rgba(13, 83, 158, 0.1)",
          tension: 0.4,
          fill: true,
        },
      ],
    },
    options: {
      responsive: true,
      maintainAspectRatio: true,
      plugins: {
        legend: { display: false },
      },
      scales: {
        y: {
          beginAtZero: true,
          ticks: { precision: 0 },
        },
      },
    },
  });
}

/**
 * Create traffic sources table
 */
function createTrafficSources(sources) {
  const section = el("div");

  section.appendChild(
    el("h2", {
      text: "Traffic Sources",
      style: {
        fontSize: "18px",
        fontWeight: "800",
        color: "#111827",
        marginBottom: "16px",
      },
    })
  );

  const card = el("div", {
    style: {
      background: "white",
      border: "1px solid #e5e7eb",
      borderRadius: "12px",
      padding: "20px",
      boxShadow: "0 1px 3px rgba(0,0,0,0.1)",
    },
  });

  const table = el("table", {
    style: {
      width: "100%",
      fontSize: "14px",
    },
  });

  // Header
  const thead = el("thead");
  const headerRow = el("tr");
  ["Channel", "Sessions", "%"].forEach((h) => {
    headerRow.appendChild(
      el("th", {
        text: h,
        style: {
          textAlign: "left",
          padding: "12px 8px",
          fontWeight: "700",
          color: "#6b7280",
          borderBottom: "2px solid #e5e7eb",
        },
      })
    );
  });
  thead.appendChild(headerRow);
  table.appendChild(thead);

  // Body
  const tbody = el("tbody");
  sources.forEach((source) => {
    const row = el("tr");

    row.appendChild(
      el("td", {
        text: escapeHtml(source.channel),
        style: {
          padding: "12px 8px",
          fontWeight: "600",
          color: "#111827",
          borderBottom: "1px solid #f3f4f6",
        },
      })
    );

    row.appendChild(
      el("td", {
        text: source.sessions.toLocaleString(),
        style: {
          padding: "12px 8px",
          color: "#6b7280",
          borderBottom: "1px solid #f3f4f6",
        },
      })
    );

    row.appendChild(
      el("td", {
        text: source.percentage + "%",
        style: {
          padding: "12px 8px",
          color: "#0d539e",
          fontWeight: "600",
          borderBottom: "1px solid #f3f4f6",
        },
      })
    );

    tbody.appendChild(row);
  });
  table.appendChild(tbody);

  card.appendChild(table);
  section.appendChild(card);

  return section;
}

/**
 * Create top pages table
 */
function createTopPages(pages) {
  const section = el("div");

  section.appendChild(
    el("h2", {
      text: "Top Pages",
      style: {
        fontSize: "18px",
        fontWeight: "800",
        color: "#111827",
        marginBottom: "16px",
      },
    })
  );

  const card = el("div", {
    style: {
      background: "white",
      border: "1px solid #e5e7eb",
      borderRadius: "12px",
      padding: "20px",
      boxShadow: "0 1px 3px rgba(0,0,0,0.1)",
    },
  });

  const table = el("table", {
    style: {
      width: "100%",
      fontSize: "14px",
    },
  });

  // Header
  const thead = el("thead");
  const headerRow = el("tr");
  ["Page", "Views"].forEach((h) => {
    headerRow.appendChild(
      el("th", {
        text: h,
        style: {
          textAlign: "left",
          padding: "12px 8px",
          fontWeight: "700",
          color: "#6b7280",
          borderBottom: "2px solid #e5e7eb",
        },
      })
    );
  });
  thead.appendChild(headerRow);
  table.appendChild(thead);

  // Body
  const tbody = el("tbody");
  pages.slice(0, 10).forEach((page) => {
    const row = el("tr");

    row.appendChild(
      el("td", {
        style: {
          padding: "12px 8px",
          borderBottom: "1px solid #f3f4f6",
        },
      })
    );

    row.children[0].appendChild(
      el("code", {
        text: escapeHtml(page.path.substring(0, 40)),
        style: {
          fontSize: "13px",
          color: "#111827",
          background: "#f3f4f6",
          padding: "2px 6px",
          borderRadius: "4px",
        },
      })
    );

    row.appendChild(
      el("td", {
        text: page.views.toLocaleString(),
        style: {
          padding: "12px 8px",
          color: "#6b7280",
          fontWeight: "600",
          borderBottom: "1px solid #f3f4f6",
        },
      })
    );

    tbody.appendChild(row);
  });
  table.appendChild(tbody);

  card.appendChild(table);
  section.appendChild(card);

  return section;
}

/**
 * Render sidebar
 */
function renderSidebar(side, state, data) {
  // Period info card
  const infoCard = el("div", {
    style: {
      background: "linear-gradient(135deg, #0d539e 0%, #0a4380 100%)",
      borderRadius: "16px",
      padding: "24px",
      color: "white",
      marginBottom: "20px",
    },
  });

  infoCard.appendChild(
    el("div", {
      text: "CURRENT PERIOD",
      style: {
        fontSize: "11px",
        fontWeight: "700",
        letterSpacing: "0.1em",
        opacity: "0.8",
        marginBottom: "12px",
      },
    })
  );

  infoCard.appendChild(
    el("div", {
      text: `Last ${currentPeriod} Days`,
      style: {
        fontSize: "24px",
        fontWeight: "900",
        marginBottom: "16px",
      },
    })
  );

  infoCard.appendChild(
    el("p", {
      text: "Data is updated in real-time from Google Analytics.",
      style: {
        fontSize: "13px",
        opacity: "0.9",
        lineHeight: "1.5",
      },
    })
  );

  side.appendChild(infoCard);

  // Insights card
  const insightsCard = el("div", {
    style: {
      background: "white",
      border: "1px solid #e5e7eb",
      borderRadius: "16px",
      padding: "20px",
      marginBottom: "20px",
    },
  });

  insightsCard.appendChild(
    el("div", {
      text: "💡",
      style: { fontSize: "32px", marginBottom: "12px" },
    })
  );

  insightsCard.appendChild(
    el("h3", {
      text: "Quick Insights",
      style: {
        fontSize: "16px",
        fontWeight: "800",
        color: "#111827",
        marginBottom: "12px",
      },
    })
  );

  const insights = [
    {
      label: "Most Active Day",
      value: getMostActiveDay(data.visitors_over_time),
    },
    {
      label: "Top Traffic Source",
      value: data.traffic_sources?.[0]?.channel || "N/A",
    },
    {
      label: "Top Page",
      value: data.top_pages?.[0]?.path || "N/A",
    },
  ];

  insights.forEach((insight, index) => {
    const row = el("div", {
      style: {
        padding: "12px 0",
        borderTop: index > 0 ? "1px solid #f3f4f6" : "none",
      },
    });

    row.appendChild(
      el("div", {
        text: insight.label,
        style: {
          fontSize: "12px",
          color: "#6b7280",
          marginBottom: "4px",
        },
      })
    );

    row.appendChild(
      el("div", {
        text: escapeHtml(insight.value),
        style: {
          fontSize: "14px",
          fontWeight: "600",
          color: "#111827",
        },
      })
    );

    insightsCard.appendChild(row);
  });

  side.appendChild(insightsCard);

  // Help card
  const helpCard = el("div", {
    style: {
      background: "#fef3c7",
      border: "1px solid #fde047",
      borderRadius: "16px",
      padding: "20px",
    },
  });

  helpCard.appendChild(
    el("div", {
      text: "📊",
      style: { fontSize: "32px", marginBottom: "12px" },
    })
  );

  helpCard.appendChild(
    el("h3", {
      text: "About Analytics",
      style: {
        fontSize: "16px",
        fontWeight: "800",
        color: "#78350f",
        marginBottom: "8px",
      },
    })
  );

  helpCard.appendChild(
    el("p", {
      text: "Your analytics are powered by Google Analytics 4 and updated regularly.",
      style: {
        fontSize: "13px",
        color: "#92400e",
        lineHeight: "1.5",
      },
    })
  );

  side.appendChild(helpCard);
}

/**
 * Get most active day
 */
function getMostActiveDay(visitors) {
  if (!visitors || visitors.length === 0) return "N/A";

  const sorted = [...visitors].sort((a, b) => b.users - a.users);
  return sorted[0].date;
}

/**
 * Show error
 */
function showError(container, message) {
  container.innerHTML = "";
  container.appendChild(
    el("div", {
      class: "wnq-alert wnq-alert-danger",
      style: { margin: "20px" },
      html: `<strong>Error:</strong> ${escapeHtml(message)}`,
    })
  );
}