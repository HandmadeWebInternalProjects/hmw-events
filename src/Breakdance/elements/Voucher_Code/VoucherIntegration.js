/**
 * Voucher Code Integration Script
 * 
 * Integrates voucher validation with Breakdance payment forms.
 * This script should be enqueued when the voucher element is used.
 */

(function() {
    'use strict';

    // Wait for DOM ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initVoucherIntegration);
    } else {
        initVoucherIntegration();
    }

    function initVoucherIntegration() {
        // Listen for voucher events
        window.addEventListener('cmsVoucherApplied', handleVoucherApplied);
        window.addEventListener('cmsVoucherRemoved', handleVoucherRemoved);

        // Intercept form submissions to include voucher code
        document.addEventListener('submit', handleFormSubmit, true);
    }

    /**
     * Handle voucher applied event
     */
    function handleVoucherApplied(event) {
        const { code, data } = event.detail;
        console.log('Voucher applied:', code, data);

        // Update displayed prices in the form
        updatePaymentAmounts(data);

        // Store voucher data for form submission
        window.cmsVoucherData = {
            code: code,
            data: data
        };
    }

    /**
     * Handle voucher removed event
     */
    function handleVoucherRemoved() {
        console.log('Voucher removed');

        // Reset payment amounts
        resetPaymentAmounts();

        // Clear voucher data
        delete window.cmsVoucherData;
    }

    /**
     * Update payment amounts displayed in the form
     */
    function updatePaymentAmounts(voucherData) {
        const pricing = voucherData.pricing;

        // Find payment type radio/select field
        const paymentTypeField = document.querySelector('[name="is_deposit"], [name="payment_type"]');
        if (!paymentTypeField) return;

        // Update full payment display
        updatePriceDisplay('.full-price, [data-full-price]', pricing.full.final_amount);

        // Update deposit display if available
        if (pricing.deposit) {
            updatePriceDisplay('.deposit-price, [data-deposit-price]', pricing.deposit.final_amount);
        }
    }

    /**
     * Reset payment amounts to original values
     */
    function resetPaymentAmounts() {
        // This would need to restore original prices
        // You might want to store original values in data attributes
        const priceElements = document.querySelectorAll('[data-original-price]');
        priceElements.forEach(el => {
            const originalPrice = el.getAttribute('data-original-price');
            if (originalPrice) {
                el.textContent = originalPrice;
            }
        });
    }

    /**
     * Update a price display element
     */
    function updatePriceDisplay(selector, amount) {
        const elements = document.querySelectorAll(selector);
        elements.forEach(el => {
            // Store original price if not already stored
            if (!el.hasAttribute('data-original-price')) {
                el.setAttribute('data-original-price', el.textContent);
            }
            // Update with new price
            el.textContent = `$${amount.toFixed(2)}`;
        });
    }

    /**
     * Handle form submission to include voucher code
     */
    function handleFormSubmit(event) {
        const form = event.target;

        // Check if this is a payment/booking form
        if (!isPaymentForm(form)) return;

        // Add voucher code to form data if applied
        if (window.cmsVoucherData) {
            const voucherInput = form.querySelector('[name="voucher_code"]');
            if (voucherInput) {
                voucherInput.value = window.cmsVoucherData.code;
            } else {
                // Create hidden field if it doesn't exist
                const hiddenField = document.createElement('input');
                hiddenField.type = 'hidden';
                hiddenField.name = 'voucher_code';
                hiddenField.value = window.cmsVoucherData.code;
                form.appendChild(hiddenField);
            }

            console.log('Voucher code added to form submission:', window.cmsVoucherData.code);
        }
    }

    /**
     * Check if form is a payment/booking form
     */
    function isPaymentForm(form) {
        // Check for payment-related fields
        const hasPaymentField = form.querySelector('[name="payment_method_id"], [name="course_id"]');
        return !!hasPaymentField;
    }

    // Expose utility functions globally
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

})();
