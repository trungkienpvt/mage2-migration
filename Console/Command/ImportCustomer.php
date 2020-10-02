<?php
namespace Dev69\Migration\Console\Command;

use Dev69\Migration\Helper\Customer as HelperCustomer;
use Magento\Framework\App\State as AppState;

use Magento\Framework\Console\Cli;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ImportCustomer extends Command
{
    protected $_helperCustomer;
    protected $_state;

    public function __construct(
        \Magento\Framework\App\State $state,
        HelperCustomer $helperCustomer

    )
    {
        $this->_state        = $state;
        $this->_helperCustomer  = $helperCustomer;
        parent::__construct();
    }

    protected function configure(

    )
    {
        $this->setName('import:customer')
            ->setDescription("Import Customers from a CSV file");

        parent::configure();
    }

    /**
     * @param \Symfony\Component\Console\Input\InputInterface $input
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     * @throws \Magento\Framework\Exception\LocalizedException
     * @return null|int null or 0 if everything went fine, or an error code
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
//        $this->_state->setAreaCode(\Magento\Framework\App\Area::AREA_GLOBAL);
        $start_time = time();

        $output->writeln("<comment>Processing import customer...</comment>");
        $importResult   = $this->_helperCustomer->importProcess();

        $output->write("\n");
        if (!empty($importResult))
            foreach ($importResult as $key => $value) {
                $output->writeln("<$key>$value</$key>");
            }

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

        return Cli::RETURN_SUCCESS;

    }

}
