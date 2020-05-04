<?php
namespace Zero1\Base\Block\Adminhtml;

class Messages extends \Magento\Backend\Block\Template
{
    const ZERO1_BASE_SECTION_NAME = 'zero1_base';
    /**
     * @var \Zero1\Base\Model\AdminNotification\Messages
     */
    private $messageManager;

    /**
     * @var \Magento\Framework\App\Request\Http
     */
    private $request;

    public function __construct(
        \Magento\Backend\Block\Template\Context $context,
        \Zero1\Base\Model\AdminNotification\Messages $messageManager,
        \Magento\Framework\App\Request\Http $request,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->messageManager = $messageManager;
        $this->request = $request;
    }

    /**
     * @return array
     */
    public function getMessages()
    {
        die(__METHOD__);
        foreach($this->messageManager->getMessages() as $message) {
            die('dddd');
        }
        return $this->messageManager->getMessages();
    }
}
