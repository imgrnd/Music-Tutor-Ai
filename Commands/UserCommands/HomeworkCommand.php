<?php
namespace Longman\TelegramBot\Commands\UserCommands;

use Longman\TelegramBot\Commands\UserCommand;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;
use Longman\TelegramBot\Entities\InlineKeyboard;
use Service\HomeworkService;
use Longman\TelegramBot\DB;

class HomeworkCommand extends UserCommand
{
    protected $name = 'homework';
    protected $description = 'Список домашних заданий';
    protected $usage = '/homework';
    protected $version = '1.2.1';

    public function execute(): ServerResponse
    {
        $chat_id = $this->getMessage()->getChat()->getId();
        $user_id = $this->getMessage()->getFrom()->getId();
        // Вызываем общий метод для отправки списка
        return $this->sendHomeworkList($chat_id, $user_id);
    }



    public function sendHomeworkList(int $chat_id, int $user_id, ?int $message_id = null): ServerResponse
    {
        $service = new HomeworkService();
        $pdo = DB::getPdo();

        try {
            $homeworks = $service->getAllHomeworks();

            // Получаем список ID выполненных заданий для текущего пользователя
            $stmt_completed = $pdo->prepare('SELECT hw_id FROM homework_answers WHERE user_id = ?');
            $stmt_completed->execute([$user_id]);
            $completed_homework_ids = $stmt_completed->fetchAll(\PDO::FETCH_COLUMN);
            $completed_homework_ids = array_flip($completed_homework_ids);

        } catch (\Throwable $e) {
            $text = 'Ошибка при получении списка заданий: ' . $e->getMessage();
            if ($message_id) {
                 return Request::editMessageText([
                    'chat_id' => $chat_id,
                    'message_id' => $message_id,
                    'text' => $text,
                ]);
            } else {
                return Request::sendMessage([
                    'chat_id' => $chat_id,
                    'text'    => $text,
                ]);
            }
        }

        if (empty($homeworks)) {
             $text = 'Домашних заданий пока нет.';
             if ($message_id) {
                 return Request::editMessageText([
                    'chat_id' => $chat_id,
                    'message_id' => $message_id,
                    'text' => $text,
                ]);
            } else {
                return Request::sendMessage([
                    'chat_id' => $chat_id,
                    'text'    => $text,
                ]);
            }
        }

        $text = "Список домашних заданий:\n";
        $keyboard_buttons = [];
        foreach ($homeworks as $hw) {
            $is_completed = isset($completed_homework_ids[$hw['id']]);
            $emoji = $is_completed ? '✅ ' : '❌ ';

            $text .= "{$hw['id']}. {$emoji}{$hw['title']}\n";

            $keyboard_buttons[] = [
                ['text' => "{$emoji}{$hw['title']}", 'callback_data' => 'hw_' . $hw['id']]
            ];
        }

        $inline_keyboard = new InlineKeyboard(...$keyboard_buttons);

        $request_params = [
            'chat_id' => $chat_id,
            'text'    => $text,
            'reply_markup' => $inline_keyboard,
            'parse_mode' => 'HTML',
        ];

        if ($message_id) {
            $request_params['message_id'] = $message_id;
            return Request::editMessageText($request_params);
        } else {
            return Request::sendMessage($request_params);
        }
    }
} 