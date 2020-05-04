<?php
namespace Zero1\Base\Model;

use Zero1\Base\Model\AdminNotification\Model\ResourceModel\Inbox\Collection\ExistsFactory;
use Zero1\Base\Model\Source\NotificationType;
use Magento\Framework\HTTP\Adapter\Curl;
use Magento\Framework\Notification\MessageInterface;
use Magento\Store\Model\ScopeInterface;

class Feed
{
    const HOUR_MIN_SEC_VALUE = 60 * 60 * 24;

    const REMOVE_EXPIRED_FREQUENCY = 60 * 60 * 6;

    const XML_LAST_UPDATE = 'zero1_base/system_value/last_update';

    const XML_FREQUENCY_PATH = 'zero1_base/notifications/frequency';

    const XML_FIRST_MODULE_RUN = 'zero1_base/system_value/first_module_run';

    const XML_LAST_REMOVMENT = 'zero1_base/system_value/remove_date';

    const FEED_URL = 'https://www.zero1.co.uk/tag/notices/feed/';

    /**
     * @var array
     */
    private $zero1Modules = [];

    /**
     * @var \Magento\Backend\App\ConfigInterface
     */
    private $config;

    /**
     * @var \Magento\Framework\App\Config\ReinitableConfigInterface
     */
    private $reinitableConfig;

    /**
     * @var \Magento\Framework\App\Config\Storage\WriterInterface
     */
    private $configWriter;

    /**
     * @var \Magento\Framework\HTTP\Adapter\CurlFactory
     */
    private $curlFactory;

    /**
     * @var \Magento\Framework\App\ProductMetadataInterface
     */
    private $productMetadata;

    /**
     * @var \Magento\AdminNotification\Model\InboxFactory
     */
    private $inboxFactory;

    /**
     * @var \Magento\Framework\App\Config\ScopeConfigInterface
     */
    private $scopeConfig;

    /**
     * @var ExpiredFactory
     */
    private $expiredFactory;

    /**
     * @var \Magento\Framework\Module\ModuleListInterface
     */
    private $moduleList;

    /**
     * @var AdminNotification\Model\ResourceModel\Inbox\Collection\ExistsFactory
     */
    private $inboxExistsFactory;

    /**
     * @var \Magento\Store\Model\StoreManagerInterface
     */
    private $storeManager;

    public function __construct(
        \Magento\Backend\App\ConfigInterface $config,
        \Magento\Framework\App\Config\ReinitableConfigInterface $reinitableConfig,
        \Magento\Framework\App\Config\Storage\WriterInterface $configWriter,
        \Magento\Framework\HTTP\Adapter\CurlFactory $curlFactory,
        \Magento\AdminNotification\Model\InboxFactory $inboxFactory,
        \Magento\Framework\App\ProductMetadataInterface $productMetadata,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Magento\Framework\Module\ModuleListInterface $moduleList,
        ExistsFactory $inboxExistsFactory,
        \Magento\Store\Model\StoreManagerInterface $storeManager
    ) {
        $this->config = $config;
        $this->reinitableConfig = $reinitableConfig;
        $this->configWriter = $configWriter;
        $this->curlFactory = $curlFactory;
        $this->productMetadata = $productMetadata;
        $this->inboxFactory = $inboxFactory;
        $this->scopeConfig = $scopeConfig;
        $this->moduleList = $moduleList;
        $this->inboxExistsFactory = $inboxExistsFactory;
        $this->storeManager = $storeManager;
    }

    /**
     * @return $this
     */
    public function checkUpdate()
    {
    	return;
//        if ($this->getFrequency() + $this->getLastUpdate() > time()) {
//            return $this;
//        }

        $feedData = null;
        $feedXml = $this->getFeedData();

        if ($feedXml && $feedXml->channel && $feedXml->channel->item) {
            foreach ($feedXml->channel->item as $item) {
                if ($this->isItemExists($item)) {
//                    continue;
                }

                $date = strtotime((string)$item->pubDate);
                $feedData = [
                    'severity' => MessageInterface::SEVERITY_CRITICAL,
//                    'date_added' => date('Y-m-d H:i:s', $date),
                    'date_added' => date('Y-m-d H:i:s'),
                    'title' => $this->convertString($item->title).'-'.date('c'),
                    'description' => $this->convertString($item->description).' - '.date('c'),
                    'url' => 'https://zero1.co.uk/'.time()
//                    'url' => $this->convertString($item->link)
                ];
            }


//            die(print_r($feedData, true));
            if ($feedData) {
                /** @var \Magento\AdminNotification\Model\Inbox $inbox */
                $inbox = $this->inboxFactory->create();
                $inbox->parse([$feedData]);

//                die('parsed');
            }
        }
        $this->setLastUpdate();
        return $this;
    }

