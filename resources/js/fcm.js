import { initializeApp } from 'firebase/app';
import { getMessaging, getToken, isSupported, onMessage, onRegistered, register } from 'firebase/messaging';
import { incomingRecord } from './storage.js';

async function activeWorker(url) {
    const worker = await navigator.serviceWorker.register(url, { updateViaCache: 'none' });
    if (worker.active) return worker;
    const installing = worker.installing || worker.waiting;

    if (!installing) throw new Error('The Notification Lab service worker did not install.');

    await new Promise((resolve, reject) => {
        const timer = setTimeout(() => finish(new Error('The service worker did not activate.')), 10000);

        function finish(error) {
            clearTimeout(timer);
            installing.removeEventListener('statechange', checkState);
            if (error) reject(error);
            else resolve();
        }

        function checkState() {
            if (installing.state === 'activated') finish();
            if (installing.state === 'redundant') finish(new Error('The service worker could not activate.'));
        }

        installing.addEventListener('statechange', checkState);
        checkState();
    });

    return worker;
}

export function browserMessaging(settings, { permission, workerReady, received }) {
    let messaging;

    async function installationId(options) {
        let stop;
        let timeout;
        try {
            return await new Promise((resolve, reject) => {
                timeout = setTimeout(() => reject(new Error('Firebase did not return an installation ID.')), 15000);
                stop = onRegistered(messaging, resolve);
                register(messaging, options).catch(reject);
            });
        } finally {
            stop?.();
            clearTimeout(timeout);
        }
    }

    return async function registerBrowser(prompt) {
        if (!window.isSecureContext || !('Notification' in window) || !('serviceWorker' in navigator)) {
            throw new Error('Open the lab over HTTPS or localhost in a browser with web push support.');
        }

        if (prompt && Notification.permission !== 'granted') await Notification.requestPermission();
        permission(Notification.permission);
        if (Notification.permission !== 'granted') {
            throw new Error(Notification.permission === 'denied'
                ? 'Notifications are blocked. Enable them in your browser site settings.'
                : 'Notification permission was not granted.');
        }

        if (!await isSupported()) throw new Error('This browser does not support Firebase web messaging.');

        const serviceWorkerRegistration = await activeWorker(settings.routes.worker);
        workerReady();

        if (!messaging) {
            messaging = getMessaging(initializeApp(settings.firebase, 'notification-lab'));
            onMessage(messaging, (payload) => received(incomingRecord(payload, 'Foreground', settings.firebase)));
        }

        const options = { serviceWorkerRegistration };
        if (settings.vapidKey) options.vapidKey = settings.vapidKey;

        const id = settings.mode === 'fid' ? await installationId(options) : await getToken(messaging, options);
        if (!id) throw new Error('Firebase did not return a browser registration.');

        return id;
    };
}
