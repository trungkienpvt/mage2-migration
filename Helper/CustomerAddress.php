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

class CustomerAddress extends AbstractHelper
{

    const LOG_PATH = '/var/log/import_customer_address.log';
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
    protected $translatedLists;
    protected $customAttributes = [];
    protected $saveTransaction;
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
    protected $groupFactory;
    protected $_utilityImport;

    protected $_customAttributes = ['address_2'];
    protected $_rootPath;

    public function __construct(
        State $appState,
        AddressFactory $addressFactory,
        CountryFactory $countryFactory,
        CustomerFactory $customerFactory,
        RegionFactory $regionFactory,
        TranslatedLists $translatedLists,
        UtilityImport $utilityImport,
        \Magento\Store\Model\StoreRepository $storeRepository,
        GroupFactory $groupFactory,
        \Magento\Framework\DB\TransactionFactory $transactionFactory,
        CustomerRepositoryInterface $customerRepository,
        HelperCustomer $helperCustomer
    ) {
        $this->addressFactory = $addressFactory;
        $this->appState = $appState;
        $this->countryFactory = $countryFactory;
        $this->customerFactory = $customerFactory;
        $this->regionFactory = $regionFactory;
        $this->translatedLists = $translatedLists;
        $this->_utilityImport = $utilityImport;
        $this->_storeRepository = $storeRepository;
        $this->groupFactory = $groupFactory;
        $this->saveTransaction = $transactionFactory->create();
        $this->_rootPath = $this->_utilityImport->getRootPath();
        $this->_customerRepository = $customerRepository;
        $this->_helperCustomer = $helperCustomer;
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
            $result = $this->importCustomerAddress($customers);
        }
        return $result;
    }

    /**
     * @param $customers
     * @return array
     */
    public function importCustomerAddress($customers)
    {
        $startTime = time();
        $totalRecord = 0;
        $numberInsert = 0;
        $numberFailed = 0;
        $numberUpdate = 0;
        $messageFailed = '';

        $headers = $customers[0];
        $headers = array_map(function ($item) {
            return strtolower(str_replace(' ', '_', $item));
        }, $headers);
        unset($customers[0]);

        $headers = array_flip($headers);
        $totalRecord = count($customers);

        foreach ($customers as $item) {

            $email = $item[$headers['email']];
            $dataShipping = [
                'firstname' => trim($item[$headers['shipping_first_name']]),
                'lastname' => trim($item[$headers['shipping_last_name']]),
                'street' => trim(($item[$headers['shipping_address_1']])),
                'address_2' => trim($item[$headers['shipping_address_2']]),
                'postcode' => trim($item[$headers['shipping_postal_code']]),
                'city' => trim($item[$headers['shipping_city']]),
                'telephone' => trim($item[$headers['shipping_phone']]),
                'company' => trim($item[$headers['shipping_company']]),
                'region' => trim($item[$headers['shipping_province']]),
                'country' => trim($item[$headers['shipping_country']]),
            ];

            $dataBilling = [
                'firstname' => trim($item[$headers['billing_first_name']]),
                'lastname' => trim($item[$headers['billing_last_name']]),
                'street' => trim(($item[$headers['billing_address_1']])),
                'address_2' => trim($item[$headers['billing_address_2']]),
                'postcode' => trim($item[$headers['billing_postal_code']]),
                'city' => trim($item[$headers['billing_city']]),
                'telephone' => trim($item[$headers['billing_phone']]),
                'company' => trim($item[$headers['billing_company']]),
                'region' => trim($item[$headers['billing_province']]),
                'country' => trim($item[$headers['billing_country']]),
            ];

            $customer = $this->_helperCustomer->getCustomerByEmail($email,self::DEFAULT_STORE_ID, self::DEFAULT_WEBSITE_ID);

            if (!$customer) {

                $customer = $this->_helperCustomer->saveCustomer([
                    'firstname' => $item[$headers['shipping_first_name']],
                    'lastname' => $item[$headers['shipping_last_name']],
                    'email' => $email

                ]);
            }

            if (empty($customer)) {
                $numberFailed++;
                $messageFailed .= '[email:' . $email . ',message: Not exist customer]' . PHP_EOL;
                continue;
            }

            try {
                //update shipping address

                $isExistShippingAddress = $this->getCustomerAddress(
                    [
                        'parent_id' => $customer->getId(),
                        'firstname' => trim($dataShipping['firstname']),
                        'lastname' => trim($dataShipping['lastname']),
//                        'company' => trim($dataShipping['company']),
                        'street' => trim(utf8_encode($dataShipping['street'])),
                        'postcode' => trim($dataShipping['postcode']),
                        'telephone' => trim($dataShipping['telephone']),
                    ]
                );

                if ($customerAddress = $isExistShippingAddress) {//update customer address
                    $shippingAddressSaved = $this->saveCustomerAddress($dataShipping, $customer, 'shipping', $customerAddress);
                    $this->saveTransaction->addObject($shippingAddressSaved);
                    $numberUpdate++;
                } else {
                    $shippingAddressSaved = $this->saveCustomerAddress($dataShipping, $customer, 'shipping');
                    $this->saveTransaction->addObject($shippingAddressSaved);
                    $numberInsert++;
                }
                $this->saveTransaction->save();

                if ($dataShipping == $dataBilling) {
                    $shippingAddressSaved->setIsDefaultBilling(1);
                } else {
                    //update billing address
                    $isExistBillingAddress = $this->getCustomerAddress([
                        'parent_id' => $customer->getId(),
                        'firstname' => trim($dataBilling['firstname']),
                        'lastname' => trim($dataBilling['lastname']),
//                        'company' => trim($dataBilling['company']),
                        'street' => trim(utf8_encode($dataShipping['street'])),
                        'postcode' => trim($dataBilling['postcode']),
                        'telephone' => trim($dataBilling['telephone']),
                    ]);
                    if ($customerAddress = $isExistBillingAddress) {//update customer address
                        $billingAddressSaved = $this->saveCustomerAddress($dataBilling, $customer, 'billing', $billingAddressSaved);
                        $this->saveTransaction->addObject($billingAddressSaved);
                        $numberUpdate++;
                    } else {
                        $billingAddressSaved = $this->saveCustomerAddress($dataBilling, $customer, 'billing');
                        $this->saveTransaction->addObject($billingAddressSaved);
                        $numberInsert++;
                    }
                    $this->saveTransaction->save();
                }

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

    /**
     * Create or Update Address.
     *
     * @param array $rowData
     * @param \Magento\Customer\Model\Address $address
     * @param \Magento\Customer\Model\Customer $customer
     * @return \Magento\Customer\Model\Address
     */
    public function saveCustomerAddress($data, $customer, $type, $address = [])
    {
        $countryId = '';
        $regionId = '';
        $regionName = '';

        $street = utf8_encode($data['street']);

        // Create new address
        if (empty($address)) {
            $address = $this->addressFactory->create();
        }
        $address->setCustomerId($customer->getId());
        $address->setFirstname($customer->getFirstname())
            ->setLastname($customer->getLastname());

        if (!empty($data['region'])) {
            $region = $this->_utilityImport->getRegion('name', $data['region']);
            if (!empty($region)) {
                $regionId = $region->getRegionId();
                $regionName = $region->getName();
            }

        }
        if (!empty($data['country'])) {
            $country = $this->_utilityImport->getCountryByName($data['country']);
            if (!empty($country)) {
                $countryId = $country->getCountryId();
                $address->setCountryId($countryId);
            }

        }

        $address->setRegionId($regionId)
            ->setRegion($regionName)
            ->setCity($data['city'])
            ->setPostcode($data['postcode'])
            ->setStreet($street)
            ->setTelephone($data['telephone'])
            ->setCompany($data['company'])
            ->setSaveInAddressBook('1');
        if ($type == 'shipping') {
            $address->setIsDefaultShipping(1);
        } elseif ($type == 'billing') {
            $address->setIsDefaultBilling(1);
        }

        foreach ($this->_customAttributes as $item) {
            if (isset($data[$item])) {
                $address->setData($item, utf8_encode($data[$item]));
            }
        }

        return $address;
    }

    /**
     * Get customer address filter by cdcli and adrnum
     *
     * @param $rowData
     * @param $customerId
     * @return mixed
     */
    public function getCustomerAddress($params)
    {
        $customerAddress = $this->addressFactory->create()
            ->getCollection();
        if (!empty($params)) {
            foreach ($params as $key => $value) {
                $customerAddress->addAttributeToFilter($key, $value);
            }
        }
        if ($customerAddress->getSize())
            return $customerAddress->getFirstItem();

        return false;
    }

    public function setCustomAttributes(array $value)
    {
        $this->customAttributes = $value;
        return $this;
    }

    public function getCustomAttributes()
    {
        return $this->customAttributes;
    }

    public function getRequiredColumns()
    {
        return $this->requiredColumns;
    }
}
