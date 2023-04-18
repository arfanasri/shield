<?php

declare(strict_types=1);

namespace CodeIgniter\Shield\Authentication\JWT\Adapters;

use CodeIgniter\Shield\Authentication\JWT\Exceptions\InvalidTokenException;
use CodeIgniter\Shield\Authentication\JWT\JWSAdapterInterface;
use CodeIgniter\Shield\Config\AuthJWT;
use CodeIgniter\Shield\Exceptions\InvalidArgumentException as ShieldInvalidArgumentException;
use CodeIgniter\Shield\Exceptions\LogicException;
use DomainException;
use Firebase\JWT\BeforeValidException;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\SignatureInvalidException;
use InvalidArgumentException;
use stdClass;
use UnexpectedValueException;

class FirebaseAdapter implements JWSAdapterInterface
{
    /**
     * {@inheritDoc}
     */
    public static function decode(string $encodedToken, $keyset): stdClass
    {
        $keys = self::createKeys($keyset);

        try {
            return JWT::decode($encodedToken, $keys);
        } catch (InvalidArgumentException $e) {
            // provided key/key-array is empty or malformed.
            throw new ShieldInvalidArgumentException(
                'Invalid Keyset: "' . $keyset . '". ' . $e->getMessage(),
                0,
                $e
            );
        } catch (DomainException $e) {
            // provided algorithm is unsupported OR
            // provided key is invalid OR
            // unknown error thrown in openSSL or libsodium OR
            // libsodium is required but not available.
            throw new LogicException('Cannot decode JWT: ' . $e->getMessage(), 0, $e);
        } catch (SignatureInvalidException $e) {
            // provided JWT signature verification failed.
            throw InvalidTokenException::forInvalidToken($e);
        } catch (BeforeValidException $e) {
            // provided JWT is trying to be used before "nbf" claim OR
            // provided JWT is trying to be used before "iat" claim.
            throw InvalidTokenException::forBeforeValidToken($e);
        } catch (ExpiredException $e) {
            // provided JWT is trying to be used after "exp" claim.
            throw InvalidTokenException::forExpiredToken($e);
        } catch (UnexpectedValueException $e) {
            // provided JWT is malformed OR
            // provided JWT is missing an algorithm / using an unsupported algorithm OR
            // provided JWT algorithm does not match provided key OR
            // provided key ID in key/key-array is empty or invalid.
            log_message(
                'error',
                '[Shield] ' . class_basename(self::class) . '::' . __FUNCTION__
                . '(' . __LINE__ . ') '
                . get_class($e) . ': ' . $e->getMessage()
            );

            throw InvalidTokenException::forInvalidToken($e);
        }
    }

    /**
     * Creates keys for Firebase php-jwt
     *
     * @param string $keyset
     *
     * @return array|Key key or key array
     */
    private static function createKeys($keyset)
    {
        $config = config(AuthJWT::class);

        $configKeys = $config->keys[$keyset];

        if (count($configKeys) === 1) {
            $key       = $configKeys[0]['secret'] ?? $configKeys[0]['public'];
            $algorithm = $configKeys[0]['alg'];

            $keys = new Key($key, $algorithm);
        } else {
            $keys = [];

            foreach ($config->keys[$keyset] as $item) {
                $key       = $item['secret'] ?? $item['public'];
                $algorithm = $item['alg'];

                $keys[$item['kid']] = new Key($key, $algorithm);
            }
        }

        return $keys;
    }

    /**
     * {@inheritDoc}
     */
    public static function encode(array $payload, $keyset, ?array $headers = null): string
    {
        $config = config(AuthJWT::class);

        if (isset($config->keys[$keyset][0]['secret'])) {
            $key = $config->keys[$keyset][0]['secret'];
        } else {
            $passphrase = $config->keys[$keyset][0]['passphrase'] ?? '';

            if ($passphrase !== '') {
                $key = openssl_pkey_get_private(
                    $config->keys[$keyset][0]['private'],
                    $passphrase
                );
            } else {
                $key = $config->keys[$keyset][0]['private'];
            }
        }

        $algorithm = $config->keys[$keyset][0]['alg'];

        $keyId = $config->keys[$keyset][0]['kid'] ?? null;
        if ($keyId === '') {
            $keyId = null;
        }

        return JWT::encode($payload, $key, $algorithm, $keyId, $headers);
    }
}
