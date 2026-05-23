<?php

declare(strict_types=1);

/** @var array $_
 * @var bool $_['enabled']
 * @var string $_['googleDomain']
 * @var int $_['syncIntervalMinutes']
 * @var string $_['userEmailSuffix']
 * @var bool $_['hasSaKey']
 * @var bool $_['syncNcToGoogle']
 * @var bool $_['syncGoogleToNc']
 */
script('neura_google_calendar_sync', 'admin');
style('neura_google_calendar_sync', 'admin');

function gws_checked(bool $val): string { return $val ? ' checked' : ''; }
?>

<div id="gws-cs-root" class="section">

    <h2 class="gws-title">
        <svg class="gws-title-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
        Google Workspace Calendar Sync
    </h2>
    <p class="gws-subtitle">Bidirectional sync between Nextcloud and Google Workspace calendars via a domain Service Account.</p>

    <!-- Setup Guide -->
    <details class="gws-guide">
        <summary class="gws-guide__summary">
            <svg class="gws-guide__arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"/></svg>
            Setup guide — follow these steps before enabling sync
        </summary>
        <ol class="gws-guide__steps">
            <li>
                <strong>Enable Google Calendar API</strong><br>
                <a href="https://console.cloud.google.com/apis/library/calendar-json.googleapis.com" target="_blank" rel="noopener">Cloud Console → APIs &amp; Services → Enable Google Calendar API ↗</a>
            </li>
            <li>
                <strong>Create a Service Account</strong><br>
                <a href="https://console.cloud.google.com/iam-admin/serviceaccounts" target="_blank" rel="noopener">IAM &amp; Admin → Service Accounts → Create ↗</a>
                — no roles needed.
            </li>
            <li>
                <strong>Enable Domain-Wide Delegation on the SA</strong><br>
                Open the SA → <em>Details</em> tab → check <strong>Enable G Suite Domain-wide Delegation</strong> → save.
            </li>
            <li>
                <strong>Download the JSON key</strong><br>
                SA → <em>Keys</em> tab → <strong>Add Key → Create new key → JSON</strong>.
            </li>
            <li>
                <strong>Authorize the SA in Google Workspace Admin</strong><br>
                <a href="https://admin.google.com/ac/owl/domainwidedelegation" target="_blank" rel="noopener">admin.google.com → Security → API Controls → Domain-wide delegation ↗</a>
                → <strong>Add new</strong><br>
                Client ID: the numeric <code>client_id</code> from the JSON file<br>
                Scope: <code>https://www.googleapis.com/auth/calendar</code><br>
                <em>Allow up to 10 minutes for propagation.</em>
            </li>
            <li>
                <strong>Configure this page</strong><br>
                Upload the JSON key, set the domain, choose sync direction, save, and click <em>Test connection</em>.
            </li>
        </ol>
    </details>

    <!-- Settings Card -->
    <div class="gws-card">

        <!-- Enable -->
        <div class="gws-field gws-field--inline">
            <label class="gws-toggle" for="gws-enabled">
                <input type="checkbox" id="gws-enabled"<?= gws_checked($_['enabled']) ?>>
                <span class="gws-toggle__track"></span>
                <span>Enable automatic sync</span>
            </label>
        </div>

        <div class="gws-divider"></div>

        <!-- Sync direction -->
        <div class="gws-field">
            <span class="gws-label">Sync direction</span>
            <div class="gws-direction">
                <label class="gws-check-label" for="gws-nc-to-g">
                    <input type="checkbox" id="gws-nc-to-g"<?= gws_checked($_['syncNcToGoogle']) ?>>
                    <span class="gws-check-box"></span>
                    <span>Nextcloud → Google</span>
                </label>
                <label class="gws-check-label" for="gws-g-to-nc">
                    <input type="checkbox" id="gws-g-to-nc"<?= gws_checked($_['syncGoogleToNc']) ?>>
                    <span class="gws-check-box"></span>
                    <span>Google → Nextcloud</span>
                </label>
            </div>
            <span class="gws-hint">At least one direction must be enabled for sync to run.</span>
        </div>

        <div class="gws-divider"></div>

        <!-- Domain -->
        <div class="gws-field">
            <label class="gws-label" for="gws-domain">Google domain</label>
            <input class="gws-input" type="text" id="gws-domain" value="<?php p($_['googleDomain']); ?>" placeholder="example.com">
            <span class="gws-hint">Users are impersonated as <em>username@google-domain</em>.</span>
        </div>

        <!-- Interval -->
        <div class="gws-field">
            <label class="gws-label" for="gws-interval">Sync interval</label>
            <div class="gws-input-row">
                <input class="gws-input gws-input--short" type="number" id="gws-interval" min="5" value="<?php p((string)$_['syncIntervalMinutes']); ?>">
                <span class="gws-input-suffix">minutes</span>
            </div>
        </div>

        <!-- Email suffix -->
        <div class="gws-field">
            <label class="gws-label" for="gws-suffix">Email suffix <span class="gws-optional">(optional)</span></label>
            <input class="gws-input" type="text" id="gws-suffix" value="<?php p($_['userEmailSuffix']); ?>" placeholder="@example.com">
            <span class="gws-hint">Appended to NC username if it is not already a full email address.</span>
        </div>

        <!-- Sync from date -->
        <div class="gws-field">
            <label class="gws-label" for="gws-from-date">Sync events from date</label>
            <input class="gws-input gws-input--date" type="date" id="gws-from-date" value="<?php p($_['syncFromDate']); ?>">
            <span class="gws-hint">Only events on or after this date will be synced from Google. Leave the default to sync the past year.</span>
        </div>

        <div class="gws-divider"></div>

        <!-- SA key -->
        <div class="gws-field">
            <span class="gws-label">Service Account JSON key</span>
            <div id="gws-sa-badge" class="gws-badge <?= $_['hasSaKey'] ? 'gws-badge--ok' : 'gws-badge--warn' ?>">
                <?= $_['hasSaKey'] ? '✓ Key configured' : '⚠ No key configured' ?>
            </div>

            <div class="gws-file-row">
                <label class="gws-file-btn" for="gws-sa-file">
                    Choose file
                    <input type="file" id="gws-sa-file" accept=".json,application/json">
                </label>
                <span id="gws-sa-filename" class="gws-file-name">No file selected</span>
                <button type="button" id="gws-sa-clear" class="gws-btn gws-btn--ghost">Clear</button>
            </div>

            <div id="gws-sa-preview-wrap" style="display:none">
                <textarea id="gws-sa-textarea" class="gws-textarea" rows="4" readonly></textarea>
            </div>
            <span id="gws-sa-msg" class="gws-hint"></span>
        </div>

        <!-- Actions -->
        <div class="gws-actions">
            <button id="gws-save" class="gws-btn gws-btn--primary" type="button">Save settings</button>
            <button id="gws-test" class="gws-btn gws-btn--secondary" type="button">Test connection</button>
            <button id="gws-sync-now" class="gws-btn gws-btn--secondary" type="button">Sync now</button>
        </div>

        <div id="gws-status" class="gws-status-msg" style="display:none"></div>

    </div><!-- .gws-card -->

</div><!-- #gws-cs-root -->
