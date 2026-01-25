<?php
/**
 * Telegram Configuration
 *
 * Configure Telegram notifications for logs
 *
 * @var array $config Current Telegram configuration
 * @var array $channels Available channels
 * @var array $log_levels Available log levels
 * @var string $admin_base_path Admin base path
 * @var string $csrf_input CSRF hidden input
 */
?>
<link rel="stylesheet" href="/css/logger-telegram.css">

<!-- Page Header -->
<div class="eap-page-header">
    <div class="eap-page-header__content">
        <a href="<?= $admin_base_path ?>/logger" class="eap-btn eap-btn--ghost eap-btn--sm">
            &larr; Back to Logger
        </a>
        <h1 class="eap-page-title">Telegram Notifications</h1>
        <p class="eap-page-subtitle">Receive log alerts directly in Telegram</p>
    </div>
</div>

<!-- How It Works -->
<div class="eap-notice eap-notice--info">
    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" width="20" height="20">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
    </svg>
    <div>
        <strong>How it works:</strong> Telegram has a <strong>separate minimum level</strong> from channels.
        <br>Example: Channel "api" at level WARNING logs everything from warning up.
        Telegram at level ERROR only sends errors and above to your chat.
    </div>
</div>

<!-- Configuration Form -->
<div class="eap-card">
    <div class="eap-card__header">
        <span class="eap-card__title">Telegram Bot Configuration</span>
    </div>
    <div class="eap-card__body">
        <form method="POST" action="<?= $admin_base_path ?>/logger/telegram/save" id="telegram-form">
            <?= $csrf_input ?>

            <!-- Enable/Disable -->
            <div class="eap-form__group">
                <label class="eap-form__checkbox eap-form__checkbox--lg">
                    <input type="checkbox" name="enabled" id="telegram-enabled"
                           <?= ($config['enabled'] ?? false) ? 'checked' : '' ?>>
                    <span>Enable Telegram Notifications</span>
                </label>
            </div>

            <div id="telegram-settings" class="<?= ($config['enabled'] ?? false) ? '' : 'eap-hidden' ?>">
                <!-- Bot Token -->
                <div class="eap-form__group">
                    <label class="eap-form__label" for="bot-token">Bot Token</label>
                    <input type="text" name="bot_token" id="bot-token" class="eap-form__input"
                           value="<?= htmlspecialchars($config['bot_token'] ?? '') ?>"
                           placeholder="123456789:ABCdefGHIjklMNOpqrsTUVwxyz">
                    <div class="eap-form__hint">
                        Get a bot token from <a href="https://t.me/BotFather" target="_blank" rel="noopener">@BotFather</a>
                    </div>
                </div>

                <!-- Chat ID -->
                <div class="eap-form__group">
                    <label class="eap-form__label" for="chat-id">Chat ID</label>
                    <input type="text" name="chat_id" id="chat-id" class="eap-form__input"
                           value="<?= htmlspecialchars($config['chat_id'] ?? '') ?>"
                           placeholder="-1001234567890 or @channel_username">
                    <div class="eap-form__hint">
                        Use <a href="https://t.me/userinfobot" target="_blank" rel="noopener">@userinfobot</a> to get your chat ID,
                        or use <code>@channel_username</code> for a channel
                    </div>
                </div>

                <!-- Minimum Level -->
                <div class="eap-form__group">
                    <label class="eap-form__label" for="min-level">Minimum Level for Telegram</label>
                    <select name="min_level" id="min-level" class="eap-form__select">
                        <?php foreach ($log_levels as $level): ?>
                            <option value="<?= $level ?>"
                                    <?= ($config['min_level'] ?? 'error') === $level ? 'selected' : '' ?>>
                                <?= ucfirst($level) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="eap-form__hint">
                        Only logs at this level or higher will be sent to Telegram.
                        <strong>Recommended: error</strong> to avoid spam.
                    </div>
                </div>

                <!-- Notify Channels -->
                <div class="eap-form__group">
                    <label class="eap-form__label">Notify for Channels</label>
                    <div class="eap-form__checkbox-group">
                        <label class="eap-form__checkbox">
                            <input type="checkbox" name="notify_channels[]" value="*"
                                   id="notify-all"
                                   <?= in_array('*', $config['notify_channels'] ?? ['*']) ? 'checked' : '' ?>>
                            <span>All channels</span>
                        </label>
                    </div>
                    <div class="eap-form__checkbox-group eap-channel-checkboxes" id="channel-checkboxes">
                        <?php foreach ($channels as $name => $channelConfig): ?>
                            <label class="eap-form__checkbox">
                                <input type="checkbox" name="notify_channels[]" value="<?= htmlspecialchars($name) ?>"
                                       class="channel-checkbox"
                                       <?= in_array($name, $config['notify_channels'] ?? []) ? 'checked' : '' ?>>
                                <span><?= htmlspecialchars($name) ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Rate Limit -->
                <div class="eap-form__group">
                    <label class="eap-form__label" for="rate-limit">Rate Limit (messages per minute)</label>
                    <input type="number" name="rate_limit" id="rate-limit" class="eap-form__input eap-form__input--sm"
                           value="<?= (int)($config['rate_limit_per_minute'] ?? 10) ?>"
                           min="1" max="60">
                    <div class="eap-form__hint">
                        Maximum number of Telegram messages per minute to prevent flooding.
                    </div>
                </div>
            </div>

            <!-- Actions -->
            <div class="eap-form__actions">
                <button type="submit" class="eap-btn eap-btn--primary">
                    Save Configuration
                </button>
                <button type="button" class="eap-btn eap-btn--secondary" id="test-btn"
                        <?= empty($config['bot_token']) || empty($config['chat_id']) ? 'disabled' : '' ?>>
                    Test Connection
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Test Result -->
<div id="test-result" class="eap-notice eap-hidden"></div>

