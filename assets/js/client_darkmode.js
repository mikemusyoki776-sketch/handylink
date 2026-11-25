
document.addEventListener('DOMContentLoaded', function () {
    const themeKey = 'handylink_client_theme';
    const html = document.documentElement;

    function applyTheme(theme) {
        const selectedTheme = theme === 'dark' ? 'dark' : 'light';

        html.setAttribute('data-theme', selectedTheme);

        try {
            localStorage.setItem(themeKey, selectedTheme);
        } catch (error) {
            // The theme still applies to the current page.
        }

        document.querySelectorAll('[data-theme-choice]').forEach(function (button) {
            const active = button.dataset.themeChoice === selectedTheme;

            button.classList.toggle('selected', active);
            button.setAttribute('aria-pressed', String(active));
        });

        const toggle = document.getElementById('headerThemeToggle');

        if (toggle) {
            toggle.innerHTML = selectedTheme === 'dark'
                ? '<i class="fa-solid fa-sun"></i>'
                : '<i class="fa-solid fa-moon"></i>';

            toggle.title = selectedTheme === 'dark'
                ? 'Switch to light mode'
                : 'Switch to dark mode';
        }
    }

    let savedTheme = 'light';

    try {
        savedTheme = localStorage.getItem(themeKey) || 'light';
    } catch (error) {
        savedTheme = 'light';
    }

    applyTheme(savedTheme);

    document.querySelectorAll('[data-theme-choice]').forEach(function (button) {
        button.addEventListener('click', function () {
            applyTheme(button.dataset.themeChoice);
        });
    });

    const toggle = document.getElementById('headerThemeToggle');

    if (toggle) {
        toggle.addEventListener('click', function () {
            const currentTheme = html.getAttribute('data-theme') || 'light';

            applyTheme(currentTheme === 'dark' ? 'light' : 'dark');
        });
    }
});
