/**
 * UI Helper Functions and Shell Mounting
 * 
 * @package WebNique Portal
 */

/**
 * Create a DOM element with properties and children
 * @param {string} tag - HTML tag name
 * @param {Object} props - Element properties
 * @param {Array} children - Child elements
 * @returns {HTMLElement} Created element
 */
export function el(tag, props = {}, children = []) {
  const node = document.createElement(tag);
  
  Object.entries(props).forEach(([k, v]) => {
    if (k === "class") {
      node.className = v;
    } else if (k === "html") {
      node.innerHTML = v;
    } else if (k === "text") {
      node.textContent = v;
    } else if (k === "style" && typeof v === "object") {
      Object.assign(node.style, v);
    } else if (k.startsWith("on") && typeof v === "function") {
      // Handle event listeners (e.g., onclick, onchange)
      const eventName = k.substring(2).toLowerCase();
      node.addEventListener(eventName, v);
    } else {
      node.setAttribute(k, v);
    }
  });
  
  // Append children
  children.forEach((child) => {
    if (child instanceof Node) {
      node.appendChild(child);
    }
  });
  
  return node;
}

/**
 * Escape HTML to prevent XSS
 * @param {string} str - String to escape
 * @returns {string} Escaped string
 */
export function escapeHtml(str) {
  return String(str)
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;")
    .replaceAll("'", "&#039;");
}

/**
 * Create a status pill/badge element
 * @param {string} text - Pill text (can include HTML)
 * @param {string} tone - Color tone (good, warn, bad, neutral)
 * @returns {HTMLElement} Pill element
 */
export function pill(text, tone = "neutral") {
  const tones = {
    good: {
      bg: "rgba(16,185,129,0.12)",
      border: "rgba(16,185,129,0.28)",
      color: "#065f46",
    },
    warn: {
      bg: "rgba(245,158,11,0.14)",
      border: "rgba(245,158,11,0.28)",
      color: "#92400e",
    },
    bad: {
      bg: "rgba(239,68,68,0.12)",
      border: "rgba(239,68,68,0.28)",
      color: "#991b1b",
    },
    neutral: {
      bg: "rgba(2,6,23,0.06)",
      border: "rgba(2,6,23,0.10)",
      color: "#0f172a",
    },
  };

  const style = tones[tone] || tones.neutral;

  return el("span", {
    style: {
      display: "inline-flex",
      alignItems: "center",
      gap: "6px",
      padding: "6px 10px",
      borderRadius: "999px",
      background: style.bg,
      border: `1px solid ${style.border}`,
      color: style.color,
      fontSize: "12px",
      lineHeight: "12px",
      fontWeight: "600",
      whiteSpace: "nowrap",
    },
    text: text,
  });
}

/**
 * Create a button element
 * @param {Object} state - Application state
 * @param {string} label - Button label
 * @param {Function} onClick - Click handler
 * @param {string} variant - Button variant (outline, primary, solid)
 * @param {boolean} disabled - Whether button is disabled
 * @returns {HTMLElement} Button element
 */
export function button(state, label, onClick, variant = "outline", disabled = false) {
  const base = {
    padding: "10px 14px",
    borderRadius: "12px",
    border: "1px solid rgba(2,6,23,0.12)",
    background: "white",
    cursor: disabled ? "not-allowed" : "pointer",
    fontWeight: "800",
    fontSize: "14px",
    opacity: disabled ? 0.55 : 1,
    userSelect: "none",
    transition: "all 0.2s ease",
  };

  const variants = {
    primary: {
      border: `1px solid rgba(13,83,158,0.30)`,
      color: state.primary || "#0d539e",
      background: "white",
    },
    solid: {
      background: state.primary || "#0d539e",
      border: `1px solid ${state.primary || "#0d539e"}`,
      color: "white",
    },
    outline: {
      border: "1px solid rgba(2,6,23,0.12)",
      background: "white",
      color: "#0f172a",
    },
  };

  const style = {
    ...base,
    ...(variants[variant] || variants.outline),
  };

  const btn = el("button", { type: "button", style });
  btn.textContent = label;

  if (disabled) {
    btn.disabled = true;
  } else {
    btn.addEventListener("click", onClick);
    
    // Add hover effect
    btn.addEventListener("mouseenter", () => {
      if (variant === "solid") {
        btn.style.opacity = "0.9";
      } else {
        btn.style.background = "rgba(2,6,23,0.04)";
      }
    });
    
    btn.addEventListener("mouseleave", () => {
      if (variant === "solid") {
        btn.style.opacity = "1";
      } else {
        btn.style.background = style.background;
      }
    });
  }

  return btn;
}

