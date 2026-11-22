import { initializeApp } from 'firebase/app';
import { getMessaging, onBackgroundMessage } from 'firebase/messaging/sw';
import { inboxStorage, incomingRecord } from './storage.js';

const firebase = self.notificationLabFirebase;
const app = initializeApp(firebase, 'notification-lab');
const storage = inboxStorage(firebase);
const scope = new URL(self.registration.scope).pathname;

self.addEventListener('install', (event) => event.waitUntil(self.skipWaiting()));

onBackgroundMessage(getMessaging(app), async (payload) => {
    const message = incomingRecord(payload, 'Background', firebase);
    await storage.save(message).catch(() => {});
    const windows = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
    for (const window of windows) {
        const path = new URL(window.url).pathname;
        if (path === scope.slice(0, -1) || path.startsWith(scope)) {
            window.postMessage({ type: 'notification-lab:received', message });
        }
    }
});
