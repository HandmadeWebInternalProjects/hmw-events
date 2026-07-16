# HMW Events — Manual Test Script

Use this script to verify all core features are working correctly. Each test has clear steps and expected results. Mark ✅ or ❌ as you go.

---

## 1. Event Types and Templates

### 1.1 Create an event and assign a type
- [ ] Go to **Events → Add New**
- [ ] Select **Parent Multi-Week Course** from the Event Type dropdown (right sidebar)
- [ ] **Verify**: All venue fields appear; Webinar URL field is hidden; Capacity defaults to 12
- [ ] Change to **Parenting Webinar**
- [ ] **Verify**: Venue fields hide; Webinar URL appears and is required; Price fields hide

### 1.2 Create an event from a template
- [ ] Go to **Events → Templates**
- [ ] Click **Create Draft Event** on any template
- [ ] **Verify**: A new draft event is created with the template's fields pre-populated
- [ ] Check the template's retired toggle: click **Retire** on a template
- [ ] **Verify**: Template shows as "Retired"; you cannot create events from it

### 1.3 Status workflow enforcement
- [ ] Edit an event set to **Archived** status
- [ ] Try changing it to **Draft**
- [ ] **Verify**: Admin notice says "Cannot change status from archived to draft"

---

## 2. Event Creation and Configuration

### 2.1 Pricing and surcharge
- [ ] Edit a **Professional Paid Online** event
- [ ] Set Full Price = $100, Surcharge = $5
- [ ] **Verify**: Both fields are visible and save correctly
- [ ] Switch to **Parent One-Off Free Event**
- [ ] **Verify**: Price, surcharge, and deposit fields are hidden

### 2.2 Per-registrant booking cap
- [ ] Set **Max Bookings Per Person** = 2
- [ ] Book the event twice with the same email
- [ ] Try booking a third time
- [ ] **Verify**: Error message "maximum of 2 booking(s) per person"

### 2.3 Per-option capacity
- [ ] Open an event with multiple attendance options (e.g., Parent Course)
- [ ] Set capacity on the "Couple" option via the attendance options
- [ ] Book that option until full
- [ ] Try booking it again
- [ ] **Verify**: Error message "Couple option is fully booked"

### 2.4 Coupons
- [ ] Go to **Events → Coupons → Add New**
- [ ] Create a coupon: Code = TEST10, Type = Fixed, Value = 10
- [ ] Check **Staff Discount** checkbox
- [ ] **Verify**: Staff coupon bypasses date/usage restrictions
- [ ] Create another coupon: Code = PERCENT20, Type = Percent, Value = 20
- [ ] Set start/end dates, max uses, event type restrictions
- [ ] **Verify**: Coupon fails when expired, exhausted, or wrong event type

---

## 3. Scheduling and Sessions

### 3.1 Recurring events
- [ ] Create a **Parent Recurring Walk-In** event
- [ ] Set start date to next Monday, end date 4 weeks later
- [ ] Toggle **Recurring Event** ON
- [ ] Set Repeat = Weekly, day = Monday
- [ ] Save the event
- [ ] **Verify**: 4 child sessions created (one per Monday)

### 3.2 Session schedule display
- [ ] View the parent event on the frontend
- [ ] **Verify**: "Session Schedule" table appears below the event content showing all 4 dates

### 3.3 Session calendar
- [ ] Go to **Events → Calendar**
- [ ] Navigate to the month with the sessions
- [ ] **Verify**: Each session appears as a green-badged entry on the correct date

### 3.4 Session management
- [ ] Edit the parent event
- [ ] **Verify**: Session Schedule meta box shows all child sessions with status, date, and actions
- [ ] Click **Edit** on one child session
- [ ] Change the date and save
- [ ] Edit the parent — change venue and click **Update All Sessions**
- [ ] **Verify**: Venue cascades to all children; the manually-edited date is preserved

### 3.5 Delete session exclusion
- [ ] Delete one child session (permanently)
- [ ] Go back to the parent and save again
- [ ] **Verify**: The deleted date is NOT recreated; excluded dates counter shows "1 excluded date"
- [ ] Click **Clear Excluded Dates** and save again
- [ ] **Verify**: The date is recreated

---

## 4. Event Lifecycle

### 4.1 Duplicate event
- [ ] Go to **Events → All Events**
- [ ] Hover over any event → click **Duplicate**
- [ ] **Verify**: A draft copy is created with all fields, meta, and taxonomies copied

