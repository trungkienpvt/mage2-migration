<?php

namespace Dev69\Migration\Setup\Patch\Data;

use Aheadworks\Sarp2\Api\Data\PlanTitleInterfaceFactory;
use Magento\Eav\Setup\EavSetup;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Aheadworks\Sarp2\Api\Data\PlanInterfaceFactory;
use Aheadworks\Sarp2\Api\Data\PlanDefinitionInterfaceFactory;
use Aheadworks\Sarp2\Api\Data\PlanTitleInterface;
use Aheadworks\Sarp2\Model\ResourceModel\Plan as PlanResource;
use Aheadworks\Sarp2\Model\PlanRepository;
use Magento\Setup\Exception;
use \Psr\Log\LoggerInterface;

class ImportProduct implements DataPatchInterface
{
    const INITIAL_FEE = 1;
    const REGULAR_PRICE_PATTERN_PERCENT = 20;
    const TRIAL_PRICE_PATTERN_PERCENT = 20;
    const PRICE_ROUNDING = 1;
    const SORT_ORDER = 2;
    const DEFAULT_ATTRIBUTE_SET = 4;
    /** @var ModuleDataSetupInterface */
    private $moduleDataSetup;

    /** @var EavSetupFactory */
    private $eavSetupFactory;
    private $planFactory;
    private $planDefinitionFactory;
    private $planTitleFactory;
    private $logger;
    private $_storeManager;
    private $_storeRepository;
    private $_productFactory;
    private $_planResource;
    private $_planRepository;
    private $state;

    /**
     * @param ModuleDataSetupInterface $moduleDataSetup
     * @param EavSetupFactory $eavSetupFactory
     */
    public function __construct(
        ModuleDataSetupInterface $moduleDataSetup,
        EavSetupFactory $eavSetupFactory,
        PlanInterfaceFactory $planFactory,
        PlanDefinitionInterfaceFactory $planDefinitionFactory,
        PlanResource $planResource,
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        \Magento\Store\Model\StoreRepository $storeRepository,
        \Magento\Catalog\Api\Data\ProductInterfaceFactory $productFactory,
        PlanTitleInterfaceFactory $planTitle,
        PlanRepository $planRepository,
        LoggerInterface $log,
        \Magento\Framework\App\State $state
    ) {
        $this->moduleDataSetup = $moduleDataSetup;
        $this->eavSetupFactory = $eavSetupFactory;
        $this->planFactory = $planFactory;
        $this->planDefinitionFactory = $planDefinitionFactory;
        $this->planTitleFactory = $planTitle;
        $this->logger = $log;
        $this->_storeRepository = $storeRepository;
        $this->_productFactory = $productFactory;
        $this->_planResource = $planResource;
        $this->_planRepository = $planRepository;
        $this->state = $state;
    }

    /**
     * {@inheritdoc}
     */
    public function apply()
    {

        $this->state->setAreaCode(\Magento\Framework\App\Area::AREA_FRONTEND);
        /** @var EavSetup $eavSetup */
        $eavSetup = $this->eavSetupFactory->create(['setup' => $this->moduleDataSetup]);
        $plan = $this->createPlan();

        if (!empty($plan)) {
            $this->importProduct($plan->getPlanId());
        } else {
            $this->logger->error('Cannot create plan:');
            exit;
        }
    }

    private function createPlan()
    {

        $planDefination = $this->planDefinitionFactory->create();

        $planDefination->setBillingFrequency(6);
        $planDefination->setBillingPeriod('week');
        $planDefination->setIsTrialPeriodEnabled(0);
        $planDefination->setTrialBillingFrequency(0);
        $planDefination->setTrialBillingPeriod('week');

        try {
            $plan = $this->planFactory->create();
            $planTitleText = 'Package 6 week';
            $plan->setDefinition($planDefination);
            $plan->setName($planTitleText);
            $plan->setRegularPricePatternPercent(self::REGULAR_PRICE_PATTERN_PERCENT);
            $plan->setTrialPricePatternPercent(self::TRIAL_PRICE_PATTERN_PERCENT);
            $plan->setStatus(\Aheadworks\Sarp2\Model\Plan\Source\Status::ENABLED);
            $plan->setPriceRounding(self::PRICE_ROUNDING);
            $plan->setSortOrder(self::SORT_ORDER);
            $planTitle = $this->planTitleFactory->create();
            $stores = $this->getAllStore();
            $planTitle->setTitle($planTitleText);
            $planTitle->setStoreId($stores[0]);
            $plan->setTitles([$planTitle]);
            $planInserted = $this->_planRepository->save($plan);
            return $planInserted;

        } catch (Exception $e) {

            $this->logger->error('Error create plan:' . $e->getMessage());
            return false;

        }
    }

