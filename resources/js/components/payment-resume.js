/**
 * Payment Resume Script
 *
 * Handles the payment recovery flow for abandoned bookings.
 *
 * @package HMWEvents
 * @since 1.0.0
 */

(function($) {
    'use strict';

    let stripe = null;
    let cardElement = null;
    let bookingData = null;

    /**
     * Initialize the payment resume functionality.
     */
    function init() {
        if (!cmsPaymentResume.token) {
            showError('No recovery token found in URL.');
            return;
        }

        // Load booking details (Stripe will be initialized after we get the educator's key)
        loadBookingDetails();
    }

    /**
     * Load booking details from the API.
     */
    function loadBookingDetails() {
        $.ajax({
            url: cmsPaymentResume.restUrl + 'payment/resume/' + cmsPaymentResume.token,
            method: 'GET',
            success: function(response) {
                if (response.success) {
                    bookingData = response.data;
                    
                    // Initialize Stripe with educator's key
                    if (!bookingData.stripe_publishable_key) {
                        showError('Payment system not configured. Please contact support.');
                        return;
                    }
                    
                    stripe = Stripe(bookingData.stripe_publishable_key);
                    
                    displayBookingDetails(bookingData);
                    setupStripeElements(bookingData.client_secret);
                } else {
                    handleApiError(response);
                }
            },
            error: function(xhr) {
                handleAjaxError(xhr);
            }
        });
    }

    /**
     * Display booking details in the UI.
     *
     * @param {Object} data Booking data from API.
     */
    function displayBookingDetails(data) {
        // Hide loading, show form
        $('#hmwevents-payment-loading').hide();
        $('#hmwevents-payment-form-container').show();

        // Populate booking summary
        $('#hmwevents-booking-reference').text(data.booking_reference || 'N/A');
        $('#hmwevents-course-name').text(data.course_name || 'N/A');
        $('#hmwevents-customer-name').text(data.customer_name || 'N/A');
        $('#hmwevents-amount').text(formatCurrency(data.amount));

        // Format expiration date
        if (data.expires_at) {
            const expiresAt = new Date(data.expires_at);
            $('#hmwevents-expires-at').text(formatDateTime(expiresAt));
        }
    }

    /**
     * Setup Stripe Elements.
     *
     * @param {string} clientSecret Client secret from payment intent.
     */
    function setupStripeElements(clientSecret) {
        const elements = stripe.elements();
        
        // Create card element
        cardElement = elements.create('card', {
            style: {
                base: {
                    fontSize: '16px',
                    color: '#32325d',
                    fontFamily: '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif',
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

        cardElement.mount('#hmwevents-card-element');

        // Handle real-time validation errors
        cardElement.on('change', function(event) {
            const displayError = $('#hmwevents-card-errors');
            if (event.error) {
                displayError.text(event.error.message).show();
            } else {
                displayError.hide();
            }
        });

        // Handle form submission
        $('#hmwevents-resume-payment-form').on('submit', function(e) {
            e.preventDefault();
            processPayment(clientSecret);
        });
    }

    /**
     * Process the payment.
     *
     * @param {string} clientSecret Client secret from payment intent.
     */
    function processPayment(clientSecret) {
        setLoading(true);

        stripe.confirmCardPayment(clientSecret, {
            payment_method: {
                card: cardElement,
                billing_details: {
                    name: bookingData.customer_name,
                    email: bookingData.customer_email
                }
            }
        }).then(function(result) {
            if (result.error) {
                // Show error to customer
                showPaymentError(result.error.message);
                setLoading(false);
            } else {
                // Payment succeeded
                if (result.paymentIntent.status === 'succeeded') {
                    confirmPaymentOnServer(result.paymentIntent.id)
                        .done(function() {
                            handlePaymentSuccess(result.paymentIntent);
                        })
                        .fail(function(xhr) {
                            let message = 'Payment succeeded, but we could not finalize your booking automatically. Please contact support with your payment reference.';

                            if (xhr && xhr.responseJSON && xhr.responseJSON.message) {
                                message = xhr.responseJSON.message;
                            }

                            showPaymentError(message);
                        })
                        .always(function() {
                            setLoading(false);
                        });
                }
            }
        });
    }

    /**
     * Confirm payment on the server so booking/payment status is persisted.
     *
     * @param {string} paymentIntentId Stripe payment intent ID.
     * @return {jqXHR}
     */
    function confirmPaymentOnServer(paymentIntentId) {
        return $.ajax({
            url: cmsPaymentResume.restUrl + 'payment/confirm',
            method: 'POST',
            data: {
                payment_intent_id: paymentIntentId
            }
        });
    }

    /**
     * Handle successful payment.
     *
     * @param {Object} paymentIntent Stripe payment intent object.
     */
    function handlePaymentSuccess(paymentIntent) {
        // Hide form, show success
        $('#hmwevents-payment-form-container').hide();
        $('#hmwevents-payment-success').show();
        $('#hmwevents-success-booking-number').text(bookingData.booking_number || bookingData.booking_reference);

        // Optional: Track conversion
        if (typeof gtag !== 'undefined') {
            gtag('event', 'purchase', {
                transaction_id: paymentIntent.id,
                value: bookingData.amount,
                currency: 'AUD',
                items: [{
                    item_name: bookingData.course_name
                }]
            });
        }
    }

    /**
     * Show payment error message.
     *
     * @param {string} message Error message.
     */
    function showPaymentError(message) {
        const resultDiv = $('#hmwevents-payment-result');
        resultDiv.html('<div class="hmwevents-alert hmwevents-alert-error">' + escapeHtml(message) + '</div>').show();
        
        // Scroll to error
        $('html, body').animate({
            scrollTop: resultDiv.offset().top - 100
        }, 500);
    }

    /**
     * Handle API error response.
     *
     * @param {Object} response API response.
     */
    function handleApiError(response) {
        $('#hmwevents-payment-loading').hide();

        // Check if payment already completed
        if (response.code === 'payment_completed') {
            $('#hmwevents-payment-completed').show();
            return;
        }

        // Check if token expired (410 status)
        if (response.data && response.data.status === 410) {
            $('#hmwevents-payment-expired').show();
            return;
        }

        // Show general error
        const message = response.message || 'Unable to load booking details. Please try again.';
        $('#hmwevents-error-message').text(message);
        $('#hmwevents-payment-error').show();
    }

    /**
     * Handle AJAX error.
     *
     * @param {Object} xhr XHR object.
     */
    function handleAjaxError(xhr) {
        $('#hmwevents-payment-loading').hide();

        let message = 'An error occurred. Please try again.';

        // Check if payment already completed
        if (xhr.responseJSON && xhr.responseJSON.code === 'payment_completed') {
            $('#hmwevents-payment-completed').show();
            return;
        }

        if (xhr.status === 410) {
            $('#hmwevents-payment-expired').show();
            return;
        }

        if (xhr.status === 404) {
            message = 'Invalid or expired recovery token.';
        } else if (xhr.responseJSON && xhr.responseJSON.message) {
            message = xhr.responseJSON.message;
        }

        $('#hmwevents-error-message').text(message);
        $('#hmwevents-payment-error').show();
    }

    /**
     * Show general error message.
     *
     * @param {string} message Error message.
     */
    function showError(message) {
        $('#hmwevents-payment-loading').hide();
        $('#hmwevents-error-message').text(message);
        $('#hmwevents-payment-error').show();
    }

    /**
     * Set loading state.
     *
     * @param {boolean} loading Whether loading.
     */
    function setLoading(loading) {
        const $button = $('#hmwevents-submit-payment');
        const $buttonText = $button.find('.hmwevents-button-text');
        const $buttonSpinner = $button.find('.hmwevents-button-spinner');

        if (loading) {
            $button.prop('disabled', true);
            $buttonText.hide();
            $buttonSpinner.show();
        } else {
            $button.prop('disabled', false);
            $buttonText.show();
            $buttonSpinner.hide();
        }
    }

    /**
     * Format currency amount.
     *
     * @param {number} amount Amount in dollars.
     * @return {string} Formatted amount.
     */
    function formatCurrency(amount) {
        return '$' + Number(amount || 0).toFixed(2);
    }

    /**
     * Format date and time.
     *
     * @param {Date} date Date object.
     * @return {string} Formatted date/time.
     */
    function formatDateTime(date) {
        const options = {
            year: 'numeric',
            month: 'long',
            day: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
            timeZoneName: 'short'
        };
        return date.toLocaleDateString('en-AU', options);
    }

    /**
     * Escape HTML to prevent XSS.
     *
     * @param {string} text Text to escape.
     * @return {string} Escaped text.
     */
    function escapeHtml(text) {
        const map = {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        };
        return text.replace(/[&<>"']/g, function(m) { return map[m]; });
    }

    // Initialize on document ready
    $(document).ready(init);

})(jQuery);
