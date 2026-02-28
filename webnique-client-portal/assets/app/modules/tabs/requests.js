// assets/app/modules/tabs/requests.js
/**
 * Web Requests Tab - Production Ready
 * 
 * Features:
 * - Proper lifecycle management (mount/unmount)
 * - Clean Firebase subscription handling
 * - No memory leaks
 * - Proper sidebar restoration
 * - Thread list with real-time updates
 * - Message view with live chat
 * - New thread creation modal
 * 
 * @version 2.0.0
 */

import { el, pill, button, escapeHtml } from "../ui.js";
import {
  initFirebase,
  getThreads,
  getMessages,
  sendMessage,
  createThread,
  subscribeToThreads,
  subscribeToMessages,
  markThreadAsRead,
} from "../services/firebase.js";

// ============================================================================
// MODULE STATE (Encapsulated - no global pollution)
// ============================================================================

let activeInstance = null;

/**
 * Create a new requests instance with isolated state
 */
function createRequestsInstance() {
  return {
    // Lifecycle
    mounted: false,
    destroyed: false,
    
    // Firebase subscriptions
    threadsUnsubscribe: null,
    messagesUnsubscribe: null,
    
    // UI state
    currentThreadId: null,
    threads: [],
    messages: [],
    
    // Loading states
    isLoadingThreads: false,
    isLoadingMessages: false,
    
    // DOM references
    containers: {
      main: null,
      side: null,
      statsContainer: null,
      threadListContainer: null,
      messageViewContainer: null,
    },
    
    // Original sidebar state (for restoration)
    originalSidebarDisplay: null,
    originalMainGridColumn: null,
  };
}

// ============================================================================
// PUBLIC API - Main Entry Point
// ============================================================================

/**
 * Render the requests tab
 * This is called by the router when switching to this tab
 * 
 * @param {HTMLElement} main - Main content area
 * @param {HTMLElement} side - Sidebar area
 * @param {Object} state - Global application state
 * @param {Object} shell - Shell interface
 */
export function renderRequests(main, side, state, shell) {
  console.log("[Requests] Mounting tab");
  
  // Step 1: Cleanup any previous instance
  if (activeInstance) {
    console.log("[Requests] Cleaning up previous instance");
    cleanup(activeInstance);
  }
  
  // Step 2: Create new instance
  const instance = createRequestsInstance();
  activeInstance = instance;
  
  // Step 3: Store DOM references
  instance.containers.main = main;
  instance.containers.side = side;
  
  // Step 4: Save original sidebar state
  if (side && side.parentElement) {
    instance.originalSidebarDisplay = side.parentElement.style.display || "block";
    instance.originalMainGridColumn = main?.parentElement?.style.gridColumn || "";
  }
  
  // Step 5: Clear containers
  if (main) main.innerHTML = "";
  if (side) side.innerHTML = "";
  
  // Step 6: Hide sidebar for full-width layout
  if (side && side.parentElement) {
    side.parentElement.style.display = "none";
  }
  
  // Step 7: Expand main area to full width
  if (main && main.parentElement) {
    main.parentElement.style.gridColumn = "span 12";
  }
  
  // Step 8: Update shell status
  if (shell?.setStatus) {
    shell.setStatus("Loading requests...", "neutral");
  }
  
  // Step 9: Initialize and load data
  instance.mounted = true;
  initializeTab(instance, state, shell);
  
  // Step 10: Return cleanup function (for future use)
  return () => cleanup(instance);
}

// ============================================================================
// INITIALIZATION & DATA LOADING
// ============================================================================

/**
 * Initialize the tab and load data
 */
