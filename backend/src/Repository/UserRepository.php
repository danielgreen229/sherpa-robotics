<?php

declare(strict_types=1);

namespace App\Repository;

use Doctrine\DBAL\Connection;

final class UserRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    /** @return array<string, mixed>|false */
    public function byEmail(string $email): array|false
    {
        return $this->db->fetchAssociative('SELECT * FROM users WHERE email = ?', [mb_strtolower($email)]);
    }

    public function exists(int $id): bool
    {
        return $this->db->fetchOne('SELECT 1 FROM users WHERE id = ?', [$id]) !== false;
    }

    public function create(string $email, string $password): void
    {
        if (!$this->byEmail($email)) {
            $this->db->insert('users', ['email' => mb_strtolower($email), 'password_hash' => password_hash($password, PASSWORD_DEFAULT)]);
        }
    }
}
