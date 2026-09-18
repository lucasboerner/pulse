<?php

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

if (file_exists(dirname(__DIR__).'/config/bootstrap.php')) {
    require dirname(__DIR__).'/config/bootstrap.php';
} elseif (method_exists(Dotenv::class, 'bootEnv')) {
    (new Dotenv())->bootEnv(dirname(__DIR__).'/.env');
}

if ($_SERVER['APP_DEBUG']) {
    umask(0000);
}

// The JWT keypair is gitignored, so it is absent on a fresh checkout (CI). Mint a
// throwaway RSA pair for the test run when it is missing, encrypted with the
// configured passphrase so lexik can load it. Locally the real keys already exist,
// so this is skipped.
$jwtDir = dirname(__DIR__).'/config/jwt';
$privateKeyPath = $jwtDir.'/private.pem';
$publicKeyPath = $jwtDir.'/public.pem';

if (false === is_file($privateKeyPath) || false === is_file($publicKeyPath)) {
    if (false === is_dir($jwtDir)) {
        mkdir($jwtDir, 0o755, true);
    }

    $passphrase = (string) ($_ENV['JWT_PASSPHRASE'] ?? $_SERVER['JWT_PASSPHRASE'] ?? '');
    $key = openssl_pkey_new([
        'private_key_bits' => 4096,
        'private_key_type' => \OPENSSL_KEYTYPE_RSA,
    ]);

    if (false !== $key) {
        openssl_pkey_export($key, $privateKey, '' === $passphrase ? null : $passphrase);
        file_put_contents($privateKeyPath, $privateKey);

        $details = openssl_pkey_get_details($key);
        if (false !== $details) {
            file_put_contents($publicKeyPath, $details['key']);
        }
    }
}
