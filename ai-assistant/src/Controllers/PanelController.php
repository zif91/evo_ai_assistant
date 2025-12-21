<?php

namespace EvolutionCMS\AiAssistant\Controllers;

use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

class PanelController extends Controller
{
    /**
     * Render the AI Assistant panel
     */
    public function index(): Response
    {
        $siteUrl = defined('MODX_SITE_URL') ? MODX_SITE_URL : '/';
        $baseUrl = rtrim($siteUrl, '/');

        // Check if AI service is configured
        $isConfigured = false;
        $apiKey = evo()->getConfig('ai_assistant_api_key', '');
        if (!empty($apiKey)) {
            $isConfigured = true;
        }

        $userName = $_SESSION['mgrShortname'] ?? 'User';
        $configJson = json_encode([
            'baseUrl' => $baseUrl . '/ai-assistant',
            'apiUrl' => $baseUrl . '/ai-assistant/api',
            'isConfigured' => $isConfigured,
            'userName' => $userName,
        ]);

        $html = $this->renderPanel($configJson, $userName, $isConfigured, $baseUrl);

        return response($html)->header('Content-Type', 'text/html');
    }

    private function renderPanel($configJson, $userName, $isConfigured, $baseUrl): string
    {
        $notConfiguredBlock = '';
        $inputBlock = '';
        $scriptBlock = '';

        if (!$isConfigured) {
            $notConfiguredBlock = '
        <div class="not-configured">
            <p>AI Assistant is not configured.</p>
            <p>Please add your API key in<br><strong>Modules &rarr; AI Assistant Settings</strong></p>
        </div>';
        } else {
            $inputBlock = '
    <div class="input-container">
        <div class="input-wrapper">
            <input type="text" id="messageInput" placeholder="Ask me anything..." autocomplete="off">
            <button id="sendBtn">Send</button>
        </div>
    </div>';

            $scriptBlock = '
    <script>
        const config = ' . $configJson . ';
        const chatContainer = document.getElementById("chatContainer");
        const messageInput = document.getElementById("messageInput");
        const sendBtn = document.getElementById("sendBtn");
        const clearBtn = document.getElementById("clearBtn");
        const STORAGE_KEY = "ai_assistant_chat";

        // Load chat from localStorage
        function loadChat() {
            const saved = localStorage.getItem(STORAGE_KEY);
            if (saved) {
                try {
                    const messages = JSON.parse(saved);
                    if (messages.length > 0) {
                        messages.forEach(function(m) {
                            addMessageToDOM(m.content, m.isUser, false);
                        });
                        return true;
                    }
                } catch(e) {}
            }
            return false;
        }

        // Save chat to localStorage
        function saveChat() {
            const messages = [];
            chatContainer.querySelectorAll(".message").forEach(function(el) {
                const isUser = el.classList.contains("user");
                const content = el.querySelector(".message-content").innerHTML;
                messages.push({ isUser: isUser, content: content });
            });
            localStorage.setItem(STORAGE_KEY, JSON.stringify(messages));
        }

        // Clear chat
        function clearChat() {
            if (!confirm("Clear chat history?")) return;
            localStorage.removeItem(STORAGE_KEY);
            chatContainer.innerHTML = "";
            showWelcome();
            // Also clear server session
            fetch(config.apiUrl + "/history", { method: "DELETE", credentials: "include" });
        }

        // Show welcome message
        function showWelcome() {
            const welcome = document.createElement("div");
            welcome.className = "welcome";
            welcome.innerHTML = "<h3>Hello, " + config.userName + "!</h3><p>How can I help you today?</p><p class=\"hint\">Try: \"Find all published pages\" or \"Create a page called Test\"</p>";
            chatContainer.appendChild(welcome);
        }

        // Add message to DOM
        function addMessageToDOM(content, isUser, save) {
            const welcome = chatContainer.querySelector(".welcome");
            if (welcome) welcome.remove();

            const div = document.createElement("div");
            div.className = "message " + (isUser ? "user" : "assistant");
            div.innerHTML = "<div class=\"message-content\">" + content + "</div>";
            chatContainer.appendChild(div);
            chatContainer.scrollTop = chatContainer.scrollHeight;

            if (save !== false) saveChat();
        }

        function addMessage(content, isUser) {
            addMessageToDOM(content, isUser, true);
        }

        function showTyping() {
            const div = document.createElement("div");
            div.className = "typing";
            div.id = "typing";
            div.textContent = "Thinking...";
            chatContainer.appendChild(div);
            chatContainer.scrollTop = chatContainer.scrollHeight;
        }

        function hideTyping() {
            const el = document.getElementById("typing");
            if (el) el.remove();
        }

        async function sendMessage() {
            const message = messageInput.value.trim();
            if (!message) return;

            addMessage(message, true);
            messageInput.value = "";
            sendBtn.disabled = true;
            showTyping();

            try {
                const response = await fetch(config.apiUrl + "/chat", {
                    method: "POST",
                    headers: {
                        "Content-Type": "application/json",
                        "X-Requested-With": "XMLHttpRequest"
                    },
                    credentials: "include",
                    body: JSON.stringify({ message: message })
                });

                const text = await response.text();
                hideTyping();

                let data;
                try {
                    data = JSON.parse(text);
                } catch(e) {
                    addMessage("Error: Server returned invalid response. Check logs.", false);
                    console.error("Server response:", text);
                    sendBtn.disabled = false;
                    return;
                }

                if (data.success) {
                    let msg = data.response || data.message || "";
                    if (data.actions && data.actions.length > 0) {
                        var acts = [];
                        data.actions.forEach(function(a) {
                            var s = a.success ? "[OK]" : "[ERR]";
                            var info = a.action || a.name || "action";
                            if (a.message) info += ": " + a.message;
                            else if (a.data && Array.isArray(a.data)) info += " (" + a.data.length + ")";
                            acts.push(s + " " + info);
                        });
                        if (acts.length > 0) msg += "<br><small>" + acts.join("<br>") + "</small>";
                    }
                    addMessage(msg || "Done", false);
                } else {
                    addMessage("Error: " + (data.error || "Unknown error"), false);
                }
            } catch (error) {
                hideTyping();
                addMessage("Error: " + error.message, false);
            }

            sendBtn.disabled = false;
            messageInput.focus();
        }

        // Init
        if (!loadChat()) {
            showWelcome();
        }

        sendBtn.addEventListener("click", sendMessage);
        clearBtn.addEventListener("click", clearChat);
        messageInput.addEventListener("keypress", function(e) {
            if (e.key === "Enter") sendMessage();
        });
    </script>';
        }

        return '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AI Assistant</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #f8f9fa; height: 100vh; display: flex; flex-direction: column; }
        .panel-header { background: #6366f1; color: white; padding: 12px 15px; display: flex; align-items: center; gap: 10px; }
        .panel-header h2 { font-size: 16px; font-weight: 600; flex: 1; }
        .panel-header .clear-btn { background: rgba(255,255,255,0.2); border: none; color: white; padding: 5px 10px; border-radius: 5px; cursor: pointer; font-size: 12px; }
        .panel-header .clear-btn:hover { background: rgba(255,255,255,0.3); }
        .chat-container { flex: 1; overflow-y: auto; padding: 15px; }
        .message { margin-bottom: 15px; }
        .message.user { text-align: right; }
        .message.assistant { text-align: left; }
        .message-content { display: inline-block; padding: 10px 15px; border-radius: 15px; max-width: 85%; word-wrap: break-word; text-align: left; }
        .message.user .message-content { background: #6366f1; color: white; }
        .message.assistant .message-content { background: white; border: 1px solid #e5e7eb; }
        .message-content small { opacity: 0.8; display: block; margin-top: 8px; font-size: 11px; }
        .input-container { padding: 15px; background: white; border-top: 1px solid #e5e7eb; }
        .input-wrapper { display: flex; gap: 10px; }
        #messageInput { flex: 1; padding: 10px 15px; border: 1px solid #e5e7eb; border-radius: 20px; outline: none; font-size: 14px; }
        #messageInput:focus { border-color: #6366f1; }
        #sendBtn { background: #6366f1; color: white; border: none; padding: 10px 20px; border-radius: 20px; cursor: pointer; font-weight: 600; }
        #sendBtn:hover { background: #4f46e5; }
        #sendBtn:disabled { background: #9ca3af; cursor: not-allowed; }
        .not-configured { padding: 20px; text-align: center; color: #6b7280; }
        .typing { padding: 10px 15px; color: #6b7280; font-style: italic; }
        .welcome { padding: 20px; text-align: center; color: #6b7280; }
        .welcome h3 { color: #374151; margin-bottom: 10px; }
        .welcome .hint { margin-top: 15px; font-size: 12px; color: #6366f1; }
    </style>
</head>
<body>
    <div class="panel-header">
        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <circle cx="12" cy="12" r="10"></circle>
            <circle cx="12" cy="10" r="3"></circle>
            <path d="M7 20.662V19a2 2 0 0 1 2-2h6a2 2 0 0 1 2 2v1.662"></path>
        </svg>
        <h2>AI Assistant</h2>
        <button class="clear-btn" id="clearBtn" title="Clear chat">Clear</button>
    </div>
    <div class="chat-container" id="chatContainer">' . $notConfiguredBlock . '</div>' . $inputBlock . $scriptBlock . '
</body>
</html>';
    }
}
