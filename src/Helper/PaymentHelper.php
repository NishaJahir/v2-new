<?php
/**
 * This module is used for real time processing of
 * Novalnet payment module of customers.
 * This free contribution made by request.
 * 
 * If you have found this script useful a small
 * recommendation as well as a comment on merchant form
 * would be greatly appreciated.
 *
 * @author       Novalnet AG
 * @copyright(C) Novalnet
 * All rights reserved. https://www.novalnet.de/payment-plugins/kostenlos/lizenz
 */

namespace Novalnet\Helper;

use Plenty\Modules\Payment\Method\Contracts\PaymentMethodRepositoryContract;
use Plenty\Modules\Payment\Models\Payment;
use Plenty\Modules\Payment\Models\PaymentProperty;
use Plenty\Modules\Payment\Contracts\PaymentRepositoryContract;
use Plenty\Modules\Order\Contracts\OrderRepositoryContract;
use Plenty\Modules\Payment\Contracts\PaymentOrderRelationRepositoryContract;
use Plenty\Modules\Order\Models\Order;
use Plenty\Plugin\Log\Loggable;
use Plenty\Plugin\Translation\Translator;
use Plenty\Plugin\ConfigRepository;
use \Plenty\Modules\Authorization\Services\AuthHelper;
use Plenty\Modules\Comment\Contracts\CommentRepositoryContract;
use Plenty\Modules\Order\Shipping\Countries\Contracts\CountryRepositoryContract;
use Plenty\Modules\Frontend\Session\Storage\Contracts\FrontendSessionStorageFactoryContract;
use Novalnet\Constants\NovalnetConstants;
use Novalnet\Services\PaymentService;
use Novalnet\Services\TransactionService;
use Plenty\Modules\Basket\Models\Basket;
use Plenty\Modules\Basket\Contracts\BasketRepositoryContract;

/**
 * Class PaymentHelper
 * @package Novalnet\Helper
 */
class PaymentHelper
{
    use Loggable;

    /**
     *
     * @var PaymentMethodRepositoryContract
     */
    private $paymentMethodRepository;
       
    /**
     *
     * @var PaymentRepositoryContract
     */
    private $paymentRepository;
    
    /**
     *
     * @var OrderRepositoryContract
     */
    private $orderRepository;

    /**
     *
     * @var PaymentOrderRelationRepositoryContract
     */
    private $paymentOrderRelationRepository;

     /**
     *
     * @var orderComment
     */
    private $orderComment;

    /**
    *
    * @var $configRepository
    */
    public $config;

    /**
    *
    * @var $countryRepository
    */
    private $countryRepository;

    /**
    *
    * @var $sessionStorage
    */
    private $sessionStorage;
      
    /**
     * @var PaymentService
     */
    private $paymentService;
    
    /**
     * @var transaction
     */
    private $transaction;
    
    /**
     * @var Basket
     */
    private $basket;

    /**
     * PaymentHelper Constructor.
     *
     * @param PaymentMethodRepositoryContract $paymentMethodRepository
     * @param PaymentRepositoryContract $paymentRepository
     * @param OrderRepositoryContract $orderRepository
     * @param PaymentOrderRelationRepositoryContract $paymentOrderRelationRepository
     * @param CommentRepositoryContract $orderComment
     * @param ConfigRepository $configRepository
     * @param FrontendSessionStorageFactoryContract $sessionStorage
     * @param PaymentService $paymentService
     * @param TransactionService $tranactionService
     * @param CountryRepositoryContract $countryRepository
     * @param BasketRepositoryContract $basket
     */
    public function __construct(PaymentMethodRepositoryContract $paymentMethodRepository,
                                PaymentRepositoryContract $paymentRepository,
                                OrderRepositoryContract $orderRepository,
                                PaymentOrderRelationRepositoryContract $paymentOrderRelationRepository,
                                CommentRepositoryContract $orderComment,
                                ConfigRepository $configRepository,
                                FrontendSessionStorageFactoryContract $sessionStorage,
                                PaymentService $paymentService,
                                TransactionService $tranactionService,
                                CountryRepositoryContract $countryRepository,
                                BasketRepositoryContract $basket
                              )
    {
        $this->paymentMethodRepository        = $paymentMethodRepository;
        $this->paymentRepository              = $paymentRepository;
        $this->orderRepository                = $orderRepository;
        $this->paymentOrderRelationRepository = $paymentOrderRelationRepository;
        $this->orderComment                   = $orderComment;      
        $this->config                         = $configRepository;
        $this->sessionStorage                 = $sessionStorage;
        $this->paymentService                 = $paymentService;
        $this->transaction                    = $tranactionService;
        $this->countryRepository              = $countryRepository;
        $this->basket                         = $basket->load();
    }

