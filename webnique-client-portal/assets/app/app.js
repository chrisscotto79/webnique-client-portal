/**
 * WebNique Portal - Main Application Entry Point
 * 
 * @package WebNique Portal
 * @requires WordPress REST API
 * @requires wp_localize_script with WNQ_PORTAL config
 */

// Import modules
import { initAppState } from "./modules/state.js";
import { mountShell } from "./modules/ui.js";

// Import tab renderers
import { renderDashboard } from "./modules/tabs/dashboard.js";
import { renderSubscription } from "./modules/tabs/subscription.js";
import { renderAnalytics } from "./modules/tabs/analytics.js";
import { renderRequests } from "./modules/tabs/requests.js";
import { renderSettings } from "./modules/tabs/settings.js";

/**
 * Self-executing function to avoid global scope pollution
 */
(function () {
  "use strict";

  // ========================================
  // 1. INITIALIZATION & VALIDATION
  // ========================================

  const root = document.getElementById("wnq-portal-root");
  
  // Exit early if root element doesn't exist
  if (!root) {
    console.warn("[WNQ Portal] Root element #wnq-portal-root not found");
    return;
  }

  // Get WordPress localized config
  const cfg = window.WNQ_PORTAL || {};
  
  // Validate required configuration
  if (!cfg.restUrl || !cfg.nonce) {
    console.error("[WNQ Portal] Missing required config (restUrl or nonce)");
    root.innerHTML = `
      <div style="padding: 20px; background: #fef2f2; border: 1px solid #fca5a5; border-radius: 12px; color: #991b1b;">
        <strong>Configuration Error:</strong> Portal not properly configured. Please contact support.
      </div>
    `;
    return;
  }

  // Clean up REST URL (remove trailing slash)
  const restBase = (cfg.restUrl || "").replace(/\/$/, "");
  const nonce = cfg.nonce || "";
  const isAdmin = !!cfg.isAdmin;

  // ========================================
  // 2. STATE INITIALIZATION
  // ========================================

  // Initialize shared application state
  const state = initAppState(root);

  // Merge WordPress config into state
  state.cfg = {
    restBase,
    nonce,
    isAdmin,
  };

  // Determine client ID from multiple sources (priority order)
  const domClientId = root.getAttribute("data-client-id") || "";
  const wpClientId = cfg.clientId || "";
  state.clientId = domClientId || wpClientId || "";

  // Store user ID if available
  state.userId = cfg.userId || null;

  // ========================================
  // 3. API HELPERS
  // ========================================

  /**
   * Safely parse JSON, return null on failure
   * @param {string} text - Text to parse
   * @returns {Object|null} Parsed object or null
   */
  function safeJsonParse(text) {
    try {
      return JSON.parse(text);
    } catch (error) {
      console.error("[WNQ Portal] JSON parse error:", error);
      return null;
    }
  }

  /**
   * Make GET request to REST API
   * @param {string} path - API endpoint path
   * @returns {Promise<Object>} Response object
   */
  async function apiGet(path) {
    if (!restBase || !nonce) {
      return {
        ok: false,
        status: 0,
        error: "Missing REST configuration",
      };
    }

    const url = `${restBase}${path.startsWith("/") ? "" : "/"}${path}`;

    try {
      const res = await fetch(url, {
        method: "GET",
        credentials: "same-origin",
        headers: {
          "X-WP-Nonce": nonce,
          "Content-Type": "application/json",
        },
      });

      const text = await res.text();
      const json = safeJsonParse(text);

      if (!json) {
        return {
          ok: false,
          status: res.status,
          error: "Invalid JSON response",
          raw: text.substring(0, 200), // Limit raw text length
        };
      }

      return {
        ...json,
        ok: res.ok,
        status: res.status,
      };
    } catch (error) {
      console.error("[WNQ Portal] API GET error:", error);
      return {
        ok: false,
        status: 0,
        error: error.message || "Network error",
      };
    }
  }

  /**
   * Make POST request to REST API
   * @param {string} path - API endpoint path
   * @param {Object} body - Request body
   * @returns {Promise<Object>} Response object
   */
  async function apiPost(path, body = {}) {
    if (!restBase || !nonce) {
      return {
        ok: false,
        status: 0,
        error: "Missing REST configuration",
      };
    }

    const url = `${restBase}${path.startsWith("/") ? "" : "/"}${path}`;

    try {
      const res = await fetch(url, {
        method: "POST",
        credentials: "same-origin",
        headers: {
          "X-WP-Nonce": nonce,
          "Content-Type": "application/json",
        },
        body: JSON.stringify(body),
      });

      const text = await res.text();
      const json = safeJsonParse(text);

      if (!json) {
        return {
          ok: false,
          status: res.status,
          error: "Invalid JSON response",
          raw: text.substring(0, 200),
        };
      }

      return {
        ...json,
        ok: res.ok,
        status: res.status,
      };
    } catch (error) {
      console.error("[WNQ Portal] API POST error:", error);
      return {
        ok: false,
        status: 0,
        error: error.message || "Network error",
      };
    }
  }

  // ========================================
  // 4. EXPOSE DEBUG API (Development Only)
  // ========================================

  if (typeof window !== "undefined") {
    window.WNQ_APP = {
      version: "1.0.0",
      state,
      api: {
        get: apiGet,
        post: apiPost,
      },
      reload: () => window.location.reload(),
    };
  }

  // ========================================
  // 5. MOUNT UI SHELL
  // ========================================

  let shell;
  
  try {
    shell = mountShell(root, state, {
      onTabChange: (key) => renderTab(key),
    });
  } catch (error) {
    console.error("[WNQ Portal] Shell mount error:", error);
    root.innerHTML = `
      <div style="padding: 20px; background: #fef2f2; border: 1px solid #fca5a5; border-radius: 12px; color: #991b1b;">
        <strong>Mount Error:</strong> Failed to initialize portal interface.
      </div>
    `;
    return;
  }

  // ========================================
  // 6. TAB ROUTING
  // ========================================

  // Map tab keys to render functions
  const tabs = {
    dashboard: renderDashboard,
    subscription: renderSubscription,
    analytics: renderAnalytics,
    requests: renderRequests,
    settings: renderSettings,
  };

  /**
   * Render a specific tab
   * @param {string} key - Tab key to render
   */
  function renderTab(key) {
    // Get render function (fallback to dashboard)
    const renderFn = tabs[key] || tabs.dashboard;

    // Update active tab in UI
    if (shell && typeof shell.setActiveTab === "function") {
      shell.setActiveTab(key);
    }

    // Clear content areas
    if (shell?.main) shell.main.innerHTML = "";
    if (shell?.side) shell.side.innerHTML = "";

    try {
      // Render the selected tab
      renderFn(shell.main, shell.side, state, shell);
    } catch (error) {
      console.error(`[WNQ Portal] Tab render error (${key}):`, error);
      
      // Show error message in main area
      if (shell?.main) {
        shell.main.innerHTML = `
          <div style="padding: 20px; background: #fef2f2; border: 1px solid #fca5a5; border-radius: 12px; color: #991b1b;">
            <strong>Render Error:</strong> Failed to load ${key} tab.
          </div>
        `;
      }
    }
  }

  // ========================================
  // 7. INITIAL BOOT SEQUENCE
  // ========================================

  /**
   * Bootstrap application with initial data
   */
  (async function boot() {
    console.log("[WNQ Portal] Booting application...");

    try {
      // Step 1: Ping REST API to verify connection and permissions
      const ping = await apiGet("/ping");
      state.rest = state.rest || {};
      state.rest.ping = ping;

      if (ping?.ok) {
        console.log("[WNQ Portal] REST API connection verified");
      } else {
        console.warn("[WNQ Portal] REST API connection failed:", ping);
      }

      // Update UI status if supported
      if (shell && typeof shell.setStatus === "function") {
        shell.setStatus(ping?.ok ? "ready" : "error");
      }

      // Step 2: Load client document if client ID exists
      if (state.clientId) {
        const client = await apiGet("/client");
        state.rest.client = client;

        if (client?.ok && client?.exists) {
          console.log("[WNQ Portal] Client document loaded");
          
          // Update state with client data
          if (client.client) {
            state.client = {
              name: client.client.name || "—",
              domain: client.client.domain || "—",
              tier: client.client.tier || "—",
            };
          }
        } else {
          console.warn("[WNQ Portal] Client document not found or error:", client);
        }

        // Update UI pill if supported
        if (shell && typeof shell.setPill === "function") {
          if (client?.ok && client?.exists) {
            shell.setPill("Client Active");
          } else if (client?.ok && !client?.exists) {
            shell.setPill("No Client Doc");
          } else {
            shell.setPill("Client Error");
          }
        }
      } else {
        console.log("[WNQ Portal] No client ID available");
      }

      // Step 3: Provide common actions to state for tabs to use
      state.actions = {
        /**
         * Ping REST API
         */
        ping: async () => {
          const result = await apiGet("/ping");
          state.rest.ping = result;
          return result;
        },

        /**
         * Load client document
         */
        loadClient: async () => {
          const result = await apiGet("/client");
          state.rest.client = result;
          return result;
        },

        /**
         * Bootstrap client (admin only)
         */
        bootstrapClient: async () => {
          if (!state.clientId) {
            return {
              ok: false,
              error: "No client ID available",
            };
          }

          const result = await apiPost("/clients/bootstrap", {
            client_id: state.clientId,
          });
          
          state.rest.bootstrap = result;
          return result;
        },

        /**
         * Generic GET helper
         */
        get: apiGet,

        /**
         * Generic POST helper
         */
        post: apiPost,
      };

      // Step 4: Render initial tab (dashboard)
      console.log("[WNQ Portal] Rendering initial view");
      renderTab("dashboard");

      console.log("[WNQ Portal] Boot complete");
    } catch (error) {
      console.error("[WNQ Portal] Boot error:", error);
      
      if (shell?.main) {
        shell.main.innerHTML = `
          <div style="padding: 20px; background: #fef2f2; border: 1px solid #fca5a5; border-radius: 12px; color: #991b1b;">
            <strong>Boot Error:</strong> Failed to initialize portal. Please refresh the page.
          </div>
        `;
      }
    }
  })();
})();
