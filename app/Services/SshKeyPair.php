<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

class SshKeyPair
{
    /** @return array{private: string, public: string} */
    public function generate(): array
    {
        $options = ['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 3072];
        $bundledConfig = dirname(PHP_BINARY).'/extras/ssl/openssl.cnf';
        if (PHP_OS_FAMILY === 'Windows' && getenv('OPENSSL_CONF') === false && is_file($bundledConfig)) {
            $options['config'] = $bundledConfig;
        }
        $key = openssl_pkey_new($options);
        if ($key === false || ! openssl_pkey_export($key, $privateKey, null, $options)) {
            throw ValidationException::withMessages(['hetzner' => 'Der SSH-Schlüssel konnte nicht erzeugt werden. Bitte die OpenSSL-Konfiguration prüfen.']);
        }
        $details = openssl_pkey_get_details($key);
        if (! is_array($details) || ! isset($details['rsa']['e'], $details['rsa']['n'])) {
            throw ValidationException::withMessages(['hetzner' => 'Der öffentliche SSH-Schlüssel konnte nicht erzeugt werden.']);
        }

        return ['private' => $privateKey, 'public' => 'ssh-rsa '.base64_encode($this->sshString('ssh-rsa')
            .$this->sshInteger($details['rsa']['e']).$this->sshInteger($details['rsa']['n'])).' luanti-portal'];
    }

    private function sshString(string $value): string
    {
        return pack('N', strlen($value)).$value;
    }

    /** Encode a positive SSH mpint, including its sign byte (RFC 4251). */
    private function sshInteger(string $value): string
    {
        return $this->sshString(ord($value[0]) >= 128 ? "\0".$value : $value);
    }
}
