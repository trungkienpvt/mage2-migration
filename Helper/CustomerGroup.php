<?php
namespace Dev69\Migration\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use Dev69\Migration\Helper\UtilityImport as UtilityImport;

use Magento\Framework\App\State;
use Magento\Customer\Model\CustomerFactory;
use Magento\Customer\Model\GroupFactory;
use Magento\Customer\Api\GroupRepositoryInterface;
use Magento\Setup\Exception;

class CustomerGroup extends AbstractHelper
{

    protected $appState;
    protected $customerFactory;
    protected $_utilityImport;
    protected $groupFactory;
    protected $websiteId = 1;
    protected $storeId = 1;
    protected $webParamFactory;
    const DEFAULT_TAX_CLASS_ID = 3;

    public function __construct(
        State $appState,
        GroupFactory $groupFactory,
        CustomerFactory $customerFactory,
        UtilityImport $utilityImport
    ) {
        $this->appState = $appState;
        $this->customerFactory = $customerFactory;
        $this->groupFactory = $groupFactory;
        $this->_utilityImport = $utilityImport;
    }

    public function importProcess()
    {

        $startTime = time();
        $totalRecord = 0;
        $numberInsert = 0;
        $numberFailed = 0;
        $numberUpdate = 0;
        $numberDelete = 0;
        $messageFailed = '';

        $rootPath           = $this->_utilityImport->getRootPath();
        $logPath            = $rootPath . '/var/log/import_customer_group.log';
        $this->_utilityImport->setLogPath($logPath);
        $listGroup = $this->getListCustomerGroupData([]);

        $totalRecord = count($listGroup);
        foreach ($listGroup as $item) {

            try {

                $isExistCustomerGroup = $this->getCustomerGroupByCode($item['code']);
                $result = $this->saveCustomerGroup($item, $isExistCustomerGroup);

                if (!empty($result['type']) && $result['type'] == 'insert') {
                    $numberInsert++;
                } elseif (!empty($result['type']) && $result['type'] == 'update') {
                    $numberUpdate++;
                } else {
                    $numberFailed++;
                    $messageFailed .="Data failed:" . json_encode($item) . PHP_EOL;
                    $messageFailed .="Message failed:{$result['message']}" . PHP_EOL;

                }

            } catch (Exception $e) {
                $numberFailed++;
                $messageFailed .="Data failed:$item" . PHP_EOL;
                $messageFailed .="Message failed:{$e->getMessage()}" . PHP_EOL;

            }

        }

        $arrResult  = [
            'total_record'     => $totalRecord,
            'number_insert'    => $numberInsert,
            'number_update'    => $numberUpdate,
            'number_delete'    => $numberDelete,
            'number_failed'    => $numberFailed,
            'number_success'   => $numberInsert+$numberUpdate,
            'message_failed'   => $messageFailed
        ];

        foreach ($arrResult as $key => $value) {
            $this->_utilityImport->log("$key: $value");
        }

        return $arrResult;
    }

    /**
     * Check if customer group exist by 'customer_group_code'
     *
     * @param $groupCode
     * @return mixed
     */
    public function getCustomerGroupByCode($groupCode)
    {
        $customerGroup = $this->groupFactory->create()
                                    ->getCollection()
                                    ->addFieldToFilter('customer_group_code', $groupCode);
        return ($customerGroup->getSize()) ? $customerGroup : false;
    }


    public function saveCustomerGroup($data, $customerGroup)
    {
        try {
            if (empty($customerGroup)) {
                $customerGroup = $this->groupFactory->create();
                $customerGroup
                    ->setCode($data['code'])
                    ->setData('label', $data['label'])
                    ->setData('short_label', $data['short_label'])
                    ->setTaxClassId(self::DEFAULT_TAX_CLASS_ID)
                ;

                $customerGroup->save();
                $groupId = $customerGroup->getId();
                return [
                    'group_id' => $groupId,
                    'type' => 'update'
                ];
            } else {

                $customerGroup
                    ->setData('label', $data['label'])
                    ->setData('short_label', $data['short_label']);
                $customerGroup->save();
                return [
                    'group_id' => $customerGroup->getId(),
                    'type' => 'insert'
                ];
            }
        } catch (\Exception $e) {
            return [
                'status' => 'failed',
                'message' => $e->getMessage()
            ];

        }
    }

    public function getListCustomerGroupData($data) {
        return [
            [
                'code' => 'A1',
                'short_label' => 'A1',
                'label' => 'Group A1',
            ],
            [
                'code' => 'A2',
                'short_label' => 'A2',
                'label' => 'Group A2',
            ],
            [
                'code' => 'A3',
                'short_label' => 'A3',
                'label' => 'Group A3',
            ],
        ];
    }
}