    /**
     * @param $value
     *
     * @return array
     */
    private function convertToArray($value)
    {
        return explode(',', (string)$value);
    }

    /**
     * @param \SimpleXMLElement $item
     * @return bool
     */
    private function isItemExists(\SimpleXMLElement $item)
    {
        return $this->inboxExistsFactory->create()->execute($item);
    }

    /**
     * @return string
     */
    protected function getCurrentEdition()
    {
        return $this->productMetadata->getEdition() == 'Community' ? 'ce' : 'ee';
    }

    /**
     * @return $this
     */
    public function removeExpiredItems()
    {
        if ($this->getLastRemovement() + self::REMOVE_EXPIRED_FREQUENCY > time()) {
            return $this;
        }

        /** @var Expired $collection */
        /*
        $collection = $this->expiredFactory->create();
        foreach ($collection as $model) {
            $model->setIsRemove(1)->save();
        }
*/
        $this->setLastRemovement();

        return $this;
    }

    /**
     * @return \SimpleXMLElement|false
     */
    public function getFeedData()
    {
        $curlObject = $this->curlFactory->create();
        $curlObject->setConfig(['timeout' => 2,]);

        $curlObject->write(\Zend_Http_Client::GET, self::FEED_URL, '1.0');
        $result = $curlObject->read();

        if ($result === false || $result === '') {
            return false;
        }

        $result = preg_split('/^\r?$/m', $result, 2);
        $result = trim($result[1]);

        $curlObject->close();

        try {
            $xml = new \SimpleXMLElement($result);
        } catch (\Exception $e) {
            return false;
        }

        return $xml;
    }

    /**
     * @return array
     */
    private function getAllowedTypes()
    {
        $allowedNotifications = $this->getModuleConfig('notifications/type');
        $allowedNotifications = explode(',', $allowedNotifications);

        return $allowedNotifications;
    }

    /**
     * @param \SimpleXMLElement $data
     * @return string
     */
    private function convertString(\SimpleXMLElement $data)
    {
        $data = htmlspecialchars((string)$data);
        return $data;
    }

    /**
     * @return int
     */
    private function getFrequency()
    {
        return $this->config->getValue(self::XML_FREQUENCY_PATH) * self::HOUR_MIN_SEC_VALUE;
    }

    /**
     * @return string
     */
    private function getFeedUrl()
    {
        $scheme = 'https://';
        return $scheme . self::URL_NEWS;
    }

    /**
     * @return int
     */
    private function getLastUpdate()
    {
        return $this->config->getValue(self::XML_LAST_UPDATE);
    }

    /**
     * @return $this
     */
    private function setLastUpdate()
    {
        $this->configWriter->save(self::XML_LAST_UPDATE, time());
        $this->reinitableConfig->reinit();

        return $this;
    }

    /**
     * @return int|mixed
     */
    private function getFirstModuleRun()
    {
        $result = $this->config->getValue(self::XML_FIRST_MODULE_RUN);
        if (!$result) {
            $result = time();
            $this->configWriter->save(self::XML_FIRST_MODULE_RUN, $result);
            $this->reinitableConfig->reinit();
        }

        return $result;
    }

