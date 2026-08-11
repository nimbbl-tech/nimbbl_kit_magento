define(
    [
        'Magento_Checkout/js/view/payment/default',
        'Magento_Checkout/js/model/quote',
        'jquery',
        'ko',
        'Magento_Checkout/js/model/payment/additional-validators',
        'Magento_Checkout/js/action/set-payment-information',
        'mage/url',
        'Magento_Customer/js/model/customer',
        'Magento_Checkout/js/action/place-order',
        'Magento_Checkout/js/model/full-screen-loader',
        'Magento_Ui/js/model/messageList',
        'Magento_Checkout/js/model/shipping-save-processor'
    ],
    function(Component, quote, $, ko, additionalValidators, setPaymentInformationAction, url, customer, placeOrderAction, fullScreenLoader, messageList, shippingSaveProcessor) {
        'use strict';

        return Component.extend({
            defaults: {
                template: 'Nimbbl_Magento/payment/nimbbl-form',
                nimbblDataFrameLoaded: false,
                nimbbl_response: {}
            },
            getMerchantName: function() {
                return window.checkoutConfig.payment.nimbbl.merchant_name;
            },

            getKeyId: function() {
                return window.checkoutConfig.payment.nimbbl.key_id;
            },

            isExpressCheckout: function() {
                return !!(window.checkoutConfig.payment.nimbbl.express_checkout);
            },

            context: function() {
                return this;
            },

            isShowLegend: function() {
                return true;
            },

            getCode: function() {
                return 'nimbbl';
            },

            isActive: function() {
                return true;
            },

            isAvailable: function() {
                return this.nimbblDataFrameLoaded;
            },

            handleError: function(error) {
                if (_.isObject(error)) {
                    this.messageContainer.addErrorMessage(error);
                } else {
                    this.messageContainer.addErrorMessage({
                        message: error
                    });
                }
            },

            initObservable: function() {
                var self = this._super(); //Resolves UI Error on Checkout

                // if (!self.nimbblDataFrameLoaded) {
                // $.getScript("https://checkout.razorpay.com/v1/checkout.js", function() {
                //     self.nimbblDataFrameLoaded = true;
                // });
                // $.getScript("https://api.nimbbl.tech/static/assets/js/checkout.js", function() {
                //     self.nimbblDataFrameLoaded = true;
                // });
                // }

                return self;
            },

            /**
             * @override
             */
            /** Process Payment */
            preparePayment: function(context, event) {

                if (!additionalValidators.validate()) { //Resolve checkout aggreement accept error
                    return false;
                }

                var self = this,
                    billing_address,
                    rzp_order_id;

                fullScreenLoader.startLoader();
                this.messageContainer.clear();

                this.amount = quote.totals()['base_grand_total'] * 100;
                billing_address = quote.billingAddress();

                this.user = {
                    name:    ((billing_address.firstname || '') + ' ' + (billing_address.lastname || '')).trim(),
                    contact: billing_address.telephone || '',
                };

                if (!customer.isLoggedIn()) {
                    this.user.email = quote.guestEmail || '';
                } else {
                    this.user.email = customer.customerData.email || '';
                }

                // Express checkout: Nimbbl collects address inside the overlay.
                // Require at least an email address before opening.
                if (this.isExpressCheckout() && !this.user.email) {
                    fullScreenLoader.stopLoader();
                    this.isPaymentProcessing.reject('Please enter your email address before proceeding with Nimbbl Express Checkout.');
                    return;
                }

                this.isPaymentProcessing = $.Deferred();

                $.when(this.isPaymentProcessing).done(
                    function() {
                        self.placeOrder();
                    }
                ).fail(
                    function(result) {
                        self.handleError(result);
                    }
                );

                self.getNimbblOrderId();

                return;
            },

            getNimbblOrderId: function() {
                var self = this;

                // Express checkout: Nimbbl overlay collects the shipping address — skip
                // Magento's address-save step so the form doesn't block the flow.
                // Virtual products also skip shipping (no physical delivery).
                if (quote.isVirtual() || self.isExpressCheckout()) {
                    self.createNimbblOrder();
                    return;
                }

                // Standard checkout: persist shipping/billing to the quote first.
                shippingSaveProcessor.saveShippingInformation().done(
                    function(response) {
                        self.createNimbblOrder();
                    }
                ).fail(
                    function(response) {
                        fullScreenLoader.stopLoader();
                        self.isPaymentProcessing.reject(response.message);
                    }
                );
            },
            createNimbblOrder: function() {
                var self = this;

                $.ajax({
                    type: 'POST',
                    url: url.build('nimbbl/payment/order?' + Math.random().toString(36).substring(10)),
                    data: {
                        email:            this.user.email,
                        billing_address:  JSON.stringify(quote.billingAddress()),
                        express_checkout: self.isExpressCheckout() ? 1 : 0
                    },

                    /**
                     * Success callback
                     * @param {Object} response
                     */
                    success: function(response) {
                        fullScreenLoader.stopLoader();
                        if (response.success) {
                            if (response.is_hosted) {
                                self.renderHosted(response);
                            } else {
                                self.renderIframe(response);
                            }
                        } else {
                            self.isPaymentProcessing.reject(response.message);
                        }
                    },


                    /**
                     * Error callback
                     * @param {*} response
                     */
                    error: function(response) {
                        fullScreenLoader.stopLoader();
                        self.isPaymentProcessing.reject(response.message);
                    }
                });
            },
            createInputFieldsFromOptions: function(options, form) {
                var self = this;

                function visitNestedOption(options, parentKey) {
                    for (let curKey in options) {
                        if (options.hasOwnProperty(curKey)) {
                            const value = options[curKey];
                            let prepareKey = parentKey ? `${parentKey}[${curKey}]` : curKey;

                            if (typeof value === 'object') {
                                visitNestedOption(value, prepareKey);
                            } else {
                                // Exception: Rename key -> key_id (merchant key)
                                if (prepareKey === 'key') {
                                    prepareKey = 'key_id';
                                }

                                form.appendChild(self.createHiddenInput(prepareKey, value));
                            }
                        }
                    }
                }
                visitNestedOption(options);
            },

            createHiddenInput: function(key, value) {
                var input = document.createElement('input');

                input.type = 'hidden';
                input.name = key;
                input.value = value;

                return input;
            },

            renderHosted: function(data) {
                var self = this;

                this.merchant_order_id = data.order_id;

                // Redirect (hosted) mode: NimbblCheckout navigates the browser to the
                // Nimbbl-hosted checkout page. Nimbbl redirects back to callback_url
                // after payment, POSTing nimbbl_transaction_id + nimbbl_signature.
                var options = {
                    "access_key":   self.getKeyId(),
                    "order_id":     data.nimbbl_order,
                    "redirect":     true,
                    "callback_url": url.build('nimbbl/payment/order'),
                    "cancel_url":   url.build('checkout/cart'),
                    "prefill": {
                        "name":    this.user.name,
                        "email":   this.user.email,
                        "contact": this.user.contact
                    },
                    "custom": {},
                    // G4: configurable Sonic JS host (mirrors WooCommerce checkout_host).
                    "checkoutHost": (window.checkoutConfig.payment.nimbbl.checkout_host || 'https://sonic.nimbbl.tech'),
                    // P3: API host for NimbblCheckout — mirrors WooCommerce api_host token.
                    "apiHost": (window.checkoutConfig.payment.nimbbl.api_host || '')
                };

                // NimbblCheckout needs MicroModal present (same as popup mode) before
                // it can redirect; require it, then open.
                require(['MicroModal'], function(mm) {
                    window.MicroModal = mm;
                    window.nimbblCheckout = new NimbblCheckout(options);
                    window.nimbblCheckout.open(data.nimbbl_order);
                });
            },

            checkNimbblOrder: function(data) {
                var self = this;

                $.ajax({
                    type: 'POST',
                    url: url.build('nimbbl/payment/order?' + Math.random().toString(36).substring(10)),
                    data: "order_check=1",

                    /**
                     * Success callback
                     * @param {Object} response
                     */
                    success: function(response) {
                        //fullScreenLoader.stopLoader();
                        if (response.success) {
                            if (response.order_id) {
                                // Use Magento's url.build() so the path resolves correctly
                                // regardless of store base URL or sub-directory installations.
                                // Append a random cache-buster so the browser does not serve
                                // a stale cached copy of the success page.
                                $(location).attr('href', url.build('checkout/onepage/success') + '?' + Math.random().toString(36).substring(10));
                            } else {
                                setTimeout(function() { self.checkNimbblOrder(data); }, 1500);
                            }
                        } else {
                            self.placeOrder(data);
                        }
                    },

                    /**
                     * Error callback
                     * @param {*} response
                     */
                    error: function(response) {
                        // FIX-4: Stop loader on network/server error during order-check polling.
                        fullScreenLoader.stopLoader();
                        self.isPaymentProcessing.reject(response.message);
                    }
                });
            },

            renderIframe: function(data) {
                var self = this;

                this.merchant_order_id = data.order_id;

                // var options = {
                //     key: self.getKeyId(),
                //     name: self.getMerchantName(),
                //     amount: data.amount,
                //     handler: function(data) {
                //         self.nimbbl_response = data;
                //         self.checkNimbblOrder(data);
                //     },
                //     order_id: data.rzp_order,
                //     modal: {
                //         ondismiss: function() {
                //             self.isPaymentProcessing.reject("Payment Closed");
                //         }
                //     },
                //     notes: {
                //         merchant_order_id: '',
                //         merchant_quote_id: data.order_id
                //     },
                //     prefill: {
                //         name: this.user.name,
                //         contact: this.user.contact,
                //         email: this.user.email
                //     },
                //     callback_url: url.build('nimbbl/payment/order'),
                //     _: {
                //         integration: 'magento',
                //         integration_version: data.module_version,
                //         integration_parent_version: data.maze_version,
                //     }
                // };

                // if (data.quote_currency !== 'INR') {
                //     options.display_currency = data.quote_currency;
                //     options.display_amount = data.quote_amount;
                // }

                // FIX-1: Declare before `options` so readers see it before the callback that references it.
                // (var hoisting makes order irrelevant at runtime, but source-order clarity matters.)
                var _callbackFired = false;

                // Options for the nimbbl checkout.
                var options = {
                    "access_key":   self.getKeyId(), // Enter the Key ID generated from the Dashboard
                    "order_id":     data.nimbbl_order,
                    // "callback_url": url.build('nimbbl/payment/order'),
                    // "redirect": false,
                    // G4: configurable Sonic JS host (mirrors WooCommerce checkout_host).
                    "checkoutHost": (window.checkoutConfig.payment.nimbbl.checkout_host || 'https://sonic.nimbbl.tech'),
                    // P3: API host for NimbblCheckout — mirrors WooCommerce api_host token.
                    "apiHost": (window.checkoutConfig.payment.nimbbl.api_host || ''),
                    "callback_handler": function(response) {
                        // Mark that callback_handler fired so the dismiss guard (below) knows
                        // not to reject the deferred a second time.
                        _callbackFired = true;

                        // ── v4 ──────────────────────────────────────────────────────────────
                        // Payload envelope: { payload: base64(innerJson), nimbbl_signature, version: 'v4' }
                        // Inner JSON (decoded): { checkout_status, reason, nimbbl_transaction_id,
                        //                        invoice_id, retry, message, nimbbl_order_id }
                        // HMAC is computed server-side over the raw inner JSON string.
                        // PHP verifies: HMAC-SHA256(keySecret, base64_decode(payload))
                        if (response.version === 'v4') {
                            var v4Inner = null;
                            try {
                                v4Inner = JSON.parse(atob(response.payload || ''));
                            } catch (e) {
                                fullScreenLoader.stopLoader();
                                self.isPaymentProcessing.reject(
                                    $.mage.__('Payment response could not be read. Please contact support.')
                                );
                                return;
                            }

                            var v4Status = (typeof v4Inner.checkout_status === 'string') ? v4Inner.checkout_status : '';

                            if (v4Status !== 'success') {
                                // Map v4 reason codes to human-readable messages.
                                var _v4ReasonMap = {
                                    'user_cancelled':                $.mage.__('Payment was cancelled. Please try again.'),
                                    'user_cancel':                   $.mage.__('Payment was cancelled. Please try again.'),
                                    'payment_failed':                $.mage.__('Payment could not be processed. Please try again.'),
                                    'max_retries_exhausted':         $.mage.__('Maximum payment attempts reached. Please contact support.'),
                                    'timed_out':                     $.mage.__('The payment session timed out. Please try again.'),
                                    'timeout':                       $.mage.__('The payment session timed out. Please try again.'),
                                    'bank_declined':                 $.mage.__('Your payment was declined by the bank. Please try a different method.'),
                                    'insufficient_funds':            $.mage.__('Insufficient funds. Please try a different payment method.'),
                                    'order_lapsed':                  $.mage.__('The order has expired. Please start a new checkout.'),
                                    'no_payment_methods_configured': $.mage.__('No payment methods are available. Please contact support.'),
                                    'invalid_order':                 $.mage.__('The order is invalid. Please start a new checkout.')
                                };
                                var _v4Reason  = (typeof v4Inner.reason === 'string') ? v4Inner.reason : '';
                                var _v4FailMsg = _v4ReasonMap[_v4Reason]
                                    || (typeof v4Inner.message === 'string' && v4Inner.message ? v4Inner.message : null)
                                    || $.mage.__('Payment could not be completed. Please try again.');
                                fullScreenLoader.stopLoader();
                                self.isPaymentProcessing.reject(_v4FailMsg);
                                return;
                            }

                            // v4 success: pass the raw base64 payload to PHP for HMAC verification.
                            // nimbbl_transaction_id is extracted from the inner payload — it is always
                            // populated when checkout_status is 'success' (a transaction exists).
                            data['nimbbl_transaction_id']    = v4Inner.nimbbl_transaction_id || '';
                            data['nimbbl_signature']         = response.nimbbl_signature;
                            data['nimbbl_signature_version'] = 'v4';
                            data['nimbbl_payload']           = response.payload; // base64 string; PHP decodes to verify HMAC
                            data['nimbbl_status']            = v4Status;
                            data['nimbbl_txn_type']          = '';

                            self.nimbbl_response = data;
                            fullScreenLoader.startLoader();
                            self.checkNimbblOrder(data);
                            return;
                        }

                        // ── v1 / v2 / v3 ────────────────────────────────────────────────────
                        if (response.status === 'failed') {
                            // FIX-2: Map Nimbbl reason codes to human-readable messages instead
                            // of showing raw "Payment Closed: undefined" / "Payment Closed: user_cancel".
                            var _reasonMap = {
                                'user_cancel':        $.mage.__('Payment was cancelled. Please try again.'),
                                'user_cancelled':     $.mage.__('Payment was cancelled. Please try again.'),
                                'timeout':            $.mage.__('The payment session timed out. Please try again.'),
                                'bank_declined':      $.mage.__('Your payment was declined by the bank. Please try a different method.'),
                                'insufficient_funds': $.mage.__('Insufficient funds. Please try a different payment method.')
                            };
                            var _rawReason = (typeof response.reason === 'string') ? response.reason : '';
                            var _failMsg   = _reasonMap[_rawReason]
                                || (typeof response.message === 'string' && response.message ? response.message : null)
                                || $.mage.__('Payment could not be completed. Please try again.');
                            fullScreenLoader.stopLoader();
                            self.isPaymentProcessing.reject(_failMsg);
                        } else {
                            // Capture everything needed for server-side HMAC verification.
                            // nimbbl_payment_id is an alias for nimbbl_transaction_id (used by getData()).
                            data['nimbbl_transaction_id']      = response.nimbbl_transaction_id;
                            data['nimbbl_signature']           = response.nimbbl_signature;
                            // Signature version lives at response.version (top-level string set
                            // by the backend: 'v1'/'v2'/'v3'/'v4').  Nested fallback covers any
                            // SDK variant that surfaces it as transaction.signature_version.
                            // Neither response.nimbbl_signature_version nor response.signature_version
                            // are real fields — using them caused v3 merchants to always HMAC-verify
                            // as v2, which always failed (different string formula).
                            data['nimbbl_signature_version']   = response.version
                                || (response.transaction && response.transaction.signature_version)
                                || 'v2';
                            data['nimbbl_status']              = response.status || 'success';
                            // transaction_type lives at response.transaction.transaction_type (nested).
                            // response.transaction_type is not a top-level field; reading it directly
                            // returned undefined → empty string → v3 HMAC always failed because
                            // the PHP built "…|status|" while the backend signed "…|status|payment".
                            data['nimbbl_txn_type']            = (response.transaction && response.transaction.transaction_type)
                                || response.transaction_type
                                || '';

                            self.nimbbl_response = data;
                            // FIX-4: Re-enable loader while checkNimbblOrder polls / places the order.
                            // The loader was stopped inside createNimbblOrder.success (before the popup
                            // opened). Without this, the checkout page appears frozen after the popup
                            // closes. Mirrors Razorpay's behaviour.
                            fullScreenLoader.startLoader();
                            self.checkNimbblOrder(data);
                        }
                    },
                    "custom": {},
                };

                // Magento's RequireJS intercepts AMD define() inside checkout.js, so
                // MicroModal ends up as a RequireJS module rather than window.MicroModal.
                // Require it and expose globally so NimbblCheckout constructor can find it.
                // Also use window.nimbblCheckout (not window.checkout) to avoid collision
                // with the Magento DOM element id="checkout".
                require(['MicroModal'], function(mm) {
                    window.MicroModal = mm;
                    window.nimbblCheckout = new NimbblCheckout(options);
                    window.nimbblCheckout.open(data.nimbbl_order);

                    // FIX-1: Dismiss guard — fires if the user closes the Nimbbl popup
                    // without completing payment AND the SDK did not call callback_handler.
                    // Without this, the checkout page returns to idle with zero feedback.
                    // Uses MicroModal's 'micromodal-close' custom event (dispatched on the
                    // trigger element or document when any MicroModal instance closes).
                    // { once: true } ensures it cleans up after the first close event.
                    document.addEventListener('micromodal-close', function _nimbblDismissGuard() {
                        if (!_callbackFired) {
                            fullScreenLoader.stopLoader();
                            self.isPaymentProcessing.reject(
                                $.mage.__('Payment was cancelled. Please try again.')
                            );
                        }
                    }, { once: true });
                });

            },

            getData: function() {
                return {
                    "method": this.item.method,
                    "po_number": null,
                    "additional_data": {
                        // nimbbl_payment_id is the server-side alias for nimbbl_transaction_id
                        nimbbl_payment_id:           this.nimbbl_response.nimbbl_transaction_id,
                        order_id:                    this.merchant_order_id,
                        nimbbl_signature:            this.nimbbl_response.nimbbl_signature,
                        nimbbl_signature_version:    this.nimbbl_response.nimbbl_signature_version || 'v2',
                        nimbbl_status:               this.nimbbl_response.nimbbl_status || 'success',
                        nimbbl_txn_type:             this.nimbbl_response.nimbbl_txn_type || '',
                        // v4 only: raw base64 inner payload; empty string for v1/v2/v3.
                        nimbbl_payload:              this.nimbbl_response.nimbbl_payload || ''
                    }
                };
            }
        });
    }


);