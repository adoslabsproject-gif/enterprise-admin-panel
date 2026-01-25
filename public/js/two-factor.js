/**
 * Enterprise Admin Panel - Two-Factor Authentication Scripts
 *
 * Features:
 * - Auto-format code input (numbers only for TOTP)
 * - Toggle recovery form visibility
 */
(function() {
    'use strict';

    document.addEventListener('DOMContentLoaded', function() {
        initCodeInput();
        initRecoveryToggle();
    });

    function initCodeInput() {
        var codeInput = document.getElementById('code');
        if (!codeInput) return;

        codeInput.addEventListener('input', function(e) {
            // Allow only numbers for TOTP codes
            var value = e.target.value.replace(/[^0-9]/g, '');
            e.target.value = value;
        });
    }

    function initRecoveryToggle() {
        var recoveryLink = document.getElementById('eap-show-recovery-form');
        var recoveryForm = document.getElementById('eap-recovery-form');

        if (!recoveryLink || !recoveryForm) return;

        recoveryLink.addEventListener('click', function(e) {
            e.preventDefault();
            recoveryForm.classList.add('eap-2fa__recovery-form--visible');
            var recoveryInput = document.getElementById('recovery-code');
            if (recoveryInput) {
                recoveryInput.focus();
            }
        });
    }
})();
