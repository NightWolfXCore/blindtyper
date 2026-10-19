<?php

declare(strict_types=1);

namespace BlindTyper\Repository;

final class UserRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    public function find(int $id, bool $lock = false): ?array
    {
        return $this->db->one('SELECT * FROM users WHERE id = ?' . ($lock ? ' FOR UPDATE' : ''), [$id]);
    }

    public function byEmail(string $email): ?array
    {
        return $this->db->one('SELECT * FROM users WHERE email = ?', [$email]);
    }

    public function create(string $email, string $hash, string $name, string $now): array
    {
        $this->db->execute('INSERT INTO users (email,password_hash,display_name,created_at,updated_at) VALUES (?,?,?,?,?)', [$email, $hash, $name, $now, $now]);
        return $this->find((int) $this->db->pdo->lastInsertId());
    }

    public function award(int $userId, int $xp, string $now): void
    {
        $this->db->execute('UPDATE users SET total_xp = total_xp + ?, updated_at = ? WHERE id = ?', [$xp, $now, $userId]);
    }
}
