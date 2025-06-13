<?php
namespace Longman\TelegramBot\Commands\UserCommands;

use Longman\TelegramBot\Commands\UserCommand;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;
use Longman\TelegramBot\DB;

class AnswerphotoCommand extends UserCommand
{
    protected $name = 'answerphoto';
    protected $description = 'Обработка фото-ответа на домашнее задание';
    protected $version = '1.0.0';

    public function execute(): ServerResponse
    {
        $message = $this->getMessage();
        $chat_id = $message->getChat()->getId();
        $user_id = $message->getFrom()->getId();

        // Проверяем, ожидает ли пользователь отправки решения
        $pdo = DB::getPdo();
        $stmt = $pdo->prepare('SELECT hw_id FROM homework_answers_waiting WHERE user_id = ? AND chat_id = ?');
        $stmt->execute([$user_id, $chat_id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$row) {
            // Не ожидается ответ
            return Request::sendMessage([
                'chat_id' => $chat_id,
                'text' => 'Сейчас не ожидается отправка решения. Пожалуйста, выберите задание и нажмите "Прислать решение".'
            ]);
        }

        // Получаем фото
        $photos = $message->getPhoto();
        if (!$photos) {
            return Request::sendMessage([
                'chat_id' => $chat_id,
                'text' => 'Пожалуйста, отправьте изображение в виде фото.'
            ]);
        }

        // Берём самое большое фото
        $photo = end($photos);
        $file_id = $photo->getFileId();

        // Получаем ссылку на файл через Telegram API
        $file_response = Request::getFile(['file_id' => $file_id]);
        if (!$file_response->isOk()) {
            return Request::sendMessage([
                'chat_id' => $chat_id,
                'text' => 'Не удалось получить файл изображения.'
            ]);
        }
        $file_path = $file_response->getResult()->getFilePath();
        $bot_api_key = $this->getTelegram()->getApiKey();
        $file_url = "https://api.telegram.org/file/bot{$bot_api_key}/{$file_path}";

        // Отправляем изображение на ai.php
        $ai_url = 'https://bot.egosmoke.shop/music-teacher/ai.php';
        $result = file_get_contents($ai_url . '?image_url=' . urlencode($file_url));
        // Можно использовать curl для POST, если нужно

        // Удаляем ожидание ответа
        $pdo->prepare('DELETE FROM homework_answers_waiting WHERE user_id = ? AND chat_id = ?')->execute([$user_id, $chat_id]);

        // Отправляем результат пользователю
        return Request::sendMessage([
            'chat_id' => $chat_id,
            'text' => "Результат анализа изображения:\n" . $result
        ]);
    }
}
