<?php

namespace Soisy\PaymentMethod\Observer;

use Magento\Framework\Event\ObserverInterface;

class GetTokenForOrder implements ObserverInterface
{
    protected $_request;

    protected $settings;

    protected $logger;

    /**
     * GetTokenForOrders constructor.
     * @param \Magento\Framework\App\Request\Http $request
     */
    public function __construct(
        \Magento\Framework\App\Request\Http $request,
        \Soisy\PaymentMethod\Helper\Settings $settings,
        \Soisy\PaymentMethod\Log\Logger $logger
    ) {
        $this->_request = $request;
        $this->settings = $settings;
        $this->logger = $logger;
    }

    /**
     * @param \Magento\Framework\Event\Observer $observer
     */
    public function execute(\Magento\Framework\Event\Observer $observer)
    {
        $order = $observer->getEvent()->getOrder();
        $paymentMethod = $order->getPayment()->getMethod();
        if ($paymentMethod === \Soisy\PaymentMethod\Model\Soisy::PAYMENT_METHOD_SOISY_CODE) {
            $this->soisyOrderCreate($order);
        }
    }

    public function soisyOrderCreate($order)
    {
        $payment = $order->getPayment()->getMethodInstance()->getCode();
        $this->logger->log('notice', "soisyOrderCreate: paymenth method: $payment");

        if ($payment!='soisy') {
            $this->logger->log('notice', "soisyOrderCreate: payment is not soisy, skip");
            return;
        }

        if ($order->getSoisyToken()) {
            $this->logger->log('notice', "soisyOrderCreate: token already exists, skip");
            return;
        }

        if ($this->settings->isSandbox()) {
            $this->logger->log('notice', "soisyOrderCreate: SANDBOX MODE.");
        }

        $incrementId = $order->getIncrementId();
        $amount = $order->getGrandTotal();

        $this->logger->log('notice', " incrementId: $incrementId");
        $this->logger->log('notice', " amount: $amount");

        $amount_cent = round($amount * 100);

        $errorUrl = $this->settings->getCmsErrorUrl();

        $successUrl = $this->settings->getCmsSuccessUrl();

        $customerEmail = $order->getData('customer_email');
        $customerFirstname = $order->getData('customer_firstname');
        $customerLastname = $order->getData('customer_lastname');

        $postdata = [
            'firstname' => $customerFirstname,
            'lastname' => $customerLastname,
            'email'=> $customerEmail,
            'amount' => $amount_cent,
            'successUrl' => $successUrl,
            'errorUrl'=> $errorUrl,
            'orderReference' => $incrementId
        ];

        /*
         * Generate a random unique email and a generic orderReference for sandbox customer.
         * */
        if ($this->settings->isSandbox()) {
            $postdata['email'] = 'soisysandbox' . date('YmdHis') . '@example.com';
            $postdata['orderReference'] = 'SOISY-SANDBOX-' . $incrementId;
        }

        foreach($postdata as $k => $v) {
            $this->logger->log('notice', " postdata $k: $v");
        }

        $token = $this->getSoisyToken($postdata);

        if ($token===false) {
            $this->logger->log('notice', " token: FALSE");
            $this->logger->log('notice', "soisyOrderCreate: end" );
            return;
        }
        $this->logger->log('notice', "token: $token");

        $webapp=trim($this->settings->getWebapp());
        $webapp=rtrim($webapp,'/');
        $shopId=trim($this->settings->getShopId());
        $webappUrl="{$webapp}/{$shopId}#/loan-request?token={$token}";


        $this->logger->log('notice', "webappUrl: $webappUrl");

        $endpoint=trim($this->settings->getEndpoint());
        $endpoint=rtrim($endpoint,'/');
        $orderUrl="{$endpoint}/api/shops/{$shopId}/orders/$token";


        //$order->addStatusToHistory(Mage_Sales_Model_Order::STATE_HOLDED, Mage::helper('soisy')->__('Customer was redirected to Soisy'));
        $stringSoisyToken = "Token Soisy";
        $stringCustomerWebappUrl = "Link avvio processo cliente su soisy";
        $stringSoisyOrderInfo = "Dati json associazione ordine (per debug)";
        $order->addStatusHistoryComment("
<b>$stringSoisyToken:</b> $token <br>\n
<b>$stringCustomerWebappUrl:</b> <a target='_blank' href='$webappUrl' >$webappUrl</a><br/>\n
<b>$stringSoisyOrderInfo:</b> <a target='_blank' href='$orderUrl' >$orderUrl</a>  ")
            ->setIsCustomerNotified(false);

        $order->setSoisyToken($token);

        $order->save();
        $this->logger->log( 'notice', "soisyOrderCreate: end" );
    }

    protected function getSoisyToken($postdata) {

        $shopId = trim($this->settings->getShopId());
        $authToken = trim($this->settings->getAuthToken());
        $endpoint = trim($this->settings->getEndpoint());
        $endpoint = rtrim($endpoint,'/');
        $soisyUrl = "{$endpoint}/api/shops/{$shopId}/orders";
        $this->logger->log('notice', " shopId:$shopId - authToken:$authToken - soisyUrl:$soisyUrl");

        $postquery = http_build_query($postdata);
        $context = stream_context_create([
            'http'=>[ 'method'  =>'POST',
                'timeout' => 5.0,
                'header'  =>"X-Auth-Token: $authToken\r\nContent-Type: application/x-www-form-urlencoded",
                'content' => $postquery]
        ]);
        $this->logger->log('notice', " soisyUrl: $soisyUrl");

        $result = @file_get_contents($soisyUrl,false,$context);
        if ($result===false) {
            return false;
        }
        $data = json_decode($result,true);
        if (!is_array($data)) {
            return false;
        }
        if (empty($data['token'])) {
            $errors=$data['errors'];
            $this->logger->log('notice', ' Errors: '.$errors);
            return false;
        }
        $token = $data['token'];
        return $token;
    }
}