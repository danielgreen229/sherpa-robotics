<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\UserRepository;
use App\Support\ApiException;
use App\Support\Config;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

final class AuthService
{
    public function __construct(private readonly UserRepository $users)
    {
    }

    /** @return array{token: string, email: string, expires_at: int} */
    public function login(string $email, string $password): array
    {
        $user = $this->users->byEmail($email);
        $dummy = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';
        $valid = password_verify($password, $user ? (string) $user['password_hash'] : $dummy);
        if (!$user || !$valid) {
            throw new ApiException('Неверный email или пароль.', 401);
        }
        $expires = time() + 3600;
        return ['token' => JWT::encode(['iss' => 'catalog', 'aud' => 'catalog-ui', 'sub' => (string) $user['id'], 'iat' => time(), 'exp' => $expires], $this->secret(), 'HS256'), 'email' => (string) $user['email'], 'expires_at' => $expires];
    }

    public function verify(string $token): int
    {
        try {
            $data = JWT::decode($token, new Key($this->secret(), 'HS256'));
            if (($data->iss ?? '') !== 'catalog' || ($data->aud ?? '') !== 'catalog-ui' || !isset($data->sub) || !ctype_digit((string) $data->sub) || !$this->users->exists((int) $data->sub)) {
                throw new \UnexpectedValueException();
            }
            return (int) $data->sub;
        } catch (\Throwable) {
            throw new ApiException('Сессия истекла. Выполните вход.', 401);
        }
    }

    private function secret(): string
    {
        $secret = Config::env('JWT_SECRET');
        if (strlen($secret) < 32) {
            throw new \RuntimeException('JWT_SECRET must contain at least 32 characters');
        }
        return $secret;
    }
}
