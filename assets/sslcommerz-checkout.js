class SslcommerzCheckout {
    constructor(form, orderHandler, response, paymentLoader) {
        this.form = form;
        this.orderHandler = orderHandler;
        this.data = response;
        this.paymentArgs = response?.payment_args || {};
        this.intent = response?.intent || {};
        this.paymentLoader = paymentLoader;
        this.currentOrderData = null;
        this.$t = this.translate.bind(this);
        this.embedScriptLoaded = false;
        this.submitButton = window.fluentcart_checkout_vars?.submit_button;
    }

    translate(string) {
        const translations = window.fct_sslcommerz_data?.translations || {};
        return translations[string] || string;
    }

    init() {
        const sslcommerzContainer = document.querySelector('.fluent-cart-checkout_embed_payment_container_sslcommerz');
        if (!sslcommerzContainer) {
            console.error('SSLCommerz container not found');
            return;
        }

        // Clear any existing content
        sslcommerzContainer.innerHTML = '';

        const checkoutType = this.paymentArgs?.checkout_type || 'hosted';

        if (checkoutType === 'modal') {
            this.initModalCheckout(sslcommerzContainer);
        } else {
            this.initHostedCheckout(sslcommerzContainer);
        }
    }

    initModalCheckout(container) {
        // Hide payment methods selector if present
        const paymentMethods = this.form.querySelector('.fluent_cart_payment_methods');
        if (paymentMethods) {
            paymentMethods.style.display = 'none';
        }

        // Create and render the Pay Now button
        this.createPayNowButton(container);

        // Listen for next action event (after order is created)
        this.setupNextActionListener();

        // Remove loading indicator
        const loadingElement = document.getElementById('fct_loading_payment_processor');
        if (loadingElement) {
            loadingElement.remove();
        }
    }

    initHostedCheckout(container) {
        // Enable checkout button for hosted redirect flow
        this.paymentLoader.enableCheckoutButton(this.submitButton?.text || this.$t('Place Order'));

        // Show payment info
        const infoDiv = document.createElement('div');
        infoDiv.style.cssText = `
            padding: 15px;
            background: #f9f9f9;
            border: 1px solid #e0e0e0;
            border-radius: 6px;
            text-align: center;
            color: #666;
        `;
        infoDiv.textContent = this.$t('You will be redirected to SSLCommerz to complete your payment');
        container.appendChild(infoDiv);

        // Listen for next action event
        this.setupNextActionListener();

        // Remove loading indicator
        const loadingElement = document.getElementById('fct_loading_payment_processor');
        if (loadingElement) {
            loadingElement.remove();
        }
    }

    createPayNowButton(container) {
        const payNowButton = document.createElement('button');
        payNowButton.id = 'sslcommerz-pay-button';
        payNowButton.className = 'sslcommerz-payment-button';
        payNowButton.type = 'button';
        payNowButton.textContent = this.data?.payment_args?.modal_checkout_button_text;
        payNowButton.style.cssText = `
            width: 100%;
            padding: 12px 24px;
            background: ${this.data?.payment_args?.modal_checkout_button_color};
            color: ${this.data?.payment_args?.modal_checkout_button_text_color};
            border: none;
            border-radius: 6px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: background-color 0.2s;
            margin-bottom: 10px;
        `;

        // Add hover effect
        payNowButton.addEventListener('mouseenter', () => {
            payNowButton.style.backgroundColor = this.data?.payment_args?.modal_checkout_button_hover_color;
        });
        payNowButton.addEventListener('mouseleave', () => {
            payNowButton.style.backgroundColor = this.data?.payment_args?.modal_checkout_button_color
        });

        payNowButton.addEventListener('click', async (e) => {
            e.preventDefault();
            await this.handlePayNowButtonClick();
        });

        // Add button to container
        container.appendChild(payNowButton);

        const extraText = document.createElement('p');
        extraText.style.cssText = `
            text-align: center;
            margin-top: 10px;
            font-size: 14px;
            color: #666;
        `;
        extraText.textContent = this.$t('Secure payment powered by SSLCommerz');
        container.appendChild(extraText);
    }

    async handlePayNowButtonClick() {
        try {
            this.paymentLoader?.changeLoaderStatus('processing');
            
            if (typeof this.orderHandler === 'function') {
                const orderData = await this.orderHandler();
                
                if (!orderData) {
                    this.paymentLoader?.changeLoaderStatus(this.$t('Order creation failed'));
                    this.paymentLoader?.hideLoader();
                    this.paymentLoader?.enableCheckoutButton();
                    throw new Error(this.$t('Failed to create order'));
                }

                this.currentOrderData = orderData;
                
                // The next action event will be triggered automatically
                // We'll handle it in setupNextActionListener
                
            } else {
                this.paymentLoader?.changeLoaderStatus(this.$t('Order handler not available'));
                this.paymentLoader?.hideLoader();
                this.paymentLoader?.enableCheckoutButton();
                throw new Error(this.$t('Order handler is not properly configured'));
            }
            
        } catch (error) {
            this.paymentLoader?.changeLoaderStatus('Error: ' + error.message);
            this.paymentLoader?.hideLoader();
            this.paymentLoader?.enableCheckoutButton();
            
            // Show error to user
            this.displayErrorMessage(
                document.querySelector('.fluent-cart-checkout_embed_payment_container_sslcommerz'),
                error.message
            );
        }
    }

    setupNextActionListener() {
        const that = this;
        
        window.addEventListener('fluent_cart_payment_next_action_sslcommerz', async (e) => {
            try {
                const remoteResponse = e.detail?.response;
                const sslcommerzData = remoteResponse?.payment_args || {};
                const checkoutType = sslcommerzData?.checkout_type || 'hosted';

                if (checkoutType === 'modal') {
                    // Modal checkout: Load embed script and setup button
                    await that.handleModalCheckout(sslcommerzData, remoteResponse);
                } else {
                    // Hosted checkout: Redirect to checkout URL
                    that.handleHostedCheckout(sslcommerzData);
                }
                
            } catch (error) {
                console.error('SSLCommerz next action error:', error);
                that.handleError(error);
            }
        }, { once: false }); // Allow multiple triggers
    }

    async handleModalCheckout(sslcommerzData, remoteResponse) {
        try {
            const checkoutUrl = sslcommerzData.checkout_url;
            const transactionHash = sslcommerzData.transaction_hash;
            const orderHash = sslcommerzData.order_hash;
            const paymentMode = sslcommerzData.payment_mode || 'test';

            if (!checkoutUrl) {
                throw new Error(this.$t('SSLCommerz checkout URL not found'));
            }

            // Load the SSLCommerz embed script
            await this.loadEmbedScript(paymentMode);

            this.paymentLoader.hideLoader();

            // Setup the modal button (required by SSLCommerz embed script)
            this.setupModalButton(sslcommerzData, transactionHash, orderHash);

            // Auto-click the button after a short delay
            setTimeout(() => {
                const btn = document.getElementById('sslczPayBtn');
                if (btn && !btn.disabled) {
                    btn.click();
                }
            }, 10);

        } catch (error) {
        
            // Fallback to redirect if modal fails
            if (sslcommerzData.checkout_url) {
                this.paymentLoader?.changeLoaderStatus(this.$t('Redirecting to payment gateway...'));
                window.location.href = sslcommerzData.checkout_url;
            } else {
                this.handleError(error);
            }
        }
    }

    handleHostedCheckout(sslcommerzData) {

        console.log('sslcommerz data', sslcommerzData);

        const checkoutUrl = sslcommerzData.checkout_url;
        
        if (!checkoutUrl) {
            this.handleError(new Error(this.$t('SSLCommerz checkout URL not found')));
            return;
        }

        // Redirect to hosted checkout
        this.paymentLoader?.changeLoaderStatus(this.$t('Redirecting to payment gateway...'));
        window.location.href = checkoutUrl;
    }

    loadEmbedScript(mode) {
        return new Promise((resolve, reject) => {
            if (this.embedScriptLoaded || window.SSLCommerz) {
                resolve();
                return;
            }

            const paymentMode = mode || 'test';
            const embedUrl = paymentMode === 'live'
                ? 'https://seamless-epay.sslcommerz.com/embed.min.js'
                : 'https://sandbox.sslcommerz.com/embed.min.js';

            const script = document.createElement('script');
            script.src = embedUrl + '?' + Math.random().toString(36).substring(7);
            script.async = true;

            script.onload = () => {
                this.embedScriptLoaded = true;
                resolve();
            };

            script.onerror = () => {
                reject(new Error(this.$t('Failed to load SSLCommerz script')));
            };

            document.head.appendChild(script);
        });
    }

    setupModalButton(sslcommerzData, transactionHash, orderHash) {
        const container = document.querySelector('.fluent-cart-checkout_embed_payment_container_sslcommerz');
        
        if (!container) {
            console.error('SSLCommerz container not found');
            return false;
        }

        // Build endpoint URL
        let endpoint = window.fluentcart_checkout_vars?.ajaxurl || window.ajaxurl || '/wp-admin/admin-ajax.php';
        
        // Add action parameter
        endpoint += endpoint.indexOf('?') > -1 ? '&' : '?';
        endpoint += 'action=sslcommerz_init_modal';

        // Add transaction_id and order_hash to endpoint
        const params = [];
        if (transactionHash) {
            params.push('transaction_hash=' + encodeURIComponent(transactionHash));
        }
        if (orderHash) {
            params.push('order_hash=' + encodeURIComponent(orderHash));
        }
        if (params.length) {
            endpoint += '&' + params.join('&');
        }

        // Clear container and add button
        container.innerHTML = '';
        
        const button = document.createElement('button');
        button.id = 'sslczPayBtn'; // REQUIRED: SSL Commerz embed script looks for this ID
        button.className = 'sslcz-payment-btn';
        button.type = 'button';
        button.setAttribute('endpoint', endpoint);
        
        if (transactionId) {
            button.setAttribute('order', transactionId);
        }
        
        button.textContent = this.$t(this.submitButton?.text || 'Pay Now');
        button.style.cssText = `
            width: 100%;
            padding: 12px 24px;
            background: #0B9E48;
            color: #fff;
            border: none;
            border-radius: 6px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
        `;
        
        // Add nonce if available
        if (window.fluentCartRestVars?.rest?.nonce) {
            button.setAttribute('token', window.fluentCartRestVars.rest.nonce);
        }

        // Append button to DOM
        container.appendChild(button);
        container.style.display = 'block';
        button.style.display = 'block';

        return true;
    }

    handleError(error) {
        const errorMessage = error?.message || this.$t('An error occurred');
        
        this.paymentLoader?.changeLoaderStatus('Error: ' + errorMessage);
        this.paymentLoader?.hideLoader();
        this.paymentLoader?.enableCheckoutButton(this.submitButton?.text || this.$t('Place Order'));
        
        // Display error to user
        this.displayErrorMessage(
            document.querySelector('.fluent-cart-checkout_embed_payment_container_sslcommerz'),
            errorMessage
        );
    }

    displayErrorMessage(container, message) {
        if (!container) return;

        const errorDiv = document.createElement('div');
        errorDiv.style.cssText = `
            color: #dc3545;
            padding: 15px;
            border: 1px solid #dc3545;
            border-radius: 6px;
            background: #ffeaea;
            margin-top: 10px;
            font-size: 14px;
        `;
        errorDiv.className = 'fc-error-message';
        errorDiv.innerHTML = `<strong>${this.$t('Error')}:</strong> ${message}`;

        // Remove any existing error messages
        const existingError = container.querySelector('.fc-error-message');
        if (existingError) {
            existingError.remove();
        }

        container.appendChild(errorDiv);
    }
}

