/**
 * Enterprise Admin Panel - Login Page Scripts
 *
 * Handles emergency recovery modal interactions.
 */
(function() {
    'use strict';

    // Wait for DOM to be ready
    document.addEventListener('DOMContentLoaded', function() {
        initRecoveryModal();
    });

    function initRecoveryModal() {
        var modal = document.getElementById('eap-recovery-modal');
        var openBtn = document.getElementById('eap-open-recovery');
        var closeBtn = document.getElementById('eap-close-recovery');
        var cancelBtn = document.getElementById('eap-cancel-recovery');

        // If modal doesn't exist, emergency recovery is disabled
        if (!modal || !openBtn) {
            return;
        }

        function openModal() {
            modal.classList.add('eap-modal--active');
            var recoveryEmail = document.getElementById('recovery-email');
            var loginEmail = document.getElementById('email');

            // Copy email from login form if filled
            if (loginEmail && loginEmail.value && recoveryEmail) {
                recoveryEmail.value = loginEmail.value;
                var tokenField = document.getElementById('recovery-token');
                if (tokenField) {
                    tokenField.focus();
                }
            } else if (recoveryEmail) {
                recoveryEmail.focus();
            }
        }

        function closeModal() {
            modal.classList.remove('eap-modal--active');
        }

        // Event listeners
        openBtn.addEventListener('click', openModal);

        if (closeBtn) {
            closeBtn.addEventListener('click', closeModal);
        }

        if (cancelBtn) {
            cancelBtn.addEventListener('click', closeModal);
        }

        // Close on backdrop click
        modal.addEventListener('click', function(e) {
            if (e.target === modal) {
                closeModal();
            }
        });

        // Close on Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && modal.classList.contains('eap-modal--active')) {
                closeModal();
            }
        });
    }
})();
