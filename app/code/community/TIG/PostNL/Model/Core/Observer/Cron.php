<?php
/**
 *                  ___________       __            __   
 *                  \__    ___/____ _/  |_ _____   |  |  
 *                    |    |  /  _ \\   __\\__  \  |  |
 *                    |    | |  |_| ||  |   / __ \_|  |__
 *                    |____|  \____/ |__|  (____  /|____/
 *                                              \/       
 *          ___          __                                   __   
 *         |   |  ____ _/  |_   ____ _______   ____    ____ _/  |_ 
 *         |   | /    \\   __\_/ __ \\_  __ \ /    \ _/ __ \\   __\
 *         |   ||   |  \|  |  \  ___/ |  | \/|   |  \\  ___/ |  |  
 *         |___||___|  /|__|   \_____>|__|   |___|  / \_____>|__|  
 *                  \/                           \/               
 *                  ________       
 *                 /  _____/_______   ____   __ __ ______  
 *                /   \  ___\_  __ \ /  _ \ |  |  \\____ \ 
 *                \    \_\  \|  | \/|  |_| ||  |  /|  |_| |
 *                 \______  /|__|    \____/ |____/ |   __/ 
 *                        \/                       |__|    
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Creative Commons License.
 * It is available through the world-wide-web at this URL: 
 * http://creativecommons.org/licenses/by-nc-nd/3.0/nl/deed.en_US
 * If you are unable to obtain it through the world-wide-web, please send an email
 * to servicedesk@totalinternetgroup.nl so we can send you a copy immediately.
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade this module to newer
 * versions in the future. If you wish to customize this module for your
 * needs please contact servicedesk@totalinternetgroup.nl for more information.
 *
 * @copyright   Copyright (c) 2013 Total Internet Group B.V. (http://www.totalinternetgroup.nl)
 * @license     http://creativecommons.org/licenses/by-nc-nd/3.0/nl/deed.en_US
 */
class TIG_PostNL_Model_Core_Observer_Cron
{
    /**
     * Xml path to maximum file storage setting in system/config
     */
    const XML_PATH_MAX_FILE_STORAGE  = 'postnl/advanced/max_temp_file_storage_time';
    
    /**
     * XML path to confirmation expire time setting
     */
    const XML_PATH_CONFIRM_EXPIRE_DAYS = 'postnl/advanced/confirm_expire_days';
    
    /**
     * Method to destroy temporary label files that have been stored for too long.
     * 
     * By default the PostNL module creates temporary label files in order to merge them into
     * a single shipping label. These files are then destroyed. However, sometimes these files
     * may survive the script if the script has encountered an error. This method will make
     * sure these files will not survive indefinitiely, which may lead to the file system
     * being overburdoned or the server running out of harddrive space.
     * 
     * @return TIG_PostNL_Model_Core_Observer_Cron
     * 
     * @throws TIG_PostNL_Exception
     */
    public function cleanTempLabels()
    {
        $helper = Mage::helper('postnl');
        
        /**
         * Check if the PostNL module is active
         */
        if (!$helper->isEnabled()) {
            return $this;
        }
        
        $helper->cronLog('CleanTempLabels cron starting...');
        
        /**
         * Directory where all temporary labels are stored. 
         * If this directory does not exist, end the script.
         */
        $tempLabelsDirectory = Mage::getConfig()->getVarDir('TIG' . DS . 'PostNL' . DS . 'temp_label');
        if (!is_dir($tempLabelsDirectory)) {
            $helper->cronLog('Temp labels directory not found. Exiting cron.');
            return $this;
        }
        
        /**
         * Check the maximum amount of time a temp file may be stored. By default this is 300s (5m).
         * If this settings is empty, end the script.
         */
        $maxFileStorageTime = (int) Mage::getStoreConfig(self::XML_PATH_MAX_FILE_STORAGE, Mage_Core_Model_App::ADMIN_STORE_ID);
        if (empty($maxFileStorageTime)) {
            $helper->cronLog('No max file storage time defined. Exiting cron.');
            return $this;
        }
        
        /**
         * Get the temporary label filename constant. This is used to construct the fgilename together with
         * an md5 hash of the content and a timestamp.
         */
        $labelModel = Mage::app()->getConfig()->getModelClassName('postnl_core/label');
        $tempLabelName = $labelModel::TEMP_LABEL_FILENAME;
        
        /**
         * Get all temporary label files in the directory
         */
        $files = glob($tempLabelsDirectory . DS . '*' . $tempLabelName);
        
        /**
         * If the directory cannot be read, throw an exception.
         */
        if ($files === false) {
            $helper->cronLog('Temporary label storage is unreadable. Exiting cron.');
            throw Mage::exception('TIG_PostNL', 'Unable to read directory: ' . $tempLabelsDirectory);
        }
        
        $fileCount = count($files);
        if ($fileCount < 1) {
            $helper->cronLog('No temporary labels found. Exiting cron.');
            return $this;
        }
        
        $helper->cronLog("{$fileCount} temporary labels found.");
        foreach ($files as $path) {
            /**
             * Get the name of the file. This should contain a timestamp after the first '-'
             */
            $filename = basename($path);
            $nameParts = explode('-', $filename);
            if (!isset($nameParts[1])) {
                $helper->cronLog("Invalid file found: {$filename}.");
                continue;
            }
            
            /**
             * Check if the timestamp is older than the maximum storage time
             */
            $time = $nameParts[1];
            if ((time() - $time) < $maxFileStorageTime) {
                continue;
            }
            
            /**
             * Delete the file
             */
            $helper->cronLog("Deleting file: {$filename}.");
            unlink($path);
        }
        
        $helper->cronLog('CleanTempLabels cron has finished.');
        return $this;
    }

