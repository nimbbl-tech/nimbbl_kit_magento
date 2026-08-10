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
                        if (response.status === 'failed') {
                            self.isPaymentProcessing.reject("Payment Closed: " + response.reason);
                        } else {
                            // Capture everything needed for server-side HMAC verification.
                            // nimbbl_payment_id is an alias for nimbbl_transaction_id (used by getData()).
                            data['nimbbl_transaction_id']      = response.nimbbl_transaction_id;
                            data['nimbbl_signature']           = response.nimbbl_signature;
                            // TODO(Nimbbl): Confirm which signature version current checkout.js
                            // emits by default. If the SDK omits the version field and signs
                            // with v3 (invoice_id|txn_id|amount|currency|status|txn_type),
                            // change the fallback from 'v2' to 'v3' to avoid HMAC mismatches.
                            data['nimbbl_signature_version']   = response.nimbbl_signature_version || response.signature_version || 'v2';
                            data['nimbbl_status']              = response.status || 'success';
                            data['nimbbl_txn_type']            = response.transaction_type || response.txn_type || '';

                            self.nimbbl_response = data;
                            self.checkNimbblOrder(data);
                        }

                        // let response_payload = {
                        //     "payload": response
                        // }
                        // let stringify_response = JSON.stringify(response_payload);
                        // let encoded_response = btoa(stringify_response);
                        // location.href = 'https://uatshop.nimbbl.tech/thank-you?esponse=' + encoded_response;
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
                        nimbbl_signature_version:    this.nimbbl_response.nimbbl_signature_version || 'v2', // see TODO above
                        nimbbl_status:               this.nimbbl_response.nimbbl_status || 'success',
                        nimbbl_txn_type:             this.nimbbl_response.nimbbl_txn_type || ''
                    }
                };
            }
        });
    }


);