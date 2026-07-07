<?php

/**
 * GST Calculator.
 *
 * Handles Australian GST calculations for event bookings.
 * GST rate is 10% (1/11th of the total).
 *
 * Supports:
 * - Input as ex-GST amount (GST is calculated on top)
 * - Input as inc-GST amount (GST is extracted)
 * - Line item breakdown with individual GST amounts
 *
 * @package HMWEvents\Services
 * @since 2.0.0
 */

namespace HMWEvents\Services;

defined('ABSPATH') || die('Don\'t run this file directly!');

class GstCalculator
{
    /**
     * Australian GST rate (10%).
     */
    public const GST_RATE = 0.10;

    /**
     * Calculate GST and total from an ex-GST amount.
     *
     * @param float $ex_gst  Amount excluding GST.
     * @return array {ex_gst, gst, total}
     */
    public static function from_ex_gst(float $ex_gst): array
    {
        $gst   = round($ex_gst * self::GST_RATE, 2);
        $total = round($ex_gst + $gst, 2);

        return [
            'ex_gst' => $ex_gst,
            'gst'    => $gst,
            'total'  => $total,
        ];
    }

    /**
     * Extract GST component from an inc-GST total.
     *
     * @param float $inc_gst Total including GST.
     * @return array {ex_gst, gst, total}
     */
    public static function from_inc_gst(float $inc_gst): array
    {
        $ex_gst = round($inc_gst / (1 + self::GST_RATE), 2);
        $gst    = round($inc_gst - $ex_gst, 2);

        return [
            'ex_gst' => $ex_gst,
            'gst'    => $gst,
            'total'  => $inc_gst,
        ];
    }

    /**
     * Calculate GST for multiple line items.
     *
     * Each item should have an 'amount' key. The 'gst_applies' key
     * controls whether GST is added to that line item.
     *
     * @param array $items Array of ['label' => string, 'amount' => float, 'gst_applies' => bool]
     * @return array {items, subtotal_ex_gst, total_gst, total_inc_gst}
     */
    public static function calculate_line_items(array $items): array
    {
        $subtotal_ex = 0;
        $total_gst   = 0;

        $result_items = [];
        foreach ($items as $item) {
            $amount = (float) ($item['amount'] ?? 0);
            $applies = $item['gst_applies'] ?? true;
            $gst = $applies ? round($amount * self::GST_RATE, 2) : 0;

            $result_items[] = [
                'label'        => $item['label'] ?? '',
                'amount_ex_gst' => $amount,
                'gst'          => $gst,
                'amount_inc_gst' => round($amount + $gst, 2),
                'gst_applies'  => $applies,
            ];

            $subtotal_ex += $amount;
            $total_gst   += $gst;
        }

        return [
            'items'           => $result_items,
            'subtotal_ex_gst' => round($subtotal_ex, 2),
            'total_gst'       => round($total_gst, 2),
            'total_inc_gst'   => round($subtotal_ex + $total_gst, 2),
        ];
    }

    /**
     * Build a full booking payment breakdown with GST.
     *
     * @param float $booking_amount  The base booking amount (ex-GST or inc-GST).
     * @param bool  $is_ex_gst       Whether the input is ex-GST.
     * @param float $discount_amount Discount to apply.
     * @param bool  $discount_is_ex_gst Whether discount is ex-GST.
     * @return array Full breakdown.
     */
    public static function booking_breakdown(
        float $booking_amount,
        bool $is_ex_gst = false,
        float $discount_amount = 0,
        bool $discount_is_ex_gst = false
    ): array {
        if ($is_ex_gst) {
            $booking = self::from_ex_gst($booking_amount);
        } else {
            $booking = self::from_inc_gst($booking_amount);
        }

        if ($discount_amount > 0) {
            if ($discount_is_ex_gst) {
                $discount = self::from_ex_gst($discount_amount);
            } else {
                $discount = self::from_inc_gst($discount_amount);
            }
        } else {
            $discount = ['ex_gst' => 0, 'gst' => 0, 'total' => 0];
        }

        $final_ex  = round($booking['ex_gst'] - $discount['ex_gst'], 2);
        $final_gst = round($booking['gst'] - $discount['gst'], 2);
        $final_total = round($booking['total'] - $discount['total'], 2);

        return [
            'booking_ex_gst'    => $booking['ex_gst'],
            'booking_gst'       => $booking['gst'],
            'booking_total'     => $booking['total'],
            'discount_ex_gst'   => $discount['ex_gst'],
            'discount_gst'      => $discount['gst'],
            'discount_total'    => $discount['total'],
            'final_ex_gst'      => $final_ex,
            'final_gst'         => $final_gst,
            'final_total'       => $final_total,
            'gst_rate'          => self::GST_RATE,
        ];
    }

    /**
     * Check if an amount is GST-free (e.g. zero-dollar event).
     */
    public static function is_gst_free(float $total): bool
    {
        return $total <= 0;
    }
}
