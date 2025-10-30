/**
 * SSL Commerz Checkout Handler for FluentCart (Vanilla JS)
 */
(function() {
    'use strict';
    window.FluentCartSslcommerz = {
        /**
         * Initialize SSL Commerz checkout
         */
        init: function(paymentArgs, formElement) {
            const checkoutUrl = paymentArgs.checkout_url;
            const checkoutType = paymentArgs.checkout_type || 'hosted';

            if (!checkoutUrl) {
                console.error('SSL Commerz checkout URL not found');
                return;
            }

            if (checkoutType === 'modal') {
                // Modal/popup checkout
                this.openModal(checkoutUrl);
                let processingDiv = document.querySelector('.fc-order-processing');
                if (processingDiv) {
                    processingDiv.classList.add('hidden');
                    processingDiv.style.display = 'none';
                }
            } else {
                // Hosted checkout (redirect)
                window.location.href = checkoutUrl;
            }
        },

        /**
         * Open payment in modal (popup window fallback due to X-Frame-Options)
         */
        openModal: function(checkoutUrl) {
            // Try to open a centered popup window
            var width = 720;
            var height = 780;
            var left = Math.max(0, (window.screen.width - width) / 2);
            var top = Math.max(0, (window.screen.height - height) / 2);
            var features = 'scrollbars=yes,resizable=yes,toolbar=no,location=no,status=no,menubar=no';
            features += ',width=' + width + ',height=' + height + ',left=' + left + ',top=' + top;

            var win = window.open(checkoutUrl, 'sslcommerz_checkout', features);

            // If popup blocked, fallback to full redirect
            if (!win || win.closed || typeof win.closed === 'undefined') {
                window.location.href = checkoutUrl;
                return;
            }
        }
    };

    // Register with FluentCart payment system
    if (window.fluentCartCheckout) {
        window.fluentCartCheckout.registerPaymentHandler('sslcommerz', function(paymentArgs, formElement) {
            window.FluentCartSslcommerz.init(paymentArgs, formElement);
        });
    }

    // Listen for next-action modal trigger
    window.addEventListener('fluent_cart_payment_next_action_sslcommerz', function(e) {
        try {
            var payload = e?.detail?.response;
            var paymentArgs = payload?.payment_args || {};
            window.FluentCartSslcommerz.init(paymentArgs, null);
        } catch (err) {
            console.error('SSL Commerz modal init failed', err);
        }
    });

})();


window.addEventListener("fluent_cart_load_payments_sslcommerz", function (e) {
    const submitButton = window.fluentcart_checkout_vars?.submit_button;
    e.detail.paymentLoader.enableCheckoutButton(submitButton.text);
});
