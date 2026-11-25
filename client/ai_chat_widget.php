
<!-- HandyLink Assistant launcher and chat panel -->
<div id="handylink-ai-chat" class="hl-ai-chat">

    <button
        type="button"
        id="hl-ai-toggle"
        class="hl-ai-toggle"
        aria-label="Open HandyLink Assistant"
        aria-expanded="false"
        aria-controls="hl-ai-panel"
        title="Ask HandyLink Assistant">

        <i class="fa-solid fa-headset" aria-hidden="true"></i>
        <span class="hl-ai-toggle-label">AI Help</span>
    </button>

    <section
        id="hl-ai-panel"
        class="hl-ai-panel"
        aria-label="HandyLink Assistant"
        hidden>

        <header class="hl-ai-header">
            <div class="hl-ai-avatar">
                <i class="fa-solid fa-headset" aria-hidden="true"></i>
            </div>

            <div class="hl-ai-header-text">
                <strong>HandyLink Assistant</strong>
                <span>
                    <span class="hl-ai-online-dot"></span>
                    Ready to help
                </span>
            </div>

            <button
                type="button"
                id="hl-ai-close"
                class="hl-ai-close"
                aria-label="Close assistant">
                <i class="fa-solid fa-xmark" aria-hidden="true"></i>
            </button>
        </header>

        <div
            id="hl-ai-messages"
            class="hl-ai-messages"
            role="log"
            aria-live="polite"
            aria-relevant="additions">

            <div class="hl-ai-message hl-ai-bot-message">
                Hi! Welcome to HandyLink.

                I can help you find services, discover approved providers, and understand how to request a service.

                <div class="hl-ai-quick-actions">
                    <button type="button" data-message="I need a plumber">
                        Plumbing
                    </button>

                    <button type="button" data-message="I need an electrician">
                        Electrical
                    </button>

                    <button type="button" data-message="How do I request a service?">
                        How it works
                    </button>
                </div>
            </div>
        </div>

        <form id="hl-ai-form" class="hl-ai-form">
            <label class="hl-ai-sr-only" for="hl-ai-input">
                Type your question
            </label>

            <input
                type="text"
                id="hl-ai-input"
                name="message"
                maxlength="500"
                placeholder="How can we help?"
                autocomplete="off"
                required>

            <button
                type="submit"
                id="hl-ai-send"
                aria-label="Send message">
                <i class="fa-solid fa-paper-plane"></i>
            </button>
        </form>

        <p class="hl-ai-disclaimer">
            Automated assistance from HandyLink
        </p>
    </section>
</div>

<link
    rel="stylesheet"
    href="../assets/css/clients/ai_chat.css">

<script
    src="../assets/js/client_ai_chat.js"
    defer>
</script>
