/**
 * AI Assistant Sidebar Controller
 * Manages the sidebar toggle and communication with the iframe
 */
(function() {
    'use strict';

    const sidebar = document.getElementById('ai-assistant-sidebar');
    const toggleBtn = document.getElementById('ai-sidebar-toggle');
    const frame = document.getElementById('ai-assistant-frame');

    // State
    let isOpen = false;

    /**
     * Initialize
     */
    function init() {
        // Load saved state
        const savedState = localStorage.getItem('ai_assistant_open');
        if (savedState === 'true') {
            openSidebar();
        }

        // Setup event listeners
        toggleBtn.addEventListener('click', toggleSidebar);

        // Keyboard shortcut: Ctrl/Cmd + Shift + A
        document.addEventListener('keydown', function(e) {
            if ((e.ctrlKey || e.metaKey) && e.shiftKey && e.key === 'A') {
                e.preventDefault();
                toggleSidebar();
            }
        });

        // Listen for messages from iframe
        window.addEventListener('message', handleFrameMessage);

        // Expose API to modx global
        if (window.modx) {
            window.modx.aiAssistant = {
                open: openSidebar,
                close: closeSidebar,
                toggle: toggleSidebar,
                sendMessage: sendMessageToAssistant,
                analyzeCurrentPage: analyzeCurrentPage
            };
        }

        // Add pulse animation initially to attract attention
        setTimeout(function() {
            toggleBtn.classList.add('ai-pulse');
            setTimeout(function() {
                toggleBtn.classList.remove('ai-pulse');
            }, 6000);
        }, 3000);
    }

    /**
     * Toggle sidebar
     */
    function toggleSidebar() {
        if (isOpen) {
            closeSidebar();
        } else {
            openSidebar();
        }
    }

    /**
     * Open sidebar
     */
    function openSidebar() {
        isOpen = true;
        sidebar.classList.remove('ai-sidebar-closed');
        sidebar.classList.add('ai-sidebar-open');
        localStorage.setItem('ai_assistant_open', 'true');
        toggleBtn.classList.remove('ai-pulse');
    }

    /**
     * Close sidebar
     */
    function closeSidebar() {
        isOpen = false;
        sidebar.classList.remove('ai-sidebar-open');
        sidebar.classList.add('ai-sidebar-closed');
        localStorage.setItem('ai_assistant_open', 'false');
    }

    /**
     * Handle messages from iframe
     */
    function handleFrameMessage(event) {
        // Verify origin for security
        if (event.origin !== window.location.origin || event.source !== frame.contentWindow
            || !event.data || typeof event.data !== 'object') return;

        const { type, payload } = event.data;

        switch (type) {
            case 'ai-assistant-close':
                closeSidebar();
                break;

            case 'ai-assistant-refresh-tree':
                if (window.modx && window.modx.tree) {
                    window.modx.tree.updateTree();
                }
                break;

            case 'ai-assistant-navigate':
                if (payload && payload.url) {
                    const mainFrame = document.getElementById('mainframe');
                    if (mainFrame) {
                        const target = new URL(payload.url, window.location.href);
                        if (target.origin === window.location.origin && /^https?:$/.test(target.protocol)) {
                            mainFrame.src = target.href;
                        }
                    }
                }
                break;

            case 'ai-assistant-edit-resource':
                if (payload && Number.isInteger(Number(payload.id)) && Number(payload.id) > 0) {
                    const mainFrame = document.getElementById('mainframe');
                    if (mainFrame) {
                        mainFrame.src = 'index.php?a=27&id=' + Number(payload.id);
                    }
                }
                break;
        }
    }

    /**
     * Send message to AI Assistant
     */
    function sendMessageToAssistant(message) {
        if (frame && frame.contentWindow) {
            // Open sidebar first
            openSidebar();

            // Wait for iframe to be ready, then send message
            setTimeout(function() {
                if (frame.contentWindow.AiAssistant) {
                    frame.contentWindow.AiAssistant.sendMessage(message);
                }
            }, 500);
        }
    }

    /**
     * Analyze current page in editor
     */
    function analyzeCurrentPage() {
        const mainFrame = document.getElementById('mainframe');
        if (mainFrame && mainFrame.contentWindow) {
            try {
                const form = mainFrame.contentWindow.document.querySelector('form[name="mutate"]');
                if (form) {
                    const resourceId = form.querySelector('input[name="id"]');
                    if (resourceId && resourceId.value) {
                        sendMessageToAssistant('Analyze SEO for resource #' + resourceId.value);
                    }
                }
            } catch (e) {
                console.error('Cannot access main frame:', e);
            }
        }
    }

    /**
     * Get current resource ID from editor
     */
    function getCurrentResourceId() {
        const mainFrame = document.getElementById('mainframe');
        if (mainFrame && mainFrame.contentWindow) {
            try {
                const form = mainFrame.contentWindow.document.querySelector('form[name="mutate"]');
                if (form) {
                    const resourceId = form.querySelector('input[name="id"]');
                    if (resourceId) {
                        return parseInt(resourceId.value, 10) || null;
                    }
                }
            } catch (e) {
                // Cross-origin or not available
            }
        }
        return null;
    }

    // Store current resource ID in modx object for access from iframe
    if (window.modx) {
        Object.defineProperty(window.modx, 'currentResourceId', {
            get: getCurrentResourceId
        });
    }

    // Initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
