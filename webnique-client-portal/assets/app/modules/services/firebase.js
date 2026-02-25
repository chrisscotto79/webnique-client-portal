// assets/app/modules/services/firebase.js
/**
 * Firebase Service for Web Requests
 * 
 * Handles all Firebase operations for the messaging system
 * No REST API needed - direct Firestore connection
 */

// Firebase config will be passed from WordPress (wp_localize_script)
let db = null;
let auth = null;
let isInitialized = false;

/**
 * Initialize Firebase
 */
export async function initFirebase() {
  // Return immediately if already initialized
  if (isInitialized && db) {
    console.log("[Firebase] Already initialized, skipping");
    return true;
  }

  const config = window.WNQ_FIREBASE_CONFIG;
  
  if (!config) {
    console.error("[Firebase] Missing configuration");
    return false;
  }

  try {
    // Import Firebase modules
    const { initializeApp, getApps } = await import('https://www.gstatic.com/firebasejs/10.7.1/firebase-app.js');
    const { getFirestore } = await import('https://www.gstatic.com/firebasejs/10.7.1/firebase-firestore.js');
    const { getAuth } = await import('https://www.gstatic.com/firebasejs/10.7.1/firebase-auth.js');

    // Check if Firebase app already exists
    let app;
    const existingApps = getApps();
    
    if (existingApps.length > 0) {
      // Use existing app
      app = existingApps[0];
      console.log("[Firebase] Using existing Firebase app");
    } else {
      // Initialize new app
      app = initializeApp(config);
      console.log("[Firebase] Initialized new Firebase app");
    }
    
    db = getFirestore(app);
    auth = getAuth(app);
    isInitialized = true;

    console.log("[Firebase] Ready");
    return true;
  } catch (error) {
    console.error("[Firebase] Initialization error:", error);
    return false;
  }
}

/**
 * Get Firestore modules
 */
async function getFirestoreModules() {
  const {
    collection,
    doc,
    getDoc,
    getDocs,
    addDoc,
    updateDoc,
    query,
    where,
    orderBy,
    onSnapshot,
    serverTimestamp,
    Timestamp,
  } = await import('https://www.gstatic.com/firebasejs/10.7.1/firebase-firestore.js');

  return {
    collection,
    doc,
    getDoc,
    getDocs,
    addDoc,
    updateDoc,
    query,
    where,
    orderBy,
    onSnapshot,
    serverTimestamp,
    Timestamp,
  };
}

/**
 * Get all threads for a client
 */
export async function getThreads(clientId) {
  if (!db || !clientId) {
    throw new Error("Firebase not initialized or missing clientId");
  }

  try {
    const { collection, query, where, orderBy, getDocs } = await getFirestoreModules();

    const threadsRef = collection(db, 'threads');
    const q = query(
      threadsRef,
      where('client_id', '==', clientId),
      orderBy('last_updated', 'desc')
    );

    const snapshot = await getDocs(q);
    const threads = [];

    snapshot.forEach((doc) => {
      threads.push({
        id: doc.id,
        ...doc.data(),
      });
    });

    return threads;
  } catch (error) {
    console.error("[Firebase] Get threads error:", error);
    throw error;
  }
}

/**
 * Get messages for a thread
 */
export async function getMessages(threadId) {
  if (!db || !threadId) {
    throw new Error("Firebase not initialized or missing threadId");
  }

  try {
    const { collection, query, orderBy, getDocs } = await getFirestoreModules();

    const messagesRef = collection(db, 'threads', threadId, 'messages');
    const q = query(messagesRef, orderBy('timestamp', 'asc'));

    const snapshot = await getDocs(q);
    const messages = [];

    snapshot.forEach((doc) => {
      const data = doc.data();
      messages.push({
        id: doc.id,
        ...data,
        // Convert Firestore Timestamp to ISO string
        timestamp: data.timestamp?.toDate?.()?.toISOString() || new Date().toISOString(),
      });
    });

    return messages;
  } catch (error) {
    console.error("[Firebase] Get messages error:", error);
    throw error;
  }
}

/**
 * Send a message
 */