    /**
     * @param $path
     * @param int $storeId
     * @return mixed
     */
    private function getModuleConfig($path, $storeId = null)
    {
        return $this->scopeConfig->getValue(
            'zero1_base/' . $path,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * @return int
     */
    private function getLastRemovement()
    {
        return $this->config->getValue(self::XML_LAST_REMOVMENT);
    }

    /**
     * @return $this
     */
    private function setLastRemovement()
    {
        $this->configWriter->save(self::XML_LAST_REMOVMENT, time());
        $this->reinitableConfig->reinit();

        return $this;
    }

    /**
     * @return array|string[]
     */
    private function getInstalledZero1Extensions()
    {
        if (!$this->zero1Modules) {
            $modules = $this->moduleList->getNames();

            $dispatchResult = new \Magento\Framework\DataObject($modules);
            $modules = $dispatchResult->toArray();

            $modules = array_filter(
                $modules,
                function ($item) {
                    return strpos($item, 'Zero1_') !== false;
                }
            );
            $this->zero1Modules = $modules;
        }

        return $this->zero1Modules;
    }

    /**
     * @return array|string[]
     */
    private function getAllExtensions()
    {
        $modules = $this->moduleList->getNames();

        $dispatchResult = new \Magento\Framework\DataObject($modules);
        $modules = $dispatchResult->toArray();

        return $modules;
    }

    /**
     * @param string $extensions
     * @return bool
     */
    private function validateByExtension($extensions, $allModules = false)
    {
        if ($extensions) {
            $result = false;
            $extensions = $this->validateExtensionValue($extensions);

            if ($extensions) {
                $installedModules = $allModules ? $this->getAllExtensions() : $this->getInstalledZero1Extensions();
                $intersect = array_intersect($extensions, $installedModules);
                if ($intersect) {
                    $result = true;
                }
            }
        } else {
            $result = true;
        }

        return $result;
    }

    /**
     * @param string $extensions
     * @return bool
     */
    private function validateByNotInstalled($extensions)
    {
        if ($extensions) {
            $result = false;
            $extensions = $this->validateExtensionValue($extensions);

            if ($extensions) {
                $installedModules = $this->getInstalledZero1Extensions();
                $diff = array_diff($extensions, $installedModules);
                if ($diff) {
                    $result = true;
                }
            }
        } else {
            $result = true;
        }

        return $result;
    }

    /**
     * @param string $extensions
     *
     * @return array
     */
    private function validateExtensionValue($extensions)
    {
        $extensions = explode(',', $extensions);
        $extensions = array_filter($extensions, function ($item) {
            return strpos($item, '_1') === false;
        });

        $extensions = array_map(function ($item) {
            return str_replace('_2', '', $item);
        }, $extensions);

        return $extensions;
    }

    /**
     * @param $counts
     * @return bool
     */
    private function validateByZero1Count($counts)
    {
        $result = true;

        $countString = (string)$counts;
        if ($countString) {
            $moreThan = null;
            $result = false;

            $position = strpos($countString, '>');
            if ($position !== false) {
                $moreThan = substr($countString, $position + 1);
                $moreThan = explode(',', $moreThan);
                $moreThan = array_shift($moreThan);
            }

            $counts = $this->convertToArray($counts);
            $zero1Modules = $this->getInstalledZero1Extensions();
            $dependModules = $this->getDependModules($zero1Modules);
            $zero1Modules = array_diff($zero1Modules, $dependModules);

            $zero1Count = count($zero1Modules);

            if ($zero1Count
                && (in_array($zero1Count, $counts)
                    || ($moreThan && $zero1Count >= $moreThan)
                )
            ) {
                $result = true;
            }
        }

        return $result;
    }

    /**
     * @param $zones
     *
     * @return bool
     */
    private function validateByDomainZone($zones)
    {
        $result = true;
        if ($zones) {
            $zones = $this->convertToArray($zones);
            $currentZone = $this->getDomainZone();

            if (!in_array($currentZone, $zones)) {
                $result = false;
            }
        }

        return $result;
    }

    /**
     * @return string
     */
    private function getDomainZone()
    {
        $domain = '';
        $url = $this->storeManager->getStore()->getBaseUrl();
        $components = parse_url($url);
        if (isset($components['host'])) {
            $host = explode('.', $components['host']);
            $domain = end($host);
        }

        return $domain;
    }

    /**
     * @return string
     */
    private function getCurrentScheme()
    {
        $scheme = '';
        $url = $this->storeManager->getStore()->getBaseUrl();
        $components = parse_url($url);
        if (isset($components['scheme'])) {
            $scheme = $components['scheme'] . '://';
        }

        return $scheme;
    }
}