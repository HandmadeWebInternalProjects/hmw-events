/**
 * Educator Payments Dashboard JavaScript.
 *
 * Handles cancel and refund actions for bookings.
 *
 * @package HMWEvents
 * @since 1.0.0
 */

(function($) {
    'use strict';

    /**
     * Cancel a booking.
     */
    $(document).on('click', '.hmwevents-cancel-booking', function(e) {
        e.preventDefault();
        
        var $button = $(this);
        var bookingId = $button.data('booking-id');
        var bookingNumber = $button.data('booking-number');
        
        if (!confirm(cmsPayments.confirmCancel)) {
            return;
        }
        
        // Disable button and show loading
        $button.prop('disabled', true).text('Cancelling...');
        
        $.ajax({
            url: cmsPayments.ajaxUrl,
            type: 'POST',
            data: {
                action: 'hmwevents_cancel_booking',
                nonce: cmsPayments.nonce,
                booking_id: bookingId
            },
            success: function(response) {
                if (response.success) {
                    // Show success message
                    showNotice('success', response.data.message);
                    
                    // Reload page after a short delay
                    setTimeout(function() {
                        location.reload();
                    }, 1500);
                } else {
                    showNotice('error', response.data.message || 'An error occurred');
                    $button.prop('disabled', false).text('Cancel');
                }
            },
            error: function() {
                showNotice('error', 'Network error. Please try again.');
                $button.prop('disabled', false).text('Cancel');
            }
        });
    });

    /**
     * Refund a booking.
     */
    $(document).on('click', '.hmwevents-refund-booking', function(e) {
        e.preventDefault();
        
        var $button = $(this);
        var bookingId = $button.data('booking-id');
        var bookingNumber = $button.data('booking-number');
        var amount = parseFloat($button.data('amount'));
        
        // Show refund amount confirmation
        var refundAmount = prompt(
            'Enter refund amount (leave empty for full refund of $' + amount.toFixed(2) + '):',
            amount.toFixed(2)
        );
        
        if (refundAmount === null) {
            return; // User cancelled
        }
        
        // Strip currency symbols/spaces before parsing (e.g. user types "$500")
        refundAmount = parseFloat(String(refundAmount).replace(/[^\d.]/g, ''));
        
        if (isNaN(refundAmount) || refundAmount <= 0) {
            alert('Please enter a valid refund amount.');
            return;
        }
        
        if (refundAmount <= 0 || refundAmount > amount) {
            alert('Invalid refund amount. Must be between $0.01 and $' + amount.toFixed(2));
            return;
        }
        
        if (!confirm(cmsPayments.confirmRefund + '\n\nAmount: $' + refundAmount.toFixed(2))) {
            return;
        }
        
        // Disable button and show loading
        $button.prop('disabled', true).text('Processing...');
        
        $.ajax({
            url: cmsPayments.ajaxUrl,
            type: 'POST',
            data: {
                action: 'hmwevents_refund_booking',
                nonce: cmsPayments.nonce,
                booking_id: bookingId,
                refund_amount: refundAmount
            },
            success: function(response) {
                if (response.success) {
                    // Show success message
                    showNotice('success', response.data.message);
                    
                    // Reload page after a short delay
                    setTimeout(function() {
                        location.reload();
                    }, 2000);
                } else {
                    showNotice('error', response.data.message || 'An error occurred');
                    $button.prop('disabled', false).text('Refund');
                }
            },
            error: function() {
                showNotice('error', 'Network error. Please try again.');
                $button.prop('disabled', false).text('Refund');
            }
        });
    });

    /**
     * Send payment link.
     */
    $(document).on('click', '.hmwevents-send-payment-link', function(e) {
        e.preventDefault();
        
        var $button = $(this);
        var bookingId = $button.data('booking-id');
        var paymentType = $button.data('payment-type'); // 'pending' or 'remaining'
        
        var message = paymentType === 'remaining'
            ? 'Send a payment link to complete the remaining balance?'
            : 'Send a payment link to complete this booking?';
        
        if (!confirm(message)) {
            return;
        }
        
        // Disable button and show loading
        var originalText = $button.text();
        $button.prop('disabled', true).text('Sending...');
        
        $.ajax({
            url: cmsPayments.ajaxUrl,
            type: 'POST',
            data: {
                action: 'hmwevents_send_payment_link',
                nonce: cmsPayments.nonce,
                booking_id: bookingId,
                payment_type: paymentType
            },
            success: function(response) {
                if (response.success) {
                    showNotice('success', response.data.message);
                    // Keep button disabled to prevent spam
                    $button.text('Link Sent');
                } else {
                    showNotice('error', response.data.message || 'An error occurred');
                    $button.prop('disabled', false).text(originalText);
                }
            },
            error: function() {
                showNotice('error', 'Network error. Please try again.');
                $button.prop('disabled', false).text(originalText);
            }
        });
    });

    /**
     * Show admin notice.
     */
    function showNotice(type, message) {
        var $notice = $('<div class="notice notice-' + type + ' is-dismissible"><p>' + message + '</p></div>');
        $('.wrap h1').after($notice);
        
        // Auto-dismiss after 5 seconds
        setTimeout(function() {
            $notice.fadeOut(function() {
                $(this).remove();
            });
        }, 5000);
    }

})(jQuery);