    /**
     * Retrieve barcodes for postnl shipments that do not have one.
     * 
     * @return TIG_PostNL_Model_Core_Observer_Cron
     */
    public function getBarcodes()
    {
        $helper = Mage::helper('postnl');
        
        /**
         * Check if the PostNL module is active
         */
        if (!$helper->isEnabled()) {
            return $this;
        }
        
        $helper->cronLog('GetBarcodes cron starting...');
        
        /**
         * Get all postnl shipments without a barcode
         */
        $postnlShipmentCollection = Mage::getResourceModel('postnl_core/shipment_collection');
        $postnlShipmentCollection->addFieldToFilter('main_barcode', array('null' => true));
        
        if ($postnlShipmentCollection->getSize() < 1) {
            $helper->cronLog('No valid shipments found. Exiting cron.');
            return $this;
        }
        
        $helper->cronLog("Getting barcodes for {$postnlShipmentCollection->getSize()} shipments.");
        
        $n = 1000;
        foreach ($postnlShipmentCollection as $postnlShipment) {
            /**
             * Process a maximum of 1000 shipments (to prevent Cif from being overburdoned).
             * Only successfull requests count towards this number
             */
            if ($n < 1) {
                break;
            }
            
            /**
             * Attempt to generate a barcode. Continue with the next one if it fails.
             */
            try {
                $helper->cronLog("Getting barcodes for shipment #{$postnlShipment->getId()}.");
                $postnlShipment->generateBarcode()
                               ->addTrackingCodeToShipment()
                               ->save();
                
                $n--;
            } catch (Exception $e) {
                Mage::helper('postnl')->logException($e);
            }
        }
        
        $helper->cronLog('GetBarcodes cron has finished.');
        
        return $this;
    }
    
