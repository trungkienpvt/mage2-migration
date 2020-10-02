<?php

namespace Dev69\Migration\Console\Command;

use Dev69\Migration\Helper\CustomerGroup as HelperCustomerGroup;
use Magento\Framework\App\State;

use Magento\Framework\Console\Cli;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ImportCustomerGroup extends Command
{

    protected $appState;
    protected $_helperCustomerGroup;

    public function __construct(
        State $appState,
        HelperCustomerGroup $helperCustomerGroup
    )
    {
        $this->appState = $appState;
        $this->_helperCustomerGroup = $helperCustomerGroup;

        parent::__construct();
    }

    protected function configure()
    {
        $this->setName('import:customer:group')
            ->setDescription("Import Customer Group");

        parent::configure();
    }


    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $start_time = time();

        $output->setDecorated(true);
//        $this->appState->setAreaCode(\Magento\Framework\App\Area::AREA_GLOBAL);
        $importResult = [];
        try {
            $output->writeln("<comment>Processing import customer group...</comment>");
            $importResult   = $this->_helperCustomerGroup->importProcess();
        } catch (\Exception $e) {
            $output->writeln("<error>{$e->getMessage()}</error>");
            return Cli::RETURN_FAILURE;
        }

        $output->write("\n");
        foreach ($importResult as $key => $value) {
            $output->writeln("<info><$key>$value</$key></info>");
        }
        $output->write("\n");

        $end_time = time();
        $times = (int)($end_time - $start_time);
        echo "|========= TIME INFO =================|" .PHP_EOL;
        if($times < 61){
            echo '|Time execute:'.$times.' seconds' . PHP_EOL;
        }else{
            $minutes =  (int)($times / 60);
            $seconds =  $times % 60;
            echo '|Time execute: '.$minutes.' minutes '.$seconds. ' seconds' . PHP_EOL;
        }
        echo '|=====================================|' .PHP_EOL;
        $output->write("\n");

        return Cli::RETURN_SUCCESS;

    }

}
