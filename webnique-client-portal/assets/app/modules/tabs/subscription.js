// assets/app/modules/tabs/subscription.js
/**
 * Subscription Tab - Billing & Plan Management
 * 
 * Shows: Current plan, services included, billing history, manage subscription
 * Data: Stripe (via server) + Firestore cache
 */

import { el, pill, button, escapeHtml } from "../ui.js";

export function renderSubscription(main, side, state, shell) {
  main.innerHTML = "";
  side.innerHTML = "";

  // Update shell status
  if (shell?.setStatus) {
    shell.setStatus("Loading subscription...", "neutral");
  }

  // ========================================
  // HEADER
  // ========================================
  main.appendChild(createHeader());

  // ========================================
  // LOAD DATA
  // ========================================
  loadSubscriptionData(state).then((data) => {
    if (!data.success) {
      showError(main, data.error || "Failed to load subscription");
      if (shell?.setStatus) {
        shell.setStatus("Error", "bad");
      }
      return;
    }

    if (shell?.setStatus) {
      shell.setStatus("Ready", "good");
    }

    // Render current plan
    main.appendChild(createCurrentPlan(data.subscription || {}, state));

    // Render services included
    main.appendChild(createServicesIncluded(data.subscription?.services || []));

    // Render billing history
    main.appendChild(createBillingHistory(data.invoices || []));

    // Render sidebar
    renderSidebar(side, data.subscription || {}, state);
  });
}

/**
 * Create header
 */
function createHeader() {
  const header = el("div", {
    style: {
      marginBottom: "32px",
      paddingBottom: "20px",
      borderBottom: "2px solid rgba(2,6,23,0.08)",
    },
  });

  header.appendChild(
    el("h1", {
      text: "Subscription & Billing",
      style: {
        fontSize: "32px",
        fontWeight: "900",
        color: "#111827",
        marginBottom: "8px",
      },
    })
  );

  header.appendChild(
    el("p", {
      text: "Manage your plan, billing, and payment methods.",
      style: {
        fontSize: "16px",
        color: "#6b7280",
        margin: "0",
      },
    })
  );

  return header;
}

/**
 * Load subscription data
 */
async function loadSubscriptionData(state) {
  try {
    const [subRes, invoicesRes] = await Promise.all([
      state.actions?.get?.("/subscription") || Promise.resolve({ ok: false }),
      state.actions?.get?.("/invoices") || Promise.resolve({ ok: false }),
    ]);

    if (!subRes.ok) {
      return { success: false, error: "Failed to load subscription data" };
    }

    return {
      success: true,
      subscription: subRes.data || {},
      invoices: invoicesRes.ok ? invoicesRes.data || [] : [],
    };
  } catch (error) {
    console.error("[Subscription] Load error:", error);
    return { success: false, error: error.message };
  }
}

/**
 * Create current plan section
 */
function createCurrentPlan(subscription, state) {
  const section = el("div", { style: { marginBottom: "32px" } });

  section.appendChild(
    el("h2", {
      text: "Current Plan",
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
      background: "linear-gradient(135deg, #0d539e 0%, #0a4380 100%)",
      borderRadius: "16px",
      padding: "32px",
      color: "white",
      position: "relative",
      overflow: "hidden",
    },
  });

  // Decorative elements
  card.appendChild(
    el("div", {
      style: {
        position: "absolute",
        top: "-50px",
        right: "-50px",
        width: "200px",
        height: "200px",
        background: "rgba(255,255,255,0.1)",
        borderRadius: "50%",
      },
    })
  );

  // Plan name
  card.appendChild(
    el("div", {
      text: escapeHtml(subscription.plan_name || "Standard Plan"),
      style: {
        fontSize: "32px",
        fontWeight: "900",
        marginBottom: "16px",
        position: "relative",
      },
    })
  );

  // Price
  const price = subscription.price || "$0";
  const cycle = subscription.billing_cycle || "month";
  
  card.appendChild(
    el("div", {
      style: {
        fontSize: "48px",
        fontWeight: "900",
        marginBottom: "24px",
        position: "relative",
      },
      html: `${escapeHtml(price)}<span style="font-size: 20px; font-weight: 600; opacity: 0.8;">/${escapeHtml(cycle)}</span>`,
    })
  );

  // Details grid
  const grid = el("div", {
    style: {
      display: "grid",
      gridTemplateColumns: "repeat(2, 1fr)",
      gap: "16px",
      position: "relative",
    },
  });

  const details = [
    { 
      label: "Status", 
      value: subscription.status || "active",
      pill: true,
    },
    { 
      label: "Billing Cycle", 
      value: formatBillingCycle(subscription.billing_cycle),
    },
    { 
      label: "Next Renewal", 
      value: formatDate(subscription.renewal_date),
    },
    { 
      label: "Payment Method", 
      value: subscription.payment_method || "•••• 4242",
    },
  ];

  details.forEach((detail) => {
    const item = el("div", {});
    
    item.appendChild(
      el("div", {
        text: detail.label,
        style: {
          fontSize: "12px",
          opacity: "0.8",
          marginBottom: "4px",
          fontWeight: "600",
          textTransform: "uppercase",
          letterSpacing: "0.05em",
        },
      })
    );

    if (detail.pill) {
      item.appendChild(
        pill(
          escapeHtml(detail.value),
          detail.value === "active" ? "good" : "warn"
        )
      );
    } else {
      item.appendChild(
        el("div", {
          text: escapeHtml(detail.value),
          style: {
            fontSize: "16px",
            fontWeight: "600",
          },
        })
      );
    }

    grid.appendChild(item);
  });

  card.appendChild(grid);
  section.appendChild(card);

  return section;
}