export async function sendMessage(threadId, content, authorType, authorName) {
  if (!db || !threadId || !content) {
    throw new Error("Missing required parameters");
  }

  try {
    const { collection, addDoc, doc, updateDoc, serverTimestamp } = await getFirestoreModules();

    // Add message to subcollection
    const messagesRef = collection(db, 'threads', threadId, 'messages');
    const messageData = {
      content: content.trim(),
      author_type: authorType, // 'client' or 'team'
      author_name: authorName,
      timestamp: serverTimestamp(),
      read: false,
    };

    const messageDoc = await addDoc(messagesRef, messageData);

    // Update thread's last_updated
    const threadRef = doc(db, 'threads', threadId);
    await updateDoc(threadRef, {
      last_updated: serverTimestamp(),
      last_message: content.trim().substring(0, 100),
    });

    return {
      id: messageDoc.id,
      ...messageData,
      timestamp: new Date().toISOString(),
    };
  } catch (error) {
    console.error("[Firebase] Send message error:", error);
    throw error;
  }
}

/**
 * Create a new thread
 */
export async function createThread(clientId, subject, firstMessage, authorName) {
  if (!db || !clientId || !subject) {
    throw new Error("Missing required parameters");
  }

  try {
    const { collection, addDoc, serverTimestamp } = await getFirestoreModules();

    // Create thread document
    const threadsRef = collection(db, 'threads');
    const threadData = {
      client_id: clientId,
      subject: subject.trim(),
      status: 'open',
      priority: 'normal',
      created_at: serverTimestamp(),
      last_updated: serverTimestamp(),
      last_message: firstMessage ? firstMessage.substring(0, 100) : '',
      assigned_to: 'WebNique Team',
      related_service: '',
    };

    const threadDoc = await addDoc(threadsRef, threadData);

    // Add first message if provided
    if (firstMessage && firstMessage.trim()) {
      await sendMessage(threadDoc.id, firstMessage, 'client', authorName);
    }

    return {
      id: threadDoc.id,
      ...threadData,
      created_at: new Date().toISOString(),
      last_updated: new Date().toISOString(),
    };
  } catch (error) {
    console.error("[Firebase] Create thread error:", error);
    throw error;
  }
}

/**
 * Listen to thread updates in real-time
 */
export function subscribeToThreads(clientId, callback) {
  if (!db || !clientId) {
    console.error("[Firebase] Cannot subscribe - not initialized");
    return () => {};
  }

  getFirestoreModules().then(({ collection, query, where, orderBy, onSnapshot }) => {
    const threadsRef = collection(db, 'threads');
    const q = query(
      threadsRef,
      where('client_id', '==', clientId),
      orderBy('last_updated', 'desc')
    );

    const unsubscribe = onSnapshot(
      q,
      (snapshot) => {
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
      },
      (error) => {
        console.error("[Firebase] Subscription error:", error);
      }
    );

    return unsubscribe;
  });

  // Return dummy unsubscribe for now
  return () => {};
}

/**
 * Listen to message updates in real-time
 */
export function subscribeToMessages(threadId, callback) {
  if (!db || !threadId) {
    console.error("[Firebase] Cannot subscribe - not initialized");
    return () => {};
  }

  getFirestoreModules().then(({ collection, query, orderBy, onSnapshot }) => {
    const messagesRef = collection(db, 'threads', threadId, 'messages');
    const q = query(messagesRef, orderBy('timestamp', 'asc'));

    const unsubscribe = onSnapshot(
      q,
      (snapshot) => {
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
      },
      (error) => {
        console.error("[Firebase] Message subscription error:", error);
      }
    );

    return unsubscribe;
  });

  // Return dummy unsubscribe for now
  return () => {};
}

/**
 * Mark thread as read
 */
export async function markThreadAsRead(threadId) {
  if (!db || !threadId) return;

  try {
    const { doc, updateDoc } = await getFirestoreModules();
    
    const threadRef = doc(db, 'threads', threadId);
    await updateDoc(threadRef, {
      unread: false,
    });
  } catch (error) {
    console.error("[Firebase] Mark as read error:", error);
  }
}

/**
 * Update thread status
 */
export async function updateThreadStatus(threadId, status) {
  if (!db || !threadId || !status) return;

  try {
    const { doc, updateDoc, serverTimestamp } = await getFirestoreModules();
    
    const threadRef = doc(db, 'threads', threadId);
    await updateDoc(threadRef, {
      status: status,
      last_updated: serverTimestamp(),
    });
  } catch (error) {
    console.error("[Firebase] Update status error:", error);
    throw error;
  }
}