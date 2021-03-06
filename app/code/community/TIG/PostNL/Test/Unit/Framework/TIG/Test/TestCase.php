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
 * to servicedesk@tig.nl so we can send you a copy immediately.
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade this module to newer
 * versions in the future. If you wish to customize this module for your
 * needs please contact servicedesk@tig.nl for more information.
 *
 * @copyright   Copyright (c) 2017 Total Internet Group B.V. (http://www.tig.nl)
 * @license     http://creativecommons.org/licenses/by-nc-nd/3.0/nl/deed.en_US
 */
class TIG_PostNL_Test_Unit_Framework_TIG_Test_TestCase extends PHPUnit_Framework_TestCase
{
    /**
     * Resets and restarts Magento.
     */
    public static function resetMagento()
    {
        Mage::reset();

        Mage::setIsDeveloperMode(false);
        Mage::app(
            'admin',
                'store',
                array(
                    'config_model' => 'TIG_PostNL_Test_Unit_Framework_TIG_Test_Config'
                )
        )->setResponse(new TIG_PostNL_Test_Unit_Framework_TIG_Test_Http_Response());

        $handler = set_error_handler(function() {});

        set_error_handler(function($errno, $errstr, $errfile, $errline) use ($handler) {
            if (E_WARNING === $errno
                && 0 === strpos($errstr, 'include(')
                && substr($errfile, -19) == 'Varien/Autoload.php'
            ) {
                return null;
            }
            return call_user_func(
                $handler, $errno, $errstr, $errfile, $errline
            );
        });
    }

    public function prepareFrontendDispatch()
    {
        $store = Mage::app()->getDefaultStoreView();
        $store->setConfig('web/url/redirect_to_base', false);
        $store->setConfig('web/url/use_store', false);
        $store->setConfig('advanced/modules_disable_output/Enterprise_Banner', true);

        Mage::app()->setCurrentStore($store->getCode());

        $this->registerMockSessions();
    }

    public function registerMockSessions($modules = null)
    {
        if (!is_array($modules)) {
            $modules = array('core', 'customer', 'checkout', 'catalog', 'reports');
        }

        foreach ($modules as $module) {
            $class = "$module/session";
            $sessionMock = $this->getMockBuilder(
                               Mage::getConfig()->getModelClassName($class)
                           )->disableOriginalConstructor()
                            ->getMock();
            $sessionMock->expects($this->any())
                        ->method('start')
                        ->will($this->returnSelf());
            $sessionMock->expects($this->any())
                        ->method('init')
                        ->will($this->returnSelf());
            $sessionMock->expects($this->any())
                        ->method('getMessages')
                        ->will($this->returnValue(
                            Mage::getModel('core/message_collection')
                        ));
            $sessionMock->expects($this->any())
                        ->method('getSessionIdQueryParam')
                        ->will($this->returnValue(
                            Mage_Core_Model_Session_Abstract::SESSION_ID_QUERY_PARAM
                        ));
            $sessionMock->expects($this->any())
                        ->method('getCookieShouldBeReceived')
                        ->will($this->returnValue(false));
            $this->setSingletonMock($class, $sessionMock);
            $this->setModelMock($class, $sessionMock);
        }

        $cookieMock = $this->getMock('Mage_Core_Model_Cookie');
        $cookieMock->expects($this->any())
                   ->method('get')
                   ->will($this->returnValue(serialize('dummy')));
        Mage::unregister('_singleton/core/cookie');
        Mage::register('_singleton/core/cookie', $cookieMock);

        // mock visitor log observer
        $logVisitorMock = $this->getMock('Mage_Log_Model_Visitor');
        $this->setModelMock('log/visitor', $logVisitorMock);

        /**
         * Fix enterprise catalog permissions issue
         */
        $factoryName = 'enterprise_catalogpermissions/permission_index';
        $className = Mage::getConfig()->getModelClassName($factoryName);
        if (class_exists($className)) {
            $mockPermissions = $this->getMock($className);
            $mockPermissions->expects($this->any())
                            ->method('getIndexForCategory')
                            ->withAnyParameters()
                            ->will($this->returnValue(array()));

            $this->setSingletonMock($factoryName, $mockPermissions);
        }
    }

    /**
     * @param string $modelClass
     * @param object $mock
     *
     * @return TIG_Test_TestCase
     */
    public function setModelMock($modelClass, $mock)
    {
        $this->getConfig()->setModelMock($modelClass, $mock);

        return $this;
    }
    /**
     * @param string $modelClass
     * @param object $mock
     *
     * @return TIG_Test_TestCase
     */
    public function setResourceModelMock($modelClass, $mock)
    {
        $this->getConfig()->setResourceModelMock($modelClass, $mock);

        return $this;
    }

    /**
     * @param string $modelClass
     * @param object $mock
     *
     * @return TIG_Test_TestCase
     */
    public function setSingletonMock($modelClass, $mock)
    {
        $registryKey = '_singleton/' . $modelClass;

        Mage::unregister($registryKey);
        Mage::register($registryKey, $mock);

        return $this;
    }

    /**
     * @param $modelClass
     *
     * @return mixed
     */
    public function getSingletonMock($modelClass)
    {
        $registryKey = '_singleton/' . $modelClass;

        return Mage::registry($registryKey);
    }

    /**
     * @param string $resourceModelClass
     * @param object $mock
     *
     * @return TIG_Test_TestCase
     */
    public function setResourceSingletonMock($resourceModelClass, $mock)
    {
        $registryKey = '_resource_singleton/' . $resourceModelClass;

        Mage::unregister($registryKey);
        Mage::register($registryKey, $mock);

        return $this;
    }

    /**
     * @param string $helperClass
     * @param object $mock
     *
     * @return $this
     */
    public function setHelperMock($helperClass, $mock)
    {
        $registryKey = '_helper/' . $helperClass;

        Mage::unregister($registryKey);
        Mage::register($registryKey, $mock);

        return $this;
    }

    /**
     * @return TIG_Test_Config
     */
    public function getConfig()
    {
        return Mage::getConfig();
    }

    /**
     * Returns the instance. Should be overridden.
     *
     * @return null
     */
    protected function _getInstance()
    {
        return null;
    }

    /**
     * Sets a protected property to the provided value.
     *
     * @param      $property
     * @param      $value
     * @param null $instance
     *
     * @return $this
     */
    public function setProperty($property, $value, $instance = null)
    {
        if ($instance === null) {
            $instance = $this->_getInstance();
        }

        $property = $this->_getProperty($property, $instance);
        $property->setValue($instance, $value);

        return $this;
    }

    /**
     * Get the value of a protected property using reflection.
     *
     * @param $property
     *
     * @param $instance
     *
     * @return mixed
     */
    public function getProtectedPropertyValue($property, $instance = null)
    {
        if (is_null($instance)) {
            $instance = $this->_getInstance();
        }

        $property = $this->_getProperty($property, $instance);

        return $property->getValue($instance);
    }

    /**
     * Retrieve the ReflectionProperty object, and set the visibility to public.
     *
     * @param      $property
     * @param null $instance
     *
     * @return ReflectionProperty
     */
    protected function _getProperty($property, $instance = null)
    {
        if ($instance === null) {
            $instance = $this->_getInstance();
        }

        $reflection = new ReflectionObject($instance);
        $property = $reflection->getProperty($property);
        $property->setAccessible(true);

        return $property;
    }

    /**
     * Updates a specific key.
     *
     * @param $key
     * @param $value
     *
     * @return $this
     */
    public function setRegistryKey($key, $value = null)
    {
        Mage::unregister($key);

        if ($value !== null) {
            Mage::register($key, $value);
        }

        return $this;
    }

    /**
     * @param $filename
     *
     * @return string
     * @throws Exception
     */
    public function getLabel($filename)
    {
        $path = Mage::getModuleDir('', 'TIG_PostNL') . '/Test/Fixtures/Labels/';
        $path = realpath($path . $filename);

        if ($path === false) {
            throw new Exception('The file ' . $filename . ' cannot be found in '. $path);
        }

        $contents = file_get_contents($path);

        return base64_encode($contents);
    }

    /**
     * Make sure that the Imagick extension is loaded. If not, skip this test. It is not required for the extension
     * to do it's work. It is only required for testing purposes.
     */
    public function requireImagick()
    {
        if (!extension_loaded('imagick')) {
            $this->markTestSkipped('Extension imagick not loaded');
        }
    }

    /**
     * @param       $source1
     * @param       $source2
     * @param float $margin
     */
    public function compareImageOrPdf($source1, $source2, $margin = 0.000001)
    {
        $image1 = new \Imagick;
        $image1->readImageBlob($source1);
        $height = $image1->getImageLength();
        $width = $image1->getImageWidth();
        $image2 = new \Imagick;
        $image2->readImageBlob($source2);

        /**
         * Force the same dimensions, as Imagick sometimes fails with an cryptic error.
         */
        $image1->resizeImage($width, $height,Imagick::FILTER_LANCZOS, 1);
        $image2->resizeImage($width, $height,Imagick::FILTER_LANCZOS, 1);

        $result = $image1->compareImages($image2, \Imagick::METRIC_MEANSQUAREERROR);
        $this->assertLessThanOrEqual($margin, $result[1]);
    }
}
