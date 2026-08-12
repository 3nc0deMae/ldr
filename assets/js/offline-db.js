/* LDB-FRAS Offline Database (IndexedDB)
 * Persists:
 *   1. students_cache         - roster of active students + their 128-d
 *                               face-api descriptors for OFFLINE recognition.
 *   2. pending_attendance_logs- attendance scans taken while offline, queued
 *                               for background sync to Railway when back online.
 *
 * Usage:
 *   await LDB_Offline_Attendance_DB.init();
 *   await LDB_Offline_Attendance_DB.bulkUpsertStudents(list);
 *   const rec = await LDB_Offline_Attendance_DB.saveOfflineScan({...});
 *   await LDB_Offline_Attendance_DB.syncPendingLogs(callback);   // helper
 */
(function () {
    'use strict';

    const DB_NAME = 'LDB_Offline_Attendance_DB';
    const DB_VERSION = 1;
    const STORE_STUDENTS = 'students_cache';
    const STORE_LOGS = 'pending_attendance_logs';

    let _db = null;

    function openDB() {
        return new Promise((resolve, reject) => {
            if (_db) return resolve(_db);
            const req = indexedDB.open(DB_NAME, DB_VERSION);

            req.onupgradeneeded = (e) => {
                const db = e.target.result;

                if (!db.objectStoreNames.contains(STORE_STUDENTS)) {
                    const store = db.createObjectStore(STORE_STUDENTS, { keyPath: 'lrn' });
                    store.createIndex('by_name', 'full_name', { unique: false });
                }

                if (!db.objectStoreNames.contains(STORE_LOGS)) {
                    const store = db.createObjectStore(STORE_LOGS, { keyPath: 'id', autoIncrement: true });
                    store.createIndex('by_synced', 'is_synced', { unique: false });
                    store.createIndex('by_scan_time', 'scan_time', { unique: false });
                }
            };

            req.onsuccess = (e) => {
                _db = e.target.result;
                resolve(_db);
            };
            req.onerror = (e) => reject(e.target.error);
        });
    }

    function tx(storeName, mode) {
        return _db.transaction(storeName, mode).objectStore(storeName);
    }

    function wrapRequest(req) {
        return new Promise((resolve, reject) => {
            req.onsuccess = () => resolve(req.result);
            req.onerror = () => reject(req.error);
        });
    }

    function uuid() {
        if (window.crypto && crypto.randomUUID) return crypto.randomUUID();
        return 'guid-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 10);
    }

    const LDB_Offline_Attendance_DB = {
        init() {
            return openDB();
        },

        // ---------- students_cache ----------
        upsertStudent(student) {
            return openDB().then(() => wrapRequest(tx(STORE_STUDENTS, 'readwrite').put(student)));
        },

        bulkUpsertStudents(students) {
            return openDB().then(() =>
                new Promise((resolve, reject) => {
                    const store = tx(STORE_STUDENTS, 'readwrite');
                    students.forEach((s) => store.put(s));
                    store.transaction.oncomplete = () => resolve(true);
                    store.transaction.onerror = () => reject(store.transaction.error);
                })
            );
        },

        getAllStudents() {
            return openDB().then(() => wrapRequest(tx(STORE_STUDENTS, 'readonly').getAll()));
        },

        getStudentByLrn(lrn) {
            return openDB().then(() => wrapRequest(tx(STORE_STUDENTS, 'readonly').get(String(lrn))));
        },

        countStudents() {
            return openDB().then(() =>
                new Promise((resolve, reject) => {
                    const count = tx(STORE_STUDENTS, 'readonly').count();
                    count.onsuccess = () => resolve(count.result);
                    count.onerror = () => reject(count.error);
                })
            );
        },

        clearStudentsCache() {
            return openDB().then(() => wrapRequest(tx(STORE_STUDENTS, 'readwrite').clear()));
        },

        // ---------- pending_attendance_logs ----------
        saveOfflineScan(scan) {
            return openDB().then(() => {
                const record = Object.assign({}, scan, {
                    id: scan.sync_guid,
                    is_synced: 0,
                    created_offline_at: new Date().toISOString()
                });
                return wrapRequest(tx(STORE_LOGS, 'readwrite').put(record));
            });
        },

        getPendingLogs() {
            return openDB().then(() =>
                new Promise((resolve, reject) => {
                    const all = tx(STORE_LOGS, 'readonly').index('by_synced').getAll(0);
                    all.onsuccess = () => resolve(all.result || []);
                    all.onerror = () => reject(all.error);
                })
            );
        },

        markLogsSynced(ids) {
            return openDB().then(() =>
                new Promise((resolve, reject) => {
                    const store = tx(STORE_LOGS, 'readwrite');
                    ids.forEach((id) => {
                        const get = store.get(id);
                        get.onsuccess = () => {
                            if (get.result) {
                                get.result.is_synced = 1;
                                get.result.synced_at = new Date().toISOString();
                                store.put(get.result);
                            }
                        };
                    });
                    store.transaction.oncomplete = () => resolve(true);
                    store.transaction.onerror = () => reject(store.transaction.error);
                })
            );
        },

        // Physically remove logs once the server confirmed them (is_synced=1 is
        // also set by markLogsSynced, but this keeps the store lean).
        deleteLogs(ids) {
            return openDB().then(() =>
                new Promise((resolve, reject) => {
                    const store = tx(STORE_LOGS, 'readwrite');
                    ids.forEach((id) => store.delete(id));
                    store.transaction.oncomplete = () => resolve(true);
                    store.transaction.onerror = () => reject(store.transaction.error);
                })
            );
        },

        countPendingLogs() {
            return openDB().then(() =>
                new Promise((resolve, reject) => {
                    // Count ONLY unsynced records (is_synced = 0), otherwise the
                    // status pill would keep showing "Syncing…" after a sync.
                    const count = tx(STORE_LOGS, 'readonly').index('by_synced').count(0);
                    count.onsuccess = () => resolve(count.result);
                    count.onerror = () => reject(count.error);
                })
            );
        }
    };

    window.LDB_Offline_Attendance_DB = LDB_Offline_Attendance_DB;
})();
