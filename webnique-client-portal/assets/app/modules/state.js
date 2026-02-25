/**
 * Application State Initialization
 * 
 * @package WebNique Portal
 */

/**
 * Initialize application state from DOM and WordPress config
 * @param {HTMLElement} root - Root element
 * @returns {Object} Initial application state
 */
export function initAppState(root) {
  // Safely get attributes from root element
  const getAttr = (name, fallback = "") => {
    try {
      return root?.getAttribute(name) || fallback;
    } catch (error) {
      console.warn(`[State] Failed to get attribute ${name}:`, error);
      return fallback;
    }
  };

  // Get WordPress localized config
  const wpConfig = window.WNQ_PORTAL || {};

  // Determine client ID from multiple sources (priority order)
  const clientId =
    getAttr("data-client-id") ||
    wpConfig.clientId ||
    "";

  // Determine mode (admin vs client)
  const mode =
    getAttr("data-mode") ||
    (wpConfig.isAdmin ? "admin" : "client") ||
    "client";

  // Get REST API configuration
  const restUrl = (wpConfig.restUrl || "").replace(/\/$/, ""); // Remove trailing slash
  const nonce = wpConfig.nonce || "";
  const isAdmin = !!wpConfig.isAdmin;
  const userId = wpConfig.userId || null;

  // Brand color
  const primary = "#0d539e";

  // Initialize state object
  const state = {
    // ========================================
    // BRANDING
    // ========================================
    primary,

    // ========================================
    // USER CONTEXT
    // ========================================
    clientId,
    mode,
    isAdmin,
    userId,

    // ========================================
    // API CONFIGURATION
    // ========================================
    restUrl,
    nonce,
    restBase: restUrl, // Alias for consistency

    // ========================================
    // CLIENT METADATA
    // ========================================
    client: {
      name: "—",
      domain: "—",
      tier: "—",
      status: "unknown",
    },

    // ========================================
    // REST API RESPONSES CACHE
    // ========================================
    rest: {
      ping: null,
      client: null,
      bootstrap: null,
    },

    // ========================================
    // UI STATE
    // ========================================
    ui: {
      activeTab: "dashboard",
      loading: false,
      error: null,
    },

    // ========================================
    // ACTIONS (populated by app.js)
    // ========================================
    actions: {},

    // ========================================
    // CONFIGURATION (populated by app.js)
    // ========================================
    cfg: {},
  };

  // Validate critical configuration
  if (!restUrl) {
    console.warn("[State] Missing REST URL configuration");
  }

  if (!nonce) {
    console.warn("[State] Missing nonce configuration");
  }

  // Log initialization (development only)
  if (wpConfig.debug) {
    console.log("[State] Initialized:", {
      clientId,
      mode,
      isAdmin,
      hasRestUrl: !!restUrl,
      hasNonce: !!nonce,
    });
  }

  return state;
}

/**
 * Update client metadata in state
 * @param {Object} state - Application state
 * @param {Object} clientData - Client data from API
 */
export function updateClientData(state, clientData) {
  if (!state || !clientData) return;

  state.client = {
    name: clientData.name || state.client.name || "—",
    domain: clientData.domain || state.client.domain || "—",
    tier: clientData.tier || state.client.tier || "—",
    status: clientData.status || state.client.status || "unknown",
    ...clientData, // Spread any additional fields
  };
}

/**
 * Update UI state
 * @param {Object} state - Application state
 * @param {Object} uiState - UI state updates
 */
export function updateUIState(state, uiState) {
  if (!state || !uiState) return;

  state.ui = {
    ...state.ui,
    ...uiState,
  };
}

/**
 * Set loading state
 * @param {Object} state - Application state
 * @param {boolean} loading - Loading state
 */
export function setLoading(state, loading) {
  if (!state) return;
  
  state.ui.loading = !!loading;
}

/**
 * Set error state
 * @param {Object} state - Application state
 * @param {string|null} error - Error message
 */
export function setError(state, error) {
  if (!state) return;
  
  state.ui.error = error;
}

/**
 * Clear error state
 * @param {Object} state - Application state
 */
export function clearError(state) {
  if (!state) return;
  
  state.ui.error = null;
}

/**
 * Get state value safely with fallback
 * @param {Object} state - Application state
 * @param {string} path - Dot-notation path (e.g., "client.name")
 * @param {*} fallback - Fallback value
 * @returns {*} Value or fallback
 */
export function getStateValue(state, path, fallback = null) {
  try {
    const keys = path.split(".");
    let value = state;

    for (const key of keys) {
      if (value && typeof value === "object" && key in value) {
        value = value[key];
      } else {
        return fallback;
      }
    }

    return value;
  } catch (error) {
    console.warn(`[State] Failed to get value for path ${path}:`, error);
    return fallback;
  }
}

/**
 * Check if user has admin privileges
 * @param {Object} state - Application state
 * @returns {boolean} True if admin
 */
export function isAdmin(state) {
  return !!(state && state.isAdmin);
}

/**
 * Check if client is linked
 * @param {Object} state - Application state
 * @returns {boolean} True if client ID exists
 */
export function hasClient(state) {
  return !!(state && state.clientId);
}

/**
 * Check if REST API is configured
 * @param {Object} state - Application state
 * @returns {boolean} True if configured
 */
export function isConfigured(state) {
  return !!(state && state.restUrl && state.nonce);
}