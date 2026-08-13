<?php

namespace Nimbbl\Magento\Model;

use Magento\Framework\Filesystem\DriverInterface;
use Monolog\Logger;

/**
 * Custom Monolog handler for the Nimbbl payment module.
 *
 * Writes all log levels (DEBUG and above) to a daily-rotated file:
 *   var/log/Nimbbl/nimbbl_YYYY_MM_DD.log
 *
 * The minimum level is set to DEBUG so that debug messages flow through when
 * Config::isDebugEnabled() is true. Application-level suppression (the
 * debugLog() helper in Order/PaymentMethod) prevents debug noise in production
 * — the handler itself should never be the gate.
 */
class LogHandler extends \Magento\Framework\Logger\Handler\Base
{
    /**
     * Minimum Monolog level this handler will accept.
     * DEBUG (100) = accept everything; suppression happens at call-site.
     *
     * @var int
     */
    protected $loggerType = Logger::DEBUG;

    /**
     * File name
     * @var string
     */
    public $fileName = '';
    /**
     * File name
     * @var string
     */
    public $cutomfileName = 'NO_PATH';
    /**
     * @var TimezoneInterface
     */
    protected $_localeDate;

    public function __construct(
        DriverInterface $filesystem,
        \Magento\Framework\Filesystem $corefilesystem,
        \Magento\Framework\Stdlib\DateTime\TimezoneInterface $localeDate,
        $filePath = null
    ) {
        $this->_localeDate = $localeDate;
        $corefilesystem= $corefilesystem->getDirectoryWrite(\Magento\Framework\App\Filesystem\DirectoryList::VAR_DIR); 
        $logpath = $corefilesystem->getAbsolutePath('log/Nimbbl/');


        // Custom log file name for each day because log will be full for optimization 
        $filename = 'nimbbl_'.Date('Y_m_d').'.log';

        $filepath = $logpath . $filename;
        
        $this->cutomfileName = $filepath;

        parent::__construct(
            $filesystem,
            $filepath
        );

    }
}