### 4.2 Cancel event
- [ ] Find an event with confirmed bookings
- [ ] Change its status to **Cancelled**
- [ ] **Verify**: All bookings are cancelled; "Event cancelled" notice appears

### 4.3 Archive and PII
- [ ] Archive an event with registrations
- [ ] **Verify**: Event no longer appears on frontend
- [ ] Go to **Events → Reporting → Data Governance box**
- [ ] **Verify**: Counter shows archived events pending PII cleanup

---

## 5. Event Statuses

### 5.1 By Invitation
- [ ] Set an event to **By Invitation** status
- [ ] View the event on the frontend
- [ ] **Verify**: Shows "Invitation Only" + "Fully Booked" badges; booking form hidden
- [ ] Scroll to the Event Bookings meta box
- [ ] **Verify**: "Invitation Tokens" section appears

### 5.2 Generate invitation link
- [ ] Enter an email in the invitation tokens box
- [ ] Click **Generate Registration Link**
- [ ] **Verify**: Registration URL appears with a token
- [ ] Copy the URL, open in an incognito window
- [ ] **Verify**: Booking form shows and allows registration
- [ ] After booking, try using the same link again
- [ ] **Verify**: Token is consumed — "invalid registration token"

### 5.3 Fully Booked
- [ ] Fill an event's capacity completely
- [ ] **Verify**: Event shows "Fully Booked" badge; booking form replaced with "fully booked" message

---

## 6. Categorisation and Listings

### 6.1 Assign categories and tags
- [ ] Edit an event → find the Categories and Tags panels (right sidebar)
- [ ] Assign a category and tag
- [ ] **Verify**: Categories and tags save and display in the admin list

### 6.2 Topic taxonomy
- [ ] Go to **Events → Topics → Add New**
- [ ] Create topics like "Sleep", "Feeding", "Behaviour"
- [ ] Edit an event and assign topics
- [ ] **Verify**: Topics appear and filter correctly

### 6.3 Public listing filters
- [ ] View the event listing on the frontend
- [ ] **Verify**: Filter bar shows: Delivery, Price, Topic, Audience
- [ ] Apply filters
- [ ] **Verify**: Results narrow correctly

### 6.4 Event card badges
- [ ] **Verify**: Each card shows delivery mode badge (Online/In Person)
- [ ] **Verify**: Free events show "Free" badge
- [ ] **Verify**: Multi-session events show "Multi-Session" badge

---

## 7. Registration Forms

### 7.1 Parent-specific fields
- [ ] Open a **Parent One-Off Free Event** on the frontend
- [ ] **Verify**: Form includes "Age of Child", "Number of Children", "Childcare Requirements"

### 7.2 Professional-specific fields
- [ ] Open a **Professional Paid Online Event**
- [ ] **Verify**: Form includes "Organisation", "Job Title", "Professional Body / AHPRA Number"

### 7.3 Audience-scoped fields
- [ ] **Verify**: Parent fields do NOT appear on professional events
- [ ] **Verify**: Professional fields do NOT appear on parent events

---

## 8. Registration Flow

### 8.1 Booking confirmation
- [ ] Book any event completely
- [ ] **Verify**: On-screen confirmation page shows booking number, event details, payment summary, and "Next Steps"

### 8.2 Confirmation email
- [ ] Check the email inbox for the registrant
- [ ] **Verify**: Email includes event name, date, location, booking reference

### 8.3 Admin notification
- [ ] Check the admin email
- [ ] **Verify**: New booking notification received with event name and customer details

### 8.4 Self-cancellation (free events)
- [ ] Book a free event
- [ ] Open the confirmation email
- [ ] **Verify**: Email contains a cancellation link (for free events only)
- [ ] Click the cancellation link
- [ ] **Verify**: "Booking Cancelled" page shown; booking status updated

---

## 9. Payments

### 9.1 Stripe payment
- [ ] Book a paid event with Stripe test card: `4242 4242 4242 4242`
- [ ] **Verify**: Payment processes successfully

### 9.2 Net Terms / Pay Later (invitation only)
- [ ] Access a paid event via an invitation URL (`?token=xxx`)
- [ ] **Verify**: "Pay by Invoice" option appears alongside "Full Payment"
- [ ] Access the same event without a token
- [ ] **Verify**: "Pay by Invoice" does NOT appear

### 9.3 Staff discount
- [ ] Create a coupon with Staff Discount checked, discount = 50%
- [ ] Apply the coupon at checkout
- [ ] **Verify**: 50% discount applied; date/usage restrictions bypassed