/**
 * Create a navigation button (for sidebar)
 * @param {Object} state - Application state
 * @param {string} label - Button label
 * @param {Function} onClick - Click handler
 * @returns {HTMLElement} Navigation button element
 */
function navButton(state, label, onClick) {
  const btn = el("button", {
    type: "button",
    style: {
      width: "100%",
      textAlign: "left",
      padding: "10px 12px",
      borderRadius: "12px",
      border: "1px solid rgba(2,6,23,0.10)",
      background: "white",
      cursor: "pointer",
      fontWeight: "600",
      fontSize: "14px",
      color: "#0f172a",
      userSelect: "none",
      transition: "all 0.2s ease",
    },
    text: label,
  });

  btn.addEventListener("click", onClick);
  
  // Add hover effect
  btn.addEventListener("mouseenter", () => {
    btn.style.background = "rgba(2,6,23,0.04)";
  });
  
  btn.addEventListener("mouseleave", () => {
    // Only reset if not active (will be overridden by setActiveTab)
    if (!btn.classList.contains("active")) {
      btn.style.background = "white";
    }
  });

  return btn;
}

/**
 * Render JSON in a formatted, scrollable container
 * @param {HTMLElement} outEl - Container element
 * @param {Object} json - JSON data to render
 */
export function renderJson(outEl, json) {
  if (!outEl) return;
  
  outEl.innerHTML = "";
  outEl.appendChild(
    el("pre", {
      style: {
        whiteSpace: "pre-wrap",
        background: "rgba(2,6,23,0.04)",
        padding: "12px",
        borderRadius: "12px",
        maxHeight: "520px",
        overflow: "auto",
        border: "1px solid rgba(2,6,23,0.08)",
        fontSize: "13px",
        lineHeight: "1.6",
        fontFamily: "monospace",
      },
      html: escapeHtml(JSON.stringify(json, null, 2)),
    })
  );
}

/**
 * Mount the main portal shell UI
 * @param {HTMLElement} root - Root container element
 * @param {Object} state - Application state
 * @param {Object} options - Shell options
 * @param {Function} options.onTabChange - Tab change callback
 * @returns {Object} Shell API
 */