// Listen for initial payment load event
window.addEventListener('fluent_cart_load_payments_sslcommerz', function (e) {
    const sslcommerzContainer = document.querySelector('.fluent-cart-checkout_embed_payment_container_sslcommerz');
    
    if (sslcommerzContainer) {
        sslcommerzContainer.innerHTML = '<div id="fct_loading_payment_processor">Loading SSLCommerz...</div>';
    }

    fetch(e.detail.paymentInfoUrl, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-WP-Nonce': e.detail.nonce,
        },
        credentials: 'include'
    })
    .then(async (response) => {
        response = await response.json();
        
        if (response?.status === 'failed') {
            displayErrorMessage(response?.message || 'Failed to load payment information');
            return;
        }
        
        // Initialize the SSLCommerz checkout class
        new SslcommerzCheckout(
            e.detail.form,
            e.detail.orderHandler,
            response,
            e.detail.paymentLoader
        ).init();
    })
    .catch(error => {
        const translations = window.fct_sslcommerz_data?.translations || {};
        function $t(string) {
            return translations[string] || string;
        }
        const message = error?.message || $t('An error occurred while loading SSLCommerz');
        displayErrorMessage(message);
    });

    function displayErrorMessage(message) {
        const sslcommerzContainer = document.querySelector('.fluent-cart-checkout_embed_payment_container_sslcommerz');
        if (sslcommerzContainer) {
            sslcommerzContainer.innerHTML = `
                <div style="color: #dc3545; padding: 15px; border: 1px solid #dc3545; border-radius: 4px; background: #ffeaea;">
                    <strong>Error:</strong> ${message}
                </div>
            `;
        }

        const loadingElement = document.getElementById('fct_loading_payment_processor');
        if (loadingElement) {
            loadingElement.remove();
        }

        // Enable checkout button
        const paymentLoader = e.detail?.paymentLoader;
        const submitButton = window.fluentcart_checkout_vars?.submit_button;
        if (paymentLoader) {
            paymentLoader.enableCheckoutButton(submitButton?.text || 'Place Order');
        }
    }
});