### 9.4 Auto receipt
- [ ] Complete a payment
- [ ] Check the registrant's email
- [ ] **Verify**: Payment receipt/invoice email received

### 9.5 Manual booking
- [ ] Edit any event → scroll to Event Bookings meta box
- [ ] Click **Add Manual Booking**
- [ ] Fill in First Name, Last Name, Email, Phone → click Create Booking
- [ ] **Verify**: Booking appears in the table with status "Confirmed", payment "Pending"

### 9.6 Mark as Paid
- [ ] Find the manual booking in the table
- [ ] Click **Mark as Paid**
- [ ] **Verify**: Payment status changes to "Paid"; receipt email queued

### 9.7 Payment Link
- [ ] Find a pending booking → click **Payment Link**
- [ ] **Verify**: Customer receives payment link email

---

## 10. Capacity and Waitlists

### 10.1 Waitlist auto-creation
- [ ] Fill an event completely (set low capacity for testing)
- [ ] Try booking again
- [ ] **Verify**: Booking is waitlisted instead of rejected

### 10.2 Waitlist prevents reopening
- [ ] Cancel one booking (free up a spot)
- [ ] Try booking again
- [ ] **Verify**: Event still shows as "fully booked" because waitlist is active

### 10.3 Waitlist view and promote
- [ ] Edit the event → scroll to Waitlist table below the bookings table
- [ ] **Verify**: Waitlisted entries shown with position, name, email
- [ ] Click **Promote** on a waitlist entry
- [ ] **Verify**: Entry promoted; invitation email sent with private URL

---

## 11. Communications

### 11.1 Custom notification email
- [ ] Edit an event → set **Notification Email Override** to `testoverride@example.com`
- [ ] Create a new booking for this event
- [ ] **Verify**: Admin notification goes to the override email, not the organizer's email

### 11.2 Disable email types
- [ ] Go to **Events → Email Queue**
- [ ] Find the "Notification Settings" section at the top
- [ ] Disable "Booking Confirmation (Customer)" 
- [ ] **Verify**: New bookings do NOT send customer confirmation emails
- [ ] Re-enable it

### 11.3 Email templates
- [ ] Go to **Events → Email Templates**
- [ ] Select a template (e.g., Booking Confirmation)
- [ ] Edit the subject and body
- [ ] Send a test email
- [ ] **Verify**: Test email uses the modified template

---

## 12. Reporting

### 12.1 Filter registrations
- [ ] Go to **Events → Reporting**
- [ ] Apply filters: Payment Status = Paid, Attendance = Registered, Event Type = Professional Online
- [ ] Click Filter
- [ ] **Verify**: Results match the filter criteria

### 12.2 CSV export
- [ ] Click **Export CSV**
- [ ] **Verify**: CSV downloads with correct headers and filtered data
- [ ] Try different export types (Registrations, Payments, Attendees)

### 12.3 Save filter
- [ ] Apply filters → click **Save Filter**
- [ ] Name it "Paid Professional Events"
- [ ] Reload the page
- [ ] **Verify**: Saved filter appears and can be reloaded

### 12.4 Remove test data
- [ ] Click **Remove Abandoned Bookings (>7 days pending)**
- [ ] **Verify**: Confirmation prompt; test bookings removed

### 12.5 Data Governance dashboard
- [ ] Scroll to the "Data Governance" box at the bottom
- [ ] **Verify**: Shows archived event count, pending documents, audit entries, cron schedule

---

## 13. Search Engine and Compliance

### 13.1 Archived events excluded from search
- [ ] Archive an event
- [ ] Perform a frontend search for that event's title
- [ ] **Verify**: Archived event does NOT appear in search results

### 13.2 Noindex meta tag
- [ ] View the source of an archived/cancelled event page
- [ ] **Verify**: `<meta name="robots" content="noindex, nofollow">` is present
- [ ] View source of a published event page
- [ ] **Verify**: The noindex tag is NOT present

---

## Quick Smoke Test (5 minutes)

Run these for a quick sanity check:

- [ ] Create a **Parenting Webinar** → External registration link works, no booking form
- [ ] Create a **Parent Course** → Recurrence generates sessions, session schedule shows
- [ ] Create a **Professional Online** → Booking form shows professional fields only
- [ ] Book the event → Stripe payment processes, confirmation email received
- [ ] Mark booking as paid → Status updates
- [ ] Cancel the event → All bookings cancelled, attendees notified
- [ ] Run CSV export → Data exports correctly

---

_Last updated: 16 July 2026_
