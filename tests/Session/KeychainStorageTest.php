<?php
declare(strict_types=1);

namespace Platformsh\Cli\Tests;

use PHPUnit\Framework\TestCase;
use Platformsh\Cli\Session\KeychainStorage;
use Platformsh\Client\Session\Session;

class KeychainStorageTest extends TestCase
{
    public function testKeyChainStorage()
    {
        if (!KeychainStorage::isSupported()) {
            $this->markTestIncomplete('Skipping keychain test (not supported on this system)');
            return;
        }

        // Set up the session.
        $testData = ['foo' => 'bar', '1' => ['2' => '3']];
        $keychain = new KeychainStorage('platformsh-cli-test');
        $session = new Session();
        $session->setStorage($keychain);

        // Save data.
        foreach ($testData as $key => $value) {
            $session->set($key, $value);
        }
        $session->save();

        // Reset the session, reload from the keychain, and check session data.
        $session = new Session('default', [], $keychain);
        foreach ($testData as $key => $value) {
            $this->assertEquals($value, $session->get($key));
        }

        // Delete data from the keychain, reset the session, and check the
        // session is empty.
        $keychain->deleteAll();
        $session = new Session('default', [], $keychain);
        $this->assertEquals(null, $session->get('foo'));
    }
}
