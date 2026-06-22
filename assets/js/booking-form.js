/**
 * Booking Form JavaScript
 * 
 * Handles voucher validation, payment type selection, and Stripe integration.
 */

(function() {
    'use strict';

    class HMWEventsBookingForm {
        constructor(form) {
            this.form = form;
            this.stripe = null;
            this.elements = null;
            this.cardElement = null;
            this.voucherData = null;
            this.isProcessing = false;

            this.init();
        }

        async init() {
            // Initialize Stripe
            await this.initializeStripe();

            // Bind events
            this.bindEvents();

            // Initialize payment type active state
            this.updatePaymentTypeActiveState();
            this.updatePaymentSectionVisibility();
        }

        async initializeStripe() {
            try {
                // Get Stripe publishable key
                const response = await fetch(`${cmsBookingData.restUrl}/payment/gateway-config?educator_id=${cmsBookingData.educatorId}`);
                const data = await response.json();

                if (data.success && data.config.publishable_key) {
                    this.stripe = Stripe(data.config.publishable_key);
                    this.elements = this.stripe.elements();

                    // Create card element
                    this.cardElement = this.elements.create('card', {
                        style: {
                            base: {
                                fontSize: '16px',
                                color: '#32325d',
                                '::placeholder': {
                                    color: '#aab7c4'
                                }
                            },
                            invalid: {
                                color: '#fa755a',
                                iconColor: '#fa755a'
                            }
                        }
                    });

                    this.cardElement.mount('#card-element');

                    // Handle card errors
                    this.cardElement.on('change', (event) => {
                        const displayError = document.getElementById('card-errors');
                        if (event.error) {
                            displayError.textContent = event.error.message;
                        } else {
                            displayError.textContent = '';
                        }
                    });
                } else {
                    throw new Error('Unable to initialize payment system');
                }
            } catch (error) {
                console.error('Stripe initialization error:', error);
                this.showMessage('Unable to initialize payment system. Please contact support.', 'error');
            }
        }

        bindEvents() {
            // Voucher apply
            document.getElementById('apply-voucher').addEventListener('click', () => {
                this.applyVoucher();
            });

            // Voucher remove
            document.getElementById('remove-voucher').addEventListener('click', () => {
                this.removeVoucher();
            });

            // Payment type change
            const paymentTypeInputs = document.querySelectorAll('input[name="payment_type"]');
            paymentTypeInputs.forEach(input => {
                input.addEventListener('change', () => {
                    this.updatePaymentTypeActiveState();
                    this.updatePrices();
                });
            });

            // Form submission
            this.form.addEventListener('submit', (e) => {
                e.preventDefault();
                this.handleSubmit();
            });

            // Enter key on voucher input
            document.getElementById('voucher_code').addEventListener('keypress', (e) => {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    this.applyVoucher();
                }
            });
        }

        updatePaymentTypeActiveState() {
            const options = document.querySelectorAll('.hmwevents-payment-option');
            options.forEach(option => {
                const radio = option.querySelector('input[type="radio"]');
                if (radio && radio.checked) {
                    option.classList.add('active');
                } else {
                    option.classList.remove('active');
                }
            });
        }

        async applyVoucher() {
            const voucherInput = document.getElementById('voucher_code');
            const voucherCode = voucherInput.value.trim();

            if (!voucherCode) {
                this.showVoucherMessage('Please enter a voucher or coupon code', 'error');
                return;
            }

            try {
                // Try voucher first
                let response = await fetch(`${cmsBookingData.restUrl}/voucher/validate`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        voucher_code: voucherCode,
                        course_id: cmsBookingData.courseId,
                    })
                });

                let data = await response.json();

                // If voucher fails, try coupon
                if (!response.ok || !data.success) {
                    response = await fetch(`${cmsBookingData.restUrl}/coupon/validate`, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                        },
                        body: JSON.stringify({
                            coupon_code: voucherCode,
                            course_id: cmsBookingData.courseId,
                            educator_id: cmsBookingData.educatorId,
                            payment_type: this.getSelectedPaymentType(),
                            amount: this.getSelectedAmount(),
                        })
                    });

                    data = await response.json();
                }

                if (response.ok && data.success) {
                    this.voucherData = data;
                    document.getElementById('voucher_data').value = JSON.stringify(data);
                    this.showVoucherSuccess(data);
                    this.updatePrices();
                } else {
                    this.showVoucherMessage(data.message || 'Invalid voucher or coupon code', 'error');
                }
            } catch (error) {
                console.error('Validation error:', error);
                this.showVoucherMessage('Unable to validate code. Please try again.', 'error');
            }
        }

        getSelectedPaymentType() {
            const depositRadio = document.getElementById('payment_deposit');
            return (depositRadio && depositRadio.checked) ? 'deposit' : 'full';
        }

        getSelectedAmount() {
            const paymentType = this.getSelectedPaymentType();
            const amount = paymentType === 'deposit' ? cmsBookingData.depositCost : cmsBookingData.fullCost;
            const parsed = parseFloat(amount);
            return Number.isFinite(parsed) ? parsed : 0;
        }

        removeVoucher() {
            this.voucherData = null;
            document.getElementById('voucher_code').value = '';
            document.getElementById('voucher_data').value = '';
            document.getElementById('voucher-details').style.display = 'none';
            document.getElementById('voucher-message').style.display = 'none';
            this.updatePrices();
        }

        showVoucherSuccess(data) {
            const detailsEl = document.getElementById('voucher-details');
            const descEl = document.getElementById('voucher-description');
            const messageEl = document.getElementById('voucher-message');

            messageEl.style.display = 'none';
            detailsEl.style.display = 'block';

            const currencySymbol = cmsBookingData.currencySymbol || '$';
            
            // Check if it's a voucher or coupon
            if (data.voucher) {
                // Voucher
                const voucher = data.voucher;
                descEl.textContent = `${voucher.code} - ${currencySymbol}${parseFloat(voucher.remaining_value).toFixed(2)} voucher value`;
            } else if (data.coupon) {
                // Coupon
                const coupon = data.coupon;
                let discountText = '';
                if (coupon.discount_type === 'percentage') {
                    discountText = `${coupon.discount_value}% off`;
                } else {
                    discountText = `${currencySymbol}${parseFloat(coupon.discount_value).toFixed(2)} off`;
                }
                descEl.textContent = `${coupon.code} - ${discountText}${coupon.description ? ' - ' + coupon.description : ''}`;
            }
        }

        showVoucherMessage(message, type = 'info') {
            const messageEl = document.getElementById('voucher-message');
            messageEl.textContent = message;
            messageEl.className = `hmwevents-voucher-message hmwevents-voucher-${type}`;
            messageEl.style.display = 'block';

            document.getElementById('voucher-details').style.display = 'none';
        }

        updatePrices() {
            const currencySymbol = cmsBookingData.currencySymbol || '$';
            
            if (!this.voucherData) {
                // Reset to original prices
                document.querySelectorAll('.hmwevents-payment-price').forEach(el => {
                    const original = el.getAttribute('data-original');
                    el.innerHTML = `${currencySymbol}${parseFloat(original).toFixed(2)}`;
                });
                this.updatePaymentSectionVisibility();
                return;
            }

            // Update full payment price
            const fullPriceEl = document.querySelector('[data-type="full"] .hmwevents-payment-price');
            if (fullPriceEl && this.voucherData.pricing.full) {
                const pricing = this.voucherData.pricing.full;
                if (pricing.discount_amount > 0) {
                    fullPriceEl.innerHTML = `
                        <span style="text-decoration: line-through; opacity: 0.6; font-size: 0.9em; margin-right: 8px;">
                            ${currencySymbol}${pricing.original_amount.toFixed(2)}
                        </span>
                        <span>${currencySymbol}${pricing.final_amount.toFixed(2)}</span>
                    `;
                }
            }

            // Update deposit price
            const depositPriceEl = document.querySelector('[data-type="deposit"] .hmwevents-payment-price');
            if (depositPriceEl && this.voucherData.pricing.deposit) {
                const pricing = this.voucherData.pricing.deposit;
                if (pricing.discount_amount > 0) {
                    depositPriceEl.innerHTML = `
                        <span style="text-decoration: line-through; opacity: 0.6; font-size: 0.9em; margin-right: 8px;">
                            ${currencySymbol}${pricing.original_amount.toFixed(2)}
                        </span>
                        <span>${currencySymbol}${pricing.final_amount.toFixed(2)}</span>
                    `;
                }
            }

            this.updatePaymentSectionVisibility();
        }

        getCurrentPricing() {
            const selectedAmount = this.getSelectedAmount();
            const paymentType = this.getSelectedPaymentType();

            if (!this.voucherData || !this.voucherData.pricing || !this.voucherData.pricing[paymentType]) {
                return {
                    original_amount: selectedAmount,
                    discount_amount: 0,
                    final_amount: selectedAmount,
                };
            }

            const pricing = this.voucherData.pricing[paymentType];
            const originalAmount = parseFloat(pricing.original_amount);
            const discountAmount = parseFloat(pricing.discount_amount);
            const finalAmount = parseFloat(pricing.final_amount);

            return {
                original_amount: Number.isFinite(originalAmount) ? originalAmount : selectedAmount,
                discount_amount: Number.isFinite(discountAmount) ? discountAmount : 0,
                final_amount: Number.isFinite(finalAmount) ? finalAmount : selectedAmount,
            };
        }

        shouldSkipPaymentGateway() {
            const pricing = this.getCurrentPricing();
            return pricing.final_amount <= 0.00001;
        }

        updatePaymentSectionVisibility() {
            const paymentSection = document.querySelector('.hmwevents-payment-section');
            if (!paymentSection) {
                return;
            }

            paymentSection.style.display = this.shouldSkipPaymentGateway() ? 'none' : '';
        }

        async handleSubmit() {
            if (this.isProcessing) return;

            // Validate form
            if (!this.form.checkValidity()) {
                this.form.reportValidity();
                return;
            }

            this.isProcessing = true;
            this.setSubmitButtonState(true);

            try {
                const formData = new FormData(this.form);
                const shouldSkipPayment = this.shouldSkipPaymentGateway();
                let paymentMethodId = null;

                // Create payment method only when payment is still required
                const mothersFirstName = document.getElementById('mothers_first_name')?.value || '';
                const mothersLastName = document.getElementById('mothers_last_name')?.value || '';
                const customerName = `${mothersFirstName} ${mothersLastName}`.trim();
                const email = document.getElementById('email')?.value || '';

                if (!shouldSkipPayment) {
                    if (!this.stripe || !this.cardElement) {
                        throw new Error('Payment system is unavailable. Please refresh and try again.');
                    }

                    const {paymentMethod, error} = await this.stripe.createPaymentMethod({
                        type: 'card',
                        card: this.cardElement,
                        billing_details: {
                            name: customerName,
                            email: email,
                        }
                    });

                    if (error) {
                        throw new Error(error.message);
                    }

                    paymentMethodId = paymentMethod.id;
                }

                // Get reCAPTCHA token if configured
                let recaptchaToken = null;
                if (cmsBookingData.recaptchaSiteKey) {
                    recaptchaToken = await this.getRecaptchaToken(cmsBookingData.recaptchaSiteKey);
                }

                // Collect form data — non-booking fields are hardcoded; booking fields
                // are collected dynamically from the registry via cmsBookingData.bookingFields.
                const data = {
                    course_id: cmsBookingData.courseId,
                    educator_id: cmsBookingData.educatorId,
                    payment_method_id: paymentMethodId,
                    skip_payment_gateway: shouldSkipPayment,
                    is_deposit: formData.get('payment_type') === 'deposit',

                    // Voucher or Coupon
                    voucher_code: this.voucherData && this.voucherData.voucher ? this.voucherData.voucher.code : null,
                    coupon_code: this.voucherData && this.voucherData.coupon ? this.voucherData.coupon.code : null,

                    // reCAPTCHA
                    recaptcha_token: recaptchaToken,
                };

                // Collect all registry-driven booking fields from the form.
                (cmsBookingData.bookingFields || []).forEach(function(field) {
                    if (field.type === 'checkbox') {
                        data[field.key] = formData.get(field.key) === '1';
                    } else {
                        data[field.key] = formData.get(field.key);
                    }
                });

                // Process payment
                const response = await fetch(`${cmsBookingData.restUrl}/payment/process`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify(data)
                });

                const result = await response.json();

                if (response.ok && result.success) {
                    // Handle 3D Secure / requires_action — need to authenticate before confirming
                    if (result.data.client_secret && result.data.payment_status === 'requires_action') {
                        this.showMessage('Completing secure authentication...', 'info');

                        const {error, paymentIntent} = await this.stripe.handleNextAction({
                            clientSecret: result.data.client_secret,
                        });

                        if (error) {
                            throw new Error(error.message);
                        }

                        if (paymentIntent.status === 'succeeded') {
                            // Confirm on server to finalise booking side effects
                            const confirmResponse = await fetch(`${cmsBookingData.restUrl}/payment/confirm`, {
                                method: 'POST',
                                headers: {'Content-Type': 'application/json'},
                                body: JSON.stringify({
                                    payment_intent_id: paymentIntent.id,
                                }),
                            });

                            const confirmResult = await confirmResponse.json();
                            if (!confirmResult.success) {
                                throw new Error(confirmResult.message || 'Failed to confirm payment after authentication');
                            }
                        } else {
                            throw new Error('Payment not completed. Status: ' + paymentIntent.status);
                        }
                    }

                    this.showMessage('Booking successful! Redirecting...', 'success');

                    // Redirect or show success page
                    setTimeout(() => {
                        window.location.href = '/booking-confirmation?booking=' + result.data.booking_number;
                    }, 2000);
                } else {
                    throw new Error(result.message || 'Payment failed');
                }
            } catch (error) {
                console.error('Payment error:', error);
                this.showMessage(error.message || 'An error occurred. Please try again.', 'error');
            } finally {
                this.isProcessing = false;
                this.setSubmitButtonState(false);
            }
        }

        getRecaptchaToken(siteKey) {
            return new Promise((resolve, reject) => {
                grecaptcha.ready(() => {
                    grecaptcha.execute(siteKey, { action: 'hmwevents_booking_submit' })
                        .then(resolve)
                        .catch(reject);
                });
            });
        }

        setSubmitButtonState(processing) {
            const button = document.getElementById('submit-booking');
            const btnText = button.querySelector('.btn-text');
            const btnSpinner = button.querySelector('.btn-spinner');

            if (processing) {
                button.disabled = true;
                btnText.style.display = 'none';
                btnSpinner.style.display = 'inline-block';
            } else {
                button.disabled = false;
                btnText.style.display = 'inline';
                btnSpinner.style.display = 'none';
            }
        }

        showMessage(message, type = 'info') {
            const messagesEl = document.getElementById('form-messages');
            messagesEl.textContent = message;
            messagesEl.className = `hmwevents-form-messages hmwevents-message-${type}`;
            messagesEl.style.display = 'block';

            // Scroll to message
            messagesEl.scrollIntoView({ behavior: 'smooth', block: 'nearest' });

            // Auto-hide success messages
            if (type === 'success') {
                setTimeout(() => {
                    messagesEl.style.display = 'none';
                }, 5000);
            }
        }
    }

    // Initialize on DOM ready
    function init() {
        const form = document.getElementById('hmwevents-booking-form');
        if (form) {
            new HMWEventsBookingForm(form);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

})();
