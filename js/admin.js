(function () {
    const APP_URL = OC.generateUrl('/apps/neura_google_calendar_sync');

    const saveBtn    = document.getElementById('gws-save');
    const testBtn    = document.getElementById('gws-test');
    const syncNowBtn = document.getElementById('gws-sync-now');
    const fileInput  = document.getElementById('gws-sa-file');
    const filename   = document.getElementById('gws-sa-filename');
    const textarea   = document.getElementById('gws-sa-textarea');
    const preview    = document.getElementById('gws-sa-preview-wrap');
    const saMsg      = document.getElementById('gws-sa-msg');
    const clearBtn   = document.getElementById('gws-sa-clear');
    const statusDiv  = document.getElementById('gws-status');

    let pendingSaJson = '';

    function showStatus(message, type) {
        // type: 'ok' | 'error' | 'info'
        statusDiv.textContent = message;
        statusDiv.className = 'gws-status-msg gws-status-msg--' + (type || 'ok');
        statusDiv.style.display = 'block';
    }

    function setBtnBusy(btn, label) {
        btn.disabled = true;
        btn._origText = btn.textContent;
        btn.textContent = label;
    }

    function resetBtn(btn) {
        btn.disabled = false;
        btn.textContent = btn._origText;
    }

    // File upload
    fileInput.addEventListener('change', function () {
        const file = fileInput.files[0];
        if (!file) return;
        filename.textContent = file.name;
        const reader = new FileReader();
        reader.onload = function (e) {
            const text = e.target.result;
            try {
                const parsed = JSON.parse(text);
                if (parsed.type !== 'service_account') {
                    saMsg.textContent = 'Warning: "type" is not "service_account". Check the file.';
                    saMsg.style.color = 'var(--color-warning, #854d0e)';
                    pendingSaJson = '';
                    return;
                }
                pendingSaJson = text;
                textarea.value = JSON.stringify(parsed, null, 2);
                preview.style.display = 'block';
                saMsg.textContent = '✓ ' + (parsed.client_email || file.name);
                saMsg.style.color = '#166534';
            } catch (err) {
                saMsg.textContent = 'Invalid JSON: ' + err.message;
                saMsg.style.color = '#991b1b';
                pendingSaJson = '';
                preview.style.display = 'none';
            }
        };
        reader.readAsText(file);
    });

    clearBtn.addEventListener('click', function () {
        fileInput.value = '';
        filename.textContent = 'No file selected';
        textarea.value = '';
        preview.style.display = 'none';
        pendingSaJson = '';
        saMsg.textContent = 'Key cleared — save to remove from server.';
        saMsg.style.color = '';
    });

    // Save
    saveBtn.addEventListener('click', async function () {
        setBtnBusy(saveBtn, 'Saving…');
        statusDiv.style.display = 'none';

        const payload = {
            enabled: document.getElementById('gws-enabled').checked ? 'yes' : 'no',
            googleDomain: document.getElementById('gws-domain').value.trim(),
            syncIntervalMinutes: parseInt(document.getElementById('gws-interval').value, 10) || 15,
            userEmailSuffix: document.getElementById('gws-suffix').value.trim(),
            saJsonKey: pendingSaJson,
            syncNcToGoogle: document.getElementById('gws-nc-to-g').checked ? 'yes' : 'no',
            syncGoogleToNc: document.getElementById('gws-g-to-nc').checked ? 'yes' : 'no',
            syncFromDate: document.getElementById('gws-from-date').value,
        };

        try {
            const resp = await fetch(APP_URL + '/api/admin/settings', {
                method: 'POST',
                headers: { requesttoken: OC.requestToken, 'Content-Type': 'application/json' },
                body: JSON.stringify(payload),
            });
            const json = await resp.json();
            if (resp.ok) {
                showStatus('Settings saved.', 'ok');
                pendingSaJson = '';
            } else {
                showStatus(json.message || 'Save failed.', 'error');
            }
        } catch (err) {
            showStatus(err.message, 'error');
        } finally {
            resetBtn(saveBtn);
        }
    });

    // Test connection
    testBtn.addEventListener('click', async function () {
        setBtnBusy(testBtn, 'Testing…');
        statusDiv.style.display = 'none';

        try {
            const resp = await fetch(APP_URL + '/api/admin/test', {
                method: 'POST',
                headers: { requesttoken: OC.requestToken, 'Content-Type': 'application/json' },
                body: JSON.stringify({ testUser: OC.currentUser }),
            });
            const json = await resp.json();
            if (resp.ok) {
                showStatus('Connection OK — impersonating ' + json.email, 'ok');
            } else {
                showStatus(json.message || 'Connection failed.', 'error');
            }
        } catch (err) {
            showStatus(err.message, 'error');
        } finally {
            resetBtn(testBtn);
        }
    });

    // Sync now — sequential per-user with live progress
    syncNowBtn.addEventListener('click', async function () {
        if (!confirm('Sync all users now?')) return;
        setBtnBusy(syncNowBtn, 'Syncing…');
        statusDiv.style.display = 'none';

        // Step 1: fetch user list
        let users;
        try {
            const r = await fetch(APP_URL + '/api/admin/users', {
                headers: { requesttoken: OC.requestToken },
            });
            const j = await r.json();
            if (!r.ok || !j.users) { showStatus(j.message || 'Could not fetch users.', 'error'); resetBtn(syncNowBtn); return; }
            users = j.users;
        } catch (err) { showStatus(err.message, 'error'); resetBtn(syncNowBtn); return; }

        if (users.length === 0) { showStatus('No users in domain to sync.', 'ok'); resetBtn(syncNowBtn); return; }

        // Step 2: build live progress table
        statusDiv.className = 'gws-sync-result-wrap';
        statusDiv.style.display = 'block';

        let html = '<div class="gws-pills" id="gws-pills-live">'
            + pillHtml('Synced', 0, 'ok') + pillHtml('Skipped', 0, 'neutral') + pillHtml('Failed', 0, 'error')
            + '</div>'
            + '<table class="gws-ptable"><thead><tr><th>User</th><th>Status</th></tr></thead>'
            + '<tbody id="gws-ptable-body">';
        users.forEach(function (u) {
            html += '<tr id="gws-row-' + cssId(u.uid) + '">'
                + '<td><code>' + escHtml(u.email) + '</code></td>'
                + '<td class="gws-pcell gws-pcell--pending"><span class="gws-spinner"></span> Pending…</td>'
                + '</tr>';
        });
        html += '</tbody></table>';
        statusDiv.innerHTML = html;

        let counts = { synced: 0, skipped: 0, failed: 0 };

        // Step 3: sync each user
        for (const u of users) {
            const row = document.getElementById('gws-row-' + cssId(u.uid));
            const cell = row ? row.querySelector('.gws-pcell') : null;
            if (cell) { cell.className = 'gws-pcell gws-pcell--running'; cell.innerHTML = '<span class="gws-spinner"></span> Syncing…'; }

            try {
                const r = await fetch(APP_URL + '/api/admin/sync-user', {
                    method: 'POST',
                    headers: { requesttoken: OC.requestToken, 'Content-Type': 'application/json' },
                    body: JSON.stringify({ userId: u.uid }),
                });
                const j = await r.json();
                const st = j.status;
                if (cell) {
                    if (st === 'synced') {
                        cell.className = 'gws-pcell gws-pcell--ok';
                        cell.textContent = '✓ Synced (' + (j.pairs || 1) + ' calendar' + (j.pairs !== 1 ? 's' : '') + ')';
                        counts.synced++;
                    } else if (st === 'skipped') {
                        cell.className = 'gws-pcell gws-pcell--skip';
                        cell.textContent = '— ' + (j.message || 'Skipped');
                        counts.skipped++;
                    } else {
                        cell.className = 'gws-pcell gws-pcell--error';
                        cell.textContent = '✗ ' + (j.message || 'Error');
                        counts.failed++;
                    }
                }
            } catch (err) {
                if (cell) { cell.className = 'gws-pcell gws-pcell--error'; cell.textContent = '✗ ' + err.message; }
                counts.failed++;
            }
            updatePills(counts);
        }

        resetBtn(syncNowBtn);
    });

    function updatePills(counts) {
        const wrap = document.getElementById('gws-pills-live');
        if (!wrap) return;
        wrap.innerHTML = pillHtml('Synced', counts.synced, 'ok')
            + pillHtml('Skipped', counts.skipped, 'neutral')
            + pillHtml('Failed', counts.failed, counts.failed > 0 ? 'error' : 'ok');
    }

    function pillHtml(label, value, type) {
        return '<div class="gws-pill gws-pill--' + type + '">'
            + '<span class="gws-pill__val">' + value + '</span>'
            + '<span class="gws-pill__label">' + label + '</span>'
            + '</div>';
    }

    function cssId(uid) { return uid.replace(/[^a-zA-Z0-9]/g, '_'); }

    function renderSyncResult(json) { // kept for compatibility

        const hasErrors = json.failed > 0;

        // Outer wrapper — neutral style, no background
        statusDiv.className = 'gws-sync-result-wrap';
        statusDiv.style.display = 'block';

        let html = '';

        // Summary pills
        html += '<div class="gws-pills">'
            + pill('Synced', json.synced || 0, 'ok')
            + pill('Skipped', json.skipped || 0, 'neutral')
            + pill('Failed', json.failed || 0, hasErrors ? 'error' : 'ok')
            + '</div>';

        // Synced users
        if (json.syncedUsers && json.syncedUsers.length) {
            html += '<details class="gws-detail gws-detail--ok" open><summary>✓ Synced users (' + json.syncedUsers.length + ')</summary>'
                + '<ul class="gws-user-list">';
            json.syncedUsers.forEach(function (u) {
                html += '<li><span class="gws-check">✓</span>' + escHtml(u) + '</li>';
            });
            html += '</ul></details>';
        }

        // Failed users
        if (hasErrors && json.errors && json.errors.length) {
            const errorsJson = JSON.stringify(json.errors, null, 2);
            html += '<details class="gws-detail gws-detail--error" open>'
                + '<summary>✗ Failed users (' + json.errors.length + ')'
                + '<button type="button" class="gws-btn gws-btn--ghost gws-copy-btn" data-json="' + escHtml(errorsJson) + '" style="margin-left:0.75rem;font-size:0.75rem">Copy JSON</button>'
                + '</summary>'
                + '<table class="gws-etable"><thead><tr><th>User</th><th>Error</th></tr></thead><tbody>';
            json.errors.forEach(function (e) {
                const sep = e.indexOf(': ');
                const user = sep > -1 ? e.substring(0, sep) : e;
                const msg  = sep > -1 ? e.substring(sep + 2) : e;
                html += '<tr><td><code>' + escHtml(user) + '</code></td><td>' + escHtml(msg) + '</td></tr>';
            });
            html += '</tbody></table></details>';
        }

        statusDiv.innerHTML = html;

        statusDiv.querySelectorAll('.gws-copy-btn').forEach(function (btn) {
            btn.addEventListener('click', function (ev) {
                ev.stopPropagation();
                navigator.clipboard.writeText(btn.dataset.json).then(function () {
                    const orig = btn.textContent;
                    btn.textContent = 'Copied!';
                    setTimeout(function () { btn.textContent = orig; }, 2000);
                });
            });
        });
    }

    function escHtml(s) {
        return String(s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }
})();
