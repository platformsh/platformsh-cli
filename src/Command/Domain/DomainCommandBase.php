<?php
declare(strict_types=1);

namespace Platformsh\Cli\Command\Domain;

use GuzzleHttp\Exception\ClientException;
use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Util\SslUtil;
use Platformsh\Client\Model\Project;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

abstract class DomainCommandBase extends CommandBase
{

    // The final array of SSL options for the client parameters.
    protected $sslOptions = [];

    protected $domainName;

    /**
     * @param InputInterface $input
     *
     * @return bool
     */
    protected function validateDomainInput(InputInterface $input)
    {
        $this->domainName = $input->getArgument('name');
        if (!$this->validDomain($this->domainName)) {
            $this->stdErr->writeln("You must specify a <error>valid domain name</error>");

            return false;
        }

        $certPath = $input->getOption('cert');
        $keyPath = $input->getOption('key');
        $chainPaths = $input->getOption('chain');
        if ($certPath || $keyPath || $chainPaths) {
            if (!isset($certPath, $keyPath)) {
                $this->stdErr->writeln("Both the --cert and the --key are required for SSL certificates");
                return false;
            }
            try {
                $this->sslOptions = (new SslUtil())->validate($certPath, $keyPath, $chainPaths);
            } catch (\InvalidArgumentException $e) {
                $this->stdErr->writeln($e->getMessage());
                return false;
            }

            return true;
        }

        return true;
    }

    protected function addDomainOptions()
    {
        $this->addArgument('name', InputArgument::REQUIRED, 'The domain name')
             ->addOption('cert', null, InputOption::VALUE_REQUIRED, 'The path to the certificate file for this domain')
             ->addOption('key', null, InputOption::VALUE_REQUIRED, 'The path to the private key file for the provided certificate.')
             ->addOption('chain', null, InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED, 'The path to the certificate chain file or files for the provided certificate');
    }

    /**
     * Validate a domain.
     *
     * @param string $domain
     *
     * @return bool
     */
    protected function validDomain($domain)
    {
        // @todo: Use symfony/Validator here once it gets the ability to validate just domain.
        return (bool) preg_match('/^([^\.]{1,63}\.)+[^\.]{2,63}$/', $domain);
    }

    /**
     * Output a clear explanation for domains API errors.
     *
     * @param ClientException $e
     * @param Project         $project
     *
     * @throws ClientException If it can't be explained.
     */
    protected function handleApiException(ClientException $e, Project $project)
    {
        $response = $e->getResponse();
        if ($response !== null && $response->getStatusCode() === 403) {
            $project->ensureFull();
            $data = $project->getData();
            if (!$project->hasLink('#manage-domains')
                && !empty($data['subscription']['plan'])
                && $data['subscription']['plan'] === 'development') {
                $this->stdErr->writeln('This project is on a Development plan. Upgrade the plan to add domains.');
            }
        } else {
            throw $e;
        }
    }
}
