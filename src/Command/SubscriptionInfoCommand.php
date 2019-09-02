<?php
declare(strict_types=1);

namespace Platformsh\Cli\Command;

use Platformsh\Cli\Console\AdaptiveTableCell;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\PropertyFormatter;
use Platformsh\Cli\Service\QuestionHelper;
use Platformsh\Cli\Service\Selector;
use Platformsh\Cli\Service\Table;
use Platformsh\Client\Model\Subscription;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class SubscriptionInfoCommand extends CommandBase
{
    protected static $defaultName = 'subscription:info';

    private $api;
    private $formatter;
    private $questionHelper;
    private $selector;
    private $table;

    public function __construct(
        Api $api,
        PropertyFormatter $formatter,
        QuestionHelper $questionHelper,
        Selector $selector,
        Table $table
    ) {
        $this->api = $api;
        $this->formatter = $formatter;
        $this->questionHelper = $questionHelper;
        $this->selector = $selector;
        $this->table = $table;
        parent::__construct();
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->addArgument('property', InputArgument::OPTIONAL, 'The name of the property')
            ->addArgument('value', InputArgument::OPTIONAL, 'Set a new value for the property')
            ->addOption('id', 's', InputOption::VALUE_REQUIRED, 'The subscription ID')
            ->setDescription('Read or modify subscription properties');
        $this->setHidden(true);

        $definition = $this->getDefinition();
        $this->formatter->configureInput($definition);
        $this->table->configureInput($definition);
        $this->selector->addProjectOption($definition);

        $this->addExample('View all subscription properties')
             ->addExample('View the subscription status', 'status')
             ->addExample('View the storage limit (in MiB)', 'storage');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $id = $input->getOption('id');
        if (empty($id)) {
            $selection = $this->selector->getSelection($input);
            $project = $selection->getProject();
            $id = $project->getSubscriptionId();
        }

        $subscription = $this->api->getClient()
                             ->getSubscription($id);
        if (!$subscription) {
            $this->stdErr->writeln(sprintf('Subscription not found: <error>%s</error>', $id));

            return 1;
        }
        $property = $input->getArgument('property');

        if (!$property) {
            return $this->listProperties($subscription);
        }

        $value = $input->getArgument('value');
        if ($value !== null) {
            return $this->setProperty($property, $value, $subscription);
        }

        switch ($property) {
            case 'url':
                $value = $subscription->getUri(true);
                break;

            default:
                $value = $this->api->getNestedProperty($subscription, $property);
        }

        $output->write($this->formatter->format($value, $property));

        return 0;
    }

    /**
     * @param Subscription $subscription
     *
     * @return int
     */
    protected function listProperties(Subscription $subscription)
    {
        $headings = [];
        $values = [];
        foreach ($subscription->getProperties() as $key => $value) {
            $headings[] = new AdaptiveTableCell($key, ['wrap' => false]);
            $values[] = $this->formatter->format($value, $key);
        }
        $this->table->renderSimple($values, $headings);

        return 0;
    }

    /**
     * @param string       $property
     * @param string       $value
     * @param Subscription $subscription
     *
     * @return int
     */
    protected function setProperty($property, $value, Subscription $subscription)
    {
        $type = $this->getType($property);
        if (!$type) {
            $this->stdErr->writeln("Property not writable: <error>$property</error>");
            return 1;
        }
        if ($type === 'boolean' && $value === 'false') {
            $value = false;
        }
        settype($value, $type);
        $currentValue = $subscription->getProperty($property);
        if ($currentValue === $value) {
            $this->stdErr->writeln(
                "Property <info>$property</info> already set as: " . $this->formatter->format($value, $property)
            );

            return 0;
        }

        $confirmMessage = sprintf(
            "Are you sure you want to change property '%s' from <comment>%s</comment> to <comment>%s</comment>?",
            $property,
            $this->formatter->format($currentValue, $property),
            $this->formatter->format($value, $property)
        );
        $warning = sprintf(
            '<comment>This action may %s the cost of your subscription.</comment>',
            is_numeric($value) && $value > $currentValue ? 'increase' : 'change'
        );
        $confirmMessage = $warning . "\n" . $confirmMessage;
        if (!$this->questionHelper->confirm($confirmMessage)) {
            return 1;
        }

        $subscription->update([$property => $value]);
        $this->stdErr->writeln(sprintf(
            'Property <info>%s</info> set to: %s',
            $property,
            $this->formatter->format($value, $property)
        ));

        return 0;
    }

    /**
     * Get the type of a writable property.
     *
     * @param string $property
     *
     * @return string|false
     */
    protected function getType($property)
    {
        $writableProperties = ['plan' => 'string', 'environments' => 'int', 'storage' => 'int'];

        return isset($writableProperties[$property]) ? $writableProperties[$property] : false;
    }
}
