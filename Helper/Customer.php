<?php

namespace Dev69\Migration\Helper;

use Magento\Customer\Model\CustomerFactory;
use Magento\Customer\Setup\CustomerSetupFactory;
use Magento\Eav\Model\Entity\Attribute\SetFactory as AttributeSetFactory;
use Magento\Framework\App\Filesystem\DirectoryList as DirectoryList;
use Magento\Framework\App\Helper\AbstractHelper;
use Dev69\Migration\Helper\UtilityImport as UtilityImport;
use Magento\Framework\Math\Random;
use Magento\Store\Model\StoreRepository as StoreRepository;
use Magento\Customer\Model\EmailNotificationInterface;
use Magento\Customer\Api\CustomerRepositoryInterface;
use \Magento\Customer\Model\AccountManagement;


class Customer extends AbstractHelper
{
    const GENDER = 1;
    const LOG_PATH = '/var/log/import_customer.log';
    const CSV_FILE = 'Order_items_sample.csv';
    protected $_utilityImport;
    protected $_random;
    const DEFAULT_WEBSITE_ID = 1;
    const DEFAULT_STORE_ID = 1;
    const DEFAULT_CUSTOMER_GROUP_ID = 1;
    protected $_registry;
    protected $_customerSetupFactory;
    protected $_attributeSetFactory;
    protected $_storeRepository;
    protected $_customerFactory;
    protected $_dir;
    protected $_rootPath;

    protected $saveTransaction;
    protected $_emailNotification;
    protected $_customerRepositoryInterface;

    protected $_accountManagement;

    /**
     * Customer constructor.
     * @param WebParamFactory $webParamFactory
     * @param \Fidesio\MigrateData\Helper\UtilityImport $utilityImport
     * @param CustomerFactory $customerFactory
     * @param DirectoryList $directoryList
     * @param Random $random
     * @param \Magento\Framework\Registry $registry
     * @param CustomerSetupFactory $customerSetupFactory
     * @param AttributeSetFactory $attributeSetFactory
     * @param StoreRepository $storeRepository
     * @param \Magento\Framework\DB\TransactionFactory $transactionFactory
     * @param EmailNotificationInterface $emailNotification
     * @param CustomerRepositoryInterface $customerRepositoryInterface
     * @param AccountManagement $accountManagement
     */
    public function __construct(
        UtilityImport $utilityImport,
        CustomerFactory $customerFactory,
        DirectoryList $directoryList,
        Random $random,
        \Magento\Framework\Registry $registry,
        CustomerSetupFactory $customerSetupFactory,
        AttributeSetFactory $attributeSetFactory,
        StoreRepository $storeRepository,
        \Magento\Framework\DB\TransactionFactory $transactionFactory,
        EmailNotificationInterface $emailNotification,
        CustomerRepositoryInterface $customerRepositoryInterface,
        AccountManagement $accountManagement
    )
    {
        $this->_utilityImport = $utilityImport;
        $this->_random = $random;
        $this->_attributeSetFactory = $attributeSetFactory;
        $this->_customerSetupFactory = $customerSetupFactory;
        $this->_registry = $registry;
        $this->_storeRepository = $storeRepository;
        $this->_customerFactory = $customerFactory;
        $this->_dir = $directoryList;
        $this->_emailNotification = $emailNotification;
        $this->_customerRepositoryInterface = $customerRepositoryInterface;
        $this->_accountManagement = $accountManagement;
        $this->saveTransaction = $transactionFactory->create();
        $this->_rootPath = $this->_dir->getRoot();
    }

    /**
     * @return array
     */
    public function importProcess()
    {

        $logPath = $this->_rootPath . self::LOG_PATH;
        $arrImport = [];

        $this->_utilityImport->setCsvFileName(self::CSV_FILE);
        $this->_utilityImport->setLogPath($logPath);

        // Check if CSV file exist
        $csvFilePath = $this->_utilityImport->getCsvFilePath();
        if (!file_exists($csvFilePath)) {
            return array('status' => false, 'message' => 'The csv file ' . $csvFilePath . ' not found');
        }

        $customers = $this->_utilityImport->readCsv($csvFilePath);

        if (!empty($customers)) {
            $arrImport = $this->importCustomerData($customers);
        } else {
            $this->_utilityImport->log("Invalid data or empty data in csv");
        }
        return  $arrImport;
    }