/**
 * Create services included section
 */
function createServicesIncluded(services) {
  const section = el("div", { style: { marginBottom: "32px" } });

  section.appendChild(
    el("h2", {
      text: "Services Included",
      style: {
        fontSize: "20px",
        fontWeight: "800",
        color: "#111827",
        marginBottom: "16px",
      },
    })
  );

  const card = el("div", { class: "wnq-card" });

  if (!services || services.length === 0) {
    card.appendChild(
      el("p", {
        text: "No services data available. Contact WebNique to review your plan.",
        style: { color: "#6b7280", fontSize: "14px", padding: "16px 0" },
      })
    );
    section.appendChild(card);
    return section;
  }

  services.forEach((service, index) => {
    const item = el("div", {
      style: {
        display: "flex",
        alignItems: "center",
        justifyContent: "space-between",
        padding: "16px 0",
        borderBottom: index < services.length - 1 ? "1px solid #e5e7eb" : "none",
      },
    });

    // Service info
    const info = el("div", { style: { flex: "1" } });
    
    info.appendChild(
      el("div", {
        text: escapeHtml(service.name),
        style: {
          fontSize: "15px",
          fontWeight: "600",
          color: "#111827",
          marginBottom: "4px",
        },
      })
    );

    if (service.description) {
      info.appendChild(
        el("div", {
          text: escapeHtml(service.description),
          style: {
            fontSize: "13px",
            color: "#6b7280",
          },
        })
      );
    }

    item.appendChild(info);

    // Status indicator
    item.appendChild(
      el("div", {
        text: service.included ? "✓ Included" : "✗ Not Included",
        style: {
          fontSize: "14px",
          fontWeight: "600",
          color: service.included ? "#10b981" : "#9ca3af",
        },
      })
    );

    card.appendChild(item);
  });

  section.appendChild(card);
  return section;
}

/**
 * Create billing history section
 */
function createBillingHistory(invoices) {
  const section = el("div", { style: { marginBottom: "32px" } });

  section.appendChild(
    el("h2", {
      text: "Billing History",
      style: {
        fontSize: "20px",
        fontWeight: "800",
        color: "#111827",
        marginBottom: "16px",
      },
    })
  );

  const card = el("div", { class: "wnq-card", style: { padding: "0" } });

  if (!invoices || invoices.length === 0) {
    card.appendChild(
      el("p", {
        text: "No billing history available.",
        style: {
          color: "#6b7280",
          textAlign: "center",
          padding: "40px 20px",
        },
      })
    );
  } else {
    const table = el("table", { class: "wnq-table" });

    // Header
    const thead = el("thead");
    const headerRow = el("tr");
    
    ["Date", "Description", "Amount", "Status", "Action"].forEach((label) => {
      headerRow.appendChild(el("th", { text: label }));
    });
    
    thead.appendChild(headerRow);
    table.appendChild(thead);

    // Body
    const tbody = el("tbody");
    
    invoices.slice(0, 10).forEach((invoice) => {
      const row = el("tr");

      // Date
      row.appendChild(
        el("td", { text: formatDate(invoice.date) })
      );

      // Description
      row.appendChild(
        el("td", { text: escapeHtml(invoice.description || "Monthly subscription") })
      );

      // Amount
      row.appendChild(
        el("td", { 
          text: escapeHtml(invoice.amount || "$0.00"),
          style: { fontWeight: "600" },
        })
      );

      // Status
      const statusCell = el("td");
      statusCell.appendChild(
        pill(
          escapeHtml(invoice.status || "paid"),
          invoice.status === "paid" ? "good" : 
          invoice.status === "pending" ? "warn" : "bad"
        )
      );
      row.appendChild(statusCell);

      // Action
      const actionCell = el("td");
      if (invoice.invoice_url) {
        const link = el("a", {
          text: "Download",
          href: invoice.invoice_url,
          target: "_blank",
          style: {
            color: "#0d539e",
            fontWeight: "600",
            textDecoration: "none",
          },
        });
        actionCell.appendChild(link);
      } else {
        actionCell.appendChild(
          el("span", { 
            text: "—",
            style: { color: "#9ca3af" },
          })
        );
      }
      row.appendChild(actionCell);

      tbody.appendChild(row);
    });

    table.appendChild(tbody);
    card.appendChild(table);
  }

  section.appendChild(card);
  return section;
}

