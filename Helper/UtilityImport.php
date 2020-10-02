<?php

namespace Dev69\Migration\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem\Io\File;
use Magento\Framework\File\Csv;
use Magento\Framework\App\ObjectManager as ObjectManager;
use Magento\Framework\Locale\TranslatedLists;
use Magento\Directory\Model\CountryFactory as CountryFactory;
use Magento\Directory\Model\RegionFactory as RegionFactory;
use Magento\Inventory\Model\ResourceModel\Source\Collection as SourceCollection;
use Magento\Inventory\Model\SourceFactory as SourceFactory;
use Magento\Catalog\Model\CategoryFactory as CategoryFactory;

class UtilityImport extends AbstractHelper
{

    const ENCLOSURE = '"';
    const DELIMITER = ';';
    /**
     * @var \Magento\Framework\File\Csv
     */
    protected $fileCsv;

    /**
     * @var \Magento\Framework\App\Filesystem\DirectoryList
     */
    protected $directoryList;

    /**
     * @var \Magento\Framework\Filesystem\Io\File
     */
    protected $io;

    /**
     * @var \Magento\Framework\Math\Random
     */
    /**
     * CSV File Name
     * @var string
     */
    protected $csvFileName;

    /**
     * CSV rile path (relative to Magento root directory)
     * @var string
     */
    protected $csvFilePath = '/var/ftp/IN/';
    protected $csvArchiveFilePath;
    /**
     * @var string
     */
    protected $logPath = '';
    protected $logPathArchive = '';
    protected $websiteId = 1;
    protected $storeId = 1;
    protected $_productRepository;
    protected $_rootPath = '';
    protected $_translatedLists;
    protected $_countryFactory;
    protected $_regionFactory;
    protected $_sourceCollection;
    protected $_sourceFactory;
    protected $_trackingLog;
    protected $_categoryFactory;
    protected $_randomMath;
    protected $_shipingConfig;
    protected $_paymentConfig;

    /**
     * UtilityImport constructor.
     * @param Context $context
     * @param Csv $fileCsv
     * @param DirectoryList $directoryList
     * @param File $io
     * @param ObjectManager $objectManager
     */
    public function __construct(
        Context $context,
        Csv $fileCsv,
        DirectoryList $directoryList,
        File $io,
        \Magento\Catalog\Model\ResourceModel\Category\CollectionFactory $categoryCollecionFactory,
        \Magento\Catalog\Api\ProductRepositoryInterface $productRepository,
        TranslatedLists $translatedLists,
        CountryFactory $countryFactory,
        RegionFactory $regionFactory,
        SourceCollection $sourceCollection,
        SourceFactory $sourceFactory,
        CategoryFactory $categoryFactory,
        \Magento\Framework\Math\Random $randomMath,
        \Magento\Payment\Model\Config $paymentConfig,
        \Magento\Shipping\Model\Config $shippingConfig
    ) {
        $this->fileCsv = $fileCsv;
        $this->directoryList = $directoryList;
        $this->io = $io;
        $this->_categoryCollectionFactory = $categoryCollecionFactory;
        $this->_productRepository = $productRepository;
        $this->io->mkdir($this->directoryList->getRoot() . $this->csvFilePath, 0775);
        $this->_rootPath = $this->directoryList->getRoot();
        $this->_translatedLists = $translatedLists;
        $this->_countryFactory = $countryFactory;
        $this->_regionFactory = $regionFactory;
        $this->_sourceCollection = $sourceCollection;
        $this->_sourceFactory = $sourceFactory;
        $this->_categoryFactory = $categoryFactory;
        $this->_randomMath = $randomMath;
        $this->_shippingConfig = $shippingConfig;
        $this->_paymentConfig = $paymentConfig;
    }

    /**
     * @param $info
     * @param string $type
     */
    public function log($info, $type = 'normal')
    {
        if ($type == 'normal') {
            $writer = new \Zend\Log\Writer\Stream($this->logPath);
        } elseif ($type == 'archive') {
            $writer = new \Zend\Log\Writer\Stream($this->logPathArchive);
        }
        $logger = new \Zend\Log\Logger();
        $logger->addWriter($writer);
        $logger->info($info);
    }

    /**
     * @return string
     */
    public function getCsvFilePath()
    {
        return $this->_rootPath . $this->csvFilePath . $this->csvFileName;
    }

    /**
     * @param $url
     * @return array
     */
    public function readCsv($url)
    {
        $csv_data = [];
        if (substr($url, 0, 7) == 'http://' || substr($url, 0, 8) == 'https://') {
            $csv_string = file_get_contents($url);
            $data = str_getcsv($csv_string, "\n");
            $is_first = 0;
            foreach ($data as $item) {
                $csv_data[] = $item;
            }
        } else {
            $handle = fopen($url, 'r');
            $is_first = 0;
            while (($data = fgetcsv($handle, 1000, ',')) !== false) {
                $csv_data[] = $data;
            }
        }
        return $csv_data;
    }