export function mountShell(root, state, { onTabChange }) {
  if (!root) {
    throw new Error("Root element is required");
  }

  root.innerHTML = "";

  // Main wrapper with font and basic styles
  const wrap = el("div", {
    style: {
      fontFamily: "system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif",
      color: "#0f172a",
      maxWidth: "1200px",
      margin: "0 auto",
      padding: "18px 14px",
    },
  });

  // Shell grid layout (sidebar + content)
  const shell = el("div", {
    style: {
      display: "grid",
      gridTemplateColumns: "260px 1fr",
      gap: "14px",
      alignItems: "start",
    },
  });

  // ========================================
  // SIDEBAR
  // ========================================

  const sidebar = el("aside", {
    style: {
      border: "1px solid rgba(2,6,23,0.10)",
      borderRadius: "16px",
      background: "white",
      padding: "14px",
      position: "sticky",
      top: "14px",
    },
  });

  // Sidebar header
  const sideTop = el("div", { style: { marginBottom: "12px" } });
  sideTop.appendChild(
    el("div", {
      text: "NAVIGATION",
      style: {
        fontSize: "11px",
        letterSpacing: "0.08em",
        fontWeight: "900",
        color: "#64748b",
        marginBottom: "4px",
      },
    })
  );
  sideTop.appendChild(
    el("div", {
      text: "Portal",
      style: {
        fontSize: "18px",
        fontWeight: "900",
        marginTop: "6px",
      },
    })
  );
  sidebar.appendChild(sideTop);

  // Navigation buttons
  const nav = el("div", {
    style: {
      display: "flex",
      flexDirection: "column",
      gap: "10px",
    },
  });

  const tabs = [
    ["dashboard", "Dashboard"],
    ["audit", "SEO Audit"],
    ["subscription", "Subscription"],
    ["analytics", "Analytics"],
    ["requests", "Web Requests"],
    ["settings", "Settings"],
  ];

  const tabButtons = new Map();

  /**
   * Set active tab styling
   * @param {string} key - Tab key to activate
   */
  function setActiveTab(key) {
    tabButtons.forEach((btn, k) => {
      const active = k === key;

      btn.style.borderColor = active
        ? `rgba(13,83,158,0.35)`
        : "rgba(2,6,23,0.10)";
      btn.style.background = active
        ? "rgba(13,83,158,0.08)"
        : "white";
      btn.style.color = active ? state.primary : "#0f172a";
      btn.style.boxShadow = active
        ? "0 0 0 3px rgba(13,83,158,0.10)"
        : "none";
      
      // Add/remove active class for hover effect management
      if (active) {
        btn.classList.add("active");
      } else {
        btn.classList.remove("active");
      }
    });
  }

  // Create nav buttons
  tabs.forEach(([key, label]) => {
    const btn = navButton(state, label, () => {
      if (typeof onTabChange === "function") {
        onTabChange(key);
      }
    });
    tabButtons.set(key, btn);
    nav.appendChild(btn);
  });

  sidebar.appendChild(nav);

  // Sidebar metadata (client info)
  const sideMeta = el("div", {
    style: {
      marginTop: "14px",
      paddingTop: "14px",
      borderTop: "1px solid rgba(2,6,23,0.08)",
    },
  });

  sideMeta.appendChild(
    pill(`Client: <b>${escapeHtml(state.clientId || "(none)")}</b>`)
  );
  sideMeta.appendChild(el("div", { style: { height: "8px" } }));
  sideMeta.appendChild(
    pill(`Mode: <b>${escapeHtml(state.mode || "(none)")}</b>`)
  );
  
  sidebar.appendChild(sideMeta);

  // Status pill container (can be updated dynamically)
  const statusPillContainer = el("div", {
    style: {
      marginTop: "8px",
    },
  });
  sidebar.appendChild(statusPillContainer);

  // ========================================
  // CONTENT AREA
  // ========================================

  const content = el("div", {
    style: {
      display: "grid",
      gridTemplateColumns: "repeat(12, 1fr)",
      gap: "14px",
    },
  });

  // Main content area (8 columns)
  const main = el("div", { style: { gridColumn: "span 8" } });
  const mainCard = el("div", {
    style: {
      border: "1px solid rgba(2,6,23,0.10)",
      borderRadius: "16px",
      padding: "16px",
      background: "white",
      minHeight: "520px",
    },
  });
  main.appendChild(mainCard);

  // Side content area (4 columns)
  const side = el("div", { style: { gridColumn: "span 4" } });
  const sideCard = el("div", {
    style: {
      border: "1px solid rgba(2,6,23,0.10)",
      borderRadius: "16px",
      padding: "16px",
      background: "white",
    },
  });
  side.appendChild(sideCard);

  content.appendChild(main);
  content.appendChild(side);

  // ========================================
  // ASSEMBLE AND MOUNT
  // ========================================

  shell.appendChild(sidebar);
  shell.appendChild(content);
  wrap.appendChild(shell);
  root.appendChild(wrap);

  // ========================================
  // RESPONSIVE BEHAVIOR
  // ========================================

  if (window && window.matchMedia) {
    const mq = window.matchMedia("(max-width: 960px)");
    
    const applyResponsive = () => {
      const isMobile = mq.matches;
      
      shell.style.gridTemplateColumns = isMobile ? "1fr" : "260px 1fr";
      sidebar.style.position = isMobile ? "relative" : "sticky";
      sidebar.style.top = isMobile ? "auto" : "14px";
      main.style.gridColumn = isMobile ? "span 12" : "span 8";
      side.style.gridColumn = isMobile ? "span 12" : "span 4";
    };
    
    applyResponsive();
    mq.addEventListener?.("change", applyResponsive);
  }

  // ========================================
  // SHELL API
  // ========================================

  return {
    main: mainCard,
    side: sideCard,
    
    /**
     * Set active tab
     */
    setActiveTab,
    
    /**
     * Set status text in sidebar
     */
    setStatus: (text, tone = "neutral") => {
      statusPillContainer.innerHTML = "";
      statusPillContainer.appendChild(pill(escapeHtml(text), tone));
    },
    
    /**
     * Alias for setStatus (for backward compatibility)
     */
    setPill: (text, tone = "neutral") => {
      statusPillContainer.innerHTML = "";
      statusPillContainer.appendChild(pill(escapeHtml(text), tone));
    },
    
    /**
     * Update client info in sidebar
     */
    updateClientInfo: (clientId, mode) => {
      sideMeta.innerHTML = "";
      sideMeta.appendChild(
        pill(`Client: <b>${escapeHtml(clientId || "(none)")}</b>`)
      );
      sideMeta.appendChild(el("div", { style: { height: "8px" } }));
      sideMeta.appendChild(
        pill(`Mode: <b>${escapeHtml(mode || "(none)")}</b>`)
      );
    },
  };
}