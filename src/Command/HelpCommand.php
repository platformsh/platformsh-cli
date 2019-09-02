<?php
declare(strict_types=1);
/**
 * @file
 * Override Symfony Console's HelpCommand to customize the appearance of help.
 */

namespace Platformsh\Cli\Command;

use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Console\CustomMarkdownDescriptor;
use Platformsh\Cli\Console\CustomTextDescriptor;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\HelpCommand as ParentHelpCommand;
use Symfony\Component\Console\Helper\DescriptorHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class HelpCommand extends ParentHelpCommand
{
    protected static $defaultName = 'help';

    protected $command;

    /**
     * @inheritdoc
     */
    public function setCommand(Command $command)
    {
        $this->command = $command;
    }

    /**
     * @inheritdoc
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if (null === $this->command) {
            $this->command = $this->getApplication()
                                  ->find($input->getArgument('command_name'));
        }

        $config = new Config();

        $helper = new DescriptorHelper();
        $helper->register('txt', new CustomTextDescriptor($config->get('application.executable')));
        $helper->register('md', new CustomMarkdownDescriptor($config->get('application.executable')));
        $helper->describe(
            $output,
            $this->command,
            [
                'format' => $input->getOption('format'),
                'raw_text' => $input->getOption('raw'),
            ]
        );

        $this->command = null;
    }
}