async function initializeTab(instance, state, shell) {
  const { main } = instance.containers;
  
  if (!main) {
    console.error("[Requests] No main container");
    return;
  }
  
  // Show loading spinner
  showLoadingSpinner(main);
  
  try {
    // Step 1: Initialize Firebase (safe to call multiple times)
    console.log("[Requests] Initializing Firebase...");
    const firebaseReady = await initFirebase();
    
    if (!firebaseReady) {
      throw new Error("Failed to initialize Firebase");
    }
    
    console.log("[Requests] Firebase ready");
    
    // Step 2: Check if we have a client ID
    if (!state.clientId) {
      throw new Error("No client ID available");
    }
    
    // Step 3: Load threads
    console.log("[Requests] Loading threads for client:", state.clientId);
    instance.isLoadingThreads = true;
    
    const threads = await getThreads(state.clientId);
    instance.threads = threads;
    instance.isLoadingThreads = false;
    
    console.log("[Requests] Loaded", threads.length, "threads");
    
    // Step 4: Render interface
    if (instance.destroyed) {
      console.log("[Requests] Instance destroyed during load, aborting");
      return;
    }
    
    renderInterface(instance, state, shell);
    
    // Step 5: Subscribe to real-time updates
    instance.threadsUnsubscribe = subscribeToThreads(state.clientId, (updatedThreads) => {
      if (instance.destroyed) {
        console.log("[Requests] Instance destroyed, ignoring thread update");
        return;
      }
      
      console.log("[Requests] Threads updated:", updatedThreads.length);
      instance.threads = updatedThreads;
      
      // Update UI
      updateThreadsInUI(instance, state, shell);
    });
    
    // Step 6: Update shell status
    if (shell?.setStatus) {
      shell.setStatus("Ready", "good");
    }
    
    console.log("[Requests] Tab fully loaded");
    
  } catch (error) {
    console.error("[Requests] Initialization error:", error);
    
    if (instance.destroyed) {
      console.log("[Requests] Instance destroyed during error, skipping error display");
      return;
    }
    
    showError(main, error.message || "Failed to load requests");
    
    if (shell?.setStatus) {
      shell.setStatus("Error", "bad");
    }
  }
}

// ============================================================================
// UI RENDERING
// ============================================================================

/**
 * Render the complete interface
 */
function renderInterface(instance, state, shell) {
  const { main } = instance.containers;
  
  if (!main || instance.destroyed) return;
  
  main.innerHTML = "";
  
  // Container for everything
  const container = el("div", {
    style: {
      display: "flex",
      flexDirection: "column",
      gap: "24px",
      height: "100%",
    },
  });
  
  // Header
  container.appendChild(createHeader(instance, state, shell));
  
  // Stats cards
  const statsContainer = el("div", { id: "requests-stats" });
  statsContainer.appendChild(createStatsCards(instance.threads));
  instance.containers.statsContainer = statsContainer;
  container.appendChild(statsContainer);
  
  // Main layout (thread list + message view)
  const layout = el("div", {
    id: "requests-layout",
    style: {
      display: "grid",
      gridTemplateColumns: "360px 1fr",
      gap: "20px",
      flex: "1",
      minHeight: "0", // Allow proper scrolling
    },
  });
  
  // Thread list column
  const threadListWrapper = el("div", {
    id: "requests-thread-list",
    style: {
      background: "white",
      border: "1px solid #e5e7eb",
      borderRadius: "12px",
      overflow: "hidden",
      display: "flex",
      flexDirection: "column",
      minHeight: "0",
    },
  });
  instance.containers.threadListContainer = threadListWrapper;
  
  // Message view column
  const messageViewWrapper = el("div", {
    id: "requests-message-view",
    style: {
      background: "white",
      border: "1px solid #e5e7eb",
      borderRadius: "12px",
      overflow: "hidden",
      display: "flex",
      flexDirection: "column",
      minHeight: "0",
    },
  });
  instance.containers.messageViewContainer = messageViewWrapper;
  
  layout.appendChild(threadListWrapper);
  layout.appendChild(messageViewWrapper);
  container.appendChild(layout);
  
  main.appendChild(container);
  
  // Render thread list
  if (instance.threads.length === 0) {
    renderEmptyState(instance, state, shell);
  } else {
    renderThreadList(instance, state, shell);
    
    // Auto-select first thread if no thread is selected
    if (!instance.currentThreadId) {
      loadThread(instance, state, shell, instance.threads[0]);
    } else {
      // Try to reload the currently selected thread
      const currentThread = instance.threads.find(t => t.id === instance.currentThreadId);
      if (currentThread) {
        loadThread(instance, state, shell, currentThread);
      } else {
        // Thread no longer exists, select first one
        loadThread(instance, state, shell, instance.threads[0]);
      }
    }
  }
}

/**
 * Create header section
 */
function createHeader(instance, state, shell) {
  const header = el("div", {
    style: {
      display: "flex",
      justifyContent: "space-between",
      alignItems: "center",
      paddingBottom: "20px",
      borderBottom: "2px solid #e5e7eb",
    },
  });
  
  // Title section
  const titleSection = el("div");
  
  titleSection.appendChild(
    el("h1", {
      text: "Web Requests",
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
      text: "Communicate with the WebNique team about your website.",
      style: {
        fontSize: "16px",
        color: "#6b7280",
        margin: "0",
      },
    })
  );
  
  header.appendChild(titleSection);
  
  // New request button
  const newBtn = button(
    state,
    "+ New Request",
    () => {
      if (instance.destroyed) return;
      showNewRequestModal(instance, state, shell);
    },
    "solid"
  );
  
  newBtn.style.background = "#0d539e";
  newBtn.style.borderColor = "#0d539e";
  newBtn.style.padding = "14px 28px";
  newBtn.style.fontSize = "15px";
  
  header.appendChild(newBtn);
  
  return header;
}

