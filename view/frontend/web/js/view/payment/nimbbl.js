define(
    [
        'uiComponent',
        'Magento_Checkout/js/model/payment/renderer-list'
    ],
    function (Component,
              rendererList) {
        'use strict';
        rendererList.push(
            {
                type: 'nimbbl',
                component: 'Nimbbl_Magento/js/view/payment/method-renderer/nimbbl-payments'
            }
        );
        return Component.extend({});
    }
);