<!-- Setup Instructions -->
<div class="eap-card">
    <div class="eap-card__header">
        <span class="eap-card__title">Setup Instructions</span>
    </div>
    <div class="eap-card__body">
        <ol class="eap-list eap-list--numbered">
            <li>
                <strong>Create a Telegram Bot:</strong>
                <ul class="eap-list">
                    <li>Open Telegram and search for <code>@BotFather</code></li>
                    <li>Send <code>/newbot</code> and follow the instructions</li>
                    <li>Copy the bot token (format: <code>123456789:ABCdef...</code>)</li>
                </ul>
            </li>
            <li>
                <strong>Get your Chat ID:</strong>
                <ul class="eap-list">
                    <li>Search for <code>@userinfobot</code> and start a chat</li>
                    <li>It will show your numeric Chat ID</li>
                    <li>For groups: add the bot to group, then use <code>@getidsbot</code></li>
                </ul>
            </li>
            <li>
                <strong>Start the bot:</strong>
                <ul class="eap-list">
                    <li>Find your bot by its username and click Start</li>
                    <li>This is required before the bot can send you messages</li>
                </ul>
            </li>
            <li>
                <strong>Test the connection:</strong>
                <ul class="eap-list">
                    <li>Enter your bot token and chat ID above</li>
                    <li>Click "Test Connection" to verify</li>
                </ul>
            </li>
        </ol>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const enabledCheckbox = document.getElementById('telegram-enabled');
    const settingsDiv = document.getElementById('telegram-settings');
    const testBtn = document.getElementById('test-btn');
    const testResult = document.getElementById('test-result');
    const notifyAllCheckbox = document.getElementById('notify-all');
    const channelCheckboxes = document.querySelectorAll('.channel-checkbox');

    // Toggle settings visibility
    enabledCheckbox.addEventListener('change', function() {
        settingsDiv.classList.toggle('eap-hidden', !this.checked);
    });

    // Toggle channel checkboxes based on "All channels"
    notifyAllCheckbox.addEventListener('change', function() {
        channelCheckboxes.forEach(cb => {
            cb.disabled = this.checked;
            if (this.checked) cb.checked = false;
        });
    });

    // Test connection
    testBtn.addEventListener('click', async function() {
        const botToken = document.getElementById('bot-token').value;
        const chatId = document.getElementById('chat-id').value;

        if (!botToken || !chatId) {
            showTestResult(false, 'Please enter bot token and chat ID');
            return;
        }

        testBtn.disabled = true;
        testBtn.textContent = 'Testing...';

        try {
            const formData = new FormData();
            formData.append('bot_token', botToken);
            formData.append('chat_id', chatId);
            formData.append('_csrf_token', document.querySelector('input[name="_csrf_token"]').value);

            const response = await fetch('<?= $admin_base_path ?>/logger/telegram/test', {
                method: 'POST',
                body: formData
            });

            const data = await response.json();
            showTestResult(data.success, data.message);
        } catch (error) {
            showTestResult(false, 'Network error: ' + error.message);
        } finally {
            testBtn.disabled = false;
            testBtn.textContent = 'Test Connection';
        }
    });

    function showTestResult(success, message) {
        testResult.classList.remove('eap-hidden', 'eap-notice--success', 'eap-notice--danger');
        testResult.classList.add(success ? 'eap-notice--success' : 'eap-notice--danger');
        testResult.innerHTML = `
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" width="20" height="20">
                ${success
                    ? '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>'
                    : '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>'}
            </svg>
            <span>${message}</span>
        `;
    }
});
</script>
