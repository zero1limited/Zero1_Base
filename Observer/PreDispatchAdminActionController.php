<?php
namespace Zero1\Base\Observer;

use Magento\Framework\Event\ObserverInterface;
use \Psr\Log\LoggerInterface;

class PreDispatchAdminActionController implements ObserverInterface
{
    /**
     * @var \Zero1\Base\Model\FeedFactory
     */
    private $feedFactory;

    /**
     * @var \Magento\Backend\Model\Auth\Session
     */
    private $backendSession;

    /**
     * @var \Psr\Log\LoggerInterface
     */
    private $logger;

    public function __construct(
        \Zero1\Base\Model\FeedFactory $feedFactory,
        \Magento\Backend\Model\Auth\Session $backendAuthSession,
        \Psr\Log\LoggerInterface $logger
    ) {
        $this->feedFactory = $feedFactory;
        $this->backendSession = $backendAuthSession;
        $this->logger = $logger;
    }

    /**
     * @param \Magento\Framework\Event\Observer $observer
     */
    public function execute(\Magento\Framework\Event\Observer $observer)
    {
        if ($this->backendSession->isLoggedIn()) {
            try {
                /** @var \Zero1\Base\Model\Feed $feedModel */
                $feedModel = $this->feedFactory->create();
		        $feedModel->checkUpdate();
                $feedModel->removeExpiredItems();
            } catch (\Exception $e) {
                die($e->getMessage());
                $this->logger->critical($e);
            }
        }
    }
}