    /**
     * Update shipping status for all confirmed, but undelivered shipments.
     * 
     * @return TIG_PostNL_Model_Core_Observer_Cron
     */
    public function updateShippingStatus()
    {
        $helper = Mage::helper('postnl');
        
        /**
         * Check if the PostNL module is active
         */
        if (!$helper->isEnabled()) {
            return $this;
        }
        
        $helper->cronLog('UpdateShippingStatus cron starting...');
        
        $postnlShipmentModelClass = Mage::getConfig()->getModelClassName('postnl_core/shipment');
        $confirmedStatus = $postnlShipmentModelClass::CONFIRM_STATUS_CONFIRMED;
        $deliveredStatus = $postnlShipmentModelClass::SHIPPING_PHASE_DELIVERED;
        
        /**
         * Get all postnl shipments with a barcode, that are confirmed and are not yet delivered.
         */
        $postnlShipmentCollection = Mage::getResourceModel('postnl_core/shipment_collection');
        $postnlShipmentCollection->addFieldToFilter(
                                     'main_barcode', 
                                     array('notnull' => true)
                                 )
                                 ->addFieldToFilter(
                                     'confirm_status', 
                                     array('eq' => $confirmedStatus)
                                 )
                                 ->addFieldToFilter(
                                     'shipping_phase', 
                                     array(
                                         array('neq' => $deliveredStatus), 
                                         array('null' => true)
                                     )
                                 );
        
        if ($postnlShipmentCollection->getSize() < 1) {
            $helper->cronLog('No valid shipments found. Exiting cron.');
            return $this;
        }
        
        $helper->cronLog("Shipping status will be updated for {$postnlShipmentCollection->getSize()} shipments.");
        
        /**
         * Request a shipping status update
         */
        foreach ($postnlShipmentCollection as $postnlShipment) {
            /**
             * Attempt to update the shipping status. Continue with the next one if it fails.
             */
            try{
                $helper->cronLog("Updating shipping status for shipment #{$postnlShipment->getId()}");
                $postnlShipment->updateShippingStatus()
                               ->save();
            } catch (Exception $e) {
                Mage::helper('postnl')->logException($e);
            }
        }
        
        $helper->cronLog('UpdateShippingStatus cron has finished.');
            
        return $this;
    }
    
    /**
     * Removes expired confirmations by resetting the postnl shipment to a pre-confirm state
     * 
     * @return TIG_PostNL_Model_Core_Observer_Cron
     * 
     * @todo Check if shipments need a new barcode before they can be re-confirmed
     */
    public function expireConfirmation()
    {
        $helper = Mage::helper('postnl');
        
        /**
         * Check if the PostNL module is active
         */
        if (!$helper->isEnabled()) {
            return $this;
        }
        
        $helper->cronLog('ExpireConfirmation cron starting...');
        
        $postnlShipmentModelClass = Mage::getConfig()->getModelClassName('postnl_core/shipment');
        $confirmedStatus = $postnlShipmentModelClass::CONFIRM_STATUS_CONFIRMED;
        $collectionPhase = $postnlShipmentModelClass::SHIPPING_PHASE_COLLECTION;
        
        $confirmationExpireDays = Mage::getStoreConfig(self::XML_PATH_CONFIRM_EXPIRE_DAYS, Mage_Core_Model_App::ADMIN_STORE_ID);
        $expireTimestamp = strtotime("-{$confirmationExpireDays} days", Mage::getModel('core/date')->timestamp());
        $expireDate = date('Y-m-d H:i:s', $expireTimestamp);
        
        $helper->cronLog("All confirmation placed before {$expireDate} will be expired.");
        
        /**
         * Get all postnl shipments that have been confirmed over X days ago and who have not yet been shipped (shipping_phase
         * other than 'collection')
         */
        $postnlShipmentCollection = Mage::getResourceModel('postnl_core/shipment_collection');
        $postnlShipmentCollection->addFieldToFilter(
                                     'confirm_status', 
                                     array('eq' => $confirmedStatus)
                                 )
                                 ->addFieldToFilter(
                                     'shipping_phase', 
                                     array(
                                         array('eq' => $collectionPhase), 
                                         array('null' => true)
                                     )
                                 )
                                 ->addFieldToFilter(
                                     'confirmed_at', 
                                     array(
                                         array('lt' => $expireDate), 
                                         array('null' => true)
                                     )
                                 );
        
        /**
         * Check to see if there are any results
         */
        if (!$postnlShipmentCollection->getSize()) {
            $helper->cronLog('No expired confirmations found. Exiting cron.');
            return $this;
        }
        
        $helper->cronLog("Number of expired confirmations found: {$postnlShipmentCollection->getSize()}");
        
        /**
         * Reset the shipments to 'unconfirmed' status
         */
        foreach ($postnlShipmentCollection as $postnlShipment) {
            /**
             * Attempt to reset the shipment to a pre-confirmed status
             */
            try{
                $helper->cronLog("Expiring confirmation of shipment #{$postnlShipment->getId()}");
                $postnlShipment->resetConfirmation()
                               ->setConfirmStatus($postnlShipment::CONFIRM_STATUS_CONFIRM_EXPIRED)
                               ->generateBarcodes() //generate new barcodes as the current ones have expired
                               ->save();
            } catch (Exception $e) {
                $helper->logException($e);
            }
        }
        $helper->cronLog('ExpireConfirmation cron has finished.');
        
        return $this;
    }
}