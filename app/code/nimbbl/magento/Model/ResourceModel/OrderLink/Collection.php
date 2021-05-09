<?php
namespace Nimbbl\Magento\Model\ResourceModel\OrderLink;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;


class Collection extends AbstractCollection
{
    /**
     * Initialize resource collection
     *
     * @return void
     */
    public function _construct()
    {
        $this->_init('Nimbbl\Magento\Model\OrderLink', 'Nimbbl\Magento\Model\ResourceModel\OrderLink');
    }
}