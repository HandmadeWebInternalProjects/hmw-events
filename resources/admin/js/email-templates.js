/**
 * Email Templates Admin JavaScript
 */

(function($) {
  'use strict';

  /**
   * Setup TinyMCE merge tags button
   * This is called from the TinyMCE setup callback (must be in setup, not init)
   */
  window.setupCmsMergeTagsButton = function(editor) {
    console.log('setupCmsMergeTagsButton called for editor:', editor.id);
    
    // Get variables for this editor
    const editorId = editor.id;
    const getVariables = function() {
      return window.cmsTemplateVariables && window.cmsTemplateVariables[editorId] 
        ? window.cmsTemplateVariables[editorId] 
        : {};
    };
    
    // Create values array for listbox (TinyMCE 4 format)
    const getValues = function() {
      const variables = getVariables();
      const values = [];
      for (const [key, description] of Object.entries(variables)) {
        values.push({
          text: key + ' - ' + description,
          value: '{{' + key + '}}'
        });
      }
      console.log('Generated', values.length, 'merge tag values for', editorId);
      return values;
    };
    
    // Add the button using TinyMCE 4 API
    console.log('Adding button to editor:', editorId);
    editor.addButton('hmwevents_merge_tags', {
      type: 'listbox',
      text: 'Merge Tags',
      icon: false,
      values: getValues(),
      onselect: function(e) {
        console.log('Merge tag selected:', this.value());
        // Insert the selected merge tag
        if (this.value()) {
          editor.insertContent(this.value());
        }
      },
      onPostRender: function() {
        console.log('Merge tags button rendered for editor:', editorId);
        // Store reference for later updates
        editor.cmsMergeTagsButton = this;
        
        // Keep the button text as "Merge Tags" after selection
        var self = this;
        this.on('select', function() {
          setTimeout(function() {
            self.text('Merge Tags');
          }, 0);
        });
      }
    });
    console.log('Button added');
  };
  
  /**
   * Update merge tags button with new values
   * Called when switching templates via AJAX
   */
  window.updateCmsMergeTagsButton = function(editor) {
    if (!editor.cmsMergeTagsButton) {
      console.log('No merge tags button reference found');
      return;
    }
    
    const editorId = editor.id;
    const variables = window.cmsTemplateVariables && window.cmsTemplateVariables[editorId] 
      ? window.cmsTemplateVariables[editorId] 
      : {};
    
    // Create new values
    const values = [];
    for (const [key, description] of Object.entries(variables)) {
      values.push({
        text: key + ' - ' + description,
        value: '{{' + key + '}}'
      });
    }
    
    // Update the listbox menu
    const button = editor.cmsMergeTagsButton;
    button.settings.values = values;
    
    console.log('Updated merge tags button with', values.length, 'items');
  };

  $(document).ready(function() {
    console.log('Email Templates JS loaded');
    console.log('cmsEmailTemplates:', window.cmsEmailTemplates);
    console.log('Template selector forms found:', $('.hmwevents-template-selector-form').length);
    
    /**
     * Handle template switching via AJAX
     * Using event delegation and button click to avoid nested form issues
     */
    $(document).on('click', '.hmwevents-template-load-btn', function(e) {
      e.preventDefault();
      e.stopPropagation();
      console.log('Template load button clicked');
      
      const $button = $(this);
      const $container = $button.closest('.hmwevents-template-selector, .hmwevents-email-template-wrap');
      const $select = $container.find('select.hmwevents-template-selector-dropdown, select[name="template_key"]');
      const templateKey = $select.val();
      const $templateEditor = $container.siblings('.hmwevents-email-template-editor');
      
      console.log('Template key:', templateKey);
      console.log('Editor div found:', $templateEditor.length);
      
      if (!templateKey) return;
      
      // Show loading state
      $button.prop('disabled', true).text('Loading...');
      $templateEditor.css('opacity', '0.5');
      
      // Build data
      const data = {
        action: 'hmwevents_load_email_template',
        template_key: templateKey,
        nonce: cmsEmailTemplates.nonce,
        context: 'system'
      };
      
      // Make AJAX request
      $.ajax({
        url: cmsEmailTemplates.ajaxUrl,
        type: 'POST',
        data: data,
        success: function(response) {
          console.log('AJAX response:', response);
          
          if (response.success) {
            const data = response.data;
            const template = data.template;
            const editorId = data.editor_id;
            
            console.log('Updating form fields');
            console.log('Editor ID:', editorId);
            console.log('Editor div:', $templateEditor.length);
            console.log('Editor HTML length:', $templateEditor.html().length);
            console.log('Editor HTML start:', $templateEditor.html().substring(0, 300));
            
            // Find all forms in the editor area
            const $allForms = $templateEditor.find('form');
            console.log('All forms in editor:', $allForms.length);
            $allForms.each(function(i) {
              console.log('Form ' + i + ' classes:', $(this).attr('class'));
            });
            
            // Find the template form div within the editor area
            const $formDiv = $templateEditor.find('div.hmwevents-email-template-form');
            console.log('Form div with .hmwevents-email-template-form in editor found:', $formDiv.length);
            
            if ($formDiv.length === 0) {
              showNotice('Error: Template form not found in editor area', 'error');
              $button.prop('disabled', false).text('Load');
              $templateEditor.css('opacity', '1');
              return;
            }
            
            // Update subject (within the form div)
            $formDiv.find('input[name="subject"]').val(template.subject);
            
            // Update hidden field (within the form div)
            $formDiv.find('input[name="template_key"]').val(template.template_key);
            
            // Update active checkbox (within the form div)
            $formDiv.find('input[name="is_active"]').prop('checked', template.is_active == 1);
            
            // Update action field
            $formDiv.find('input[name="action"]').val('hmwevents_email_template_save');
            
            // Update status message (within the editor)
            $templateEditor.find('.hmwevents-template-status').html(data.status_message || '');
            
            // Update variables (within the editor)
            $templateEditor.find('.hmwevents-template-variables').html(data.variables_html || '');
            
            // Update TinyMCE editor - find the actual textarea in the form
            const $textarea = $formDiv.find('textarea[name="body"]');
            console.log('Body textarea found:', $textarea.length);
            
            if ($textarea.length) {
              const textareaId = $textarea.attr('id');
              console.log('Textarea ID from DOM:', textareaId);
              console.log('Expected editor ID from response:', editorId);
              
              // Update the textarea value first (fallback)
              $textarea.val(template.body);
              
              // Try to find the TinyMCE editor instance
              if (typeof tinymce !== 'undefined') {
                console.log('TinyMCE available');
                console.log('TinyMCE editors count:', tinymce.editors ? tinymce.editors.length : 0);
                
                if (tinymce.editors && tinymce.editors.length > 0) {
                  console.log('All TinyMCE editor IDs:', tinymce.editors.map(e => e.id));
                  
                  // Try to get editor by textarea ID first
                  let foundEditor = tinymce.get(textareaId);
                  if (foundEditor) {
                    console.log('Found TinyMCE editor by textarea ID:', textareaId);
                  } else {
                    console.log('No editor found with textarea ID, searching in form...');
                    
                    // Find any TinyMCE editor whose textarea is inside this form div
                    for (let i = 0; i < tinymce.editors.length; i++) {
                      const ed = tinymce.editors[i];
                      const $editorElement = $('#' + ed.id);
                      console.log('Checking editor:', ed.id, 'Element exists:', $editorElement.length, 'In form:', $formDiv.find('#' + ed.id).length);
                      
                      // Check if this editor's textarea is in our form
                      if ($editorElement.length && $formDiv.find('#' + ed.id).length > 0) {
                        foundEditor = ed;
                        console.log('Found TinyMCE editor in form:', ed.id);
                        break;
                      }
                    }
                  }
                  
                  if (foundEditor) {
                    // Update the content
                    console.log('Updating TinyMCE content for editor:', foundEditor.id);
                    foundEditor.setContent(template.body);
                    
                    // Update merge tags for this editor
                    if (data.variables && typeof window.cmsTemplateVariables !== 'undefined') {
                      window.cmsTemplateVariables[foundEditor.id] = data.variables;
                      console.log('Updated merge tags for editor:', foundEditor.id);
                      
                      // Update the merge tags button (not recreate it)
                      if (typeof window.updateCmsMergeTagsButton !== 'undefined') {
                        console.log('Updating merge tags button');
                        window.updateCmsMergeTagsButton(foundEditor);
                      }
                    }
                  } else {
                    console.log('No TinyMCE editor found in form, textarea updated as fallback');
                    console.log('Form div selector:', $formDiv.attr('class'));
                  }
                } else {
                  console.log('TinyMCE available but no editors initialized');
                }
              } else {
                console.log('TinyMCE not available');
              }
            }
            
            // Show success feedback
            showNotice('Template loaded successfully', 'success');
            
            // Mark form as clean
            markFormClean();
          } else {
            showNotice(response.data.message || 'Failed to load template', 'error');
          }
        },
        error: function() {
          showNotice('An error occurred while loading the template', 'error');
        },
        complete: function() {
          $button.prop('disabled', false).text('Load');
          $templateEditor.css('opacity', '1');
        }
      });
    });
    
    /**
     * Handle template save via AJAX
     * Using button click instead of form submit to avoid nested form issues
     */
    $(document).on('click', '.hmwevents-save-template-btn', function(e) {
      e.preventDefault();
      e.stopPropagation();
      
      const $button = $(this);
      console.log('Save button clicked');
      
      const $formDiv = $button.closest('div.hmwevents-email-template-form');
      const originalText = $button.text();
      
      console.log('Form div found:', $formDiv.length);
      if ($formDiv.length === 0) {
        console.error('No form div found! Button is not inside a .hmwevents-email-template-form');
        showNotice('Error: Form not found. Please refresh the page.', 'error');
        return;
      }
      
      // Manually build form data from the div's inputs
      const formData = new FormData();
      
      // Add all inputs, textareas, and selects within the form div
      $formDiv.find('input, textarea, select').each(function() {
        const $field = $(this);
        const name = $field.attr('name');
        const type = $field.attr('type');
        
        if (!name) return;
        
        if (type === 'checkbox') {
          if ($field.is(':checked')) {
            formData.append(name, $field.val() || '1');
          }
        } else if (type !== 'radio' || $field.is(':checked')) {
          formData.append(name, $field.val());
        }
      });
      
      // Get TinyMCE content
      const editorId = $formDiv.find('textarea[name="body"]').attr('id');
      if (typeof tinymce !== 'undefined' && editorId) {
        const editor = tinymce.get(editorId);
        if (editor) {
          formData.set('body', editor.getContent());
        }
      }
      
      // Replace action with AJAX action
      const originalAction = formData.get('action') || $formDiv.data('action');
      console.log('Original action:', originalAction);
      
      if (originalAction === 'hmwevents_email_template_save') {
        formData.set('action', 'hmwevents_save_email_template_ajax');
      }

      if (!formData.get('action')) {
        showNotice('Save action not configured for this template form. Please reload and try again.', 'error');
        $button.prop('disabled', false).html(originalText);
        return;
      }
      
      console.log('AJAX action:', formData.get('action'));
      console.log('Template key:', formData.get('template_key'));
      
      // Add nonce
      formData.set('ajax_nonce', cmsEmailTemplates.nonce);
      
      // Debug: Log all form data
      console.log('Form data being sent:');
      for (let pair of formData.entries()) {
        console.log(pair[0] + ': ' + (pair[1].length > 100 ? pair[1].substring(0, 100) + '...' : pair[1]));
      }
      
      // Show loading state
      $button.prop('disabled', true).html('<span class="dashicons dashicons-update" style="animation: rotation 1s infinite linear;"></span> Saving...');
      
      // Make AJAX request
      $.ajax({
        url: cmsEmailTemplates.ajaxUrl,
        type: 'POST',
        data: formData,
        processData: false,
        contentType: false,
        success: function(response) {
          if (response.success) {
            showNotice(response.data.message || 'Template saved successfully', 'success');
            markFormClean();
            
            // Update any status messages
            if (response.data.status_message) {
              $('.hmwevents-template-status').html(response.data.status_message);
            }
          } else {
            showNotice(response.data.message || 'Failed to save template', 'error');
          }
        },
        error: function(xhr, textStatus) {
          let errorMessage = 'An error occurred while saving the template';

          if (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
            errorMessage = xhr.responseJSON.data.message;
          } else if (xhr && xhr.responseText) {
            const trimmed = xhr.responseText.trim();
            if (trimmed === '0') {
              errorMessage = 'Save request was rejected (missing or invalid AJAX action).';
            } else if (trimmed.length > 0) {
              errorMessage = 'Save failed: ' + trimmed.substring(0, 200);
            }
          } else if (textStatus) {
            errorMessage = 'Save failed: ' + textStatus;
          }

          showNotice(errorMessage, 'error');
        },
        complete: function() {
          $button.prop('disabled', false).html(originalText);
        }
      });
    });

    /**
     * Handle template preview rendering.
     */
    $(document).on('click', '.hmwevents-preview-template-btn', function(e) {
      e.preventDefault();

      const $button = $(this);
      const $formDiv = $button.closest('div.hmwevents-email-template-form');
      const originalText = $button.text();

      const payload = getTemplatePayload($formDiv);
      payload.action = 'hmwevents_preview_email_template';
      payload.nonce = cmsEmailTemplates.nonce;

      $button.prop('disabled', true).text('Rendering...');

      $.ajax({
        url: cmsEmailTemplates.ajaxUrl,
        type: 'POST',
        data: payload,
        success: function(response) {
          if (response.success) {
            openPreviewModal(response.data.subject, response.data.body);
          } else {
            showNotice(response.data.message || 'Failed to render preview', 'error');
          }
        },
        error: function() {
          showNotice('An error occurred while rendering the preview', 'error');
        },
        complete: function() {
          $button.prop('disabled', false).text(originalText);
        }
      });
    });

    /**
     * Handle sending test emails.
     */
    $(document).on('click', '.hmwevents-send-test-email-btn', function(e) {
      e.preventDefault();

      const $button = $(this);
      const $formDiv = $button.closest('div.hmwevents-email-template-form');
      const $emailInput = $formDiv.find('.hmwevents-test-email-input');
      const recipientEmail = ($emailInput.val() || '').trim();
      const originalText = $button.text();

      if (!recipientEmail) {
        showNotice('Please enter a test recipient email address.', 'error');
        return;
      }

      const payload = getTemplatePayload($formDiv);
      payload.action = 'hmwevents_send_test_email_template';
      payload.nonce = cmsEmailTemplates.nonce;
      payload.recipient_email = recipientEmail;

      $button.prop('disabled', true).text('Sending...');

      $.ajax({
        url: cmsEmailTemplates.ajaxUrl,
        type: 'POST',
        data: payload,
        success: function(response) {
          if (response.success) {
            showNotice(response.data.message || 'Test email sent', 'success');
          } else {
            showNotice(response.data.message || 'Failed to send test email', 'error');
          }
        },
        error: function() {
          showNotice('An error occurred while sending test email', 'error');
        },
        complete: function() {
          $button.prop('disabled', false).text(originalText);
        }
      });
    });
    
    /**
     * Track form changes
     */
    let formIsDirty = false;
    
    $('.hmwevents-email-template-form input, .hmwevents-email-template-form textarea, .hmwevents-email-template-form select').on('change input', function() {
      formIsDirty = true;
    });
    
    // Track TinyMCE changes
    if (typeof tinymce !== 'undefined') {
      tinymce.on('AddEditor', function(e) {
        e.editor.on('change', function() {
          formIsDirty = true;
        });
      });
    }
    
    /**
     * Mark form as clean (after save)
     */
    function markFormClean() {
      formIsDirty = false;
    }
    
    /**
     * Warn before leaving with unsaved changes
     */
    $(window).on('beforeunload', function(e) {
      if (formIsDirty) {
        const message = 'You have unsaved changes to the email template. Are you sure you want to leave?';
        e.returnValue = message;
        return message;
      }
    });
    
    /**
     * Don't warn when switching templates (since we use AJAX now)
     */
    $('.hmwevents-template-selector-form').on('submit', function() {
      formIsDirty = false;
    });
    
    /**
     * Toggle booking selector visibility
     */
    $(document).on('change', '.hmwevents-use-real-booking', function() {
      const $checkbox = $(this);
      const $selector = $checkbox.closest('.hmwevents-email-template-form').find('.hmwevents-booking-selector');
      const $input = $selector.find('.hmwevents-booking-id-input');
      
      if ($checkbox.is(':checked')) {
        $selector.slideDown(200);
        $input.focus();
      } else {
        $selector.slideUp(200);
        $input.val('');
      }
    });

    /**
     * Handle booking ID input with autocomplete
     */
    $(document).on('keyup', '.hmwevents-booking-id-input', function() {
      const $input = $(this);
      const $formDiv = $input.closest('.hmwevents-email-template-form');
      const search = $input.val().trim();
      const $suggestions = $input.closest('.hmwevents-booking-selector').find('.hmwevents-booking-suggestions');

      if (search.length < 1) {
        $suggestions.hide();
        return;
      }

      // Search bookings via AJAX
      $.ajax({
        url: cmsEmailTemplates.ajaxUrl,
        type: 'POST',
        data: {
          action: 'hmwevents_get_available_bookings',
          nonce: cmsEmailTemplates.nonce,
          search: search
        },
        success: function(response) {
          if (response.success && response.data.length > 0) {
            let html = '<ul style="list-style: none; padding: 0; margin: 0;">';
            
            response.data.forEach(function(item) {
              html += '<li style="padding: 8px; cursor: pointer; border-bottom: 1px solid #ddd;" ' +
                      'data-booking-id="' + item.id + '">' + 
                      item.text + '</li>';
            });
            
            html += '</ul>';
            $suggestions.html(html).show();
          } else if (!response.success && response.data && response.data.message) {
            $suggestions.html('<div style="padding: 8px; color: #d00;">' + response.data.message + '</div>').show();
          } else {
            $suggestions.html('<div style="padding: 8px; color: #999;">No bookings found</div>').show();
          }
        },
        error: function() {
          $suggestions.html('<div style="padding: 8px; color: #d00;">Error loading bookings</div>').show();
        }
      });
    });

    /**
     * Handle booking selection from autocomplete
     */
    $(document).on('click', '.hmwevents-booking-suggestions li', function() {
      const $li = $(this);
      const bookingId = $li.data('booking-id');
      const $input = $li.closest('.hmwevents-booking-selector').find('.hmwevents-booking-id-input');
      const $suggestions = $li.closest('.hmwevents-booking-suggestions');

      $input.val(bookingId);
      $suggestions.hide();
    });

    /**
     * Hide booking suggestions when clicking elsewhere
     */
    $(document).on('click', function(e) {
      if (!$(e.target).closest('.hmwevents-booking-selector, .hmwevents-booking-suggestions').length) {
        $('.hmwevents-booking-suggestions').hide();
      }
    });

    /**
     * Show admin notice
     */
    function showNotice(message, type) {
      type = type || 'info';
      
      // Remove existing notices
      $('.hmwevents-ajax-notice').remove();
      
      // Create new notice
      const $notice = $('<div>', {
        class: 'notice notice-' + type + ' is-dismissible hmwevents-ajax-notice',
        html: '<p>' + message + '</p>',
        css: {
          'margin': '10px 0',
          'position': 'relative'
        }
      });
      
      // Add dismiss button
      $notice.append('<button type="button" class="notice-dismiss"><span class="screen-reader-text">Dismiss this notice.</span></button>');
      
      // Insert at top of the editor area (no scroll)
      const $editor = $('.hmwevents-email-template-editor');
      if ($editor.length) {
        $editor.prepend($notice);
      } else {
        // Fallback to top of wrap
        const $wrap = $('.hmwevents-email-template-wrap, .wrap');
        if ($wrap.find('h1, h2').first().length) {
          $notice.insertAfter($wrap.find('h1, h2').first());
        } else {
          $wrap.prepend($notice);
        }
      }
      
      // Handle dismiss
      $notice.find('.notice-dismiss').on('click', function() {
        $notice.fadeOut(function() {
          $(this).remove();
        });
      });
      
      // Auto-dismiss success messages
      if (type === 'success') {
        setTimeout(function() {
          $notice.fadeOut(function() {
            $(this).remove();
          });
        }, 3000);
      }
      
      // Scroll to notice
      $('html, body').animate({
        scrollTop: $notice.offset().top - 50
      }, 300);
    }

    /**
     * Collect current template editor values (including unsaved edits).
     */
    function getTemplatePayload($formDiv) {
      const templateKey = $formDiv.find('input[name="template_key"]').val() || '';
      const subject = $formDiv.find('input[name="subject"]').val() || '';

      let body = $formDiv.find('textarea[name="body"]').val() || '';
      const editorId = $formDiv.find('textarea[name="body"]').attr('id');
      if (typeof tinymce !== 'undefined' && editorId) {
        const editor = tinymce.get(editorId);
        if (editor) {
          body = editor.getContent();
        }
      }

      return {
        template_key: templateKey,
        context: 'system',
        subject: subject,
        body: body,
        booking_id: $formDiv.find('.hmwevents-booking-id-input').val() || 0
      };
    }

    /**
     * Open/refresh template preview modal.
     */
    function openPreviewModal(subject, bodyHtml) {
      let $modal = $('#hmwevents-template-preview-modal');

      if (!$modal.length) {
        $modal = $('<div id="hmwevents-template-preview-modal" class="hmwevents-template-preview-modal">' +
          '<div class="hmwevents-template-preview-backdrop"></div>' +
          '<div class="hmwevents-template-preview-dialog">' +
            '<div class="hmwevents-template-preview-header">' +
              '<h2>Email Preview</h2>' +
              '<button type="button" class="hmwevents-template-preview-close">&times;</button>' +
            '</div>' +
            '<div class="hmwevents-template-preview-content">' +
              '<p><strong>Subject:</strong> <span class="hmwevents-preview-subject"></span></p>' +
              '<hr>' +
              '<div class="hmwevents-preview-body"></div>' +
            '</div>' +
          '</div>' +
        '</div>');

        $('body').append($modal);
      }

      $modal.find('.hmwevents-preview-subject').text(subject || '');
      $modal.find('.hmwevents-preview-body').html(bodyHtml || '');
      $modal.addClass('is-visible');
    }

    $(document).on('click', '.hmwevents-template-preview-close, .hmwevents-template-preview-backdrop', function() {
      $('#hmwevents-template-preview-modal').removeClass('is-visible');
    });

    /**
     * Auto-save functionality (optional - can be enabled)
     */
    let autoSaveTimer;
    const autoSaveEnabled = false; // Set to true to enable auto-save
    
    if (autoSaveEnabled) {
      $('.hmwevents-email-template-form input, .hmwevents-email-template-form textarea').on('input', function() {
        clearTimeout(autoSaveTimer);
        autoSaveTimer = setTimeout(function() {
          if (formIsDirty) {
            $('.hmwevents-email-template-form').trigger('submit');
          }
        }, 3000); // Auto-save after 3 seconds of inactivity
      });
    }
    
  });

})(jQuery);

