/**
 * Enterprise Admin Panel - Goodbye Page Scripts
 *
 * Security features:
 * - Prevents back button navigation
 * - Clears sensitive data from storage
 * - Animated stars background
 */
(function() {
    'use strict';

    // ================================================================
    // Prevent back button navigation
    // ================================================================

    // Replace current history state
    if (window.history && window.history.replaceState) {
        window.history.replaceState(null, '', window.location.href);
    }

    // Push a new state and handle popstate
    window.history.pushState(null, '', window.location.href);
    window.addEventListener('popstate', function() {
        window.history.pushState(null, '', window.location.href);
    });

    // ================================================================
    // Create animated stars
    // ================================================================

    var starsContainer = document.getElementById('eap-stars');
    if (starsContainer) {
        var starCount = 50;

        for (var i = 0; i < starCount; i++) {
            var star = document.createElement('div');
            star.className = 'eap-goodbye__star';
            star.style.left = Math.random() * 100 + '%';
            star.style.top = Math.random() * 100 + '%';
            star.style.animationDelay = Math.random() * 2 + 's';
            star.style.animationDuration = (2 + Math.random() * 2) + 's';
            starsContainer.appendChild(star);
        }
    }

    // ================================================================
    // Clear any sensitive data from sessionStorage/localStorage
    // ================================================================

    try {
        sessionStorage.clear();
        // Don't clear all localStorage, just admin-related keys
        for (var key in localStorage) {
            if (localStorage.hasOwnProperty(key)) {
                if (key.startsWith('eap_') || key.startsWith('admin_')) {
                    localStorage.removeItem(key);
                }
            }
        }
    } catch (e) {
        // Ignore storage errors
    }
})();