/**
 * Create stats cards
 */
function createStatsCards(threads) {
  const container = el("div", {
    style: {
      display: "grid",
      gridTemplateColumns: "repeat(auto-fit, minmax(250px, 1fr))",
      gap: "20px",
    },
  });
  
  // Calculate stats
  const openCount = threads.filter(t => t.status === "open").length;
  const closedCount = threads.filter(t => t.status === "closed").length;
  const totalCount = threads.length;
  
  const stats = [
    {
      label: "OPEN REQUESTS",
      value: openCount,
      icon: "📝",
      color: "#0d539e",
    },
    {
      label: "COMPLETED",
      value: closedCount,
      icon: "✅",
      color: "#10b981",
    },
    {
      label: "TOTAL REQUESTS",
      value: totalCount,
      icon: "📊",
      color: "#6b7280",
    },
  ];
  
  stats.forEach((stat) => {
    container.appendChild(createStatCard(stat));
  });
  
  return container;
}

/**
 * Create individual stat card
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
    },
  });
  
  // Hover effect
  card.addEventListener("mouseenter", () => {
    card.style.transform = "translateY(-2px)";
    card.style.boxShadow = "0 4px 6px rgba(0,0,0,0.1)";
  });
  
  card.addEventListener("mouseleave", () => {
    card.style.transform = "translateY(0)";
    card.style.boxShadow = "0 1px 3px rgba(0,0,0,0.1)";
  });
  
  // Icon
  card.appendChild(
    el("div", {
      text: icon,
      style: {
        fontSize: "36px",
        marginBottom: "12px",
      },
    })
  );
  
  // Label
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
  
  // Value
  card.appendChild(
    el("div", {
      text: value.toString(),
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
 * Render thread list
 */
function renderThreadList(instance, state, shell) {
  const container = instance.containers.threadListContainer;
  if (!container || instance.destroyed) return;
  
  container.innerHTML = "";
  
  // Header
  const header = el("div", {
    style: {
      padding: "20px",
      borderBottom: "1px solid #e5e7eb",
      background: "#f9fafb",
      display: "flex",
      justifyContent: "space-between",
      alignItems: "center",
      flexShrink: "0",
    },
  });
  
  header.appendChild(
    el("h3", {
      text: "All Requests",
      style: {
        fontSize: "16px",
        fontWeight: "800",
        color: "#111827",
        margin: "0",
      },
    })
  );
  
  header.appendChild(
    pill(`${instance.threads.length}`, "neutral")
  );
  
  container.appendChild(header);
  
  // Scrollable list
  const listScroll = el("div", {
    style: {
      flex: "1",
      overflowY: "auto",
      minHeight: "0",
    },
  });
  
  if (instance.threads.length === 0) {
    listScroll.appendChild(
      el("div", {
        style: {
          padding: "40px 20px",
          textAlign: "center",
          color: "#9ca3af",
        },
        html: "<p>No requests yet</p>",
      })
    );
  } else {
    instance.threads.forEach((thread) => {
      const isActive = thread.id === instance.currentThreadId;
      const threadItem = createThreadItem(thread, isActive);
      
      threadItem.addEventListener("click", () => {
        if (instance.destroyed) return;
        loadThread(instance, state, shell, thread);
      });
      
      listScroll.appendChild(threadItem);
    });
  }
  
  container.appendChild(listScroll);
}

/**
 * Create thread item
 */
