<?php

namespace Dev69\Migration\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use Dev69\Migration\Helper\UtilityImport as UtilityImport;
use Dev69\Migration\Helper\Customer as HelperCustomer;

use Magento\Framework\App\State;
use Magento\Customer\Model\CustomerFactory;
use Magento\Customer\Model\AddressFactory;
use Magento\Directory\Model\CountryFactory;
use Magento\Directory\Model\RegionFactory;
use Magento\Framework\Locale\TranslatedLists;
use Magento\Customer\Model\GroupFactory;
use Magento\Setup\Exception;
use Magento\Customer\Api\CustomerRepositoryInterface;

class Order extends AbstractHelper
{

    const LOG_PATH = '/var/log/import_order.log';
    const CSV_FILE = 'Upcoming_Orders_Export_sample.csv';
    const DEFAULT_WEBSITE_ID = 1;
    const DEFAULT_STORE_ID = 1;
    protected $appState;
    protected $addressFactory;
    protected $customerFactory;
    protected $utilityImport;
    protected $_helperCustomer;
    protected $countryFactory;
    protected $regionFactory;
    protected $customAttributes = [];
    protected $_customerRepository;

    protected $requiredColumns = [
        'firstname',
        'lastname',
        'city',
        'street',
        'region',
        'postcode',
        'country_id',
        'telephone'
    ];
    protected $websiteId = 1;
    protected $storeId = 1;
    protected $_storeRepository;
    protected $_orderCollectionFactory;
    protected $_utilityImport;
    protected $_store;
    protected $_quoteItem;
    protected $_dateTime;
    protected $_rootPath;


    public function __construct(
        State $appState,
        \Magento\Framework\App\Helper\Context $context,
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        \Magento\Catalog\Model\Product $product,
        \Magento\Framework\Data\Form\FormKey $formkey,
        \Magento\Quote\Model\QuoteFactory $quote,
        \Magento\Quote\Model\QuoteManagement $quoteManagement,
        \Magento\Customer\Model\CustomerFactory $customerFactory,
        \Magento\Customer\Api\CustomerRepositoryInterface $customerRepository,
        \Magento\Sales\Model\Service\OrderService $orderService,
        UtilityImport $utilityImport,
        HelperCustomer $helperCustomer,
        \Magento\Quote\Model\Quote $quoteItem,
        \Magento\Framework\Stdlib\DateTime\DateTime $dateTime

    ) {
        $this->appState = $appState;
        $this->_utilityImport = $utilityImport;
        $this->customerFactory = $customerFactory;
        $this->_helperCustomer = $helperCustomer;
        $this->_storeManager = $storeManager;
        $this->_product = $product;
        $this->_formkey = $formkey;
        $this->quote = $quote;
        $this->quoteManagement = $quoteManagement;
        $this->customerFactory = $customerFactory;
        $this->customerRepository = $customerRepository;
        $this->orderService = $orderService;
        $this->_quoteItem = $quoteItem;
        $this->_dateTime = $dateTime;

        parent::__construct($context);
    }

    /**
     * @return array
     */
    public function importProcess()
    {

        $logPath = $this->_rootPath . '/var/log/import_customer_address.log';

        $result = [];
        $this->_utilityImport->setCsvFileName(self::CSV_FILE);
        $this->_utilityImport->setLogPath($logPath);

        // Check if CSV file exist
        $csvFilePath = $this->_utilityImport->getCsvFilePath();
        if (!file_exists($csvFilePath)) {
            return [
                'status' => false,
                'message' => 'The csv file ' . $csvFilePath . ' not found'
            ];
        }

        $customers = $this->_utilityImport->readCsv($csvFilePath);
        if (!empty($customers)) {
            $result = $this->importOrder($customers);
        }
        return $result;
    }

