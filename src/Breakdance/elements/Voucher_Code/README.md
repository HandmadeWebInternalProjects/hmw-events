# Voucher Code Breakdance Element

A custom Breakdance element for Handmade Web Event Manager that allows users to enter and validate WooCommerce PDF Product Voucher codes before making a payment.

## Features

- ✅ Real-time voucher validation
- ✅ Displays discount details for both full payment and deposit
- ✅ Calculates final amounts after discount
- ✅ Beautiful success/error states
- ✅ Fully customizable styling in Breakdance
- ✅ Responsive design
- ✅ Integrates with payment forms via hidden fields

## Installation

The element is automatically registered when the plugin is active. Simply:

1. Open Breakdance Builder
2. Look for **"Voucher Code"** element under the **"Calmbirth"** category
3. Drag and drop onto your page

## Usage

### Basic Setup

1. **Add the Element to Your Page**
   - Add it to your booking/payment page, typically above or near the payment form
   - Configure the Course ID source (URL parameter or manual)

2. **Configure Settings**
   
   In the **Content** tab:
   - **Course ID Source**: Choose "URL Parameter" (default) or "Custom/Manual"
   - **Course ID**: If using manual mode, enter the course ID
   - **Input Placeholder**: Customize the input placeholder text
   - **Button Text**: Customize the apply button text
   - **Payment Type Field Name**: The form field name for deposit/full payment selection (default: `is_deposit`)

3. **Customize Styling**
   
   Use the **Styling** tab to customize:
   - Container padding and margin
   - Input field style (width, typography, borders, padding)
   - Button style (colors, typography, etc.)
   - Success/error message colors

### Integration with Payment Forms

The element automatically adds a hidden field `voucher_code` that your payment form can pick up:

```html
<!-- Hidden field automatically added when voucher is applied -->
<input type="hidden" name="voucher_code" value="SAVE20" />
```

Your payment endpoint (`/hmwevents/v1/payment/process`) will automatically receive this parameter.

### JavaScript Events

The element dispatches custom events you can listen to:

```javascript
// When voucher is successfully applied
window.addEventListener('cmsVoucherApplied', (event) => {
    const { code, data } = event.detail;
    console.log('Voucher applied:', code);
    console.log('Discount data:', data);
});

// When voucher is removed
window.addEventListener('cmsVoucherRemoved', () => {
    console.log('Voucher removed');
});
```

### JavaScript API

Access voucher data programmatically:

```javascript
// Check if voucher is applied
if (window.CMSVoucher.hasVoucher()) {
    // Get current voucher data
    const voucher = window.CMSVoucher.getCurrentVoucher();
    
    // Get discount amount for full payment
    const discount = window.CMSVoucher.getDiscountAmount(false);
    
    // Get final amount for deposit
    const finalAmount = window.CMSVoucher.getFinalAmount(true);
}
```

## Example Layout

```
┌─────────────────────────────────────────┐
│  Course Booking Form                    │
├─────────────────────────────────────────┤
│                                         │
│  Name: [________________]               │
│  Email: [________________]              │
│                                         │
│  ┌────────────────────────────────────┐ │
│  │ Voucher Code Element               │ │
│  ├────────────────────────────────────┤ │
│  │ [Enter voucher code] [Apply]       │ │
│  │                                    │ │
│  │ ✓ Voucher applied successfully!    │ │
│  │   Discount: 20% off                │ │
│  │   Full: $500 → $400               │ │
│  │   You save: $100                   │ │
│  └────────────────────────────────────┘ │
│                                         │
│  Payment Type:                          │
│  ○ Full Payment ($400)                  │
│  ○ Deposit ($100)                       │
│                                         │
│  [Card Details]                         │
│  [Submit Payment]                       │
└─────────────────────────────────────────┘
```

## Course ID Methods

### Method 1: URL Parameter (Recommended)

Use this when linking from a course page:

```
/booking-page?course_id=123
```

The element automatically reads `course_id` from the URL.

### Method 2: Custom/Manual

Set a specific course ID in the element settings. Useful for:
- Single-course landing pages
- Testing
- Hardcoded course bookings

## API Endpoints

The element uses these REST API endpoints:

### Validate Voucher
```
POST /wp-json/hmwevents/v1/voucher/validate
```

**Request:**
```json
{
    "voucher_code": "SAVE20",
    "course_id": 123
}
```

**Response:**
```json
{
    "success": true,
    "voucher": {
        "code": "SAVE20",
        "discount_type": "percentage",
        "discount_value": 20
    },
    "pricing": {
        "full": {
            "original_amount": 500,
            "discount_amount": 100,
            "final_amount": 400,
            "discount_percentage": 20
        },
        "deposit": {
            "original_amount": 150,
            "discount_amount": 30,
            "final_amount": 120,
            "discount_percentage": 20
        }
    }
}
```

## Styling Classes

Target these classes for custom CSS:

```css
.hmwevents-voucher-wrapper          /* Main container */
.hmwevents-voucher-input-group      /* Input + button container */
.hmwevents-voucher-input            /* Input field */
.hmwevents-voucher-button           /* Apply button */
.hmwevents-voucher-loading          /* Loading state */
.hmwevents-voucher-success          /* Success message */
.hmwevents-voucher-error            /* Error message */
.hmwevents-voucher-remove           /* Remove voucher button */
```

## Error Messages

The element handles these error states automatically:

- **Empty code**: "Please enter a voucher code"
- **Invalid code**: "Invalid voucher code"
- **Already used**: "This voucher has already been used"
- **Expired**: "This voucher has expired"
- **No course ID**: "Course ID not found"
- **Network error**: "Unable to validate voucher. Please try again."

## Browser Support

- ✅ Chrome 90+
- ✅ Firefox 88+
- ✅ Safari 14+
- ✅ Edge 90+
- ✅ Mobile browsers (iOS Safari, Chrome Mobile)

## Troubleshooting

### Voucher not validating

1. Check that WooCommerce PDF Product Vouchers plugin is active
2. Verify the voucher code exists in WooCommerce
3. Check browser console for errors
4. Verify course ID is being passed correctly

### Styling not applying

1. Clear Breakdance cache
2. Regenerate CSS in Breakdance settings
3. Check for CSS conflicts with theme

### Integration issues

1. Ensure payment form has `name="voucher_code"` field or the hidden field is present
2. Check that payment endpoint is receiving the voucher_code parameter
3. Verify API endpoints are accessible (check REST API permissions)

## Support

For issues or questions:
- Check the browser console for error messages
- Review the server error logs
- Contact support@handmadewebdesign.com.au
