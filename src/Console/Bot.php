<?php
declare(strict_types=1);

namespace Platformsh\Cli\Console;

use Symfony\Component\Console\Output\OutputInterface;

class Bot extends Animation
{
    /**
     * @param OutputInterface $output
     * @param string          $signature
     */
    public function __construct(OutputInterface $output, $signature = '')
    {
        $filenames = [
            CLI_ROOT . '/resources/bot/bot1',
            CLI_ROOT . '/resources/bot/bot2',
            CLI_ROOT . '/resources/bot/bot3',
            CLI_ROOT . '/resources/bot/bot4',
        ];

        $indent = '    ';
        if (strlen($signature) > 0) {
            $signatureIndent = str_repeat(' ', strlen($indent) + 5 - floor(strlen($signature) / 2));
            $signature = "\n" . $signatureIndent . '<info>' . $signature . '</info>';
        }

        // The frames are the contents of each file, with each line indented.
        $frames = array_map(function ($filename) use ($indent, $signature) {
            return preg_replace('/^/m', $indent, file_get_contents($filename))
                . $signature;
        }, $filenames);

        parent::__construct($output, $frames);
    }
}