    /**
     * @param $customers
     * @return array
     */
    public function importOrder($orderData)
    {
        $startTime = time();
        $totalRecord = 0;
        $numberInsert = 0;
        $numberFailed = 0;
        $numberUpdate = 0;
        $messageFailed = '';

        $orderData = $this->reformatOrderData($orderData);
        if (!empty($orderData['status']) == 'failed') {
            $arrResult = [
                'total_record' => $totalRecord,
                'number_failed' => $numberFailed,
                'number_insert' => $numberInsert,
                'number_update' => $numberUpdate,
                'message_failed' => $orderData['message']
            ];
            return $arrResult;
        }

        foreach ($orderData as $item) {
            try {

                $email = $item['email'];
                $customer = $this->_helperCustomer->getCustomerByEmail($email);
                if (!$customer) {

                    $customer = $this->_helperCustomer->saveCustomer([
                        'firstname' => $item['shipping_first_name'],
                        'lastname' => $item['shipping_last_name'],
                        'email' => $email

                    ]);
                }

                $dataShipping = $item['shipping'];
                $dataBilling = $item['billing'];
                $store = $this->_storeManager->getStore(self::DEFAULT_STORE_ID);
                $isExistQuote = $this->isExistQuoteItem($customer->getId(), $item['created_at']);
                if ($isExistQuote) {
                    continue;
                }

                $shippingMethod = $this->_utilityImport->getShippingMethodCode();
                $paymentMethod = $this->_utilityImport->getPaymentMethod();
                $quote = $this->saveQuote([
                    'payment_method' => $paymentMethod->getCode(),
                    'shipping_method' => $shippingMethod,
                    'shipping' => $dataShipping,
                    'billing' => $dataBilling,
                    'items' => $item['items'],
                    'store' => $store,
                    'customer_id' => $customer->getId(),
                ]);
                if (!empty($quote['status']) && $quote['status'] == 'failed') {
                    $numberFailed++;
                    $messageFailed .= '[email:' . $email . ',message: ' . $quote['message'] . ']' . PHP_EOL;
                    continue;
                }

                // Create Order From Quote
                $order = $this->quoteManagement->submit($quote);
                $order->setEmailSent(0);
                $numberInsert++;

            } catch (Exception $e) {
                $numberFailed++;
                $messageFailed .= '[email:' . $email . ',message: ' . $e->getMessage() . ']' . PHP_EOL;
            }

        }

        $arrResult = [
            'total_record' => $totalRecord,
            'number_failed' => $numberFailed,
            'number_insert' => $numberInsert,
            'number_update' => $numberUpdate,
            'message_failed' => $messageFailed
        ];
        return $arrResult;
    }

    private function getOrderFromCsv($dataCsv)
    {
        $headers = $dataCsv[0];
        $headers = array_map(function ($item) {
            return strtolower(str_replace(' ', '_', $item));
        }, $headers);
        unset($dataCsv[0]);
        $headers = array_flip($headers);
        $listOrders = [];
        foreach ($dataCsv as $item) {
            $arrTmp = [
                'email' =>$item[$headers['email']],
                'subscription_id' =>$item[$headers['subscription_id']],
            ];
            if (!in_array($arrTmp, $listOrders)) {
                $listOrders[] = $arrTmp;
            }
        }
        return $listOrders;
    }

    private function getOrderItemsFromCsv($dataCsv, $order)
    {
        $headers = $dataCsv[0];
        $headers = array_map(function ($item) {
            return strtolower(str_replace(' ', '_', $item));
        }, $headers);
        unset($dataCsv[0]);
        $headers = array_flip($headers);
        $orderItems = [];

        foreach ($dataCsv as $item) {
            if ($item[$headers['email']] == $order['email'] &&
                $item[$headers['subscription_id']] == $order['subscription_id']
            ) {

                $countryShipping = $this->_utilityImport->getCountryByName($item[$headers['shipping_country']]);
                $countryBilling = $this->_utilityImport->getCountryByName($item[$headers['billing_country']]);

                if (!$countryShipping || !$countryBilling) {
                    continue;
                }
                $countryIdShipping = $countryShipping->getCountryId();
                $countryIdBilling = $countryBilling->getCountryId();
                $regionShipping = $this->_utilityImport->getRegion('name', 'shipping_province', $countryIdShipping);
                $regionBilling = $this->_utilityImport->getRegion('name', 'billing_province', $countryIdBilling);

                $dataShipping = [
                    'firstname' => $item[$headers['shipping_first_name']],
                    'lastname' => $item[$headers['shipping_last_name']],
                    'street' => $item[$headers['shipping_address_1']],
                    'address_2' => $item[$headers['shipping_address_2']],
                    'postcode' => $item[$headers['shipping_postal_code']],
                    'city' => $item[$headers['shipping_city']],
                    'telephone' => $item[$headers['shipping_phone']],
                    'company' => $item[$headers['shipping_company']],
                    'country_id' => $countryShipping['country_id'],
                ];
                if ($regionShipping)
                    $dataShipping['region_id'] = $regionShipping->getRegionId();

                $dataBilling = [
                    'firstname' => $item[$headers['billing_first_name']],
                    'lastname' => $item[$headers['billing_last_name']],
                    'street' => $item[$headers['billing_address_1']],
                    'address_2' => $item[$headers['billing_address_2']],
                    'postcode' => $item[$headers['billing_postal_code']],
                    'city' => $item[$headers['billing_city']],
                    'telephone' => $item[$headers['billing_phone']],
                    'company' => $item[$headers['billing_company']],
                    'country_id' => $countryBilling['country_id'],
                ];
                if ($regionBilling)
                    $dataBilling['region_id'] = $regionBilling->getRegionId();

                 $tmp = [
                    'sku' => $item[$headers['sku']],
                    'price' => $item[$headers['item_price']],
                    'qty' => $item[$headers['quantity']],
                    'created_at' => $item[$headers['item_created_at']],
                    'subscription' => [
                        'properties' => $item[$headers['properties']],
                        'subscription_id' =>$order['subscription_id'],
                        'recharge_purchase_id' => $item[$headers['recharge_purchase_id']],
                        'charge_in_sequence' => $item[$headers['charge_in_sequence']],
                        'recharge_charge_id' => $item[$headers['recharge_charge_id']],

                    ],
                    'shopify' => [
                        'product_id' => $item[$headers['shopify_product_id']],
                        'variant_id' => $item[$headers['shopify_variant_id']],
                    ],
                    'shipping_method' => $item[$headers['shipping_method']],
                    'shipping' => $dataShipping,
                    'billing' => $dataBilling,
                    'tax_amount' => $item[$headers['tax_amount']],
                    'discount_amount' => $item[$headers['discount_amount']],
                ];
                $orderItems[] = $tmp;

            }

        }
        return $orderItems;
    }

