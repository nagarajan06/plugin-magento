<?php
namespace ValorPay\CardPay\Controller\Adminhtml\SendOtp;

use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Request\InvalidRequestException;

class Index extends \Magento\Framework\App\Action\Action implements CsrfAwareActionInterface
{
    /**
     * @var \Magento\Framework\Controller\Result\JsonFactory
     */
    protected $resultJsonFactory;
    protected $creditmemoLoader;
    
    protected $_curl;
    
    protected $_scopeConfig;
    
    protected $_valor_api_url = 'http://localhost:7000/v1/sendotp';
    
    public function __construct(
    	\Magento\Backend\App\Action\Context $context,
        \Magento\Framework\Controller\Result\JsonFactory $resultJsonFactory,
	\Magento\Sales\Controller\Adminhtml\Order\CreditmemoLoader $creditmemoLoader,
	\Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
	\Magento\Framework\HTTP\Client\Curl $curl
    )
    {
        parent::__construct($context);
        $this->_curl = $curl;
        $this->resultJsonFactory = $resultJsonFactory;
        $this->creditmemoLoader = $creditmemoLoader;
        $this->_scopeConfig = $scopeConfig;
    }
    
    public function createCsrfValidationException(RequestInterface $request): ? InvalidRequestException
    {
	return null;
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
	return true;
    }
    
    private function getConfigData2($param) {
    	return $this->_scopeConfig->getValue('payment/valorpay_gateway/'.$param, \Magento\Store\Model\ScopeInterface::SCOPE_WEBSITE);
    }

    public function execute()
    { 
	
	$creditmemo_array = $this->getRequest()->getParam('creditmemo');
	
	$this->creditmemoLoader->setOrderId($this->getRequest()->getParam('order_id'));
	$this->creditmemoLoader->setCreditmemoId($this->getRequest()->getParam('creditmemo_id'));
	$this->creditmemoLoader->setCreditmemo($creditmemo_array);
	$this->creditmemoLoader->setInvoiceId($this->getRequest()->getParam('invoice_id'));
	
	$creditmemo = $this->creditmemoLoader->load();
	$shipping_amount = $creditmemo->getShippingAmount();
        $amount  = $creditmemo->getBaseGrandTotal();
        $amount -= $shipping_amount;
        $amount += $creditmemo_array["shipping_amount"];
        $amount += $creditmemo_array["adjustment_positive"];
        $amount -= $creditmemo_array["adjustment_negative"];
        
        $requestData = array(
	   'appid' => $this->getConfigData2('appid'),
	   'appkey' => $this->getConfigData2('appkey'),
	   'epi' => $this->getConfigData2('epi'),
	   'amount' => $amount,
	   'sandbox' => $this->getConfigData2('sandbox')
	);
	
	$this->_curl->setOption(CURLOPT_RETURNTRANSFER, true);
	$this->_curl->post($this->_valor_api_url, $requestData);

	//response will contain the output of curl request
	$response = $this->_curl->getBody();
	
	$response = json_decode($response);
	
	/*$objectManager = \Magento\Framework\App\ObjectManager::getInstance();
	$directory     = $objectManager->get('\Magento\Framework\Filesystem\DirectoryList');
	$rootPath      = $directory->getRoot();
	$file          = fopen($rootPath."/capture54.txt","w");
	fwrite($file,$response->response->emailId);
	fclose($file);*/
	
	$resultJson = $this->resultJsonFactory->create();
	
	if( $response->status == false ) {
		
		return $resultJson->setData([
			'message' => __($response->message),
			'error'   => true
		]);

	}
	elseif( $response->status == "error" ) {
		
		return $resultJson->setData([
			'message' => __($response->mesg),
			'error'   => true
		]);
	}
	else {
	
		$masked_is_enable_2fa = $response->response->is_enable_2fa;

		if( $masked_is_enable_2fa == 1 ) {

			$masked_email = $response->response->emailId;
			$masked_phone = $response->response->phoneNumber;
			$masked_uuid  = $response->response->uuid;

			return $resultJson->setData([
			    'message' => '<span>'. sprintf(__('OTP sent to your registered Email Address %1$s and Mobile Number %2$s'), '<b>'.$masked_email.'</b>', '<b>'.$masked_phone.'</b>') .' </span>',
			    'error'   => false,
			    'is_enable_2fa' => true,
			    'uuid'    => $masked_uuid
			]);

		}
		else {

			$masked_uuid  = $response->response->uuid;

			$resultJson = $this->resultJsonFactory->create();
			return $resultJson->setData([
			    'error'   => false,
			    'is_enable_2fa' => false,
			    'uuid'    => $masked_uuid
			]);

		}
        
        }
        
    }
}