    private function importProduct($planId)
    {

        $arrData = [
            [
                'sku' => 'BSTAB01-8',
                'price' => 21.9,
                'title' => 'Biostime SN-2 Bio Plus – 1er âge',
                'qty' => 100
            ],
            [
                'sku' => 'BSTAB02-8',
                'price' => 19.35,
                'title' => 'Biostime SN-2 Bio Plus – 2ème âge : Lait de suite biologique en poudre  10.00% Off Auto renew',
                'qty' => 100
            ],
            [
                'sku' => 'BSTAB03-8',
                'price' => 18.81,
                'title' => 'Biostime SN2 Bio Plus – 3ème âge : Lait de croissance biologique en poudre  10.00% Off Auto renew',
                'qty' => 100
            ],

        ];

        foreach ($arrData as $item) {
            try {

                $product = $this->_productFactory->create();
                $productId = $product->getIdBySku($item['sku']);
                if (empty($productId)) {
                    $product->setSku($item['sku']);
                    $product->setName($item['title']);
                    $product->setAttributeSetId(self::DEFAULT_ATTRIBUTE_SET);
                    $product->setStatus(\Magento\Catalog\Model\Product\Attribute\Source\Status::STATUS_ENABLED);
                    $product->setWeight(10);
                    $product->setVisibility(\Magento\Catalog\Model\Product\Visibility::VISIBILITY_BOTH);
                    $product->setTaxClassId(0);
                    $product->setTypeId(\Magento\Catalog\Model\Product\Type::TYPE_SIMPLE);
                    $product->setPrice($item['price']);
                    $product->setStockData(
                        [
                            'use_config_manage_stock' => 0,
                            'manage_stock' => 1,
                            'is_in_stock' => 1,
                            'qty' => $item['qty']
                        ]
                    );
                    $product->save();
                    $productId = $product->getId();
                    $this->assignPlanToProduct($productId, $item['price'], $planId, self::INITIAL_FEE);
                }

            } catch (Exception $e) {
                $this->logger->error('Error import product: ' . $e->getMessage());
            }

        }
    }

    private function assignPlanToProduct(
        $productId,
        $productPrice,
        $planId,
        $initial_fee
    ) {
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance(); // Instance of object manager
        $resource = $objectManager->get('Magento\Framework\App\ResourceConnection');
        $connection = $resource->getConnection();
        $tableName = $resource->getTableName('aw_sarp2_subscription_option'); //gives table name with prefix

        //Select Data from table
        $sql = "SELECT * FROM " . $tableName." Where product_id = $productId AND plan_id = $planId";
        $result = $connection->fetchAll($sql); // gives associated array, table fields as key in array.

        $plan = $this->_planRepository->get($planId);
        if (empty($plan)) {
            $this->logger->error('Not exist plan with plan id: ' . $planId);
            return;
        }
        $regularPricePatternPercent = $plan->getRegularPricePatternPercent();
        $trialPricePatternPercent = $plan->getTrialPricePatternPercent();

        $trialPrice = ($productPrice/100) * $trialPricePatternPercent;
        $regularPrice = ($productPrice/100) * $regularPricePatternPercent;
        try {

            if (empty($result)) {
                $sql = "
                INSERT INTO " . $tableName."
                SET
                    product_id = $productId,
                    plan_id = $planId,
                    initial_fee=$initial_fee,
                    trial_price=$trialPrice,
                    regular_price=$regularPrice";
                $result = $connection->query($sql);
            } else {
                $sql = "
                UPDATE " . $tableName."
                (initial_fee, initial_fee, trial_price, regular_price)VALUES($initial_fee, $trialPrice, $regularPrice)
                WHERE  product_id = $productId AND plan_id = $planId";
                $result = $connection->query($sql);

            }
        } catch (Exception $e) {
            $this->logger->error("Error assign plan to product:[product_id:$productId,message:{$e->getMessage()}] ");
        }
    }

    private function getAllStore()
    {
        $stores = $this->_storeRepository->getList();
        $listStores = [];
        foreach ($stores as $store) {
            $listStores[] = $store['website_id'];
        }
        return $listStores;
    }

    /**
     * {@inheritdoc}
     */
    public static function getDependencies()
    {
        return [];
    }

    /**
     * {@inheritdoc}
     */
    public function getAliases()
    {
        return [];
    }

    /**
     * {@inheritdoc}
     */
    public static function getVersion()
    {
        return '2.0.1';
    }
}
