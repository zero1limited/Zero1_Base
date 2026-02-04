<?php
namespace Zero1\Base\Model;

use Zero1\Base\Model\AdminNotification\Model\ResourceModel\Inbox\Collection\ExistsFactory;
use Zero1\Base\Model\Source\NotificationType;
use Magento\Framework\HTTP\Adapter\Curl;
use Magento\Framework\Notification\MessageInterface;
use Magento\Store\Model\ScopeInterface;
use \Psr\Log\LoggerInterface;

class Feed
{
    const HOUR_MIN_SEC_VALUE = 60 * 60 * 24;

    const REMOVE_EXPIRED_FREQUENCY = 60 * 60 * 6;

    const XML_LAST_UPDATE = 'zero1_base/system_value/last_update';

    const XML_FREQUENCY_PATH = 'zero1_base/notifications/frequency';

    const XML_FIRST_MODULE_RUN = 'zero1_base/system_value/first_module_run';

    const XML_LAST_REMOVMENT = 'zero1_base/system_value/remove_date';

    // M1 URL 'https://www.zero1.co.uk/tag/notices/feed/';
    const FEED_URL = 'https://www.zero1.co.uk/tag/m2_admin_notices/feed/';

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

    /** @var LoggerInterface */
    private $logger;


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
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        \Psr\Log\LoggerInterface $logger
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
        $this->logger = $logger;
    }

    /**
     * @return $this
     */
    public function checkUpdate()
    {
        //$this->logger->notice($this->getLastUpdate());
        if ($this->getFrequency() + $this->getLastUpdate() > time()) {
            return $this;
        }

        $feedData = null;
        $feedXml = $this->getFeedData();

        if ($feedXml && $feedXml->channel && $feedXml->channel->item) {


            foreach ($feedXml->channel->item as $item) {
                if ($this->isItemExists($item)) {
                    continue;
                }

                $date = strtotime((string)$item->pubDate);
                $feedData = [
                    'severity' => MessageInterface::SEVERITY_CRITICAL,
                    'date_added' => date('Y-m-d H:i:s'),
                    'title' => html_entity_decode($this->convertString($item->title)),
                    'description' => html_entity_decode($this->convertString($item->description)),
                    'url' => $this->convertString($item->link).'?utm_source=map&utm_medium=organic&utm_campaign=zero1-base&utm_content='.$item->title
                ];
            }

            if ($feedData) {
                /** @var \Magento\AdminNotification\Model\Inbox $inbox */
                $inbox = $this->inboxFactory->create();
                $inbox->parse([$feedData]);
            }
        }
        $this->setLastUpdate();
        return $this;
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

        $curlObject->write(\Laminas\Http\Request::METHOD_GET, self::FEED_URL, '1.0');
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

    private function getModuleConfig($field)
    {
        return $this->scopeConfig->getValue(
            'zero1_base/' . $field,
            ScopeInterface::SCOPE_STORE,
            $this->storeManager->getStore()->getId()
        );
    }


}