function createThreadItem(thread, isActive) {
  const item = el("div", {
    "data-thread-id": thread.id,
    style: {
      padding: "20px",
      borderBottom: "1px solid #f3f4f6",
      borderLeft: isActive ? "4px solid #0d539e" : "4px solid transparent",
      background: isActive ? "#f0f9ff" : "white",
      cursor: "pointer",
      transition: "all 0.2s ease",
      position: "relative",
    },
  });
  
  // Hover effect
  item.addEventListener("mouseenter", () => {
    if (!isActive) {
      item.style.background = "#f9fafb";
    }
  });
  
  item.addEventListener("mouseleave", () => {
    if (!isActive) {
      item.style.background = "white";
    }
  });
  
  // Subject
  item.appendChild(
    el("div", {
      text: escapeHtml(thread.subject || "Untitled Request"),
      style: {
        fontSize: "15px",
        fontWeight: "700",
        color: "#111827",
        marginBottom: "8px",
        overflow: "hidden",
        textOverflow: "ellipsis",
        whiteSpace: "nowrap",
      },
    })
  );
  
  // Last message preview
  if (thread.last_message) {
    item.appendChild(
      el("div", {
        text: escapeHtml(thread.last_message),
        style: {
          fontSize: "13px",
          color: "#6b7280",
          marginBottom: "8px",
          overflow: "hidden",
          textOverflow: "ellipsis",
          whiteSpace: "nowrap",
        },
      })
    );
  }
  
  // Meta row
  const meta = el("div", {
    style: {
      display: "flex",
      alignItems: "center",
      gap: "8px",
    },
  });
  
  meta.appendChild(
    pill(
      escapeHtml(thread.status || "open"),
      thread.status === "closed" ? "neutral" : "good"
    )
  );
  
  meta.appendChild(
    el("span", {
      text: "•",
      style: { color: "#d1d5db", fontSize: "12px" },
    })
  );
  
  meta.appendChild(
    el("span", {
      text: formatTimestamp(thread.last_updated),
      style: {
        fontSize: "12px",
        color: "#9ca3af",
      },
    })
  );
  
  item.appendChild(meta);
  
  // Unread indicator
  if (thread.unread) {
    item.appendChild(
      el("div", {
        style: {
          position: "absolute",
          top: "24px",
          right: "20px",
          width: "10px",
          height: "10px",
          borderRadius: "50%",
          background: "#0d539e",
        },
      })
    );
  }
  
  return item;
}

/**
 * Load a thread and display its messages
 */
async function loadThread(instance, state, shell, thread) {
  if (instance.destroyed || !thread) return;
  
  console.log("[Requests] Loading thread:", thread.id);
  
  const container = instance.containers.messageViewContainer;
  if (!container) return;
  
  // Update current thread
  instance.currentThreadId = thread.id;
  
  // Update thread list UI
  const threadListContainer = instance.containers.threadListContainer;
  if (threadListContainer) {
    threadListContainer.querySelectorAll('[data-thread-id]').forEach((item) => {
      const threadId = item.getAttribute('data-thread-id');
      const isActive = threadId === thread.id;
      
      item.style.background = isActive ? "#f0f9ff" : "white";
      item.style.borderLeft = isActive ? "4px solid #0d539e" : "4px solid transparent";
    });
  }
  
  // Unsubscribe from previous messages
  if (instance.messagesUnsubscribe) {
    instance.messagesUnsubscribe();
    instance.messagesUnsubscribe = null;
  }
  
  // Show loading
  container.innerHTML = "";
  container.appendChild(
    el("div", {
      style: {
        flex: "1",
        display: "flex",
        alignItems: "center",
        justifyContent: "center",
      },
      html: '<div class="wnq-spinner"></div>',
    })
  );
  
  try {
    // Load messages
    instance.isLoadingMessages = true;
    const messages = await getMessages(thread.id);
    instance.messages = messages;
    instance.isLoadingMessages = false;
    
    console.log("[Requests] Loaded", messages.length, "messages");
    
    if (instance.destroyed) {
      console.log("[Requests] Instance destroyed during message load");
      return;
    }
    
    // Mark as read
    if (thread.unread) {
      markThreadAsRead(thread.id).catch(err => {
        console.warn("[Requests] Failed to mark as read:", err);
      });
    }
    
    // Render message view
    renderMessageView(instance, state, shell, thread);
    
    // Subscribe to real-time updates
    instance.messagesUnsubscribe = subscribeToMessages(thread.id, (updatedMessages) => {
      if (instance.destroyed) {
        console.log("[Requests] Instance destroyed, ignoring message update");
        return;
      }
      
      if (instance.currentThreadId !== thread.id) {
        console.log("[Requests] Different thread selected, ignoring update");
        return;
      }
      
      console.log("[Requests] Messages updated:", updatedMessages.length);
      instance.messages = updatedMessages;
      
      // Update messages in UI
      updateMessagesInUI(instance, state);
    });
    
  } catch (error) {
    console.error("[Requests] Message load error:", error);
    
    if (instance.destroyed) return;
    
    container.innerHTML = "";
    container.appendChild(
      el("div", {
        class: "wnq-alert wnq-alert-danger",
        style: { margin: "20px" },
        html: `<strong>Error:</strong> ${escapeHtml(error.message || "Failed to load messages")}`,
      })
    );
  }
}

