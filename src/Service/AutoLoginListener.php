<?php
declare(strict_types=1);

namespace Platformsh\Cli\Service;

use Platformsh\Cli\Exception\LoginRequiredException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class AutoLoginListener
{
    private $commandDispatcher;
    private $config;
    private $input;
    private $output;
    private $stdErr;
    private $questionHelper;
    private $urlService;

    public function __construct(
        SubCommandRunner $commandDispatcher,
        Config $config,
        InputInterface $input,
        OutputInterface $output,
        QuestionHelper $questionHelper,
        Url $urlService
    ) {
        $this->commandDispatcher = $commandDispatcher;
        $this->config = $config;
        $this->input = $input;
        $this->output = $output;
        $this->stdErr = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput(): $output;
        $this->questionHelper = $questionHelper;
        $this->urlService = $urlService;
    }

    /**
     * Log in the user.
     *
     * This is called via the 'login.required' event.
     *
     * @see Api::getClient()
     */
    public function onLoginRequired()
    {
        if (!$this->input->isInteractive()) {
            throw new LoginRequiredException();
        }
        $method = $this->config->getWithDefault('application.login_method', 'browser');
        if ($method === 'browser') {
            if ($this->urlService->canOpenUrls()
                && $this->questionHelper->confirm("Authentication is required.\nLog in via a browser?")) {
                $this->stdErr->writeln('');
                $exitCode = $this->commandDispatcher
                    ->run('auth:browser-login');
                $this->stdErr->writeln('');
                if ($exitCode === 0) {
                    return;
                }
            }
        } elseif ($method === 'password') {
            $exitCode = $this->commandDispatcher
                ->run('auth:password-login');
            $this->stdErr->writeln('');
            if ($exitCode === 0) {
                return;
            }
        }
        throw new LoginRequiredException();
    }
}