    /**
     * @param $ds_customers
     * @param $send_activation_email
     * @return array
     */
    private function importCustomerData($customers)
    {
        $totalRecord = count($customers);
        $numberInsert = 0;
        $numberUpdate = 0;
        $numberFailed = 0;
        $messageFailed = '';

        $headers = $customers[0];
        $headers = array_map('strtolower', $headers);
        $headers = array_flip($headers);
        unset($customers[0]);

        foreach ($customers as $item) {
            $email = $this->cleanEmail($item[$headers['email']]);
            if (empty($email))
                continue;
            $isValidEmail = $this->validEmail($email);
            if (!$isValidEmail) {
                $numberFailed++;
                $emailFail = strtolower($email);
                $messageFailed .= "['invalid email':$emailFail]" . PHP_EOL;
                continue;
            }

            $isExistCustomer = $this->getCustomerByEmail($email, self::DEFAULT_STORE_ID, self::DEFAULT_WEBSITE_ID);

            $firstName = $item[$headers['first_name']];
            $lastName = $item[$headers['last_name']];

            try {
                $dataCustomer = array(
                    'email' => $email,
                    'firstname' => $firstName,
                    'lastname' => $lastName,

                );
                if ($customer = $isExistCustomer) {
                    // Update customer

                    $customerSaved = $this->saveCustomer($dataCustomer, $customer);
                    if ($customerSaved)
                        $numberUpdate++;

                } else {
                    // create new customer
                    $customerSaved = $this->saveCustomer($dataCustomer);
                    if ($customer)
                        $numberInsert++;

                    // Send email activation
                    // Reset token
                    $newPasswordToken = $this->_random->getUniqueHash();
                    $this->_accountManagement->changeResetPasswordLinkToken($customerSaved, $newPasswordToken);

                    $templateType = AccountManagement::NEW_ACCOUNT_EMAIL_CONFIRMED;
                    $redirectUrl = '';
                    $this->_emailNotification->newAccount($customerSaved, $templateType, $redirectUrl, $customerSaved->getStoreId());
                    $numberInsert++;
                }
            } catch (Exception $e) {
                $numberFailed++;
                $emailFail = strtolower($email);
                $messageFailed .= "[email: $emailFail, message: {$e->getMessage()}]" . PHP_EOL;
            }


        }
        $arrResult = array(
            'total_record' => $totalRecord,
            'number_failed' => $numberFailed,
            'number_insert' => $numberInsert,
            'number_update' => $numberUpdate,
            'message_failed' => $messageFailed
        );

        foreach ($arrResult as $key => $value) {
            $this->_utilityImport->log("$key: $value");
        }

        return $arrResult;
    }


    /**
     * Check if customer exist with email.
     *
     * @param $email
     * @return mixed
     */
    public function getCustomerByEmail($email, $storeId, $websiteId)
    {
        $customer = $this->_customerFactory->create()
            ->getCollection()
            ->addFieldToFilter('email', $email)
            ->addFieldToFilter('store_id', $storeId)
            ->addFieldToFilter('website_id', $websiteId)
            ->getFirstItem();

        if ($customer && $customer->getId())
            return $customer;

        return false;
    }

    /**
     * @param $email
     * @return string
     */
    function cleanEmail($email)
    {
        $email = strtolower($email);
        $email = str_replace(' .', '.', $email);
        $email = str_replace('  .', '.', $email);
        $email = str_replace(',', '.', $email);
        $email = str_replace(' ', '', $email);
        $email = str_replace('*', '', $email);
        return trim($email);
    }

    /**
     * @param $email
     * @return bool
     */
    function validEmail($email)
    {
        return (!preg_match("/^([a-z0-9\+_\-]+)(\.[a-z0-9\+_\-]+)*@([a-z0-9\-]+\.)+[a-z]{2,6}$/ix", $email)) ? FALSE : TRUE;
    }

    public function saveCustomer($data, $customer = []) {
        try {
            if (!empty($customer)) {
                $customer->setEmail($data['email']);
                $customer->setData('firstname', $data['firstname']);
                $customer->setData('lastname', $data['lastname']);
                $customer->setData('is_active', true);
                $customer->setData('gender', self::GENDER);
                $this->saveTransaction->addObject($customer);
                $this->saveTransaction->save();
            } else {
                $customer = $this->_customerFactory->create();

                $customer->setData('email', $data['email']);
                $customer->setData('firstname', $data['firstname']);
                $customer->setData('lastname', $data['lastname']);
                $customer->setData('is_active', true);
                $customer->setData('website_id', self::DEFAULT_WEBSITE_ID);
                $customer->setData('group_id', self::DEFAULT_CUSTOMER_GROUP_ID);
                $customer->setData('gender', self::GENDER);

                // save the customer
                $this->saveTransaction->addObject($customer);
                $this->saveTransaction->save();
                $customer = $this->_customerRepositoryInterface->getById($customer->getId());

            }
            return $customer;
        } catch (\Exception $e) {
            return false;
        }

    }
}
