<?php declare(strict_types=1);

namespace Platformsh\Cli\Service;

use Platformsh\Cli\Model\Host\LocalHost;
use Platformsh\Cli\Model\Host\RemoteHost;

class HostFactory {
    private $shell;
    private $ssh;

    public function __construct(Shell $shell, Ssh $ssh) {
        $this->shell = $shell;
        $this->ssh = $ssh;
    }

    public function local() {
        return new LocalHost($this->shell);
    }

    public function remote(string $sshUrl) {
        return new RemoteHost($sshUrl, $this->ssh, $this->shell);
    }
}
