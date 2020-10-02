<?php
namespace Dev69\Migration\Model\Sales\Order;

use Aheadworks\Sarp2\Api\Data\ProfileInterface;
use Aheadworks\Sarp2\Api\Data\ProfileItemInterface;
use Magento\Catalog\Model\Indexer\Product\Price\Processor as PriceIndexerProcessor;
use Magento\CatalogInventory\Api\StockManagementInterface;
use Magento\CatalogInventory\Model\Indexer\Stock\Processor as StockIndexerProcessor;
use Magento\CatalogInventory\Model\StockManagement;
use Magento\CatalogInventory\Observer\ItemsForReindex;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Class InventoryManagement
 * @package Aheadworks\Sarp2\Model\Sales\Order
 */
class InventoryManagement extends \Aheadworks\Sarp2\Model\Sales\Order\InventoryManagement
{


    /**
     * @var StockManagementInterface|StockManagement
     */
    private $stockManagement;

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var ItemsForReindex
     */
    private $itemsForReindex;

    /**
     * @var StockIndexerProcessor
     */
    private $stockIndexerProcessor;

    /**
     * @var PriceIndexerProcessor
     */
    private $priceIndexerProcessor;

    /**
     * @param StockManagementInterface $stockManagement
     * @param StoreManagerInterface $storeManager
     * @param ItemsForReindex $itemsForReindex
     * @param StockIndexerProcessor $stockIndexerProcessor
     * @param PriceIndexerProcessor $priceIndexerProcessor
     */
    public function __construct(
        StockManagementInterface $stockManagement,
        StoreManagerInterface $storeManager,
        ItemsForReindex $itemsForReindex,
        StockIndexerProcessor $stockIndexerProcessor,
        PriceIndexerProcessor $priceIndexerProcessor
    ) {

        $this->stockManagement = $stockManagement;
        $this->storeManager = $storeManager;
        $this->itemsForReindex = $itemsForReindex;
        $this->stockIndexerProcessor = $stockIndexerProcessor;
        $this->priceIndexerProcessor = $priceIndexerProcessor;
    }

    /**
     * Subtract profile items quantities from stock items related with profile items products
     *
     * @param ProfileInterface $profile
     * @return void
     */
    public function subtract(ProfileInterface $profile)
    {
        $websiteId = $this->storeManager->getStore($profile->getStoreId())
            ->getWebsiteId();
        $itemsForReindex = $this->stockManagement->registerProductsSale(
            $this->getProductQuantities($profile->getItems()),
            $websiteId
        );
        $this->itemsForReindex->setItems($itemsForReindex);
    }

    /**
     * Revert profile items inventory data
     *
     * @param ProfileInterface $profile
     * @return void
     */
    public function revert(ProfileInterface $profile)
    {


        $websiteId = $this->storeManager->getStore($profile->getStoreId())
            ->getWebsiteId();
        $quantities = $this->getProductQuantities($profile->getItems());
//        $this->stockManagement->revertProductsSale($quantities, $websiteId);
        $productIds = array_keys($quantities);
        if (!empty($productIds)) {
            $this->stockIndexerProcessor->reindexList($productIds);
            $this->priceIndexerProcessor->reindexList($productIds);
        }
    }

    /**
     * Get product quantities
     *
     * @param ProfileItemInterface[] $items
     * @return array
     */
    private function getProductQuantities(array $items)
    {
        $parentIds = [];
        $qtyArray = [];
        foreach ($items as $item) {
            $parentItemId = $item->getParentItemId();
            if ($parentItemId) {
                $this->addItemToQtyArray($item, $qtyArray);
                $parentIds[] = $parentItemId;
            }
        }
        foreach ($items as $item) {
            if (!$item->getParentItemId()
                && !in_array($item->getItemId(), $parentIds)
            ) {
                $this->addItemToQtyArray($item, $qtyArray);
            }
        }
        return $qtyArray;
    }

    /**
     * Adds profile item qty to qty array (creates new entry or increments existing one)
     *
     * @param ProfileItemInterface $profileItem
     * @param array &$qtyArray
     * @return void
     */
    private function addItemToQtyArray(ProfileItemInterface $profileItem, &$qtyArray)
    {
        $productId = $profileItem->getProductId();
        if ($productId) {
            if (isset($qtyArray[$productId])) {
                $qtyArray[$productId] += $profileItem->getQty();
            } else {
                $qtyArray[$productId] = $profileItem->getQty();
            }
        }
    }


}