/**
 * Render message view
 */
function renderMessageView(instance, state, shell, thread) {
  const container = instance.containers.messageViewContainer;
  if (!container || instance.destroyed) return;
  
  container.innerHTML = "";
  
  // Header
  const header = el("div", {
    style: {
      padding: "24px 32px",
      borderBottom: "1px solid #e5e7eb",
      background: "#f9fafb",
      flexShrink: "0",
    },
  });
  
  header.appendChild(
    el("h2", {
      text: escapeHtml(thread.subject || "Untitled Request"),
      style: {
        fontSize: "22px",
        fontWeight: "800",
        color: "#111827",
        marginBottom: "8px",
      },
    })
  );
  
  const meta = el("div", {
    style: {
      display: "flex",
      gap: "12px",
      alignItems: "center",
    },
  });
  
  meta.appendChild(
    pill(
      escapeHtml(thread.status || "open"),
      thread.status === "closed" ? "neutral" : "good"
    )
  );
  
  if (thread.priority && thread.priority !== "normal") {
    meta.appendChild(
      pill(
        escapeHtml(thread.priority),
        thread.priority === "high" ? "bad" : "neutral"
      )
    );
  }
  
  header.appendChild(meta);
  container.appendChild(header);
  
  // Messages area
  const messagesArea = el("div", {
    id: "messages-scroll-area",
    style: {
      flex: "1",
      overflowY: "auto",
      padding: "32px",
      minHeight: "0",
      display: "flex",
      flexDirection: "column",
      gap: "20px",
    },
  });
  
  if (instance.messages.length === 0) {
    messagesArea.appendChild(
      el("div", {
        style: {
          textAlign: "center",
          padding: "40px 20px",
          color: "#9ca3af",
        },
        html: "<p>No messages yet. Start the conversation!</p>",
      })
    );
  } else {
    instance.messages.forEach((message) => {
      messagesArea.appendChild(createMessageBubble(message, state));
    });
    
    // Scroll to bottom after render
    setTimeout(() => {
      messagesArea.scrollTop = messagesArea.scrollHeight;
    }, 50);
  }
  
  container.appendChild(messagesArea);
  
  // Reply area (only for open threads)
  if (thread.status !== "closed") {
    container.appendChild(createReplyArea(instance, state, thread));
  } else {
    // Show closed message
    container.appendChild(
      el("div", {
        style: {
          padding: "20px 32px",
          background: "#f9fafb",
          borderTop: "1px solid #e5e7eb",
          textAlign: "center",
          color: "#6b7280",
          fontSize: "14px",
          fontWeight: "600",
        },
        text: "This request has been closed.",
      })
    );
  }
}

/**
 * Create message bubble
 */
function createMessageBubble(message, state) {
  const isClient = message.author_type === "client";
  
  const wrapper = el("div", {
    style: {
      display: "flex",
      justifyContent: isClient ? "flex-end" : "flex-start",
    },
  });
  
  const bubble = el("div", {
    style: {
      maxWidth: "70%",
      padding: "16px 20px",
      borderRadius: "16px",
      background: isClient ? "#0d539e" : "#f3f4f6",
      color: isClient ? "white" : "#111827",
    },
  });
  
  // Author name
  bubble.appendChild(
    el("div", {
      text: escapeHtml(message.author_name || (isClient ? "You" : "WebNique Team")),
      style: {
        fontSize: "13px",
        fontWeight: "700",
        marginBottom: "6px",
        color: isClient ? "rgba(255,255,255,0.9)" : "#6b7280",
      },
    })
  );
  
  // Message content
  bubble.appendChild(
    el("div", {
      text: escapeHtml(message.content || ""),
      style: {
        fontSize: "15px",
        lineHeight: "1.6",
        marginBottom: "8px",
        whiteSpace: "pre-wrap",
        wordBreak: "break-word",
      },
    })
  );
  
  // Timestamp
  bubble.appendChild(
    el("div", {
      text: formatTimestamp(message.timestamp),
      style: {
        fontSize: "12px",
        opacity: "0.7",
      },
    })
  );
  
  wrapper.appendChild(bubble);
  return wrapper;
}

/**
 * Create reply area
 */
