// assets/app/modules/tabs/settings.js
/**
 * Settings Tab - Account & Profile Management
 * 
 * Shows: Client profile, user account, notifications, permissions
 * Data: Firestore client doc + WordPress user data
 */

import { el, pill, button, escapeHtml } from "../ui.js";

export function renderSettings(main, side, state, shell) {
  main.innerHTML = "";
  side.innerHTML = "";

  if (shell?.setStatus) {
    shell.setStatus("Loading settings...", "neutral");
  }

  // ========================================
  // HEADER
  // ========================================
  main.appendChild(createHeader());

  // ========================================
  // LOAD DATA
  // ========================================
  loadSettingsData(state).then((data) => {
    if (!data.success) {
      showError(main, data.error || "Failed to load settings");
      if (shell?.setStatus) {
        shell.setStatus("Error", "bad");
      }
      return;
    }

    if (shell?.setStatus) {
      shell.setStatus("Ready", "good");
    }

    // Render sections
    main.appendChild(createClientProfile(data.client || {}, state));
    main.appendChild(createUserAccount(data.user || {}, state));
    main.appendChild(createNotificationSettings(data.notifications || {}, state));

    // Admin-only permissions section
    if (state.isAdmin) {
      main.appendChild(createPermissions(data.users || [], state));
    }

    // Render sidebar
    renderSidebar(side, state);
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
      text: "Settings",
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
      text: "Manage your account information and preferences.",
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
 * Load settings data
 */
async function loadSettingsData(state) {
  try {
    const result = await state.actions?.get?.("/client");

    if (!result?.ok) {
      return { success: false, error: "Failed to load settings" };
    }

    return {
      success: true,
      client: result.data || {},
      user: {
        email: state.user?.email || "N/A",
        name: state.user?.name || "N/A",
      },
      notifications: result.data?.notification_settings || {},
      users: result.data?.linked_users || [],
    };
  } catch (error) {
    console.error("[Settings] Load error:", error);
    return { success: false, error: error.message };
  }
}

/**
 * Create client profile section
 */
function createClientProfile(client, state) {
  const section = el("div", { style: { marginBottom: "32px" } });

  section.appendChild(
    el("h2", {
      text: "Client Profile",
      style: {
        fontSize: "20px",
        fontWeight: "800",
        color: "#111827",
        marginBottom: "16px",
      },
    })
  );

  const card = el("div", { class: "wnq-card" });

  // Form fields
  const fields = [
    { 
      label: "Business Name", 
      value: client.name || "",
      key: "name",
      type: "text",
    },
    { 
      label: "Domain", 
      value: client.domain || "",
      key: "domain",
      type: "url",
    },
    { 
      label: "Address", 
      value: client.address || "",
      key: "address",
      type: "textarea",
    },
    { 
      label: "Phone", 
      value: client.phone || "",
      key: "phone",
      type: "tel",
    },
    { 
      label: "Primary Contact Name", 
      value: client.primary_contact || "",
      key: "primary_contact",
      type: "text",
    },
  ];

  const form = el("form", {
    style: { display: "flex", flexDirection: "column", gap: "20px" },
  });

  const formData = {};

  fields.forEach((field) => {
    const fieldGroup = el("div");

    // Label
    fieldGroup.appendChild(
      el("label", {
        class: "wnq-label",
        text: field.label,
      })
    );

    // Input
    let input;
    if (field.type === "textarea") {
      input = el("textarea", {
        class: "wnq-textarea",
        value: field.value,
        rows: "3",
      });
    } else {
      input = el("input", {
        class: "wnq-input",
        type: field.type,
        value: field.value,
      });
    }

    input.addEventListener("input", (e) => {
      formData[field.key] = e.target.value;
    });

    fieldGroup.appendChild(input);
    form.appendChild(fieldGroup);
  });

  // Save button
  const saveBtn = button(
    state,
    "Save Changes",
    async () => {
      if (Object.keys(formData).length === 0) {
        alert("No changes to save");
        return;
      }

      saveBtn.disabled = true;
      saveBtn.textContent = "Saving...";

      try {
        const result = await state.actions?.post?.("/client/update", formData);

        if (result?.ok) {
          alert("Settings saved successfully!");
        } else {
          alert("Failed to save settings. Please try again.");
        }
      } catch (error) {
        console.error("Save error:", error);
        alert("An error occurred while saving.");
      } finally {
        saveBtn.disabled = false;
        saveBtn.textContent = "Save Changes";
      }
    },
    "solid"
  );

  form.appendChild(saveBtn);
  card.appendChild(form);
  section.appendChild(card);

  return section;
}

/**
 * Create user account section
 */
function createUserAccount(user, state) {
  const section = el("div", { style: { marginBottom: "32px" } });

  section.appendChild(
    el("h2", {
      text: "User Account",
      style: {
        fontSize: "20px",
        fontWeight: "800",
        color: "#111827",
        marginBottom: "16px",
      },
    })
  );

  const card = el("div", { class: "wnq-card" });

  // Email (read-only)
  const emailGroup = el("div", { style: { marginBottom: "20px" } });
  
  emailGroup.appendChild(
    el("label", {
      class: "wnq-label",
      text: "Email (Login)",
    })
  );

  emailGroup.appendChild(
    el("input", {
      class: "wnq-input",
      type: "email",
      value: user.email || "",
      disabled: true,
      style: { background: "#f9fafb" },
    })
  );

  emailGroup.appendChild(
    el("p", {
      text: "Contact support to change your email address.",
      style: {
        fontSize: "12px",
        color: "#6b7280",
        marginTop: "4px",
      },
    })
  );

  card.appendChild(emailGroup);

  // Password reset
  const passwordGroup = el("div");
  
  passwordGroup.appendChild(
    el("label", {
      class: "wnq-label",
      text: "Password",
    })
  );

  const resetBtn = button(
    state,
    "Reset Password",
    () => {
      const resetUrl = (window.WNQ_PORTAL || {}).lostpasswordUrl || "/wp-login.php?action=lostpassword";
      window.open(resetUrl, "_blank");
    },
    "outline"
  );

  passwordGroup.appendChild(resetBtn);

  passwordGroup.appendChild(
    el("p", {
      text: "You'll receive a password reset link via email.",
      style: {
        fontSize: "12px",
        color: "#6b7280",
        marginTop: "8px",
      },
    })
  );

  card.appendChild(passwordGroup);
  section.appendChild(card);

  return section;
}

/**
 * Create notification settings section
 */
function createNotificationSettings(settings, state) {
  const section = el("div", { style: { marginBottom: "32px" } });

  section.appendChild(
    el("h2", {
      text: "Notification Preferences",
      style: {
        fontSize: "20px",
        fontWeight: "800",
        color: "#111827",
        marginBottom: "16px",
      },
    })
  );

  const card = el("div", { class: "wnq-card" });

  const notifications = [
    {
      key: "request_updates",
      label: "Web Request Updates",
      description: "Get notified when there are updates to your requests",
      default: true,
    },
    {
      key: "monthly_report",
      label: "Monthly Reports",
      description: "Receive your monthly performance report via email",
      default: true,
    },
    {
      key: "billing_alerts",
      label: "Billing Alerts",
      description: "Important notifications about payments and invoices",
      default: true,
    },
    {
      key: "product_updates",
      label: "Product Updates",
      description: "Learn about new features and improvements",
      default: false,
    },
  ];

  const formData = {};

  notifications.forEach((notif, index) => {
    const row = el("div", {
      style: {
        display: "flex",
        justifyContent: "space-between",
        alignItems: "center",
        padding: "16px 0",
        borderBottom: index < notifications.length - 1 ? "1px solid #e5e7eb" : "none",
      },
    });

    const info = el("div", { style: { flex: "1" } });
    
    info.appendChild(
      el("div", {
        text: notif.label,
        style: {
          fontSize: "15px",
          fontWeight: "600",
          color: "#111827",
          marginBottom: "4px",
        },
      })
    );

    info.appendChild(
      el("div", {
        text: notif.description,
        style: {
          fontSize: "13px",
          color: "#6b7280",
        },
      })
    );

    row.appendChild(info);

    // Toggle switch (simplified - use checkbox for now)
    const checkbox = el("input", {
      type: "checkbox",
      checked: settings[notif.key] !== undefined ? settings[notif.key] : notif.default,
      style: {
        width: "20px",
        height: "20px",
        cursor: "pointer",
      },
    });

    checkbox.addEventListener("change", (e) => {
      formData[notif.key] = e.target.checked;
    });

    row.appendChild(checkbox);
    card.appendChild(row);
  });

  // Save button
  const saveBtn = button(
    state,
    "Save Preferences",
    async () => {
      if (Object.keys(formData).length === 0) {
        alert("No changes to save");
        return;
      }

      saveBtn.disabled = true;
      saveBtn.textContent = "Saving...";

      try {
        const result = await state.actions?.post?.("/notifications/update", formData);

        if (result?.ok) {
          alert("Preferences saved successfully!");
        } else {
          alert("Failed to save preferences. Please try again.");
        }
      } catch (error) {
        console.error("Save error:", error);
        alert("An error occurred while saving.");
      } finally {
        saveBtn.disabled = false;
        saveBtn.textContent = "Save Preferences";
      }
    },
    "solid"
  );

  saveBtn.style.marginTop = "20px";
  card.appendChild(saveBtn);
  section.appendChild(card);

  return section;
}

/**
 * Create permissions section (admin only)
 */
function createPermissions(users, state) {
  const section = el("div", { style: { marginBottom: "32px" } });

  section.appendChild(
    el("h2", {
      text: "User Permissions",
      style: {
        fontSize: "20px",
        fontWeight: "800",
        color: "#111827",
        marginBottom: "8px",
      },
    })
  );

  section.appendChild(
    el("p", {
      text: "Manage users who have access to this portal (Admin only)",
      style: {
        fontSize: "14px",
        color: "#6b7280",
        marginBottom: "16px",
      },
    })
  );

  const card = el("div", { class: "wnq-card", style: { padding: "0" } });

  if (users.length === 0) {
    card.appendChild(
      el("p", {
        text: "No additional users configured.",
        style: {
          padding: "40px 20px",
          textAlign: "center",
          color: "#6b7280",
        },
      })
    );
  } else {
    const table = el("table", { class: "wnq-table" });

    // Header
    const thead = el("thead");
    const headerRow = el("tr");
    ["Name", "Email", "Role", "Status"].forEach((label) => {
      headerRow.appendChild(el("th", { text: label }));
    });
    thead.appendChild(headerRow);
    table.appendChild(thead);

    // Body
    const tbody = el("tbody");
    users.forEach((user) => {
      const row = el("tr");

      row.appendChild(el("td", { text: escapeHtml(user.name || "—") }));
      row.appendChild(el("td", { text: escapeHtml(user.email || "—") }));
      row.appendChild(el("td", { text: escapeHtml(user.role || "User") }));

      const statusCell = el("td");
      statusCell.appendChild(
        pill(user.status || "active", user.status === "active" ? "good" : "neutral")
      );
      row.appendChild(statusCell);

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
function renderSidebar(side, state) {
  // Account info card
  const infoCard = el("div", {
    style: {
      background: "white",
      border: "1px solid #e5e7eb",
      borderRadius: "16px",
      padding: "20px",
      marginBottom: "20px",
    },
  });

  infoCard.appendChild(
    el("h3", {
      text: "Account Information",
      style: {
        fontSize: "16px",
        fontWeight: "800",
        color: "#111827",
        marginBottom: "16px",
      },
    })
  );

  const info = [
    { label: "Client ID", value: state.clientId || "N/A" },
    { label: "Account Type", value: state.mode === "admin" ? "Administrator" : "Client" },
    { label: "Portal Access", value: "Full Access" },
  ];

  info.forEach((item) => {
    const row = el("div", {
      style: {
        padding: "8px 0",
        fontSize: "14px",
      },
    });

    row.appendChild(
      el("div", {
        text: item.label,
        style: {
          color: "#6b7280",
          fontSize: "12px",
          marginBottom: "4px",
        },
      })
    );

    row.appendChild(
      el("div", {
        text: escapeHtml(item.value),
        style: {
          fontWeight: "600",
          color: "#111827",
        },
      })
    );

    infoCard.appendChild(row);
  });

  side.appendChild(infoCard);

  // Help card
  const helpCard = el("div", {
    style: {
      background: "#f0f9ff",
      border: "1px solid #bfdbfe",
      borderRadius: "16px",
      padding: "20px",
    },
  });

  helpCard.appendChild(
    el("div", {
      text: "ℹ️",
      style: { fontSize: "32px", marginBottom: "12px" },
    })
  );

  helpCard.appendChild(
    el("h3", {
      text: "Need Help?",
      style: {
        fontSize: "16px",
        fontWeight: "800",
        color: "#1e3a8a",
        marginBottom: "8px",
      },
    })
  );

  helpCard.appendChild(
    el("p", {
      text: "If you need to make changes that aren't available here, please contact our support team.",
      style: {
        fontSize: "13px",
        color: "#1e40af",
        lineHeight: "1.6",
      },
    })
  );

  side.appendChild(helpCard);
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