/**
 * Render sidebar
 */
function renderSidebar(side, subscription, state) {
  // Manage subscription card
  const manageCard = el("div", {
    style: {
      background: "white",
      border: "1px solid #e5e7eb",
      borderRadius: "16px",
      padding: "24px",
      marginBottom: "20px",
    },
  });

  manageCard.appendChild(
    el("h3", {
      text: "Manage Subscription",
      style: {
        fontSize: "18px",
        fontWeight: "800",
        color: "#111827",
        marginBottom: "16px",
      },
    })
  );

  manageCard.appendChild(
    el("p", {
      text: "Update your payment method, view invoices, or make changes to your plan.",
      style: {
        fontSize: "14px",
        color: "#6b7280",
        marginBottom: "20px",
        lineHeight: "1.6",
      },
    })
  );

  const portalBtn = button(
    state,
    "Open Billing Portal",
    async () => {
      portalBtn.disabled = true;
      portalBtn.textContent = "Opening...";

      try {
        const result = await state.actions?.post?.("/billing-portal-session");
        
        if (result?.ok && result?.url) {
          window.open(result.url, "_blank");
        } else {
          alert("Failed to open billing portal. Please try again.");
        }
      } catch (error) {
        console.error("Billing portal error:", error);
        alert("An error occurred. Please try again.");
      } finally {
        portalBtn.disabled = false;
        portalBtn.textContent = "Open Billing Portal";
      }
    },
    "solid"
  );

  portalBtn.style.width = "100%";
  manageCard.appendChild(portalBtn);

  side.appendChild(manageCard);

  // Plan summary card
  const summaryCard = el("div", {
    style: {
      background: "#f9fafb",
      border: "1px solid #e5e7eb",
      borderRadius: "16px",
      padding: "20px",
    },
  });

  summaryCard.appendChild(
    el("h3", {
      text: "Plan Summary",
      style: {
        fontSize: "16px",
        fontWeight: "800",
        color: "#111827",
        marginBottom: "16px",
      },
    })
  );

  const summaryItems = [
    { label: "Started", value: formatDate(subscription.start_date) },
    { label: "Renewals", value: subscription.renewal_count || "0" },
    { label: "Total Paid", value: subscription.total_paid || "$0.00" },
  ];

  summaryItems.forEach((item) => {
    const row = el("div", {
      style: {
        display: "flex",
        justifyContent: "space-between",
        padding: "8px 0",
        fontSize: "14px",
      },
    });

    row.appendChild(
      el("span", { 
        text: item.label,
        style: { color: "#6b7280" },
      })
    );

    row.appendChild(
      el("span", { 
        text: escapeHtml(item.value),
        style: { fontWeight: "600", color: "#111827" },
      })
    );

    summaryCard.appendChild(row);
  });

  side.appendChild(summaryCard);
}

/**
 * Format billing cycle
 */
function formatBillingCycle(cycle) {
  if (!cycle) return "Monthly";
  
  const cycles = {
    month: "Monthly",
    quarterly: "Quarterly",
    year: "Annual",
    annual: "Annual",
  };

  return cycles[cycle.toLowerCase()] || cycle;
}

/**
 * Format date
 */
function formatDate(timestamp) {
  if (!timestamp) return "N/A";
  
  try {
    const date = new Date(timestamp);
    return date.toLocaleDateString('en-US', { 
      month: 'short', 
      day: 'numeric', 
      year: 'numeric' 
    });
  } catch (e) {
    return "N/A";
  }
}

/**
 * Show error
 */
function showError(container, message) {
  container.innerHTML = "";
  container.appendChild(
    el("div", {
      class: "wnq-alert wnq-alert-danger",
      html: `<strong>Error:</strong> ${escapeHtml(message)}`,
    })
  );
}