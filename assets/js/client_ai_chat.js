
document.addEventListener("DOMContentLoaded", function () {
    const toggleButton = document.getElementById("hl-ai-toggle");
    const closeButton = document.getElementById("hl-ai-close");
    const chatPanel = document.getElementById("hl-ai-panel");
    const messagesContainer = document.getElementById("hl-ai-messages");
    const chatForm = document.getElementById("hl-ai-form");
    const messageInput = document.getElementById("hl-ai-input");
    const sendButton = document.getElementById("hl-ai-send");

    // Stop safely if the chatbot widget is not present.
    if (
        !toggleButton ||
        !closeButton ||
        !chatPanel ||
        !messagesContainer ||
        !chatForm ||
        !messageInput ||
        !sendButton
    ) {
        return;
    }

    let isSending = false;

    // Open the chatbot.
    function openChat() {
        chatPanel.hidden = false;
        toggleButton.setAttribute("aria-expanded", "true");
        messageInput.focus();
        scrollToLatest();
    }

    // Close the chatbot.
    function closeChat() {
        chatPanel.hidden = true;
        toggleButton.setAttribute("aria-expanded", "false");
        toggleButton.focus();
    }

    toggleButton.addEventListener("click", function () {
        if (chatPanel.hidden) {
            openChat();
        } else {
            closeChat();
        }
    });

    closeButton.addEventListener("click", closeChat);

    // Escape closes the chatbot.
    document.addEventListener("keydown", function (event) {
        if (event.key === "Escape" && !chatPanel.hidden) {
            closeChat();
        }
    });

    // Scroll to the newest message.
    function scrollToLatest() {
        messagesContainer.scrollTop = messagesContainer.scrollHeight;
    }

    // Add a message safely.
    function addMessage(text, sender) {
        const bubble = document.createElement("div");

        bubble.classList.add("hl-ai-message");

        if (sender === "user") {
            bubble.classList.add("hl-ai-user-message");
        } else {
            bubble.classList.add("hl-ai-bot-message");
        }

        bubble.textContent = text;
        messagesContainer.appendChild(bubble);

        scrollToLatest();
        return bubble;
    }

    // Display the animated three-dot loading indicator.
    function showTypingIndicator() {
        removeTypingIndicator();

        const typing = document.createElement("div");
        typing.id = "hl-ai-typing";
        typing.className = "hl-ai-typing";

        typing.setAttribute("role", "status");
        typing.setAttribute(
            "aria-label",
            "HandyLink Assistant is responding"
        );

        for (let i = 0; i < 3; i++) {
            const dot = document.createElement("span");
            dot.setAttribute("aria-hidden", "true");
            typing.appendChild(dot);
        }

        messagesContainer.appendChild(typing);
        scrollToLatest();
    }

    // Remove the loading indicator.
    function removeTypingIndicator() {
        const typing = document.getElementById("hl-ai-typing");

        if (typing) {
            typing.remove();
        }
    }

    // Keep the loading indicator visible for at least three seconds.
    async function maintainLoadingTime(startTime) {
        const elapsed = Date.now() - startTime;
        const remaining = Math.max(0, 3000 - elapsed);

        if (remaining > 0) {
            await new Promise(function (resolve) {
                setTimeout(resolve, remaining);
            });
        }
    }

    // Send a message to the PHP chatbot.
    async function sendMessage(rawMessage) {
        const message = String(rawMessage || "").trim();

        if (!message || isSending) {
            return;
        }

        if (message.length > 500) {
            addMessage(
                "Please keep your message to 500 characters or fewer.",
                "bot"
            );
            return;
        }

        isSending = true;

        messageInput.disabled = true;
        sendButton.disabled = true;
        sendButton.setAttribute("aria-busy", "true");

        addMessage(message, "user");
        messageInput.value = "";

        showTypingIndicator();

        const loadingStartedAt = Date.now();

        try {
            const response = await fetch("ai_chat.php", {
                method: "POST",
                headers: {
                    "Content-Type": "application/json",
                    "Accept": "application/json"
                },
                credentials: "same-origin",
                body: JSON.stringify({
                    message: message
                })
            });

            // Read the raw response first.
            const responseText = await response.text();

            let data;

            try {
                data = JSON.parse(responseText);
            } catch (parseError) {
                console.error(
                    "HandyLink chatbot returned invalid JSON:",
                    responseText
                );

                if (responseText.trim() === "") {
                    throw new Error(
                        "The server returned an empty response. Please try again."
                    );
                }

                if (
                    responseText.toLowerCase().includes("<!doctype html") ||
                    responseText.toLowerCase().includes("<html")
                ) {
                    throw new Error(
                        "The chatbot received an HTML page instead of a response. Check the PHP endpoint and login session."
                    );
                }

                throw new Error(
                    "The chatbot server returned an invalid response. Check the PHP error log."
                );
            }

            if (!response.ok) {
                throw new Error(
                    data && typeof data.reply === "string"
                        ? data.reply
                        : "The assistant could not process your message."
                );
            }

            if (
                !data ||
                typeof data.reply !== "string"
            ) {
                throw new Error(
                    "The assistant response was incomplete. Please try again."
                );
            }

            // Keep the typing indicator visible for at least three seconds.
            await maintainLoadingTime(loadingStartedAt);

            removeTypingIndicator();

            addMessage(data.reply, "bot");

            // Display service recommendations, if provided.
            if (
                Array.isArray(data.services) &&
                data.services.length > 0
            ) {
                addServiceLinks(data.services);
            }

        } catch (error) {
            await maintainLoadingTime(loadingStartedAt);

            removeTypingIndicator();

            console.error("HandyLink chatbot error:", error);

            addMessage(
                error.message ||
                "Sorry, I couldn't connect. Please try again.",
                "bot"
            );

        } finally {
            removeTypingIndicator();

            isSending = false;

            messageInput.disabled = false;
            sendButton.disabled = false;

            sendButton.removeAttribute("aria-busy");
            messageInput.focus();
        }
    }

    // Render service recommendations safely.
    function addServiceLinks(services) {
        const list = document.createElement("div");
        list.className = "hl-ai-service-links";

        services.slice(0, 5).forEach(function (service) {
            if (
                !service ||
                typeof service.name !== "string" ||
                typeof service.url !== "string"
            ) {
                return;
            }

            // Only allow links to this site's client service page.
            let parsedUrl;

            try {
                parsedUrl = new URL(
                    service.url,
                    window.location.href
                );
            } catch (error) {
                return;
            }

            if (
                parsedUrl.origin !== window.location.origin ||
                !parsedUrl.pathname.endsWith(
                    "/client/find_services.php"
                )
            ) {
                return;
            }

            const link = document.createElement("a");

            link.href = parsedUrl.href;
            link.textContent = service.name;
            link.className = "hl-ai-service-link";

            list.appendChild(link);
        });

        if (list.children.length > 0) {
            messagesContainer.appendChild(list);
            scrollToLatest();
        }
    }

    // Handle message form submission.
    chatForm.addEventListener("submit", function (event) {
        event.preventDefault();
        sendMessage(messageInput.value);
    });

    // Handle predefined quick-action buttons.
    messagesContainer.addEventListener("click", function (event) {
        const target = event.target;

        if (!(target instanceof Element)) {
            return;
        }

        const quickButton = target.closest("[data-message]");

        if (!quickButton || isSending) {
            return;
        }

        sendMessage(quickButton.dataset.message);
    });
});
