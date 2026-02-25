// assets/admin/requests-admin.js
/**
 * Admin Web Requests Manager
 * 
 * Allows admin to view all client requests and reply
 --

// Firebase
let db = null;
let isInitialized = false;

/**
 * Initialize Firebase
 --
async function initFirebase() {
  if (isInitialized && db) {
    return true;
  }

  const config = window.WNQ_ADMIN_CONFIG?.firebaseConfig;
  
  if (!config || !config.projectId) {
    console.error("[Admin] Missing Firebase configuration");
    return false;
  }

  try {
    const { initializeApp, getApps } = await import('https://www.gstatic.com/firebasejs/10.7.1/firebase-app.js');
    const { getFirestore } = await import('https://www.gstatic.com/firebasejs/10.7.1/firebase-firestore.js');

    let app;
    const existingApps = getApps();
    
    if (existingApps.length > 0) {
      app = existingApps[0];
    } else {
      app = initializeApp(config);
    }
    
    db = getFirestore(app);
    isInitialized = true;

    console.log("[Admin] Firebase initialized");
    return true;
  } catch (error) {
    console.error("[Admin] Firebase init error:", error);
    return false;
  }
}

/**
 * Get all threads (admin sees all)
 --
async function getAllThreads() {
  if (!db) throw new Error("Firebase not initialized");

  const { collection, query, orderBy, getDocs } = await import('https://www.gstatic.com/firebasejs/10.7.1/firebase-firestore.js');

  const threadsRef = collection(db, 'threads');
  const q = query(threadsRef, orderBy('last_updated', 'desc'));

  const snapshot = await getDocs(q);
  const threads = [];

  snapshot.forEach((doc) => {
    const data = doc.data();
    threads.push({
      id: doc.id,
      ...data,
      created_at: data.created_at?.toDate?.()?.toISOString() || new Date().toISOString(),
      last_updated: data.last_updated?.toDate?.()?.toISOString() || new Date().toISOString(),
    });
  });

  return threads;
}

/**
 * Get messages for a thread
 --
async function getMessages(threadId) {
  if (!db) throw new Error("Firebase not initialized");

  const { collection, query, orderBy, getDocs } = await import('https://www.gstatic.com/firebasejs/10.7.1/firebase-firestore.js');

  const messagesRef = collection(db, 'threads', threadId, 'messages');
  const q = query(messagesRef, orderBy('timestamp', 'asc'));

  const snapshot = await getDocs(q);
  const messages = [];

  snapshot.forEach((doc) => {
    const data = doc.data();
    messages.push({
      id: doc.id,
      ...data,
      timestamp: data.timestamp?.toDate?.()?.toISOString() || new Date().toISOString(),
    });
  });

  return messages;
}

/**
 * Send message as team
 --
async function sendMessage(threadId, content, authorName) {
  if (!db) throw new Error("Firebase not initialized");

  const { collection, addDoc, doc, updateDoc, serverTimestamp } = await import('https://www.gstatic.com/firebasejs/10.7.1/firebase-firestore.js');

  // Add message
  const messagesRef = collection(db, 'threads', threadId, 'messages');
  const messageData = {
    content: content.trim(),
    author_type: 'team',
    author_name: authorName,
    timestamp: serverTimestamp(),
    read: false,
  };

  await addDoc(messagesRef, messageData);

  // Update thread
  const threadRef = doc(db, 'threads', threadId);
  await updateDoc(threadRef, {
    last_updated: serverTimestamp(),
    last_message: content.trim().substring(0, 100),
  });
}

/**
 * Update thread status
 --
async function updateThreadStatus(threadId, status) {
  if (!db) throw new Error("Firebase not initialized");

  const { doc, updateDoc, serverTimestamp } = await import('https://www.gstatic.com/firebasejs/10.7.1/firebase-firestore.js');

  const threadRef = doc(db, 'threads', threadId);
  await updateDoc(threadRef, {
    status: status,
    last_updated: serverTimestamp(),
  });
}

/**
 * Subscribe to thread updates
 --
function subscribeToThreads(callback) {
  if (!db) return () => {};

  import('https://www.gstatic.com/firebasejs/10.7.1/firebase-firestore.js').then(({ collection, query, orderBy, onSnapshot }) => {
    const threadsRef = collection(db, 'threads');
    const q = query(threadsRef, orderBy('last_updated', 'desc'));

    onSnapshot(q, (snapshot) => {
      const threads = [];
      snapshot.forEach((doc) => {
        const data = doc.data();
        threads.push({
          id: doc.id,
          ...data,
          created_at: data.created_at?.toDate?.()?.toISOString() || new Date().toISOString(),
          last_updated: data.last_updated?.toDate?.()?.toISOString() || new Date().toISOString(),
        });
      });
      callback(threads);
    });
  });
}

/**
 * Subscribe to message updates
 --
function subscribeToMessages(threadId, callback) {
  if (!db) return () => {};

  import('https://www.gstatic.com/firebasejs/10.7.1/firebase-firestore.js').then(({ collection, query, orderBy, onSnapshot }) => {
    const messagesRef = collection(db, 'threads', threadId, 'messages');
    const q = query(messagesRef, orderBy('timestamp', 'asc'));

    onSnapshot(q, (snapshot) => {
      const messages = [];
      snapshot.forEach((doc) => {
        const data = doc.data();
        messages.push({
          id: doc.id,
          ...data,
          timestamp: data.timestamp?.toDate?.()?.toISOString() || new Date().toISOString(),
        });
      });
      callback(messages);
    });
  });
}

/**
 * Escape HTML
 --
function escapeHtml(str) {
  return String(str)
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;")
    .replaceAll("'", "&#039;");
}

/**
 * Format timestamp
 --
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
      hour: "2-digit",
      minute: "2-digit",
    });
  } catch (e) {
    return "Unknown";
  }
}

/**
 * Render admin interface
 --
async function renderInterface() {
  console.log('[Admin] renderInterface called');
  
  const root = document.getElementById('wnq-admin-requests-root');
  if (!root) {
    console.error('[Admin] Root element not found!');
    return;
  }

  console.log('[Admin] Root element found');

  try {
    // Initialize Firebase
    console.log('[Admin] Initializing Firebase...');
    const success = await initFirebase();
    
    if (!success) {
      console.error('[Admin] Firebase initialization failed');
      root.innerHTML = `
        <div style="padding: 40px;">
          <div style="background: #fef2f2; border: 1px solid #fca5a5; border-radius: 8px; padding: 20px; color: #991b1b;">
            <strong>Error:</strong> Failed to initialize Firebase. Check console for details.
            <br><br>
            <strong>Debug:</strong> Check that WNQ_ADMIN_CONFIG.firebaseConfig exists in the page source.
          </div>
        </div>
      `;
      return;
    }

    console.log('[Admin] Firebase initialized successfully');

    // Load threads
    console.log('[Admin] Loading threads...');
    const threads = await getAllThreads();
    console.log('[Admin] Loaded threads:', threads.length);

    // Render UI
    root.innerHTML = `
      <div class="wnq-admin-container">
        <div class="wnq-admin-header">
          <div>
            <h1>Web Requests Manager</h1>
            <p>View and respond to client web requests</p>
          </div>
          <div class="wnq-admin-stats">
            <div class="stat-box">
              <span class="stat-label">Total</span>
              <span class="stat-value">${threads.length}</span>
            </div>
            <div class="stat-box">
              <span class="stat-label">Open</span>
              <span class="stat-value stat-open">${threads.filter(t => t.status === 'open').length}</span>
            </div>
            <div class="stat-box">
              <span class="stat-label">Closed</span>
              <span class="stat-value stat-closed">${threads.filter(t => t.status === 'closed').length}</span>
            </div>
          </div>
        </div>

        <div class="wnq-admin-content">
          <div class="threads-sidebar" id="threads-sidebar">
            <div class="threads-header">
              <h3>All Requests</h3>
            </div>
            <div class="threads-list" id="threads-list">
              <!-- Threads will be rendered here -->
            </div>
          </div>

          <div class="conversation-area" id="conversation-area">
            <div class="empty-state">
              <div class="empty-icon">📭</div>
              <h3>Select a Request</h3>
              <p>Choose a request from the left to view the conversation</p>
            </div>
          </div>
        </div>
      </div>
    `;

    // Render threads
    renderThreads(threads);

    // Auto-select first thread if exists
    if (threads.length > 0) {
      selectThread(threads[0]);
    }

    // Subscribe to real-time updates
    subscribeToThreads((updatedThreads) => {
      renderThreads(updatedThreads);
    });

  } catch (error) {
    console.error("[Admin] Render error:", error);
    root.innerHTML = `
      <div style="padding: 40px;">
        <div style="background: #fef2f2; border: 1px solid #fca5a5; border-radius: 8px; padding: 20px; color: #991b1b;">
          <strong>Error:</strong> ${escapeHtml(error.message)}
        </div>
      </div>
    `;
  }
}

/**
 * Render threads list grouped by client
 --
function renderThreads(threads) {
  const container = document.getElementById('threads-list');
  if (!container) return;

  container.innerHTML = '';

  if (threads.length === 0) {
    container.innerHTML = '<div class="no-threads">No requests yet</div>';
    return;
  }

  // Group threads by client_id
  const groupedThreads = {};
  threads.forEach((thread) => {
    const clientId = thread.client_id || 'Unknown Client';
    if (!groupedThreads[clientId]) {
      groupedThreads[clientId] = [];
    }
    groupedThreads[clientId].push(thread);
  });

  // Render each client group
  Object.entries(groupedThreads).forEach(([clientId, clientThreads]) => {
    // Create client folder
    const clientFolder = document.createElement('div');
    clientFolder.className = 'client-folder';
    
    const openCount = clientThreads.filter(t => t.status === 'open').length;
    const totalCount = clientThreads.length;
    
    clientFolder.innerHTML = `
      <div class="client-folder-header">
        <div class="client-folder-icon">📁</div>
        <div class="client-folder-info">
          <div class="client-folder-name">${escapeHtml(clientId)}</div>
          <div class="client-folder-meta">${totalCount} request${totalCount !== 1 ? 's' : ''} ${openCount > 0 ? `(${openCount} open)` : ''}</div>
        </div>
        <div class="client-folder-toggle">▼</div>
      </div>
      <div class="client-folder-threads"></div>
    `;

    const threadsContainer = clientFolder.querySelector('.client-folder-threads');
    const header = clientFolder.querySelector('.client-folder-header');
    const toggleIcon = clientFolder.querySelector('.client-folder-toggle');

    // Toggle folder open/close
    header.addEventListener('click', () => {
      const isOpen = clientFolder.classList.contains('open');
      if (isOpen) {
        clientFolder.classList.remove('open');
        toggleIcon.textContent = '▼';
      } else {
        clientFolder.classList.add('open');
        toggleIcon.textContent = '▲';
      }
    });

    // Add threads to this client's folder
    clientThreads.forEach((thread) => {
      const item = document.createElement('div');
      item.className = 'thread-item';
      item.dataset.threadId = thread.id;

      const statusClass = thread.status === 'closed' ? 'status-closed' : 
                         thread.status === 'pending' ? 'status-pending' : 'status-open';

      item.innerHTML = `
        <div class="thread-subject">${escapeHtml(thread.subject || 'Untitled')}</div>
        <div class="thread-preview">${escapeHtml(thread.last_message || '')}</div>
        <div class="thread-meta">
          <span class="thread-status ${statusClass}">${escapeHtml(thread.status || 'open')}</span>
          <span class="thread-time">${formatTimestamp(thread.last_updated)}</span>
        </div>
      `;

      item.addEventListener('click', (e) => {
        e.stopPropagation();
        // Remove active from all
        document.querySelectorAll('.thread-item').forEach(el => el.classList.remove('active'));
        // Add active to this
        item.classList.add('active');
        // Load thread
        selectThread(thread);
      });

      threadsContainer.appendChild(item);
    });

    container.appendChild(clientFolder);

    // Auto-open folders with open requests
    if (openCount > 0) {
      clientFolder.classList.add('open');
      toggleIcon.textContent = '▲';
    }
  });
}

/**
 * Select and display a thread
 --
async function selectThread(thread) {
  const container = document.getElementById('conversation-area');
  if (!container) return;

  // Show loading
  container.innerHTML = `
    <div style="padding: 40px; text-align: center;">
      <div class="wnq-spinner"></div>
    </div>
  `;

  try {
    const messages = await getMessages(thread.id);

    // Render conversation
    container.innerHTML = `
      <div class="conversation-header">
        <div>
          <h2>${escapeHtml(thread.subject || 'Untitled')}</h2>
          <div class="conversation-meta">
            <span>Client: <strong>${escapeHtml(thread.client_id || 'Unknown')}</strong></span>
            <span class="status-badge status-${thread.status || 'open'}">${escapeHtml(thread.status || 'open')}</span>
          </div>
        </div>
        <div class="conversation-actions">
          <select id="status-select" class="status-select">
            <option value="open" ${thread.status === 'open' ? 'selected' : ''}>Open</option>
            <option value="pending" ${thread.status === 'pending' ? 'selected' : ''}>Pending</option>
            <option value="closed" ${thread.status === 'closed' ? 'selected' : ''}>Closed</option>
          </select>
        </div>
      </div>

      <div class="messages-container" id="messages-container">
        ${messages.map(msg => createMessageHTML(msg)).join('')}
      </div>

      <div class="reply-area">
        <form id="reply-form">
          <textarea 
            id="reply-input" 
            placeholder="Type your reply..." 
            rows="3"
            required
          ></textarea>
          <button type="submit" class="button button-primary">Send Reply</button>
        </form>
      </div>
    `;

    // Scroll to bottom
    const msgContainer = document.getElementById('messages-container');
    if (msgContainer) {
      msgContainer.scrollTop = msgContainer.scrollHeight;
    }

    // Handle status change
    const statusSelect = document.getElementById('status-select');
    if (statusSelect) {
      statusSelect.addEventListener('change', async (e) => {
        try {
          await updateThreadStatus(thread.id, e.target.value);
          console.log("[Admin] Status updated");
        } catch (error) {
          console.error("[Admin] Status update error:", error);
          alert('Failed to update status');
        }
      });
    }

    // Handle reply
    const replyForm = document.getElementById('reply-form');
    const replyInput = document.getElementById('reply-input');

    if (replyForm && replyInput) {
      replyForm.addEventListener('submit', async (e) => {
        e.preventDefault();

        const content = replyInput.value.trim();
        if (!content) return;

        const submitBtn = replyForm.querySelector('button[type="submit"]');
        submitBtn.disabled = true;
        submitBtn.textContent = 'Sending...';

        try {
          const userName = window.WNQ_ADMIN_CONFIG?.user?.name || 'WebNique Team';
          await sendMessage(thread.id, content, userName);

          replyInput.value = '';
          replyInput.focus();

        } catch (error) {
          console.error("[Admin] Send error:", error);
          alert('Failed to send message');
        } finally {
          submitBtn.disabled = false;
          submitBtn.textContent = 'Send Reply';
        }
      });
    }

    // Subscribe to real-time message updates
    subscribeToMessages(thread.id, (updatedMessages) => {
      const msgContainer = document.getElementById('messages-container');
      if (msgContainer) {
        const wasAtBottom = 
          msgContainer.scrollHeight - msgContainer.scrollTop - msgContainer.clientHeight < 50;

        msgContainer.innerHTML = updatedMessages.map(msg => createMessageHTML(msg)).join('');

        if (wasAtBottom) {
          msgContainer.scrollTop = msgContainer.scrollHeight;
        }
      }
    });

  } catch (error) {
    console.error("[Admin] Select thread error:", error);
    container.innerHTML = `
      <div style="padding: 40px;">
        <div style="background: #fef2f2; border: 1px solid #fca5a5; border-radius: 8px; padding: 20px; color: #991b1b;">
          <strong>Error:</strong> ${escapeHtml(error.message)}
        </div>
      </div>
    `;
  }
}

/**
 * Create message HTML
 --
function createMessageHTML(message) {
  const isTeam = message.author_type === 'team';
  const alignClass = isTeam ? 'message-team' : 'message-client';

  return `
    <div class="message ${alignClass}">
      <div class="message-bubble">
        <div class="message-author">${escapeHtml(message.author_name || (isTeam ? 'Team' : 'Client'))}</div>
        <div class="message-content">${escapeHtml(message.content || '')}</div>
        <div class="message-time">${formatTimestamp(message.timestamp)}</div>
      </div>
    </div>
  `;
}

/**
 * Initialize on page load
 --
document.addEventListener('DOMContentLoaded', () => {
  console.log('[Admin] DOMContentLoaded fired');
  console.log('[Admin] WNQ_ADMIN_CONFIG:', window.WNQ_ADMIN_CONFIG);
  renderInterface();
});

console.log('[Admin] requests-admin.js loaded');

*/