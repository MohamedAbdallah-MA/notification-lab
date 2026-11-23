function messageTarget(data, reportedTopic) {
    const target = data.notification_lab_target;
    const type = data.notification_lab_target_type;
    const namedTarget = typeof target === 'string' && target !== '';

    if (type === 'direct') return { source: 'direct', topic: null, condition: null };
    if (type === 'topic' && namedTarget) return { source: 'topic', topic: target, condition: null };
    if (type === 'condition' && namedTarget) return { source: 'topic', topic: null, condition: target };

    if (reportedTopic) return { source: 'topic', topic: reportedTopic, condition: null };
    if (target === 'browser') return { source: 'direct', topic: null, condition: null };
    if (namedTarget) return { source: 'topic', topic: target, condition: null };

    return { source: 'unknown', topic: null, condition: null };
}

export function incomingRecord(payload, mode) {
    const data = payload.data || {};
    const sender = String(payload.from || '');
    const reportedTopic = sender.startsWith('/topics/') ? sender.slice(8) : null;

    return {
        id: payload.messageId || crypto.randomUUID(),
        title: payload.notification?.title || data.title || 'Data message',
        body: payload.notification?.body || data.body || '',
        ...messageTarget(data, reportedTopic),
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
        async load() {
            const messages = await use('readonly', (store) => store.getAll());

            return messages.map((message) => ({
                ...message,
                ...messageTarget(message.data || {}, message.topic),
            }));
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
