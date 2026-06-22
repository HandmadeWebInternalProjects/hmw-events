<?php

namespace HMWEvents\Services;

defined('ABSPATH') || die('Don\'t run this file directly!');

/**
 * Voucher/Coupon validation and application service.
 */
class VoucherService
{
  /**
   * Validate a WooCommerce PDF voucher code.
   *
   * @param string $voucher_code The voucher code to validate.
   * @param int $course_id The course ID.
   * @param string $customer_email Customer email.
   * @return array|WP_Error Voucher details or error.
   */
  public function validate_voucher($voucher_code, $course_id, $customer_email = '')
  {
    if (empty($voucher_code)) {
      return new \WP_Error('empty_code', 'Voucher code is required');
    }

    // Check if WooCommerce PDF Product Vouchers is active
    if (!class_exists('WC_PDF_Product_Vouchers')) {
      return new \WP_Error('plugin_missing', 'Voucher system not available');
    }

    // Get voucher by code
    $voucher = $this->get_voucher_by_code($voucher_code);

    if (!$voucher) {
      return new \WP_Error('invalid_code', 'Invalid voucher code');
    }

    // Check if already redeemed - status should be 'active'
    if ($voucher->has_status('redeemed')) {
      return new \WP_Error('already_used', 'This voucher has already been used');
    }

    // Check if voided
    if ($voucher->has_status('voided')) {
      return new \WP_Error('voided', 'This voucher has been voided');
    }

    // Check expiry date
    $expiration_date = $voucher->get_expiration_date('timestamp');
    if ($expiration_date && $expiration_date < time()) {
      return new \WP_Error('expired', 'This voucher has expired');
    }

    // Voucher must be active
    if (!$voucher->has_status('active')) {
      return new \WP_Error('not_active', 'This voucher is not active');
    }

    // Get discount details
    $voucher_type = $voucher->get_voucher_type(); // 'single' or 'multi'
    $remaining_value = $voucher->get_remaining_value(); // Remaining value in currency
    $product_price = $voucher->get_product_price(); // Original product price

    return [
      'valid' => true,
      'voucher_id' => $voucher->get_id(),
      'code' => $voucher_code,
      'voucher_type' => $voucher_type,
      'remaining_value' => $remaining_value,
      'product_price' => $product_price,
      'voucher' => $voucher,
    ];
  }

  /**
   * Calculate discounted amount.
   * Note: PDF Product Vouchers uses fixed value, not percentages.
   *
   * @param float $original_amount Original price.
   * @param array $voucher_data Voucher details from validate_voucher().
   * @return array Discount calculation details.
   */
  public function calculate_discount($original_amount, $voucher_data)
  {
    // Use the remaining voucher value as discount, but don't exceed original amount
    $discount_amount = min($voucher_data['remaining_value'], $original_amount);

    $final_amount = max(0, $original_amount - $discount_amount);

    return [
      'original_amount' => $original_amount,
      'discount_amount' => $discount_amount,
      'final_amount' => $final_amount,
      'discount_percentage' => $original_amount > 0 ? ($discount_amount / $original_amount) * 100 : 0,
    ];
  }

  /**
   * Mark voucher as redeemed after successful payment.
   *
   * @param string $voucher_code Voucher code.
   * @param int $booking_id Booking ID.
   * @param float $amount Amount redeemed.
   * @return bool Success status.
   */
  public function redeem_voucher($voucher_code, $booking_id, $amount = 0)
  {
    $voucher = $this->get_voucher_by_code($voucher_code);

    if (!$voucher) {
      return false;
    }

    // Prepare redemption arguments
    $redemption_args = [
      'amount' => $amount,
      'quantity' => 1,
    ];

    // Mark as redeemed in WooCommerce
    // For single vouchers, redeem() marks status as 'redeemed'
    // For multi vouchers, it deducts the amount from remaining value
    $voucher->redeem(null, $redemption_args);

    // Add note to voucher
    $voucher->add_note(sprintf(
      'Redeemed for booking #%d (Amount: %s)',
      $booking_id,
      wc_price($amount)
    ));

    return true;
  }

  /**
   * Get voucher by code.
   *
   * @param string $code Voucher code.
   * @return WC_Voucher|null Voucher object or null.
   */
  private function get_voucher_by_code($code)
  {
    if (!function_exists('wc_pdf_product_vouchers_get_voucher')) {
      return null;
    }

    return wc_pdf_product_vouchers_get_voucher($code);
  }
}
