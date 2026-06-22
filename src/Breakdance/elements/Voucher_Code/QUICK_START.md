# Voucher Code Element - Quick Start Guide

## 🎯 What You Created

A complete custom Breakdance element that:
- Validates WooCommerce PDF Product Voucher codes
- Shows real-time discount calculations
- Integrates seamlessly with your payment flow
- Has beautiful UI with success/error states

## 📁 Files Created

```
Voucher_Code/
├── element.php              # Element registration & PHP logic
├── html.twig                # HTML template with inline JS
├── css.twig                 # Dynamic CSS (uses Breakdance styling)
├── default.css              # Default fallback styles
├── ssr.php                  # Server-side rendering
├── VoucherIntegration.js    # Optional integration helpers
└── README.md                # Full documentation
```

## 🚀 How to Use

### Step 1: Open Breakdance Builder
Go to your booking page and open the Breakdance editor.

### Step 2: Add the Element
1. Click **Add Element** (+)
2. Search for **"Voucher Code"**
3. It appears under the **"Calmbirth"** category
4. Drag it onto your page (typically above the payment form)

### Step 3: Configure Settings

**Content Settings:**
```
Course ID Source: URL Parameter (course_id)
- This automatically reads ?course_id=123 from URL
- OR choose "Custom/Manual" to set a specific course ID

Input Placeholder: "Enter voucher code (optional)"
Button Text: "Apply Voucher"
```

**Styling Options:**
- Container padding/margin
- Input field width, typography, borders
- Button colors and styles
- Success/error message colors

### Step 4: Test It Out

1. Enter a valid voucher code (e.g., from WooCommerce)
2. Click "Apply Voucher"
3. See the magic happen:
   - ✅ Success message appears
   - 💰 Shows discount details
   - 💵 Displays original vs. final prices
   - 🎉 Both full payment and deposit amounts calculated

## 🎨 Example Page Layout

```
┌──────────────────────────────────────────┐
│         BOOK YOUR COURSE                 │
├──────────────────────────────────────────┤
│                                          │
│  Name: [_____________________]           │
│  Email: [_____________________]          │
│  Phone: [_____________________]          │
│                                          │
│  ╔════════════════════════════════════╗  │
│  ║  Have a voucher code?              ║  │
│  ║  ┌────────────────┬──────────┐    ║  │
│  ║  │ SAVE20         │ Apply ✓  │    ║  │
│  ║  └────────────────┴──────────┘    ║  │
│  ║                                    ║  │
│  ║  ✅ Voucher applied successfully!  ║  │
│  ║  Discount: 20% off                 ║  │
│  ║  Full payment: $500 → $400        ║  │
│  ║  You save: $100                    ║  │
│  ║  Deposit: $150 → $120             ║  │
│  ╚════════════════════════════════════╝  │
│                                          │
│  Select Payment Type:                    │
│  ⚪ Full Payment ($400)                  │
│  🔘 Deposit ($120)                       │
│                                          │
│  [Credit Card Details]                   │
│                                          │
│  [ Complete Booking ]                    │
└──────────────────────────────────────────┘
```

## 💡 Integration with Payment Form

The element automatically adds a hidden field:
```html
<input type="hidden" name="voucher_code" value="SAVE20" />
```

Your Breakdance form submission will include this automatically!

## 🔧 Backend Integration

**Already done!** Your backend is ready:

✅ `/cms/v1/voucher/validate` - Validates vouchers
✅ `/cms/v1/payment/process` - Accepts voucher_code parameter
✅ `StripePaymentGateway` - Applies discount automatically
✅ Database tracking in `educator_voucher_usage` table
✅ WooCommerce voucher redemption after successful payment

## 📱 Responsive Design

Works perfectly on:
- 💻 Desktop
- 📱 Mobile phones
- 📲 Tablets

The input and button stack vertically on mobile for better UX.

## 🎯 User Flow

```
1. User enters voucher code
        ↓
2. Clicks "Apply Voucher"
        ↓
3. Element calls API: /cms/v1/voucher/validate
        ↓
4. API checks WooCommerce PDF Vouchers
        ↓
5. Returns discount details
        ↓
6. Element shows:
   ✅ Success message
   💰 Discount amount
   💵 New prices
        ↓
7. User sees updated prices
        ↓
8. Submits payment form (voucher code included)
        ↓
9. Backend applies discount to Stripe payment
        ↓
10. Marks voucher as used in WooCommerce
```

## 🎨 Customization Examples

### Change Button Color
In Breakdance Styling > Button Style:
- Background: `#10b981` (green)
- Hover: `#059669` (darker green)

### Adjust Input Width
In Breakdance Styling > Input Style > Width:
- `100%` - Full width (default)
- `70%` - 70% width, button takes rest
- `400px` - Fixed pixel width

### Custom Success Color
In Breakdance Styling > Message Style:
- Success Color: `#10b981` (green)
- Error Color: `#ef4444` (red)

## 🐛 Troubleshooting

### "Course ID not found"
- Make sure URL has `?course_id=123`
- Or set Course ID Source to "Custom/Manual"

### "Invalid voucher code"
- Check WooCommerce PDF Product Vouchers plugin is active
- Verify voucher exists in WooCommerce > Vouchers
- Check voucher hasn't expired or been used

### Styles not applying
1. In Breakdance: Settings > Performance > Regenerate CSS
2. Clear browser cache
3. Check for theme CSS conflicts

## 🎓 Advanced Usage

### Listen to Events
```javascript
window.addEventListener('cmsVoucherApplied', (e) => {
    console.log('Voucher:', e.detail.code);
    console.log('Discount:', e.detail.data);
    
    // Update other UI elements
    document.querySelector('.total-price').textContent = 
        `$${e.detail.data.pricing.full.final_amount}`;
});
```

### Check if Voucher Applied
```javascript
if (window.CMSVoucher.hasVoucher()) {
    const discount = window.CMSVoucher.getDiscountAmount();
    console.log('Discount:', discount);
}
```

## ✅ Testing Checklist

- [ ] Element appears in Breakdance builder
- [ ] Can drag and drop onto page
- [ ] Settings panel opens correctly
- [ ] Input accepts text
- [ ] Button triggers validation
- [ ] Valid voucher shows success message
- [ ] Invalid voucher shows error
- [ ] Discount calculations are correct
- [ ] Hidden field gets populated
- [ ] Payment form receives voucher_code
- [ ] Backend applies discount
- [ ] Voucher marked as used after payment
- [ ] Mobile responsive layout works
- [ ] Styling controls work in Breakdance

## 🎉 You're Done!

Your voucher system is now complete with a beautiful, user-friendly interface that integrates perfectly with your booking flow.

Need help? Check the full README.md or contact support.