    /**
     * Load the ID, Key, Name of the payment method
     * 
     * @param string $paymentKey
     * @return string|array
     */
    public function getPaymentMethodByKey($paymentKey) 
    {
        $paymentMethods = $this->paymentMethodRepository->allForPlugin('plenty_novalnet');
        
        if(!is_null($paymentMethods))
        {
            foreach($paymentMethods as $paymentMethod)
            {
                if($paymentMethod->paymentKey == $paymentKey)
                {
                    return [$paymentMethod->id, $paymentMethod->paymentKey, $paymentMethod->paymentName];
                }
            }
        }
        return 'no_paymentmethod_found';
    }

    /**
     * Load the ID of the payment method
     *
     * @param int $mop
     * @return string|bool
     */
    public function getPaymentKeyByMop($mop) 
    {
        $paymentMethods = $this->paymentMethodRepository->allForPlugin('plenty_novalnet');

        if(!is_null($paymentMethods))
        {
            foreach($paymentMethods as $paymentMethod)
            {
                if($paymentMethod->id == $mop)
                {
                    return $paymentMethod->paymentKey;
                }
            }
        }
        return false;
    }

    /**
     * Create the Plenty payment for a order
     *
     * @param array $requestData
     * @return object
     */
    public function createPlentyPayment($requestData) 
    {
        try {
        /** @var Payment $payment */
        $payment = pluginApp(\Plenty\Modules\Payment\Models\Payment::class);
        
        $payment->mopId           = (int) $requestData['mop'];
        $payment->transactionType = Payment::TRANSACTION_TYPE_BOOKED_POSTING;
        $payment->status          = ($requestData['transaction']['status'] == 'FAILURE' ? Payment::STATUS_CANCELED : (in_array($requestData['transaction']['status'], ['PENDING', 'ON_HOLD']) ? Payment::STATUS_APPROVED : Payment::STATUS_CAPTURED));
        $payment->currency        = $requestData['transaction']['currency'];
        $payment->amount          = (in_array($requestData['transaction']['status'], ['PENDING', 'ON_HOLD', 'FAILURE']) ? 0 : ( $requestData['transaction']['amount'] / 100 ) );
        if(isset($requestData['booking_text']) && !empty($requestData['booking_text'])) {
        $bookingText = $requestData['booking_text'];
        } else {
        $bookingText = $requestData['transaction']['tid'];
        }

        $paymentProperty     = [];
        $paymentProperty[]   = $this->getPaymentProperty(PaymentProperty::TYPE_BOOKING_TEXT, $bookingText);
        $paymentProperty[]   = $this->getPaymentProperty(PaymentProperty::TYPE_TRANSACTION_ID, $requestData['transaction']['tid']);
        $paymentProperty[]   = $this->getPaymentProperty(PaymentProperty::TYPE_ORIGIN, Payment::ORIGIN_PLUGIN);
        $paymentProperty[]   = $this->getPaymentProperty(PaymentProperty::TYPE_EXTERNAL_TRANSACTION_STATUS, $requestData['transaction']['status']);
        
        $payment->properties = $paymentProperty;
        
        $paymentObj = $this->paymentRepository->createPayment($payment);
        
        $this->assignPlentyPaymentToPlentyOrder($paymentObj, (int)$requestData['transaction']['order_no']);
        } catch (\Exception $e) {
            $this->getLogger(__METHOD__)->error('Novalnet::createPlentyPayment', $e);
        }
    }
    

    /**
     * Get the payment property object
     *
     * @param mixed $typeId
     * @param mixed $value
     * @return object
     */
    public function getPaymentProperty($typeId, $value) 
    {
        /** @var PaymentProperty $paymentProperty */
        $paymentProperty = pluginApp(\Plenty\Modules\Payment\Models\PaymentProperty::class);
        $paymentProperty->typeId = $typeId;
        $paymentProperty->value  = (string) $value;

        return $paymentProperty;
    }

    /**
     * Assign the payment to an order in plentymarkets.
     *
     * @param Payment $payment
     * @param int $orderId
     */
    public function assignPlentyPaymentToPlentyOrder(Payment $payment, int $orderId) {
        try {
        /** @var \Plenty\Modules\Authorization\Services\AuthHelper $authHelper */
        $authHelper = pluginApp(AuthHelper::class);
        $authHelper->processUnguarded(
                function () use ($payment, $orderId) {
                //unguarded
                $order = $this->orderRepository->findOrderById($orderId);
                if (!is_null($order) && $order instanceof Order)
                {
                    $this->paymentOrderRelationRepository->createOrderRelation($payment, $order);
                }
            });
        } catch (\Exception $e) {
            $this->getLogger(__METHOD__)->error('Novalnet::assignPlentyPaymentToPlentyOrder', $e);
        }
    }

