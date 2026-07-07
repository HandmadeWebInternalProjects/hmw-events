/**
 * Voucher Code Element JavaScript
 * 
 * Handles voucher validation and UI interactions for the Breakdance element.
 */

(function() {
    'use strict';

    class HMWEventsVoucherCode {
        constructor(element) {
            this.wrapper = element;
            this.voucherData = null;
            
            // Get elements
            this.input = this.wrapper.querySelector('.hmwevents-voucher-input');
            this.applyBtn = this.wrapper.querySelector('.hmwevents-voucher-button__apply');
            this.removeBtn = this.wrapper.querySelector('.hmwevents-voucher-button__remove');
            this.loading = this.wrapper.querySelector('.hmwevents-voucher-loading');
            this.success = this.wrapper.querySelector('.hmwevents-voucher-success');
            this.error = this.wrapper.querySelector('.hmwevents-voucher-error');
            this.hiddenField = this.wrapper.querySelector('.hmwevents-voucher-code-field');
            this.discountDataField = this.wrapper.querySelector('.hmwevents-voucher-discount-data');
            
            // Get data attributes
            this.courseId = this.wrapper.dataset.courseId;
            this.applyToField = this.wrapper.dataset.applyToField;
            
            this.init();
        }

        init() {
            if (this.wrapper.dataset.initialized) return;
            this.wrapper.dataset.initialized = 'true';
            
            this.bindEvents();
        }

        bindEvents() {
            // Apply voucher
            if (this.applyBtn) {
                this.applyBtn.addEventListener('click', () => this.applyVoucher());
            }

            // Remove voucher
            if (this.removeBtn) {
                this.removeBtn.addEventListener('click', () => this.removeVoucher());
            }

            // Allow Enter key to apply
            if (this.input) {
                this.input.addEventListener('keypress', (e) => {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        this.applyVoucher();
                    }
                });
            }
        }

        async applyVoucher() {
            const code = this.input.value.trim();
            
            if (!code) {
                this.showError('Please enter a voucher code');
                return;
            }

            if (!this.courseId) {
                this.showError('Course ID not found. Please ensure you have a valid course selected.');
                return;
            }

            this.hideMessages();
            this.loading.style.display = 'flex';
            this.applyBtn.disabled = true;

            try {
                const response = await fetch('/wp-json/hmwevents/v1/voucher/validate', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        voucher_code: code,
                        course_id: parseInt(this.courseId),
                    }),
                });

                const data = await response.json();

                if (response.ok && data.success) {
                    this.voucherData = data;
                    this.showSuccess(data);
                    this.hiddenField.value = code;
                    this.discountDataField.value = JSON.stringify(data);
                    
                    // Dispatch event for payment form integration
                    window.dispatchEvent(new CustomEvent('cmsVoucherApplied', {
                        detail: { code, data }
                    }));
                } else {
                    this.showError(data.message || 'Invalid voucher code');
                }
            } catch (err) {
                console.error('Voucher validation error:', err);
                this.showError('Unable to validate voucher. Please try again.');
            } finally {
                this.loading.style.display = 'none';
                this.applyBtn.disabled = false;
            }
        }

        removeVoucher() {
            this.input.value = '';
            this.hiddenField.value = '';
            this.discountDataField.value = '';
            this.voucherData = null;
            this.hideMessages();
            
            // Dispatch event for payment form integration
            window.dispatchEvent(new CustomEvent('cmsVoucherRemoved'));
        }

        showSuccess(data) {
            this.hideMessages();
            this.success.style.display = 'flex';

            const voucher = data.voucher;
            const pricing = data.pricing;

            // Show voucher value
            const discountTypeEl = this.success.querySelector('.hmwevents-voucher-discount-type');
            if (discountTypeEl && voucher.remaining_value) {
                discountTypeEl.textContent = `$${parseFloat(voucher.remaining_value).toFixed(2)} voucher value`;
            }

            // Full payment details
            this.success.querySelector('.hmwevents-voucher-full-original').textContent = `$${pricing.full.original_amount.toFixed(2)}`;
            this.success.querySelector('.hmwevents-voucher-full-final').textContent = `$${pricing.full.final_amount.toFixed(2)}`;
            this.success.querySelector('.hmwevents-voucher-full-saved').textContent = `$${pricing.full.discount_amount.toFixed(2)}`;

            // Deposit details (if available)
            if (pricing.deposit) {
                const depositLine = this.success.querySelector('.hmwevents-voucher-deposit-line');
                depositLine.style.display = 'block';
                this.success.querySelector('.hmwevents-voucher-deposit-original').textContent = `$${pricing.deposit.original_amount.toFixed(2)}`;
                this.success.querySelector('.hmwevents-voucher-deposit-final').textContent = `$${pricing.deposit.final_amount.toFixed(2)}`;
            }
        }

        showError(message) {
            this.hideMessages();
            this.error.style.display = 'flex';
            this.error.querySelector('.hmwevents-voucher-error-message').textContent = message;
        }

        hideMessages() {
            this.success.style.display = 'none';
            this.error.style.display = 'none';
        }
    }

    // Initialize on DOM ready
    function init() {
        const elements = document.querySelectorAll('.hmwevents-voucher-wrapper');
        elements.forEach(element => {
            if (!element.hmweventsVoucherInstance) {
                element.hmweventsVoucherInstance = new HMWEventsVoucherCode(element);
            }
        });
    }

    // Wait for DOM ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    // Expose global API
    window.HMWEventsVoucher = {
        getCurrentVoucher: function() {
            return window.cmsVoucherData || null;
        },
        hasVoucher: function() {
            return !!window.cmsVoucherData;
        },
        getDiscountAmount: function(isDeposit = false) {
            if (!window.cmsVoucherData) return 0;
            const pricing = isDeposit && window.cmsVoucherData.data.pricing.deposit
                ? window.cmsVoucherData.data.pricing.deposit
                : window.cmsVoucherData.data.pricing.full;
            return pricing.discount_amount;
        },
        getFinalAmount: function(isDeposit = false) {
            if (!window.cmsVoucherData) return null;
            const pricing = isDeposit && window.cmsVoucherData.data.pricing.deposit
                ? window.cmsVoucherData.data.pricing.deposit
                : window.cmsVoucherData.data.pricing.full;
            return pricing.final_amount;
        }
    };

    // Make the class available globally for re-initialization
    window.HMWEventsVoucherCode = HMWEventsVoucherCode;

})();
