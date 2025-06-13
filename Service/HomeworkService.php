<?php
namespace Service;

use Longman\TelegramBot\DB;

class HomeworkService
{

    public function getAllHomeworks(): array
    {
        $pdo = DB::getPdo();
        $stmt = $pdo->query('SELECT id, title FROM homeworks');
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }


    public function getHomeworkById(int $id): ?array
    {
        $pdo = DB::getPdo();
        $stmt = $pdo->prepare('SELECT * FROM homeworks WHERE id = ?');
        $stmt->execute([$id]);
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $result ?: null;
    }
} 