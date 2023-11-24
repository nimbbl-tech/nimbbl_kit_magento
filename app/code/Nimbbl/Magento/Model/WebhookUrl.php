<?php 

namespace Nimbbl\Magento\Model;

use Magento\Framework\Data\Form\Element\AbstractElement;
/**
 *  Used to display webhook url link
 */
class WebhookUrl extends \Magento\Config\Block\System\Config\Form\Field
{    
    protected function _getElementHtml(AbstractElement $element)
    {

        $baseUrl = $this->_storeManager->getStore()->getBaseUrl(\Magento\Framework\UrlInterface::URL_TYPE_WEB);
        $copyButton = "<div class='nimbbl-webhook-to-clipboard'
                                           style='background-color: #337ab7; color: white; border: none;cursor: pointer; padding: 2px 4px; text-decoration: none;display: inline-block;'>Copy Url</div>
						<script type='text/javascript'>
						//<![CDATA[
						require([
						    'jquery'
						], function ($) {
							'use strict';
						    $(function() {
						        $('.nimbbl-webhook-to-clipboard').click(function() { 
						            var temp = $('<input>');
									$('body').append(temp);
									temp.val($('.nimbbl-webhook-url').text()).select();
									document.execCommand('copy');
									temp.remove();
						            $('.nimbbl-webhook-to-clipboard').text('Copied to clipboard');
						        });
						    });
						});
						//]]>
						</script>
						";

        $element->setComment("*Please use below url for webhook* <span style='width:300px;font-weight: bold;' class='nimbbl-webhook-url' >" . $baseUrl . "nimbbl/payment/webhook</span>" . $copyButton );
                
        return $element->getElementHtml();

    }
}
