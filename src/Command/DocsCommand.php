<?php
declare(strict_types=1);

namespace Platformsh\Cli\Command;

use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\Url;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class DocsCommand extends CommandBase
{
    protected static $defaultName = 'docs';

    private $config;
    private $urlService;

    public function __construct(
        Config $config,
        Url $urlService
    ) {
        $this->config = $config;
        $this->urlService = $urlService;
        parent::__construct();
    }

    protected function configure()
    {
        $this->setDescription('Open the online documentation')
            ->addArgument('search', InputArgument::IS_ARRAY, 'Search term(s)');
        $this->addExample('Search for information about the CLI', 'CLI');
        $this->urlService->configureInput($this->getDefinition());
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if ($searchArguments = $input->getArgument('search')) {
            $query = $this->getSearchQuery($searchArguments);
            $url = str_replace('{{ terms }}', rawurlencode($query), $this->config->get('service.docs_search_url'));
        } else {
            $url = $this->config->get('service.docs_url');
        }

        $this->urlService->openUrl($url);
    }

    /**
     * Turn a list of command arguments into a search query.
     *
     * Arguments containing a space would have been quoted on the command line,
     * so quotes are added again here.
     *
     * @param string[] $args
     *
     * @return string
     */
    protected function getSearchQuery(array $args)
    {
        return implode(' ', array_map(function ($term) {
            return strpos($term, ' ') ? '"' . $term . '"' : $term;
        }, $args));
    }
}