    /**
     * Execute the Curl process
     *
     * @param array $data
     * @param string $url
     * @return array
     */
    public function executeCurl($data, $url) 
    {
        try {
            $accessKey = trim($this->config->get('Novalnet.novalnet_access_key'));
            $headers = array(
                'Content-Type:application/json',
                'charset:utf-8',
                'X-NN-Access-Key:'. base64_encode($accessKey),
            );

            $curl = curl_init();
            // Set cURL options
            curl_setopt($curl, CURLOPT_URL, $url);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($curl, CURLOPT_POST, 1);
            curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
            curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);

            // Execute cURL
            $response = curl_exec($curl);

            // Handle cURL error
            if (curl_errno($curl)) {
                echo 'Request Error:' . curl_error($curl);
                return $response;
            }
            curl_close($curl);
            return json_decode($response, true);
        } catch (\Exception $e) {
            $this->getLogger(__METHOD__)->error('Novalnet::executeCurlError', $e);
        }
    }

   /**
    * Get the translated text for the Novalnet key
    * @param string $key
    * @param string $lang
    *
    * @return string
    */
    public function getTranslatedText($key, $lang = null) 
    {
        $translator = pluginApp(Translator::class);

        return $lang == null ? $translator->trans("Novalnet::PaymentMethod.$key") : $translator->trans("Novalnet::PaymentMethod.$key",[], $lang);
    }

    /**
     * Retrieves the original end-customer address with and without proxy
     *
     * @return string
     */
    public function getRemoteAddress() 
    {
        $ipKeys = ['HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED', 'HTTP_X_CLUSTER_CLIENT_IP', 'HTTP_FORWARDED_FOR', 'HTTP_FORWARDED', 'REMOTE_ADDR'];

        foreach ($ipKeys as $key)
        {
            if (array_key_exists($key, $_SERVER) === true)
            {
                foreach (explode(',', $_SERVER[$key]) as $ip)
                {
                    return $ip;
                }
            }
        }
    }

    /**
    * Check the payment activate conditions
    * 
    * @param string $paymentKey
    * return bool
    */
    public function isPaymentActive($paymentKey) 
    {
        $paymentDisplay = false;
        
        // Allowed country check
        $paymentAllowedCountry = 'true';
        if ($allowedCountry = $this->config->get('Novalnet.' . $paymentKey . '_allowed_country')) {
            $paymentAllowedCountry  = $this->paymentService->allowedCountries($this->basket, $allowedCountry);
        }

        // Minimum order amount check
        $minimumOrderAmount = 'true';
        $minOrderAmount = trim($this->config->get('Novalnet.' . $paymentKey . '_minimum_order_amount'));
        if (!empty($minOrderAmount) && is_numeric($minOrderAmount)) {
            $minimumOrderAmount = $this->paymentService->getMinBasketAmount($this->basket, $minOrderAmount);
        }

        // Maximum order amount check
        $maximumOrderAmount = 'true';
        $maxOrderAmount = trim($this->config->get('Novalnet.' . $paymentKey . '_maximum_order_amount'));
        if (!empty($maxOrderAmount) && is_numeric($maxOrderAmount)) {
            $maximumOrderAmount = $this->paymentService->getMaxBasketAmount($this->basket, $maxOrderAmount);
        }

        if (!empty(trim($this->config->get('Novalnet.novalnet_public_key'))) && is_numeric(trim($this->config->get('Novalnet.novalnet_tariff_id'))) && !empty(trim($this->config->get('Novalnet.novalnet_access_key'))) && $paymentAllowedCountry && $minimumOrderAmount && $maximumOrderAmount)
        {
            $paymentDisplay = true;
        }
        return $paymentDisplay;
    }
    
    /**
    * Due date calculation and change the date format as YYYY-MM-DD
    *
    * return string
    */
    public function getPaymentDueDate($days) 
    {
        return date( 'Y-m-d', strtotime( date( 'y-m-d' ) . '+ ' . $days . ' days' ) );
    }
    
    /**
    * Convert the order amount from decimal to integer
    *
    * return int
    */
    public function ConvertAmountToSmallerUnit($amount) 
    {
        return sprintf('%0.2f', $amount) * 100;
    }
}
