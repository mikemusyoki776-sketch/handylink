
document.addEventListener('DOMContentLoaded', function () {

    // Prevent duplicate event listeners
    if (document.documentElement.dataset.providerNotificationsInit === 'true') {
        return;
    }
    document.documentElement.dataset.providerNotificationsInit = 'true';

    const notificationTrigger = document.getElementById('notificationTrigger');
    const notificationDropdown = document.getElementById('notificationDropdown');

    const profileTrigger = document.getElementById('profileTrigger');
    const profileDropdown = document.getElementById('profileDropdown');

    const mobileMenuToggle = document.getElementById('mobileMenuToggle');
    const mainNav = document.getElementById('mainNav');

    function closeNotificationMenu() {
        notificationDropdown?.classList.remove('open');
        notificationTrigger?.setAttribute('aria-expanded', 'false');
    }

    function closeProfileMenu() {
        profileDropdown?.classList.remove('open');
        profileTrigger?.setAttribute('aria-expanded', 'false');
    }

    // Notification dropdown
    if (notificationTrigger && notificationDropdown) {
        notificationTrigger.addEventListener('click', function (event) {
            event.stopPropagation();

            closeProfileMenu();

            const isOpen = notificationDropdown.classList.toggle('open');
            notificationTrigger.setAttribute('aria-expanded', String(isOpen));
        });

        notificationDropdown.addEventListener('click', function (event) {
            event.stopPropagation();
        });
    }

    // Profile dropdown
    if (profileTrigger && profileDropdown) {
        profileTrigger.addEventListener('click', function (event) {
            event.stopPropagation();

            closeNotificationMenu();

            const isOpen = profileDropdown.classList.toggle('open');
            profileTrigger.setAttribute('aria-expanded', String(isOpen));
        });

        profileDropdown.addEventListener('click', function (event) {
            event.stopPropagation();
        });
    }

    // Close menus when clicking outside
    document.addEventListener('click', function () {
        closeNotificationMenu();
        closeProfileMenu();
    });

    // Mobile navigation
    if (mobileMenuToggle && mainNav) {
        mobileMenuToggle.addEventListener('click', function (event) {
            event.stopPropagation();

            const isOpen = mainNav.classList.toggle('open');
            mobileMenuToggle.setAttribute('aria-expanded', String(isOpen));
        });
    }

    // DARK MODE — unchanged
    const darkModeToggle = document.getElementById('darkModeToggle');
    const themeIcon = document.getElementById('themeIcon');
    const themeText = document.getElementById('themeText');

    function updateThemeButton() {
        const dark = document.body.classList.contains('dark-mode');

        if (themeIcon) {
            themeIcon.className = dark ? 'fas fa-sun' : 'fas fa-moon';
        }

        if (themeText) {
            themeText.textContent = dark ? 'Light Mode' : 'Dark Mode';
        }
    }

    try {
        const savedTheme = localStorage.getItem('handylink-provider-theme');

        document.body.classList.toggle('dark-mode', savedTheme === 'dark');
    } catch (error) {
        console.error('Theme loading error:', error);
    }

    updateThemeButton();

    if (darkModeToggle) {
        darkModeToggle.addEventListener('click', function () {
            document.body.classList.toggle('dark-mode');

            const dark = document.body.classList.contains('dark-mode');

            try {
                localStorage.setItem(
                    'handylink-provider-theme',
                    dark ? 'dark' : 'light'
                );
            } catch (error) {
                console.error('Theme saving error:', error);
            }

            updateThemeButton();
        });
    }

});
