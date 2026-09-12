<?php

namespace App\Support\Security;

use Illuminate\Support\Facades\Crypt;
use LogicException;

final class SensitiveIdentifierCrypto
{
    public function encrypt(string $plaintext): string
    {
        $this->currentLookupKey();

        return Crypt::encryptString($plaintext);
    }

    public function decrypt(string $ciphertext): string
    {
        return Crypt::decryptString($ciphertext);
    }

    public function currentLookupHash(string $normalizedValue): string
    {
        return hash_hmac('sha256', $normalizedValue, $this->currentLookupKey());
    }

    /** @return list<string> */
    public function lookupHashes(string $normalizedValue): array
    {
        $hashes = [];
        foreach ($this->lookupKeys() as $key) {
            $hashes[] = hash_hmac('sha256', $normalizedValue, $key);
        }

        return array_values(array_unique($hashes));
    }

    public function matchesStoredLookupHash(string $normalizedValue, string $storedHash): bool
    {
        foreach ($this->lookupHashes($normalizedValue) as $candidate) {
            if (hash_equals($storedHash, $candidate)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private function lookupKeys(): array
    {
        $keys = [$this->currentLookupKey()];
        $previous = config('security.sensitive_identifiers.previous_lookup_keys', []);
        if (! is_array($previous)) {
            throw new LogicException('Sensitive identifier previous lookup keys must be configured as a list.');
        }

        foreach ($previous as $key) {
            if (! is_string($key) || trim($key) === '') {
                continue;
            }
            $keys[] = $this->validatedKey(trim($key), false);
        }

        return array_values(array_unique($keys));
    }

    private function currentLookupKey(): string
    {
        $configured = config('security.sensitive_identifiers.lookup_key');
        if (! is_string($configured) || trim($configured) === '') {
            throw new LogicException('Sensitive identifier lookup key is unavailable.');
        }
        $key = $this->validatedKey(trim($configured), true);
        $appKey = config('app.key');
        if (is_string($appKey) && $appKey !== '' && hash_equals($appKey, $key)) {
            throw new LogicException('Sensitive identifier lookup key must be independent from APP_KEY.');
        }

        return $key;
    }

    private function validatedKey(string $key, bool $current): string
    {
        if (strlen($key) < 32) {
            throw new LogicException(($current ? 'Current' : 'Previous').' sensitive identifier lookup key must contain at least 32 bytes of secret material.');
        }

        return $key;
    }
}