    private function reformatOrderData($data)
    {
        $dataReturn = [];
        try {
            $listOrder = $this->getOrderFromCsv($data);
            if (empty($listOrder)) {
                return  ['status' => 'failed', 'message' => 'empty data on csv'];
            }
            foreach ($listOrder as $item) {
                $orderItem = $this->getOrderItemsFromCsv($data, $item);
                $dataShipping = $orderItem[0]['shipping'];
                $dataBilling = $orderItem[0]['billing'];
                $tmp = [
                    'email' => $item['email'],
                    'created_at' => $orderItem[0]['created_at'],
                    'shipping' => $dataShipping,
                    'billing' => $dataBilling,
                    'items' => $orderItem

                ];
                $dataReturn[] = $tmp;
            }

        } catch (\Exception $e) {
            return ['status' => 'failed', 'message' => $e->getMessage()];
        }
        return $dataReturn;
    }



    public function saveQuote($data)
    {
        try {
            $customer= $this->customerRepository->getById($data['customer_id']);
            $quote = $this->quote->create(); //Create object of quote
            $quote->setStore($data['store']); //set store for which you create quote
            $quote->setCurrency();
            $quote->assignCustomer($customer); //Assign quote to customer

            //add items in quote
            foreach ($data['items'] as $item) {

                $product = $this->_product->loadByAttribute('sku',$item['sku']);
                $product->setPrice($item['price']);
                $quote->addProduct(
                    $product,
                    (int)($item['qty'])
                );
            }

            //Set Address to quote
            $quote->getBillingAddress()->addData($data['billing']);
            $quote->getShippingAddress()->addData($data['shipping']);

            // Collect Rates and Set Shipping & Payment Method

            $shippingAddress = $quote->getShippingAddress();
            $shippingAddress->setCollectShippingRates(true)
                ->collectShippingRates()
                ->setShippingMethod('freeshipping_freeshipping'); //shipping method
//                ->setShippingMethod($data['shipping_method'])
            $quote->setPaymentMethod($data['payment_method']); //payment method
            $quote->setInventoryProcessed(false); //not effetc inventory
            $createdAt = $this->_dateTime->gmtTimestamp($item['created_at']);
            $quote->setCreatedAt($createdAt);

            $quote->save(); //Now Save quote and your quote is ready

            // Set Sales Order Payment
//            $quote->setPaymentMethod('checkmo'); //payment method
            $quote->getPayment()->importData(['method' => $data['payment_method']]);

            // Collect Totals & Save Quote
            $quote->collectTotals()->save();
            return $quote;

        } catch (\Exception $e) {
            return [
                'status' => 'failed',
                'message' => $e->getMessage()
            ];

        }
    }

    public function isExistQuoteItem($customerId, $createdAt)
    {

        $timeStamp = $this->_dateTime->gmtTimestamp($createdAt);

        $quoteItem = $this->_quoteItem->getCollection()
            ->addFieldToFilter('customer_id', $customerId)
            ->addFieldToFilter('created_at', array('eq' => $timeStamp))
            ->getData();
        return !empty($quoteItem) ? true : false;
    }





}
