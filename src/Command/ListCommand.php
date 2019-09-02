<?php
declare(strict_types=1);
/**
 * @file
 * Override Symfony Console's ListCommand to customize the list's appearance.
 */

namespace Platformsh\Cli\Command;

use Platformsh\Cli\Console\CustomTextDescriptor;
use Symfony\Component\Console\Command\ListCommand as ParentListCommand;
use Symfony\Component\Console\Helper\DescriptorHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ListCommand extends ParentListCommand
{
    protected static $defaultName = 'list';

    protected function configure()
    {
        parent::configure();
        $this->addOption('all', null, InputOption::VALUE_NONE, 'Show all commands, including hidden ones');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $helper = new DescriptorHelper();
        $helper->register('txt', new CustomTextDescriptor());
        $helper->describe(
            $output,
            $this->getApplication(),
            [
                'format' => $input->getOption('format'),
                'raw_text' => $input->getOption('raw'),
                'namespace' => $input->getArgument('namespace'),
                'all' => $input->getOption('all'),
            ]
        );
    }
}