function createReplyArea(instance, state, thread) {
  const area = el("div", {
    style: {
      padding: "24px 32px",
      borderTop: "1px solid #e5e7eb",
      background: "#f9fafb",
      flexShrink: "0",
    },
  });
  
  const form = el("form", {
    style: {
      display: "flex",
      gap: "12px",
      alignItems: "flex-end",
    },
  });
  
  const textarea = el("textarea", {
    placeholder: "Type your message...",
    rows: "3",
    style: {
      flex: "1",
      padding: "14px",
      borderRadius: "8px",
      border: "1px solid #d1d5db",
      fontSize: "15px",
      resize: "none",
      fontFamily: "inherit",
    },
  });
  
  form.appendChild(textarea);
  
  const sendBtn = button(
    state,
    "Send",
    async (e) => {
      e.preventDefault();
      
      if (instance.destroyed) return;
      
      const content = textarea.value.trim();
      if (!content) return;
      
      // Disable button
      sendBtn.disabled = true;
      sendBtn.textContent = "Sending...";
      
      try {
        await sendMessage(
          thread.id,
          content,
          "client",
          state.user?.name || state.userId || "Client"
        );
        
        // Clear textarea
        textarea.value = "";
        textarea.focus();
        
        // Re-enable button
        sendBtn.disabled = false;
        sendBtn.textContent = "Send";
        
      } catch (error) {
        console.error("[Requests] Send error:", error);
        alert("Failed to send message. Please try again.");
        
        sendBtn.disabled = false;
        sendBtn.textContent = "Send";
      }
    },
    "solid"
  );
  
  sendBtn.style.background = "#0d539e";
  sendBtn.style.borderColor = "#0d539e";
  sendBtn.style.alignSelf = "stretch";
  
  form.appendChild(sendBtn);
  
  // Handle form submission
  form.addEventListener("submit", (e) => {
    e.preventDefault();
    sendBtn.click();
  });
  
  // Handle Ctrl+Enter to send
  textarea.addEventListener("keydown", (e) => {
    if (e.key === "Enter" && (e.ctrlKey || e.metaKey)) {
      e.preventDefault();
      sendBtn.click();
    }
  });
  
  area.appendChild(form);
  return area;
}

/**
 * Update threads in UI (called by real-time subscription)
 */
function updateThreadsInUI(instance, state, shell) {
  if (instance.destroyed) return;
  
  // Update stats
  if (instance.containers.statsContainer) {
    instance.containers.statsContainer.innerHTML = "";
    instance.containers.statsContainer.appendChild(
      createStatsCards(instance.threads)
    );
  }
  
  // Update thread list
  renderThreadList(instance, state, shell);
}

/**
 * Update messages in UI (called by real-time subscription)
 */
function updateMessagesInUI(instance, state) {
  if (instance.destroyed) return;
  
  const messagesArea = document.getElementById("messages-scroll-area");
  if (!messagesArea) return;
  
  // Check if user was at bottom
  const wasAtBottom = 
    messagesArea.scrollHeight - messagesArea.scrollTop - messagesArea.clientHeight < 50;
  
  // Clear and re-render
  messagesArea.innerHTML = "";
  
  if (instance.messages.length === 0) {
    messagesArea.appendChild(
      el("div", {
        style: {
          textAlign: "center",
          padding: "40px 20px",
          color: "#9ca3af",
        },
        html: "<p>No messages yet. Start the conversation!</p>",
      })
    );
  } else {
    instance.messages.forEach((message) => {
      messagesArea.appendChild(createMessageBubble(message, state));
    });
  }
  
  // Scroll to bottom if user was already there
  if (wasAtBottom) {
    setTimeout(() => {
      messagesArea.scrollTop = messagesArea.scrollHeight;
    }, 50);
  }
}

/**
 * Show new request modal
 */
