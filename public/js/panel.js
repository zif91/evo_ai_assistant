/**
 * AI Assistant Panel JavaScript
 */
(function() {
    'use strict';

    const config = window.AiAssistantConfig || {};
    const translations = window.AiAssistantTranslations || {};

    // DOM Elements
    const messagesContainer = document.getElementById('ai-messages');
    const inputField = document.getElementById('ai-input');
    const sendButton = document.getElementById('ai-btn-send');
    const clearButton = document.getElementById('ai-btn-clear');
    const historyButton = document.getElementById('ai-btn-history');
    const typingIndicator = document.getElementById('ai-typing');
    const checkpointsPanel = document.getElementById('ai-checkpoints-panel');
    const checkpointsList = document.getElementById('ai-checkpoints-list');
    const closeCheckpointsBtn = document.getElementById('ai-close-checkpoints');

    // State
    let isLoading = false;

    /**
     * Initialize the panel
     */
    function init() {
        setupEventListeners();
        autoResizeInput();
    }

    /**
     * Setup event listeners
     */
    function setupEventListeners() {
        // Send message
        sendButton.addEventListener('click', sendMessage);

        // Enter to send (Shift+Enter for new line)
        inputField.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                sendMessage();
            }
        });

        // Auto-resize input
        inputField.addEventListener('input', autoResizeInput);

        // Clear history
        clearButton.addEventListener('click', clearHistory);

        // Show checkpoints
        historyButton.addEventListener('click', showCheckpoints);

        // Close checkpoints
        closeCheckpointsBtn.addEventListener('click', function() {
            checkpointsPanel.style.display = 'none';
        });

        // Suggestion clicks
        document.addEventListener('click', function(e) {
            if (e.target.matches('.ai-suggestions-list li')) {
                const prompt = e.target.dataset.prompt;
                if (prompt) {
                    inputField.value = prompt;
                    sendMessage();
                }
            }
        });
    }

    /**
     * Auto-resize input field
     */
    function autoResizeInput() {
        inputField.style.height = 'auto';
        inputField.style.height = Math.min(inputField.scrollHeight, 120) + 'px';
    }

    /**
     * Send message to AI
     */
    async function sendMessage() {
        const message = inputField.value.trim();
        if (!message || isLoading) return;

        // Add user message to UI
        addMessage(message, 'user');

        // Clear input
        inputField.value = '';
        autoResizeInput();

        // Show typing indicator
        showTyping(true);
        isLoading = true;

        try {
            const response = await fetch(config.apiUrl + '/chat', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                },
                body: JSON.stringify({
                    message: message,
                    context: getPageContext()
                }),
                credentials: 'same-origin'
            });

            const data = await response.json();

            showTyping(false);
            isLoading = false;

            if (data.success) {
                addMessage(data.message, 'assistant', data.actions);
            } else {
                addMessage(data.error || translations.error, 'assistant', null, true);
            }
        } catch (error) {
            showTyping(false);
            isLoading = false;
            console.error('AI Assistant error:', error);
            addMessage(translations.error, 'assistant', null, true);
        }
    }

    /**
     * Get current page context
     */
    function getPageContext() {
        // Try to get context from parent frame
        try {
            const parentWindow = window.parent;
            if (parentWindow && parentWindow.modx) {
                return {
                    currentResourceId: parentWindow.modx.currentResourceId || null,
                    // Add more context as needed
                };
            }
        } catch (e) {
            // Cross-origin access denied
        }
        return {};
    }

    /**
     * Add message to UI
     */
    function addMessage(content, role, actions, isError) {
        const messageDiv = document.createElement('div');
        messageDiv.className = `ai-message ai-message-${role}`;

        const avatarHtml = role === 'user'
            ? `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
                <circle cx="12" cy="7" r="4"/>
               </svg>`
            : `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M12 2a10 10 0 0 1 10 10 10 10 0 0 1-10 10A10 10 0 0 1 2 12 10 10 0 0 1 12 2z"/>
                <circle cx="12" cy="10" r="3"/>
               </svg>`;

        let contentHtml = formatContent(content);

        // Add action results if any
        if (actions && actions.length > 0) {
            contentHtml += renderActions(actions);
        }

        if (isError) {
            contentHtml = `<span style="color: #dc2626;">${contentHtml}</span>`;
        }

        messageDiv.innerHTML = `
            <div class="ai-message-avatar">${avatarHtml}</div>
            <div class="ai-message-content">${contentHtml}</div>
        `;

        messagesContainer.appendChild(messageDiv);
        scrollToBottom();
    }

    /**
     * Format content with markdown-like syntax
     */
    function formatContent(content) {
        if (!content) return '';

        // Escape HTML
        content = content
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');

        // Code blocks
        content = content.replace(/```(\w*)\n?([\s\S]*?)```/g, '<pre><code>$2</code></pre>');

        // Inline code
        content = content.replace(/`([^`]+)`/g, '<code>$1</code>');

        // Bold
        content = content.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');

        // Italic
        content = content.replace(/\*([^*]+)\*/g, '<em>$1</em>');

        // Line breaks
        content = content.replace(/\n/g, '<br>');

        // Wrap in paragraphs
        content = `<p>${content}</p>`;

        return content;
    }

    /**
     * Render action results
     */
    function renderActions(actions) {
        let html = '';

        actions.forEach(action => {
            const isSuccess = action.success !== false;
            const statusClass = isSuccess ? 'ai-action-result-success' : 'ai-action-result-error';

            html += `
                <div class="ai-action-result">
                    <div class="ai-action-result-header ${statusClass}">
                        ${isSuccess ? '✓' : '✗'} ${action.action || 'Action'}
                    </div>
                    ${renderActionData(action)}
                </div>
            `;
        });

        return html;
    }

    /**
     * Render action data based on type
     */
    function renderActionData(action) {
        if (action.error) {
            return `<p style="color: #dc2626;">${action.error}</p>`;
        }

        if (!action.data) return '';

        // Resource list
        if (Array.isArray(action.data) && action.data.length > 0 && action.data[0].pagetitle) {
            return action.data.map(resource => `
                <div class="ai-resource-card">
                    <div class="ai-resource-card-header">
                        <span class="ai-resource-card-title">${escapeHtml(resource.pagetitle)}</span>
                        <span class="ai-resource-card-id">#${resource.id}</span>
                    </div>
                    <div class="ai-resource-card-meta">
                        <span class="ai-resource-card-status ${resource.published ? 'published' : 'unpublished'}">
                            ${resource.published ? '● Published' : '○ Unpublished'}
                        </span>
                    </div>
                </div>
            `).join('');
        }

        // SEO analysis
        if (action.data.score !== undefined && action.data.grade) {
            return `
                <div class="ai-seo-score">
                    <div class="ai-seo-score-circle grade-${action.data.grade.toLowerCase()}">${action.data.grade}</div>
                    <div class="ai-seo-score-info">
                        <h4>SEO Score: ${action.data.score}/100</h4>
                        <p>${action.data.summary || ''}</p>
                    </div>
                </div>
            `;
        }

        // Generic data
        if (typeof action.data === 'object') {
            return `<pre><code>${JSON.stringify(action.data, null, 2)}</code></pre>`;
        }

        return `<p>${action.data}</p>`;
    }

    /**
     * Escape HTML
     */
    function escapeHtml(str) {
        if (!str) return '';
        return str
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    /**
     * Show/hide typing indicator
     */
    function showTyping(show) {
        typingIndicator.style.display = show ? 'flex' : 'none';
        if (show) scrollToBottom();
    }

    /**
     * Scroll to bottom of messages
     */
    function scrollToBottom() {
        messagesContainer.scrollTop = messagesContainer.scrollHeight;
    }

    /**
     * Clear chat history
     */
    async function clearHistory() {
        try {
            await fetch(config.apiUrl + '/history', {
                method: 'DELETE',
                credentials: 'same-origin'
            });

            // Clear UI except welcome message
            const messages = messagesContainer.querySelectorAll('.ai-message');
            messages.forEach((msg, index) => {
                if (index > 0) msg.remove();
            });
        } catch (error) {
            console.error('Failed to clear history:', error);
        }
    }

    /**
     * Show checkpoints panel
     */
    async function showCheckpoints() {
        checkpointsPanel.style.display = 'flex';

        try {
            const response = await fetch(config.apiUrl + '/checkpoints', {
                credentials: 'same-origin'
            });
            const data = await response.json();

            if (data.success && data.data.length > 0) {
                checkpointsList.innerHTML = data.data.map(cp => `
                    <div class="ai-checkpoint-item" data-id="${cp.id}">
                        <div class="ai-checkpoint-info">
                            <div class="ai-checkpoint-desc">${escapeHtml(cp.description)}</div>
                            <div class="ai-checkpoint-time">${cp.created_at}</div>
                        </div>
                        <button class="ai-checkpoint-btn" onclick="window.AiAssistant.rollback(${cp.id})">
                            ${translations.rollback}
                        </button>
                    </div>
                `).join('');
            } else {
                checkpointsList.innerHTML = `
                    <div class="ai-checkpoints-empty">
                        No checkpoints available
                    </div>
                `;
            }
        } catch (error) {
            console.error('Failed to load checkpoints:', error);
            checkpointsList.innerHTML = `
                <div class="ai-checkpoints-empty">
                    Failed to load checkpoints
                </div>
            `;
        }
    }

    /**
     * Rollback to checkpoint
     */
    async function rollback(checkpointId) {
        if (!confirm(translations.confirm_rollback)) return;

        try {
            const response = await fetch(config.apiUrl + '/checkpoints/' + checkpointId + '/rollback', {
                method: 'POST',
                credentials: 'same-origin'
            });
            const data = await response.json();

            if (data.success) {
                addMessage('Checkpoint restored successfully!', 'assistant');
                checkpointsPanel.style.display = 'none';
            } else {
                addMessage('Failed to restore checkpoint: ' + (data.error || 'Unknown error'), 'assistant', null, true);
            }
        } catch (error) {
            console.error('Failed to rollback:', error);
            addMessage('Failed to restore checkpoint', 'assistant', null, true);
        }
    }

    /**
     * Execute action directly
     */
    async function executeAction(action, params) {
        showTyping(true);
        isLoading = true;

        try {
            const response = await fetch(config.apiUrl + '/execute', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({ action, params }),
                credentials: 'same-origin'
            });
            const data = await response.json();

            showTyping(false);
            isLoading = false;

            return data;
        } catch (error) {
            showTyping(false);
            isLoading = false;
            throw error;
        }
    }

    // Expose API
    window.AiAssistant = {
        sendMessage: function(msg) {
            inputField.value = msg;
            sendMessage();
        },
        rollback: rollback,
        executeAction: executeAction,
        showCheckpoints: showCheckpoints
    };

    // Initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
