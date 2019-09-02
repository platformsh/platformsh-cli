<?php
declare(strict_types=1);

namespace Platformsh\Cli\Service;

use Platformsh\Cli\Util\NestedArrayUtil;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;

class PropertyFormatter implements InputConfiguringInterface
{
    /** @var InputInterface|null */
    protected $input;

    /** @var \Platformsh\Cli\Service\Config */
    protected $config;

    public function __construct(InputInterface $input = null, Config $config = null)
    {
        $this->input = $input;
        $this->config = $config ?: new Config();
    }

    /**
     * @param mixed  $value
     * @param string $property
     *
     * @return string
     */
    public function format($value, ?string $property = null): string
    {
        if ($value === null) {
            return '';
        }

        switch ($property) {
            case 'http_access':
                return $this->formatHttpAccess($value);

            case 'token':
                return '******';

            case 'addon_credentials':
                if (is_array($value) && isset($value['shared_secret'])) {
                    $value['shared_secret'] = '******';
                }
                break;

            case 'app_credentials':
                if (is_array($value) && isset($value['secret'])) {
                    $value['secret'] = '******';
                }
                break;

            case 'author.date':
            case 'committer.date':
            case 'created_at':
            case 'updated_at':
            case 'expires_at':
            case 'started_at':
            case 'completed_at':
            case 'ssl.expires_on':
                $value = $this->formatDate($value);
                break;

            case 'ssl':
                if ($property === 'ssl' && is_array($value) && isset($value['expires_on'])) {
                    $value['expires_on'] = $this->formatDate($value['expires_on']);
                }
        }

        if ($value === null) {
            $value = '';
        } elseif (!is_string($value)) {
            $value = rtrim(Yaml::dump($value, 2));
        }

        return $value;
    }

    /**
     * Add options to a command's input definition.
     *
     * @param InputDefinition $definition
     */
    public function configureInput(InputDefinition $definition): void
    {
        $definition->addOption(new InputOption(
            'date-fmt',
            null,
            InputOption::VALUE_REQUIRED,
            'The date format (as a PHP date format string)',
            // @todo refactor so this can be non-static and use injected config
            (new Config())->getWithDefault('application.date_format', 'c')
        ));
    }

    /**
     * @param string $value
     *
     * @return string|null
     */
    protected function formatDate(string $value): ?string
    {
        $format = null;
        if (isset($this->input) && $this->input->hasOption('date-fmt')) {
            $format = $this->input->getOption('date-fmt');
        }
        if ($format === null) {
            $format = $this->config->getWithDefault('application.date_format', 'c');
        }

        // Workaround for the ssl.expires_on date, which is currently a
        // timestamp in milliseconds.
        if (substr($value, -3) === '000' && strlen($value) === 13) {
            $value = substr($value, 0, 10);
        }

        $timestamp = is_numeric($value) ? $value : strtotime($value);

        return $timestamp === false ? null : date($format, $timestamp);
    }

    /**
     * @param array|string|null $httpAccess
     *
     * @return string
     */
    protected function formatHttpAccess($httpAccess): string
    {
        $info = (array) $httpAccess;
        $info += [
            'addresses' => [],
            'basic_auth' => [],
            'is_enabled' => true,
        ];
        // Hide passwords.
        $info['basic_auth'] = array_map(function () {
            return '******';
        }, $info['basic_auth']);

        return $this->format($info);
    }

    /**
     * Display a complex data structure.
     *
     * @param OutputInterface $output     An output object.
     * @param array           $data       The data to display.
     * @param string|null     $property   The property of the data to display
     *                                    (a dot-separated string).
     */
    public function displayData(OutputInterface $output, array $data, ?string $property = null): void
    {
        $key = null;

        if ($property) {
            $parents = explode('.', $property);
            $key = end($parents);
            $data = NestedArrayUtil::getNestedArrayValue($data, $parents, $keyExists);
            if (!$keyExists) {
                throw new \InvalidArgumentException('Property not found: ' . $property);
            }
        }

        if ($data === null) {
            return;
        } elseif (!is_string($data)) {
            $output->write(Yaml::dump($data, 5, 4, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK));
        } else {
            $output->write($this->format($data, $key));
        }
    }
}