function showNewRequestModal(instance, state, shell) {
  if (instance.destroyed) return;
  
  const overlay = el("div", {
    style: {
      position: "fixed",
      top: "0",
      left: "0",
      right: "0",
      bottom: "0",
      background: "rgba(0,0,0,0.5)",
      display: "flex",
      alignItems: "center",
      justifyContent: "center",
      zIndex: "9999",
      backdropFilter: "blur(2px)",
    },
  });
  
  const modal = el("div", {
    style: {
      background: "white",
      borderRadius: "16px",
      padding: "32px",
      maxWidth: "500px",
      width: "90%",
      maxHeight: "90vh",
      overflowY: "auto",
      boxShadow: "0 20px 25px -5px rgba(0,0,0,0.1), 0 10px 10px -5px rgba(0,0,0,0.04)",
    },
  });
  
  // Title
  modal.appendChild(
    el("h2", {
      text: "New Request",
      style: {
        fontSize: "24px",
        fontWeight: "800",
        color: "#111827",
        marginBottom: "8px",
      },
    })
  );
  
  modal.appendChild(
    el("p", {
      text: "Describe what you need help with and we'll get back to you soon.",
      style: {
        fontSize: "14px",
        color: "#6b7280",
        marginBottom: "24px",
      },
    })
  );
  
  // Subject field
  const subjectGroup = el("div", { style: { marginBottom: "20px" } });
  
  subjectGroup.appendChild(
    el("label", {
      class: "wnq-label",
      text: "Subject",
      style: { marginBottom: "8px" },
    })
  );
  
  const subjectInput = el("input", {
    class: "wnq-input",
    type: "text",
    placeholder: "What do you need help with?",
    required: true,
  });
  
  subjectGroup.appendChild(subjectInput);
  modal.appendChild(subjectGroup);
  
  // Message field
  const messageGroup = el("div", { style: { marginBottom: "24px" } });
  
  messageGroup.appendChild(
    el("label", {
      class: "wnq-label",
      text: "Message (Optional)",
      style: { marginBottom: "8px" },
    })
  );
  
  const messageInput = el("textarea", {
    class: "wnq-textarea",
    rows: "6",
    placeholder: "Provide more details about your request...",
  });
  
  messageGroup.appendChild(messageInput);
  modal.appendChild(messageGroup);
  
  // Buttons
  const btnRow = el("div", {
    style: {
      display: "flex",
      gap: "12px",
      justifyContent: "flex-end",
    },
  });
  
  const cancelBtn = button(
    state,
    "Cancel",
    () => closeModal(),
    "outline"
  );
  
  const createBtn = button(
    state,
    "Create Request",
    async () => {
      if (instance.destroyed) return;
      
      const subject = subjectInput.value.trim();
      const message = messageInput.value.trim();
      
      if (!subject) {
        subjectInput.focus();
        subjectInput.style.borderColor = "#dc2626";
        return;
      }
      
      // Disable button
      createBtn.disabled = true;
      createBtn.textContent = "Creating...";
      
      try {
        await createThread(
          state.clientId,
          subject,
          message,
          state.user?.name || state.userId || "Client"
        );

        // Close modal
        closeModal();
        
        // Threads will update via subscription
        
      } catch (error) {
        console.error("[Requests] Create error:", error);
        alert("Failed to create request. Please try again.");
        
        createBtn.disabled = false;
        createBtn.textContent = "Create Request";
      }
    },
    "solid"
  );
  
  createBtn.style.background = "#0d539e";
  createBtn.style.borderColor = "#0d539e";
  
  btnRow.appendChild(cancelBtn);
  btnRow.appendChild(createBtn);
  modal.appendChild(btnRow);
  
  overlay.appendChild(modal);

  function closeModal() {
    if (overlay.parentElement) {
      document.body.removeChild(overlay);
    }
    document.removeEventListener("keydown", escapeHandler);
  }

  // Close on overlay click
  overlay.addEventListener("click", (e) => {
    if (e.target === overlay) closeModal();
  });

  // Close on Escape key
  function escapeHandler(e) {
    if (e.key === "Escape") closeModal();
  }

  document.addEventListener("keydown", escapeHandler);

  document.body.appendChild(overlay);
  subjectInput.focus();
}

/**
 * Render empty state
 */
