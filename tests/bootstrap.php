<?php

declare(strict_types=1);

/*
 * Minimal stand-ins for the few GLPI core symbols src/Config.php and hook.php
 * touch, so the plugin's security logic can be unit-tested without a full
 * GLPI installation and database. GLPIKey reproduces GLPI's real scheme
 * (XChaCha20-Poly1305 IETF AEAD, base64(nonce . ciphertext)), so tokens,
 * capabilities and document links are exercised with genuine authenticated
 * encryption, not a mock.
 */

namespace {
    if (!class_exists('CommonGLPI')) {
        class CommonGLPI
        {
        }
    }
    if (!class_exists('CommonDBTM')) {
        class CommonDBTM extends CommonGLPI
        {
            public $fields = [];

            /** Row returned for the plugin's single configuration record (id 1). */
            public static array $testConfig = [];

            public function getFromDB($id): bool
            {
                $this->fields = ['id' => 1] + static::$testConfig;
                return true;
            }

            public function add(array $input)
            {
                return 1;
            }
        }
    }
    if (!function_exists('__')) {
        function __($str, $domain = 'glpi')
        {
            return $str;
        }
    }
    if (!class_exists('GLPIKey')) {
        class GLPIKey
        {
            private static ?string $key = null;

            public function __construct()
            {
                self::$key ??= random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES);
            }

            public function encrypt($string): string
            {
                $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
                $cipher = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt((string) $string, $nonce, $nonce, self::$key);
                return base64_encode($nonce . $cipher);
            }

            public function decrypt($string): string
            {
                $raw = base64_decode((string) $string, true);
                $len = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;
                if ($raw === false || strlen($raw) <= $len) {
                    return '';
                }
                $nonce = substr($raw, 0, $len);
                $plain = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(substr($raw, $len), $nonce, $nonce, self::$key);
                return $plain === false ? '' : $plain;
            }
        }
    }

    /** Records every query/update and answers request() through a per-test callback. */
    final class FakeDB
    {
        /** @var array<int, array> */
        public array $queries = [];
        /** @var array<int, array> */
        public array $updates = [];
        /** @var callable|null */
        public $responder = null;

        public function tableExists($table): bool
        {
            return true;
        }

        public function fieldExists($table, $field): bool
        {
            return true;
        }

        public function request($criteria): \ArrayIterator
        {
            $this->queries[] = $criteria;
            $rows = $this->responder ? ($this->responder)($criteria) : [];
            return new \ArrayIterator($rows);
        }

        public function update($table, $values, $where): bool
        {
            $this->updates[] = ['table' => $table, 'values' => $values, 'where' => $where];
            return true;
        }
    }

    $GLOBALS['DB'] = new FakeDB();
}

namespace {
    require_once dirname(__DIR__) . '/src/Config.php';
    require_once dirname(__DIR__) . '/hook.php';
    require_once __DIR__ . '/TestHelpers.php';
}
