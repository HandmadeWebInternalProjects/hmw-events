/**
 * Email Queue Admin JavaScript
 */

(function($) {
  'use strict';

  $(document).ready(function() {
    
    /**
     * Handle page number input for quick navigation
     */
    $('#current-page-selector').on('keypress', function(e) {
      if (e.which === 13) { // Enter key
        e.preventDefault();
        const page = parseInt($(this).val());
        const totalPages = parseInt($('.total-pages').text().replace(/,/g, ''));
        
        if (page > 0 && page <= totalPages) {
          const url = new URL(window.location.href);
          url.searchParams.set('paged', page);
          window.location.href = url.toString();
        } else {
          alert('Please enter a valid page number (1-' + totalPages + ')');
          $(this).val($(this).data('original-value'));
        }
      }
    });

    // Store original value
    $('#current-page-selector').data('original-value', $('#current-page-selector').val());

    /**
     * Confirm before sending email immediately
     */
    $('.hmwevents-email-table form[action*="hmwevents_email_send_now"]').on('submit', function(e) {
      if (!confirm('Are you sure you want to send this email immediately?')) {
        e.preventDefault();
        return false;
      }
    });

    /**
     * Add loading state to action buttons
     */
    $('.hmwevents-email-table .inline-form').on('submit', function() {
      const $btn = $(this).find('button');
      $btn.prop('disabled', true);
      
      const originalText = $btn.html();
      $btn.data('original-text', originalText);
      
      // Add spinner
      $btn.html('<span class="dashicons dashicons-update" style="animation: rotation 1s infinite linear;"></span> Processing...');
    });

    /**
     * Auto-focus recipient filter if it has a value
     */
    const $recipientFilter = $('#filter_recipient');
    if ($recipientFilter.val()) {
      $recipientFilter.focus();
    }

    /**
     * Clear filter button - clear the recipient input
     */
    $('.hmwevents-filter-form .button[href*="Clear"]').on('click', function(e) {
      $('#filter_recipient').val('');
    });

    /**
     * Add keyboard shortcut for filtering (Cmd/Ctrl + K)
     */
    $(document).on('keydown', function(e) {
      if ((e.metaKey || e.ctrlKey) && e.key === 'k') {
        e.preventDefault();
        $('#filter_recipient').focus().select();
      }
    });

    /**
     * Highlight status cards on hover for better UX
     */
    $('.hmwevents-email-card').on('mouseenter', function() {
      $(this).addClass('hover');
    }).on('mouseleave', function() {
      $(this).removeClass('hover');
    });

    /**
     * Add row click functionality for easier interaction
     */
    let clickTimer = null;
    $('.hmwevents-email-table tbody tr').on('click', function(e) {
      // Don't trigger if clicking on a button or form
      if ($(e.target).closest('button, form, a').length > 0) {
        return;
      }

      // Toggle row highlight
      const $row = $(this);
      
      // Clear other highlights
      $('.hmwevents-email-table tbody tr').not($row).removeClass('highlight');
      
      // Toggle this row
      $row.toggleClass('highlight');
      
      // Auto-remove highlight after 3 seconds
      clearTimeout(clickTimer);
      clickTimer = setTimeout(function() {
        $row.removeClass('highlight');
      }, 3000);
    });

  });

})(jQuery);

// Add CSS for rotation animation
const style = document.createElement('style');
style.textContent = `
  @keyframes rotation {
    from { transform: rotate(0deg); }
    to { transform: rotate(359deg); }
  }
  
  .hmwevents-email-table tbody tr.highlight {
    background-color: #eff6ff !important;
    box-shadow: inset 0 0 0 2px #3b82f6;
  }
  
  .hmwevents-email-card.hover {
    transform: translateY(-2px);
  }
`;
document.head.appendChild(style);
