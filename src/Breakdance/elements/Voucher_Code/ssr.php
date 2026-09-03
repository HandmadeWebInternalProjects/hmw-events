<?php

defined('ABSPATH') || die('Don\'t run this file directly!');

/**
 * Server-Side Rendering for Voucher Code Element
 */

// Determine course ID
$courseId = get_the_ID();

$placeholder = $propertiesData['content']['content']['input_placeholder'] ?? 'Enter voucher code (optional)';
$buttonText = $propertiesData['content']['content']['button_text'] ?? 'Apply Voucher';
$applyToField = $propertiesData['content']['content']['apply_to_field'] ?? 'is_deposit';
?>

<div class="hmwevents-voucher-wrapper"
  data-course-id="<?php echo esc_attr($courseId); ?>"
  data-apply-to-field="<?php echo esc_attr($applyToField); ?>">

  <div class="hmwevents-voucher-input-group">
    <input
      type="text"
      class="hmwevents-voucher-input"
      placeholder="<?php echo esc_attr($placeholder); ?>"
      aria-label="Voucher code" />
    <?php

    $twig = \Breakdance\Render\Twig::getInstance();
    $template = "{{ macros.atomV1ButtonHtml(content.content, 'bde-button__button hmwevents-voucher-button hmwevents-voucher-button__apply', design.button, content.text) }}";
    $result = $twig->runTwig($template, [
      'content' => [
        'content' => [
          'text' => esc_html($buttonText),
          'link' => []
        ]
      ],
      'design' => [
        'button' => $propertiesData['design']['button'] ?? []
      ]
    ]);
    echo $result;

    ?>
  </div>

  <div class="hmwevents-voucher-loading" style="display: none;">
    <span class="hmwevents-voucher-spinner"></span>
    <span>Validating voucher...</span>
  </div>

  <div class="hmwevents-voucher-success" style="display: none;">
    <div class="hmwevents-voucher-message-icon">✓</div>
    <div class="hmwevents-voucher-message-content">
      <p class="hmwevents-voucher-message-title">Voucher applied successfully!</p>
      <div class="hmwevents-voucher-discount-details">
        <p>Discount: <strong class="hmwevents-voucher-discount-type"></strong></p>
        <p>Full payment: <span class="hmwevents-voucher-full-original"></span> → <strong class="hmwevents-voucher-full-final"></strong></p>
        <p class="hmwevents-voucher-savings">You save: <strong class="hmwevents-voucher-full-saved"></strong></p>
        <p class="hmwevents-voucher-deposit-line" style="display: none;">
          Deposit payment: <span class="hmwevents-voucher-deposit-original"></span> → <strong class="hmwevents-voucher-deposit-final"></strong>
        </p>
      </div>
      <button type="button" class="hmwevents-voucher-remove hmwevents-voucher-button__remove">Remove voucher</button>
    </div>
  </div>

  <div class="hmwevents-voucher-error" style="display: none;">
    <div class="hmwevents-voucher-message-icon">⚠</div>
    <div class="hmwevents-voucher-message-content">
      <p class="hmwevents-voucher-message-title">Voucher validation failed</p>
      <p class="hmwevents-voucher-error-message"></p>
    </div>
  </div>

  <input type="hidden" name="voucher_code" class="hmwevents-voucher-code-field" value="" />
  <input type="hidden" class="hmwevents-voucher-discount-data" value="" />
</div>