<?php
declare(strict_types=1);

final class AdminAuth
{
    private const MAX_ATTEMPTS = 5;
    private const WINDOW_SECONDS = 900;

    public static function countUsers(PDO $pdo): int
    {
        return (int) $pdo->query('SELECT COUNT(*) FROM admin_users')->fetchColumn();
    }

    /** @return string|null komunikat błędu */
    public static function validateNewUser(string $username, string $password, string $repeat): ?string
    {
        if (!preg_match('/^[A-Za-z0-9._-]{3,40}$/', $username)) {
            return 'Login: 3–40 znaków (litery bez polskich znaków, cyfry, . _ -).';
        }
        if (strlen($password) < 10) {
            return 'Hasło musi mieć co najmniej 10 znaków.';
        }
        if (strlen($password) > 200) {
            return 'Hasło jest zbyt długie.';
        }
        if ($password !== $repeat) {
            return 'Hasła nie są takie same.';
        }
        return null;
    }

    public static function createUser(PDO $pdo, string $username, string $password): bool
    {
        $exists = $pdo->prepare('SELECT 1 FROM admin_users WHERE username = :u');
        $exists->execute(['u' => $username]);
        if ($exists->fetchColumn()) {
            return false;
        }
        $pdo->prepare('INSERT INTO admin_users (username, password_hash, created_at) VALUES (:u, :h, :t)')
            ->execute(['u' => $username, 'h' => password_hash($password, PASSWORD_DEFAULT), 't' => date('Y-m-d H:i:s')]);
        return true;
    }

    /** @return array{id:int,username:string}|null */
    public static function verify(PDO $pdo, string $username, string $password): ?array
    {
        $statement = $pdo->prepare('SELECT id, username, password_hash FROM admin_users WHERE username = :u');
        $statement->execute(['u' => $username]);
        $user = $statement->fetch();
        // Stały koszt także dla nieistniejącego loginu.
        $hash = $user ? (string) $user['password_hash'] : password_hash('dummy', PASSWORD_DEFAULT);
        if (!password_verify($password, $hash) || !$user) {
            return null;
        }
        if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
            $pdo->prepare('UPDATE admin_users SET password_hash = :h WHERE id = :id')
                ->execute(['h' => password_hash($password, PASSWORD_DEFAULT), 'id' => $user['id']]);
        }
        return ['id' => (int) $user['id'], 'username' => (string) $user['username']];
    }

    public static function tooManyAttempts(PDO $pdo, string $ip): bool
    {
        $pdo->prepare('DELETE FROM login_attempts WHERE attempted_at < :before')
            ->execute(['before' => date('Y-m-d H:i:s', time() - self::WINDOW_SECONDS)]);
        $count = $pdo->prepare('SELECT COUNT(*) FROM login_attempts WHERE ip_hash = :h');
        $count->execute(['h' => hash('sha256', $ip)]);
        return (int) $count->fetchColumn() >= self::MAX_ATTEMPTS;
    }

    public static function registerFailure(PDO $pdo, string $ip): void
    {
        $pdo->prepare('INSERT INTO login_attempts (ip_hash, attempted_at) VALUES (:h, :t)')
            ->execute(['h' => hash('sha256', $ip), 't' => date('Y-m-d H:i:s')]);
    }

    /** @return list<array{id:int,username:string,created_at:string}> */
    public static function listUsers(PDO $pdo): array
    {
        return $pdo->query('SELECT id, username, created_at FROM admin_users ORDER BY username')->fetchAll();
    }

    public static function deleteUser(PDO $pdo, int $id): void
    {
        $pdo->prepare('DELETE FROM admin_users WHERE id = :id')->execute(['id' => $id]);
    }
}
