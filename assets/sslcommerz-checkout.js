/**
 * SSL Commerz Checkout Handler for FluentCart (Vanilla JS)
 */
(function() {
    'use strict';
    window.FluentCartSslcommerz = {
        embedScriptLoaded: false,
        paymentMode: 'test', // Will be set from settings

        /**
         * Load SSL Commerz embed script
         */
        loadEmbedScript: function(mode) {
            if (this.embedScriptLoaded) {
                return;
            }

            this.paymentMode = mode || 'test';
            var script = document.createElement("script");
            var tag = document.getElementsByTagName("script")[0];

            // Use sandbox or live embed script based on mode
            var embedUrl = this.paymentMode === 'live'
                ? "https://seamless-epay.sslcommerz.com/embed.min.js"
                : "https://sandbox.sslcommerz.com/embed.min.js";

            script.src = embedUrl + "?" + Math.random().toString(36).substring(7);
            script.async = true;

            script.onload = function() {
                window.FluentCartSslcommerz.embedScriptLoaded = true;
            };

            tag.parentNode.insertBefore(script, tag);
        },

        /**
         * Initialize SSL Commerz checkout
         */
        init: function(paymentArgs, formElement) {
            const checkoutUrl = paymentArgs.checkout_url;
            const checkoutType = paymentArgs.checkout_type || 'hosted';
            const paymentMode = paymentArgs.payment_mode || 'test';
            const transactionId = paymentArgs.transaction_id;
            const orderHash = paymentArgs.order_hash;

            if (checkoutType === 'modal') {
                // Modal/popup checkout using SSL Commerz embed script
                this.initModalCheckout(paymentArgs, formElement, transactionId, orderHash, paymentMode);
            } else {
                // Hosted checkout (redirect)
                if (!checkoutUrl) {
                    console.error('SSL Commerz checkout URL not found');
                    return;
                }
                window.location.href = checkoutUrl;
            }
        },

        /**
         * Initialize modal checkout with SSL Commerz embed script
         */
        initModalCheckout: function(paymentArgs, formElement, transactionId, orderHash, paymentMode) {
            // IMPORTANT: Create the button FIRST before loading the embed script
            // The embed script looks for #sslczPayBtn when it loads
            var buttonCreated = this.setupModalButton(paymentArgs, formElement, transactionId, orderHash);
            
            if (!buttonCreated) {
                console.error('Failed to create SSL Commerz payment button');
                // Fallback to redirect
                if (paymentArgs.checkout_url) {
                    window.location.href = paymentArgs.checkout_url;
                }
                return;
            }

            // Now load the embed script - it will automatically attach to the button
            this.loadEmbedScript(paymentMode);
        },

        /**
         * Setup the SSL Commerz payment button
         * Returns true if button was created successfully, false otherwise
         */
        setupModalButton: function(paymentArgs, formElement, transactionId, orderHash) {
            // Find or create the button container
            var container = formElement || document.querySelector('.fluent-cart-checkout_embed_payment_container_sslcommerz');
            
            if (!container) {
                console.error('SSL Commerz payment container not found. Looking for:', '.fluent-cart-checkout_embed_payment_container_sslcommerz');
                // Try to find any payment container as fallback
                container = document.querySelector('[data-fluent-cart-checkout-page-embed-payment-container]');
                if (!container) {
                    return false;
                }
            }

            // Build endpoint URL using WordPress ajaxurl
            var endpoint = window.ajaxurl;
            if (!endpoint && window.fluentcart_checkout_vars) {
                endpoint = window.fluentcart_checkout_vars.ajaxurl;
            }
            if (!endpoint) {
                endpoint = '/wp-admin/admin-ajax.php';
            }
            
            // Add action parameter
            if (endpoint.indexOf('?') > -1) {
                endpoint += '&action=sslcommerz_init_modal';
            } else {
                endpoint += '?action=sslcommerz_init_modal';
            }

            // Add transaction_id and order_hash to endpoint
            var params = [];
            if (transactionId) {
                params.push('transaction_id=' + encodeURIComponent(transactionId));
            }
            if (orderHash) {
                params.push('order_hash=' + encodeURIComponent(orderHash));
            }
            if (params.length) {
                endpoint += (endpoint.indexOf('?') > -1 ? '&' : '?') + params.join('&');
            }

            // Clear container and add button
            container.innerHTML = '';
            
            var button = document.createElement('button');
            button.id = 'sslczPayBtn'; // REQUIRED: SSL Commerz embed script looks for this ID
            button.className = 'sslcz-payment-btn';
            button.type = 'button';
            button.setAttribute('endpoint', endpoint); // REQUIRED: Endpoint for payment initialization
            
            if (transactionId) {
                button.setAttribute('order', transactionId);
            }
            
            button.textContent = window.fct_sslcommerz_data?.translations?.['Pay Now'] || 'Pay Now';
            button.style.cssText = 'padding: 12px 24px; background-color: #0B9E48; color: white; border: none; border-radius: 4px; cursor: pointer; font-size: 16px; font-weight: bold; width: 100%;';
            
            // Add nonce if available
            if (window.fluentCartRestVars?.rest?.nonce) {
                button.setAttribute('token', window.fluentCartRestVars.rest.nonce);
            }

            // Append button to DOM immediately - embed script needs it to exist
            container.appendChild(button);

            // Ensure button is visible and in the DOM
            container.style.display = 'block';
            button.style.display = 'block';

            // Hide processing div
            var processingDiv = document.querySelector('.fc-order-processing');
            if (processingDiv) {
                processingDiv.classList.add('hidden');
                processingDiv.style.display = 'none';
            }

            // Auto-click button after script loads (give it time to attach event handlers)
            var self = this;
            var clickInterval = setInterval(function() {
                if (window.SSLCommerz || self.embedScriptLoaded) {
                    clearInterval(clickInterval);
                    // Small delay to ensure SSL Commerz has attached its handlers
                    setTimeout(function() {
                        var btn = document.getElementById('sslczPayBtn');
                        if (btn && !btn.disabled) {
                            btn.click();
                        }
                    }, 200);
                }
            }, 100);

            // Stop checking after 5 seconds
            setTimeout(function() {
                clearInterval(clickInterval);
            }, 5000);

            return true;
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
    const sslcommerzContainer = document.querySelector('.fluent-cart-checkout_embed_payment_container_sslcommerz');

    if (sslcommerzContainer) {
        // sslcommerzContainer.innerHTML = `<p>Pay with any card or mobile banking account.</p>`;
        e.detail.paymentLoader.enableCheckoutButton(submitButton.text);
    }
});
