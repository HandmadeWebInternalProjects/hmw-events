async function handlePayment(formValues) {
  const messageDiv = document.getElementById('payment-message');
  const formContainer = document.getElementById('booking-form-container');
  const paymentContainer = document.getElementById('payment-container');

  if (messageDiv) messageDiv.innerHTML = '';

  try {
    if (!formValues.stripe_publishable_key) {
      throw new Error('Stripe configuration missing');
    }

    const stripe = Stripe(formValues.stripe_publishable_key);

    // SECURITY: Verify amount from backend - never trust client-side values
    const verifyResponse = await fetch('/wp-json/cms/v1/payment/verify-amount', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        course_id: parseInt(formValues.course_id),
        is_deposit: formValues.ticket_type === 'deposit',
      }),
    });

    const verifyResult = await verifyResponse.json();
    if (!verifyResult.success) {
      throw new Error(verifyResult.message || 'Unable to verify course amount');
    }

    const amount = verifyResult.data.amount; // Use server-verified amount

    if (amount <= 0) {
      throw new Error('Invalid payment amount');
    }

    // FIXED: Add paymentMethodCreation: 'manual'
    const elements = stripe.elements({
      mode: 'payment',
      amount: Math.round(amount * 100),
      currency: 'aud',
      paymentMethodCreation: 'manual', // ← This is required!
    });

    const paymentElement = elements.create('payment');

    if (formContainer) formContainer.style.display = 'none';
    paymentContainer.style.display = 'block';
    paymentElement.mount('#payment-element');

    // Scroll into view with top padding of --header-total-height
    const headerHeight = getComputedStyle(document.documentElement).getPropertyValue('--header-total-height') || '0px';
    const headerHeightValue = parseInt(headerHeight);
    const elementPosition = paymentContainer.getBoundingClientRect().top + window.pageYOffset;
    window.scrollTo({
      top: elementPosition - headerHeightValue - 20,
      behavior: 'smooth'
    });

    document.getElementById('submit-payment').onclick = async () => {
      const submitBtn = document.getElementById('submit-payment');
      submitBtn.disabled = true;
      submitBtn.textContent = 'Processing...';

      try {
        // Submit the form first
        const { error: submitError } = await elements.submit();
        if (submitError) throw new Error(submitError.message);

        // Create payment method
        const { error, paymentMethod } = await stripe.createPaymentMethod({
          elements,
          params: {
            billing_details: {
              name: formValues.customer_name,
              email: formValues.customer_email,
              phone: formValues.customer_phone || undefined,
            },
          },
        });

        if (error) throw new Error(error.message);

        // Send to backend with all form data
        const response = await fetch('/wp-json/cms/v1/payment/process', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            customer_name: formValues.customer_name,
            customer_email: formValues.customer_email,
            customer_phone: formValues.customer_phone || '',
            course_id: parseInt(formValues.course_id),
            educator_id: parseInt(formValues.educator_id),
            is_deposit: formValues.ticket_type === 'deposit',
            payment_method_id: paymentMethod.id,
            // Booking details / questionnaire fields
            partner_name: formValues.partner_name,
            occupation: formValues.occupation,
            partner_occupation: formValues.partner_occupation,
            health_fund: formValues.health_fund,
            due_date: formValues.due_date,
            model_of_care: formValues.model_of_care,
            caregiver: formValues.caregiver,
            hospital_location: formValues.hospital_location,
            dietary_requirements: formValues.dietary_requirements,
            medical_conditions: formValues.medical_conditions,
            medications: formValues.medications,
            disabilities: formValues.disabilities,
            first_baby: formValues.first_baby,
            birth_trauma: formValues.birth_trauma,
            fears: formValues.fears,
            feelings: formValues.feelings,
            expectations: formValues.expectations,
            hear_about: formValues.hear_about,
            mailing_agreement: formValues.mailing_agreement,
            additional_information: formValues.additional_information,
          }),
        });

        const result = await response.json();

        if (!result.success) {
          throw new Error(result.message || 'Payment failed');
        }

        // Handle 3D Secure / requires_action — authenticate via Stripe
        if (result.data.client_secret && result.data.payment_status === 'requires_action') {
          const {error, paymentIntent} = await stripe.handleNextAction({
            clientSecret: result.data.client_secret,
          });

          if (error) {
            throw new Error(error.message);
          }

          if (paymentIntent.status !== 'succeeded') {
            throw new Error('Payment not completed. Status: ' + paymentIntent.status);
          }

          // Confirm on server to finalise booking side effects
          const confirmResponse = await fetch('/wp-json/cms/v1/payment/confirm', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
              payment_intent_id: paymentIntent.id,
            }),
          });

          const confirmResult = await confirmResponse.json();
          if (!confirmResult.success) {
            throw new Error(confirmResult.message || 'Failed to confirm payment after authentication');
          }
        } else if (result.data.payment_status !== 'succeeded') {
          throw new Error('Payment not completed. Status: ' + result.data.payment_status);
        }

        paymentContainer.innerHTML = `
          <div style="color: green; padding: 20px; background: #efe; border-radius: 4px; text-align: center;">
            <h3 style="margin-top: 0;">✓ Payment Successful!</h3>
            <p><strong>Booking Number:</strong> ${result.data.booking_number}</p>
            <p>Confirmation sent to ${formValues.customer_email}</p>
          </div>
        `;

      } catch (err) {
        messageDiv.innerHTML = `<div style="color: red; padding: 15px; background: #fee; border-radius: 4px;">${err.message}</div>`;
        submitBtn.disabled = false;
        submitBtn.textContent = 'Complete Payment';
      }
    };

  } catch (error) {
    messageDiv.innerHTML = `<div style="color: red; padding: 15px; background: #fee; border-radius: 4px;">${error.message}</div>`;
    if (formContainer) formContainer.style.display = 'block';
    if (paymentContainer) paymentContainer.style.display = 'none';
  }
}

// Expose globally for Breakdance
window.handlePayment = handlePayment;