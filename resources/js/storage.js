export function incomingRecord(payload, mode, firebase) {
    const data = payload.data || {};
    const testTarget = data.notification_lab_target;
    const sender = String(payload.from || '');
    const topic = sender.startsWith('/topics/') ? sender.slice(8)
        : (testTarget && testTarget !== 'browser' ? testTarget : null);

    return {
        id: payload.messageId || crypto.randomUUID(),
        title: payload.notification?.title || data.title || 'Data message',
        body: payload.notification?.body || data.body || '',
        source: topic ? 'topic' : (testTarget === 'browser' || sender === firebase.messagingSenderId ? 'direct' : 'unknown'),
        topic,
        testId: data.notification_lab_test_id,
        receivedAt: Date.now(),
        mode,
        data,
    };
}

export function inboxStorage(firebase) {
    const name = 'notification-lab:' + firebase.projectId + ':' + firebase.appId;
    const database = new Promise((resolve, reject) => {
        const request = indexedDB.open(name, 1);
        request.onupgradeneeded = () => {
            request.result.createObjectStore('messages', { keyPath: 'id' });
        };
        request.onsuccess = () => resolve(request.result);
        request.onerror = () => reject(request.error);
        request.onblocked = () => reject(new Error('Inbox storage is blocked.'));
    });

    async function use(mode, operation) {
        const db = await database;
        return new Promise((resolve, reject) => {
            const transaction = db.transaction('messages', mode);
            const result = operation(transaction.objectStore('messages'));
            transaction.oncomplete = () => resolve(result?.result);
            transaction.onabort = () => reject(transaction.error);
            transaction.onerror = () => reject(transaction.error);
        });
    }

    return {
        load() {
            return use('readonly', (store) => store.getAll());
        },
        save(message) {
            return use('readwrite', (store) => {
                const existing = store.get(message.id);
                existing.onsuccess = () => {
                    if (existing.result) return;
                    store.put(message);
                    const all = store.getAll();
                    all.onsuccess = () => {
                        const old = all.result.sort((left, right) => right.receivedAt - left.receivedAt).slice(100);
                        for (const item of old) store.delete(item.id);
                    };
                };
            });
        },
        clear() {
            return use('readwrite', (store) => store.clear());
        },
    };
}