// Add CSS for rotation animation
const style = document.createElement('style');
style.textContent = `
  @keyframes rotation {
    from { transform: rotate(0deg); }
    to { transform: rotate(359deg); }
  }
  
  .hmwevents-ajax-notice {
    margin: 15px 0;
  }
  
  .hmwevents-template-status {
    padding: 10px 15px;
    background: #f0f6fc;
    border-left: 4px solid #2563eb;
    margin: 15px 0;
    display: none; //not used yet.
  }
  
  .hmwevents-template-variables {
    background: #f9fafb;
    padding: 15px;
    border-radius: 4px;
    margin: 15px 0;
  }
  
  .hmwevents-template-variables ul {
    margin: 10px 0;
    padding-left: 20px;
  }
  
  .hmwevents-template-variables code {
    background: #fff;
    padding: 2px 6px;
    border-radius: 3px;
    border: 1px solid #e5e7eb;
  }

  .hmwevents-template-preview-modal {
    position: fixed;
    inset: 0;
    display: none;
    z-index: 100000;
  }

  .hmwevents-template-preview-modal.is-visible {
    display: block;
  }

  .hmwevents-template-preview-backdrop {
    position: absolute;
    inset: 0;
    background: rgba(0, 0, 0, 0.45);
  }

  .hmwevents-template-preview-dialog {
    position: relative;
    max-width: 900px;
    max-height: 85vh;
    overflow: hidden;
    margin: 40px auto;
    background: #fff;
    border-radius: 8px;
    box-shadow: 0 15px 40px rgba(0, 0, 0, 0.2);
    z-index: 1;
    display: flex;
    flex-direction: column;
  }

  .hmwevents-template-preview-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 12px 16px;
    border-bottom: 1px solid #e5e7eb;
  }

  .hmwevents-template-preview-header h2 {
    margin: 0;
    font-size: 18px;
  }

  .hmwevents-template-preview-close {
    border: 0;
    background: transparent;
    font-size: 28px;
    line-height: 1;
    cursor: pointer;
    color: #666;
  }

  .hmwevents-template-preview-content {
    padding: 16px;
    overflow: auto;
  }

  .hmwevents-preview-body {
    background: #fafafa;
    border: 1px solid #e5e7eb;
    border-radius: 4px;
    padding: 16px;
  }
`;
document.head.appendChild(style);
