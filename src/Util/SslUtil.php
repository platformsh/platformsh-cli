<?php
declare(strict_types=1);

namespace Platformsh\Cli\Util;

class SslUtil
{
    /**
     * @param string $certPath
     * @param string $keyPath
     * @param array  $chainPaths
     *
     * @return array
     *   An array containing the contents of the certificate files, keyed as
     *   'certificate' (string), 'key' (string), and 'chain' (array).
     */
    public function validate($certPath, $keyPath, array $chainPaths)
    {
        // Get the contents.
        if (!is_readable($certPath)) {
            throw new \InvalidArgumentException('The certificate file could not be read: ' . $certPath);
        }
        $sslCert = trim(file_get_contents($certPath));
        // Do a bit of validation.
        $certResource = openssl_x509_read($sslCert);
        if (!$certResource) {
            throw new \InvalidArgumentException('The certificate file is not a valid X509 certificate: ' . $certPath);
        }
        // Then the key. Does it match?
        if (!is_readable($keyPath)) {
            throw new \InvalidArgumentException('The private key file could not be read: ' . $keyPath);
        }
        $sslPrivateKey = trim(file_get_contents($keyPath));
        $keyResource = openssl_pkey_get_private($sslPrivateKey);
        if (!$keyResource) {
            throw new \InvalidArgumentException('Private key not valid, or passphrase-protected: ' . $keyPath);
        }
        $keyMatch = openssl_x509_check_private_key($certResource, $keyResource);
        if (!$keyMatch) {
            throw new \InvalidArgumentException('The provided certificate does not match the provided private key.');
        }

        // Each chain needs to contain one or more valid certificates.
        $chainFileContents = $this->readChainFiles($chainPaths);
        foreach ($chainFileContents as $filePath => $data) {
            $chainResource = openssl_x509_read($data);
            if (!$chainResource) {
                throw new \InvalidArgumentException('File contains an invalid X509 certificate: ' . $filePath);
            }
            openssl_x509_free($chainResource);
        }

        // Split up the chain file contents.
        $chain = [];
        $begin = '-----BEGIN CERTIFICATE-----';
        foreach ($chainFileContents as $data) {
            if (substr_count($data, $begin) > 1) {
                foreach (explode($begin, $data) as $cert) {
                    $chain[] = $begin . $cert;
                }
            } else {
                $chain[] = $data;
            }
        }

        return [
            'certificate' => $sslCert,
            'key' => $sslPrivateKey,
            'chain' => $chain,
        ];
    }

    /**
     * Get the contents of multiple chain files.
     *
     * @param string[] $chainPaths
     *
     * @throws \Exception If any one of the files is not readable.
     *
     * @return array
     *   An array of file contents (whitespace trimmed) keyed by file name.
     */
    protected function readChainFiles(array $chainPaths)
    {
        $chainFiles = [];
        foreach ($chainPaths as $chainPath) {
            if (!is_readable($chainPath)) {
                throw new \Exception("The chain file could not be read: $chainPath");
            }
            $chainFiles[$chainPath] = trim(file_get_contents($chainPath));
        }

        return $chainFiles;
    }
}