    /**
     * @param $csvFile
     */
    public function setCsvFileName($csvFile)
    {
        $this->csvFileName = $csvFile;
    }

    /**
     * @return string
     */
    public function getCsvFileName()
    {
        return $this->csvFileName;
    }

    /**
     * @param $csvPath
     */
    public function setCsvFilePath($csvPath)
    {
        $this->csvFilePath = $csvPath;
    }

    public function setLogPath($logPath)
    {
        $this->logPath = $logPath;
    }

    public function getRootPath()
    {
        return $this->_rootPath;
    }

    public function getCountries()
    {
        return $this->_translatedLists->getOptionCountries();
    }


    public function getCountryByName($countryName)
    {

        $countries = $this->getCountries();
        if (empty($countries)) {
            return false;
        }
        $countryName = strtolower($countryName);
        foreach ($countries as $item) {
            if (strtolower($item['label']) == $countryName) {
                $country = $this->_countryFactory->create()->loadByCode($item['value']);
                return $country;
            }

        }
        return false;
    }

    public function getRegion($type, $value, $countryId)
    {
        if ($type == 'code') {
            $region = $this->_regionFactory->create()->loadByCode($value, $countryId);
        } elseif ($type == 'name') {
            $region = $this->_regionFactory->create()->loadByName($value, $countryId);
        }
        return empty($region) ? false : $region;
    }

    public function addNewRegion($countryCode, $locale, $regionCode, $regionName)
    {

        try {
            $objectManager = \Magento\Framework\App\ObjectManager::getInstance(); // Instance of object manager
            $resource = $objectManager->get('Magento\Framework\App\ResourceConnection');
            $connection = $resource->getConnection();
            $tableName = $resource->getTableName('directory_country_region'); //gives table name with prefix

            $sql = "INSERT INTO " . $tableName . " (`region_id`,`country_id`,`code`,`default_name`) VALUES (NULL,?,?,?)";
            $connection->query($sql, [$countryCode, $regionCode, $regionName]);

            // get new region id for next query
            $regionId = $connection->lastInsertId();

            // insert region name
            $tableName = $resource->getTableName('directory_country_region_name'); //gives table name with prefix
            $sql = "INSERT INTO " . $tableName . " (`locale`,`region_id`,`name`) VALUES (?,?,?)";
            $connection->query($sql, [$locale, $regionId, $regionName]);
        } catch (\Exception $e) {

        }
    }

    public function isExistSource($sourceCode)
    {

        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $collection = $objectManager->create('Magento\Inventory\Model\ResourceModel\Source\Collection');
        $collection->addFieldToFilter('source_code', $sourceCode);
        $source = $collection->getFirstItem();
        $sourceData = $source->getData();

        return !empty($sourceData) ? $sourceData['source_code'] : false;
    }



    public function isExistStockSourceLink($stockId, $sourceCode)
    {

        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $collection = $objectManager->create('Magento\Inventory\Model\ResourceModel\StockSourceLink\Collection');
        $collection->addFieldToFilter('source_code', $sourceCode)
            ->addFieldToFilter('stock_id', $stockId);
        $source = $collection->getFirstItem();
        $sourceData = $source->getData();

        return !empty($sourceData) ? $sourceData['link_id'] : false;
    }

    public function isExistSourceItem($sku, $sourceCode)
    {

        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $collection = $objectManager->create('Magento\Inventory\Model\ResourceModel\SourceItem\Collection');
        $collection->addFieldToFilter('source_code', $sourceCode)
            ->addFieldToFilter('sku', $sku);
        $source = $collection->getFirstItem();
        $sourceData = $source->getData();

        return !empty($sourceData) ? $sourceData['source_item_id'] : false;
    }


    public function isExistStock($stockName)
    {

        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $collection = $objectManager->create('Magento\Inventory\Model\ResourceModel\Stock\Collection');
        $collection->addFieldToFilter('name', ['like' => '%' . $stockName . '%']);
        $stock = $collection->getFirstItem();
        $stockData = $stock->getData();

        return !empty($stockData) ? $stockData['stock_id'] : false;
    }

    public function getAllSource()
    {

        $sourceFactory = $this->_sourceFactory->create();
        $collection = $sourceFactory->getCollection(); //Get Collection of module data
        return $collection->getData();
    }

    public function getPaymentMethod()
    {
        $paymentMethods = $this->_paymentConfig->getActiveMethods();
        foreach ($paymentMethods as $payment) {
            if ($payment->isAvailable()) {
                return $payment;
            }
        }
        return false;
    }

    public function getShippingMethodCode()
    {
        $activeCarriers = $this->_shippingConfig->getActiveCarriers();
        foreach ($activeCarriers as $carrierCode => $carrierModel) {
            if ($carrierMethods = $carrierModel->getAllowedMethods()) {
                foreach ($carrierMethods as $methodCode => $method) {
                    return $methodCode;
                }
            }
        }
        return false;
    }
}
