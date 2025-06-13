<?php
namespace Longman\TelegramBot\Commands\SystemCommands;

use Longman\TelegramBot\Commands\SystemCommand;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;
use Longman\TelegramBot\Entities\InlineKeyboard;
use Longman\TelegramBot\Entities\InlineKeyboardButton;
use Longman\TelegramBot\DB;
use Longman\TelegramBot\Commands\UserCommands\HomeworkCommand;
use Service\HomeworkService;

class CallbackqueryCommand extends SystemCommand
{
    protected $name = 'callbackquery';
    protected $description = 'Обработка inline-кнопок';
    protected $version = '1.0.0';

    public function execute(): ServerResponse
    {
        $callback = $this->getCallbackQuery();
        $data = $callback->getData();
        $chat_id = $callback->getMessage()->getChat()->getId();
        $message_id = $callback->getMessage()->getMessageId();
        $user_id = $callback->getFrom()->getId();

        if ($data === 'back_to_homework_list') {
            $homeworkCommand = new \Longman\TelegramBot\Commands\UserCommands\HomeworkCommand($this->getTelegram(), $this->getMessage());
            return $homeworkCommand->sendHomeworkList($chat_id, $user_id, $message_id);
        }

        if (strpos($data, 'submit_hw_') === 0) {
            $hw_id = (int)substr($data, 10);
            // Сохраняем ожидание ответа
            $pdo = \Longman\TelegramBot\DB::getPdo();
            $stmt = $pdo->prepare('INSERT INTO homework_answers_waiting (user_id, chat_id, hw_id) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE hw_id = VALUES(hw_id), created_at = NOW()');
            $stmt->execute([$user_id, $chat_id, $hw_id]);
            $inline_keyboard = new \Longman\TelegramBot\Entities\InlineKeyboard([
                ['text' => '❌ Отменить отправку решения', 'callback_data' => 'cancel_hw_answer']
            ]);
            Request::answerCallbackQuery([
                'callback_query_id' => $callback->getId(),
                'text' => '',
                'show_alert' => false,
            ]);
            return Request::sendMessage([
                'chat_id' => $chat_id,
                'text' => "Пожалуйста, отправьте ваше решение для задания #$hw_id в следующем сообщении.",
                'reply_markup' => $inline_keyboard,
            ]);
        }

        if (strpos($data, 'hw_') === 0) {
            $hw_id = (int)substr($data, 3);
            $service = new HomeworkService();
            $pdo = DB::getPdo();

            try {
                $hw = $service->getHomeworkById($hw_id);

                // выполнено ли задание пользователем
                $stmt_completed = $pdo->prepare('SELECT COUNT(*) FROM homework_answers WHERE user_id = ? AND hw_id = ?');
                $stmt_completed->execute([$user_id, $hw_id]);
                $is_completed = $stmt_completed->fetchColumn() > 0;

            } catch (\Throwable $e) {
                 return Request::answerCallbackQuery([
                    'callback_query_id' => $callback->getId(),
                    'text' => 'Ошибка при получении данных задания: ' . $e->getMessage(),
                    'show_alert' => true,
                ]);
            }

            if ($hw) {
                $text = "<b>Задание:</b> {$hw['title']}\n\n{$hw['task']}";

                $keyboard_rows = [
                    [new InlineKeyboardButton(['text' => '◀️ Назад к списку', 'callback_data' => 'back_to_homework_list'])]
                ];

                if (!$is_completed) {
                    $keyboard_rows[] = [new InlineKeyboardButton(['text' => '✅ Прислать решение', 'callback_data' => 'submit_hw_' . $hw['id']])];
                }

                $inline_keyboard = new InlineKeyboard(...$keyboard_rows);

            } else {
                $text = "Задание не найдено.";
                $inline_keyboard = null;
            }

            return Request::editMessageText([
                'chat_id' => $chat_id,
                'message_id' => $message_id,
                'text' => $text,
                'parse_mode' => 'HTML',
                'reply_markup' => $inline_keyboard,
            ]);
        }

        if ($data === 'cancel_hw_answer') {
            $pdo = \Longman\TelegramBot\DB::getPdo();
            $pdo->prepare('DELETE FROM homework_answers_waiting WHERE user_id = ? AND chat_id = ?')->execute([$user_id, $chat_id]);
            Request::answerCallbackQuery([
                'callback_query_id' => $callback->getId(),
                'text' => '',
                'show_alert' => false,
            ]);
            return Request::editMessageText([
                'chat_id' => $chat_id,
                'message_id' => $message_id,
                'text' => 'Отправка решения отменена.',
            ]);
        }

        return Request::answerCallbackQuery([
            'callback_query_id' => $callback->getId(),
            'text' => '',
            'show_alert' => false,
        ]);
    }
} 