function renderEmptyState(instance, state, shell) {
  const messageView = instance.containers.messageViewContainer;
  if (!messageView) return;
  
  messageView.innerHTML = "";
  
  const empty = el("div", {
    style: {
      display: "flex",
      flexDirection: "column",
      alignItems: "center",
      justifyContent: "center",
      flex: "1",
      padding: "60px 40px",
      textAlign: "center",
    },
  });
  
  empty.appendChild(
    el("div", {
      text: "📭",
      style: {
        fontSize: "80px",
        marginBottom: "24px",
        opacity: "0.5",
      },
    })
  );
  
  empty.appendChild(
    el("h3", {
      text: "No Requests Yet",
      style: {
        fontSize: "24px",
        fontWeight: "800",
        color: "#111827",
        marginBottom: "12px",
      },
    })
  );
  
  empty.appendChild(
    el("p", {
      text: 'Click the "+ New Request" button to start a conversation with our team.',
      style: {
        fontSize: "16px",
        color: "#6b7280",
        marginBottom: "24px",
        maxWidth: "400px",
      },
    })
  );
  
  const newBtn = button(
    state,
    "+ New Request",
    () => {
      if (instance.destroyed) return;
      showNewRequestModal(instance, state, shell);
    },
    "solid"
  );
  
  newBtn.style.background = "#0d539e";
  newBtn.style.borderColor = "#0d539e";
  
  empty.appendChild(newBtn);
  messageView.appendChild(empty);
  
  // Also show in thread list
  const threadList = instance.containers.threadListContainer;
  if (threadList) {
    threadList.innerHTML = "";
    
    const header = el("div", {
      style: {
        padding: "20px",
        borderBottom: "1px solid #e5e7eb",
        background: "#f9fafb",
      },
    });
    
    header.appendChild(
      el("h3", {
        text: "All Requests",
        style: {
          fontSize: "16px",
          fontWeight: "800",
          color: "#111827",
          margin: "0",
        },
      })
    );
    
    threadList.appendChild(header);
    
    threadList.appendChild(
      el("div", {
        style: {
          padding: "40px 20px",
          textAlign: "center",
          color: "#9ca3af",
        },
        html: "<p>No requests yet</p>",
      })
    );
  }
}

// ============================================================================
// UTILITIES
// ============================================================================

/**
 * Show loading spinner
 */
function showLoadingSpinner(container) {
  if (!container) return;
  
  container.innerHTML = "";
  container.appendChild(
    el("div", {
      style: {
        display: "flex",
        alignItems: "center",
        justifyContent: "center",
        padding: "60px 20px",
      },
      html: '<div class="wnq-spinner"></div>',
    })
  );
}

/**
 * Show error message
 */
function showError(container, message) {
  if (!container) return;
  
  container.innerHTML = "";
  container.appendChild(
    el("div", {
      class: "wnq-alert wnq-alert-danger",
      style: { margin: "20px" },
      html: `<strong>Error:</strong> ${escapeHtml(message)}`,
    })
  );
}

/**
 * Format timestamp for display
 */
function formatTimestamp(timestamp) {
  if (!timestamp) return "Unknown";
  
  try {
    const date = new Date(timestamp);
    const now = new Date();
    const diffMs = now - date;
    const diffMins = Math.floor(diffMs / (1000 * 60));
    
    if (diffMins < 1) return "Just now";
    if (diffMins < 60) return `${diffMins}m ago`;
    if (diffMins < 1440) return `${Math.floor(diffMins / 60)}h ago`;
    if (diffMins < 2880) return "Yesterday";
    
    return date.toLocaleDateString("en-US", {
      month: "short",
      day: "numeric",
      year: date.getFullYear() !== now.getFullYear() ? "numeric" : undefined,
    });
  } catch (e) {
    console.error("[Requests] Date format error:", e);
    return "Unknown";
  }
}

// ============================================================================
// CLEANUP
// ============================================================================

/**
 * Cleanup instance resources
 * This is called when switching tabs or unmounting
 */
function cleanup(instance) {
  if (!instance || instance.destroyed) return;
  
  console.log("[Requests] Cleaning up instance");
  
  // Mark as destroyed
  instance.destroyed = true;
  
  // Unsubscribe from Firebase
  if (instance.threadsUnsubscribe) {
    console.log("[Requests] Unsubscribing from threads");
    instance.threadsUnsubscribe();
    instance.threadsUnsubscribe = null;
  }
  
  if (instance.messagesUnsubscribe) {
    console.log("[Requests] Unsubscribing from messages");
    instance.messagesUnsubscribe();
    instance.messagesUnsubscribe = null;
  }
  
  // Restore sidebar if it was hidden
  if (instance.containers.side && instance.containers.side.parentElement) {
    console.log("[Requests] Restoring sidebar");
    instance.containers.side.parentElement.style.display = instance.originalSidebarDisplay || "block";
  }
  
  // Restore main area grid column
  if (instance.containers.main && instance.containers.main.parentElement) {
    console.log("[Requests] Restoring main grid column");
    instance.containers.main.parentElement.style.gridColumn = instance.originalMainGridColumn || "";
  }
  
  // Clear references
  instance.containers = {};
  instance.threads = [];
  instance.messages = [];
  instance.currentThreadId = null;
  
  console.log("[Requests] Cleanup complete");
}

/**
 * Export cleanup for external use
 * (In case the router needs to manually cleanup)
 */
export function cleanupRequests() {
  if (activeInstance) {
    cleanup(activeInstance);
    activeInstance = null;
  }
}