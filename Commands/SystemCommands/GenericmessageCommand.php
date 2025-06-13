<?php
namespace Longman\TelegramBot\Commands\SystemCommands;

use Longman\TelegramBot\Commands\SystemCommand;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;
use Longman\TelegramBot\DB;

// Функция для отправки текста на Gemini

// Removed send_to_gemini function, now sending requests to ai.php

function is_error_json($str) {
    $json = json_decode($str, true);
    return is_array($json) && isset($json['error']);
}

// Функция для извлечения вердикта из ответа ии
function extract_verdict($text) {
    if (preg_match('/=== ОЦЕНКА ===\s*(ПРАВИЛЬНО|НЕПРАВИЛЬНО)/u', $text, $m)) {
        return $m[1];
    }
    return null;
}

class GenericmessageCommand extends SystemCommand
{
    protected $name = 'genericmessage';
    protected $description = 'Handle';
    protected $version = '1.0.0';

    public function execute(): ServerResponse
    {
        $message = $this->getMessage();
        $chat_id = $message->getChat()->getId();
        $user_id = $message->getFrom()->getId();
        $config_local = include __DIR__ . '/../../config.php';
        if (!is_array($config_local)) {
            return Request::sendMessage([
                'chat_id' => $chat_id,
                'text' => 'Ошибка'
            ]);
        }
        if (!isset($config_local['api_key']) || !isset($config_local['ai_endpoint']) || !isset($config_local['gemini_api_key'])) {
            return Request::sendMessage([
                'chat_id' => $chat_id,
                'text' => 'Ошибка:'
            ]);
        }
        $bot_api_key = $config_local['api_key'];
        $gemini_api_key = $config_local['gemini_api_key'];

        // Проверяем состояние state
        $pdo = DB::getPdo();

        $stmt_state = $pdo->prepare('SELECT state FROM user_states WHERE user_id = ?');
        $stmt_state->execute([$user_id]);
        $user_state_row = $stmt_state->fetch(\PDO::FETCH_ASSOC);
        $user_state = $user_state_row ? $user_state_row['state'] : null;

        $base_system_prompt = "ОТВЕТЬ НА РУССКОМ. Ты — универсальный помощник для проверки учебных заданий. 

Основные принципы:
1. Работаешь по схеме: Анализ → Сравнение с примером решщения → Оценка (Пример решения используй для понимания сути задания, но не ориентируйся на него как на строгий эталон. Решение пользователя может отличаться, оценивай его по общей правильности и соответствию задаче.)
2. Всегда сохраняй объективность и доброжелательный тон

Стандартная процедура проверки:
1. Формат ответа:
- Проверь соответствие требуемой структуре (Примерно, относительно)
- Допускай вариации формулировок, если суть сохранена
- Не учитывай мелкие опечатки, не влияющие на смысл

2. Содержание:
- Сравни каждый ключевой элемент с решением
- Различай существенные и несущественные ошибки

3. Оценка:
Всегда выводи в начале вердикт в строгом формате:
=== ОЦЕНКА ===
ПРАВИЛЬНО/НЕПРАВИЛЬНО

4. Пояснение:
- Для правильных решений: краткое подтверждение
- Для ошибок: четко укажи:
  • Какое требование нарушено
  • Где именно ошибка
  • Как должно быть правильно
- Сохраняй 1-2 предложения";

        // Если в режиме АИ приходит обычное сообщение
        if ($user_state === 'ai_tutor' && $message->getText() && substr($message->getText(), 0, 1) !== '/') {
            $user_question = $message->getText();

            // Если пользователь нажал кнопку 'Выйти из АИ режима'
            if ($user_question === 'Выйти из АИ режима') {
                // Удаляем состояние
                $stmt_delete_state = $pdo->prepare('DELETE FROM user_states WHERE user_id = ?');
                $stmt_delete_state->execute([$user_id]);

                // Удаляем историю пользователя
                $stmt_delete_history = $pdo->prepare('DELETE FROM ai_tutor_messages WHERE user_id = ?');
                $stmt_delete_history->execute([$user_id]);

                // Отправляем подтверждение и основную клавиатуру
                 $keyboard = new \Longman\TelegramBot\Entities\Keyboard(
                    ['📚 Домашние задания'],
                    ['🤖 АИ репетитор']
                );
                $keyboard
                    ->setResizeKeyboard(true)
                    ->setOneTimeKeyboard(false);

                return Request::sendMessage([
                    'chat_id' => $chat_id,
                    'text'    => 'Вы вышли из режима АИ репетитора. Теперь вы можете использовать основные функции бота.',
                    'reply_markup' => $keyboard,
                ]);
            }


            // Сохраняем сообщение пользователя в историю
            $stmt_insert_user = $pdo->prepare('INSERT INTO ai_tutor_messages (user_id, role, content) VALUES (?, ?, ?)');
            $stmt_insert_user->execute([$user_id, 'user', $user_question]);


            $history_limit = 20;
            $stmt_history = $pdo->prepare('SELECT role, content FROM ai_tutor_messages WHERE user_id = ? ORDER BY created_at ASC LIMIT ?');
            $stmt_history->bindValue(1, $user_id, \PDO::PARAM_INT);
            $stmt_history->bindValue(2, $history_limit, \PDO::PARAM_INT);
            $stmt_history->execute();
            $history_messages = $stmt_history->fetchAll(\PDO::FETCH_ASSOC);


            // Формируем массив сообщений для вызова API
            $messages_for_api = [
                [
                    'role' => 'system',
                    'content' => "Ты — виртуальный музыкальный репетитор, профессионал с большим опытом преподавания музыки для людей любого возраста и уровня подготовки. Твоя задача — помогать пользователям учиться музыке, отвечать на их вопросы, давать советы по практике, теории, выбору инструментов, разбору песен, развитию слуха, ритма, и других музыкальных навыков. Ты всегда вежлив, доброжелателен, поддерживаешь интерес к обучению, мотивируешь и объясняешь сложные вещи простым языком."
                ]
            ];

            foreach ($history_messages as $hist_message) {
                 $messages_for_api[] = [
                     'role' => $hist_message['role'] === 'assistant' ? 'assistant' : 'user',
                     'content' => $hist_message['content']
                 ];
            }



            // Отправляем историю сообщений в Gemini через ai.php
            $post_data = [
                'api_key' => $gemini_api_key,
                'messages' => $messages_for_api,
            ];

            $ch = curl_init($config_local['ai_endpoint']);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($post_data));
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
            ]);
            $gemini_result_raw = curl_exec($ch);
            curl_close($ch);

            $gemini_result_decoded = json_decode($gemini_result_raw, true);
            $gemini_result = isset($gemini_result_decoded['candidates'][0]['content']['parts'][0]['text']) ? $gemini_result_decoded['candidates'][0]['content']['parts'][0]['text'] : null;
            if (isset($gemini_result_decoded['error'])) {
                $gemini_result = "API error: " . ($gemini_result_decoded['error']['message'] ?? 'Unknown error');
            }

            // Сохраняем ответ ИИ в историю
            if ($gemini_result && !is_error_json($gemini_result)) {
                $stmt_insert_ai = $pdo->prepare('INSERT INTO ai_tutor_messages (user_id, role, content) VALUES (?, ?, ?)');
                $stmt_insert_ai->execute([$user_id, 'assistant', $gemini_result]);
            }

            // Отправляем ответ ИИ пользователю
            $text_to_send = $gemini_result ?: 'Произошла ошибка при получении ответа от АИ.';

            return Request::sendMessage([
                'chat_id' => $chat_id,
                'text'    => $text_to_send,
                'parse_mode' => 'HTML',
            ]);
        }

        // Проверяем, ожидает ли пользователь отправки решения
        $stmt = $pdo->prepare('SELECT hw_id FROM homework_answers_waiting WHERE user_id = ? AND chat_id = ?');
        $stmt->execute([$user_id, $chat_id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($row && $message->getPhoto()) {
            $hw_id = $row['hw_id'];
            // Получаем текст задания base_system_prompt
            $task_text = '';
            $task_stmt = $pdo->prepare('SELECT title, task, solution, ai_check_prompt FROM homeworks WHERE id = ?');
            $task_stmt->execute([$hw_id]);
            $task = $task_stmt->fetch(\PDO::FETCH_ASSOC);
            $example_solution = '';
            $ai_check_prompt = '';
            if ($task) {
                $task_text = ($task['title'] ? $task['title'] . "\n" : "") . $task['task'];
                if (!empty($task['solution'])) {
                    $example_solution = $task['solution'];
                }
                // Добавляем уникальный промт для проверки
                if (!empty($task['ai_check_prompt'])) {
                    $ai_check_prompt = $task['ai_check_prompt'];
                }
            }
            // Получаем фото
            $photos = $message->getPhoto();
            $photo = end($photos);
            $file_id = $photo->getFileId();

            // Получаем ссылку на файл
            $file_response = Request::getFile(['file_id' => $file_id]);
            if ($file_response->isOk()) {
                $file_path = $file_response->getResult()->getFilePath();
                $file_url = "https://api.telegram.org/file/bot{$bot_api_key}/{$file_path}";

                $system_prompt = $base_system_prompt;
                if (!empty($ai_check_prompt)) {
                    $system_prompt .= "\n\n" . $ai_check_prompt; // Append database prompt if it exists
                }

                // Формируем комбинированный промт для проверки решения нейросетью
                $user_query_prompt = "ОТВЕТЬ НА РУССКОМ. Проверь правильность этого решения домашнего задания:\n\nТекст задания:\n" . $task_text . "\n\nПример решения:\n" . $example_solution . "\n\nРешение пользователя (распознанный текст на изображении): [изображение]\n\nНапиши краткий вердикт: ОЦЕНКА: ПРАВИЛЬНО или ОЦЕНКА: НЕПРАВИЛЬНО, и краткое пояснение.";

                // Объединяем системный промпт с пользовательским для мультимодальных запросов
                // так как Gemini API не поддерживает отдельную системную роль с изображениями.
                $combined_prompt = $system_prompt . "\n\n" . $user_query_prompt;

                // Отправляем изображение и промт на ai.php для комплексной проверки
                $post_data = [
                    'api_key' => $gemini_api_key,
                    'image_url' => $file_url,
                    'messages' => [ // Отправляем все как одно пользовательское сообщение
                        ['role' => 'user', 'content' => $combined_prompt]
                    ]
                ];

                $ch = curl_init($config_local['ai_endpoint']);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($post_data));
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    'Content-Type: application/json',
                ]);
                $gemini_response_raw = curl_exec($ch);
                curl_close($ch);

                $gemini_response_decoded = json_decode($gemini_response_raw, true);
                $gemini_result = isset($gemini_response_decoded['candidates'][0]['content']['parts'][0]['text']) ? $gemini_response_decoded['candidates'][0]['content']['parts'][0]['text'] : null;

                if (isset($gemini_response_decoded['error'])) {
                    $gemini_result = "API error: " . ($gemini_response_decoded['error']['message'] ?? 'Unknown error');
                }

                $verdict = extract_verdict($gemini_result);
                if ($verdict === 'ПРАВИЛЬНО') {
                    // If it's correct, we assume the AI has processed the image and given a verdict
                    // We can store the verdict directly or, if we need the recognized text,
                    // ai.php should return it as well. For now, let's assume the verdict is enough.
                    $stmt = $pdo->prepare('INSERT INTO homework_answers (user_id, hw_id, answer, created_at) VALUES (?, ?, ?, NOW())');
                    $stmt->execute([$user_id, $hw_id, "[ОТВЕТ НА ИЗОБРАЖЕНИИ - ПРОВЕРЕНО AI]"]); // Placeholder as text not recognized here anymore
                }

                // Удаляем состояние после отправки результата
                $pdo->prepare('DELETE FROM homework_answers_waiting WHERE user_id = ? AND chat_id = ?')->execute([$user_id, $chat_id]);

                // Временно показываем, что отправляем на проверку и результат
                $debug_message = "<b>Текст задания:</b>\n<pre>" . htmlspecialchars($task_text) . "</pre>";
                if ($example_solution) {
                    $debug_message .= "\n<b>Пример решения:</b>\n<pre>" . htmlspecialchars($example_solution) . "</pre>";
                }
                // The ai_response (recognized text) is no longer separate here.
                // We display the full AI response directly.
                $debug_message .= "\n<b>Промт, переданный АИ:</b>\n<pre>" . htmlspecialchars($combined_prompt) . "</pre>";
                $debug_message .= "\n<b>Проверка решения нейросетью:</b>\n" . ($gemini_result ?: 'No response from AI.php');

                if ($verdict === 'ПРАВИЛЬНО') {
                    $debug_message .= "\n\n<b>✅ Ваше решение засчитано!</b>";
                } elseif ($verdict === 'НЕПРАВИЛЬНО') {
                    $debug_message .= "\n\n<b>❌ Ваше решение не засчитано.</b>";
                }

                return Request::sendMessage([
                    'chat_id' => $chat_id,
                    'text' => $debug_message,
                    'parse_mode' => 'HTML',
                ]);
            } else {
                return Request::sendMessage([
                    'chat_id' => $chat_id,
                    'text' => "Не удалось получить файл изображения.",
                ]);
            }
        }

        // если пользователь ожидает и прислал т екст (без фото)
        if ($row && $message->getText() && !$message->getPhoto()) {
            $hw_id = $row['hw_id'];
            // Получаем текст задания
            $task_text = '';
            $task_stmt = $pdo->prepare('SELECT title, task, solution, ai_check_prompt FROM homeworks WHERE id = ?');
            $task_stmt->execute([$hw_id]);
            $task = $task_stmt->fetch(\PDO::FETCH_ASSOC);
            $example_solution = '';
            $ai_check_prompt = '';
            if ($task) {
                $task_text = ($task['title'] ? $task['title'] . "\n" : "") . $task['task'];
                if (!empty($task['solution'])) {
                    $example_solution = $task['solution'];
                }
                // спец промт
                if (!empty($task['ai_check_prompt'])) {
                    $ai_check_prompt = $task['ai_check_prompt'];
                }
            }
            $user_answer = $message->getText();


            $system_prompt = $base_system_prompt;
            if (!empty($ai_check_prompt)) {
                $system_prompt .= "\n\n" . $ai_check_prompt;
            }

            // Формируем prompt для Gemini
            $messages_for_gemini = [
                [
                    'role' => 'system',
                    'content' => $system_prompt
                ],
                [
                    'role' => 'user',
                    'content' => "Текст задания:\n" . $task_text . "\n\nПример решения:\n" . $example_solution . "\n\nРешение пользователя:\n" . $user_answer
                ]
            ];

            // Отправляем на проверку через ai.php
            $post_data = [
                'api_key' => $gemini_api_key,
                'messages' => $messages_for_gemini,
            ];

            $ch = curl_init($config_local['ai_endpoint']);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($post_data));
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
            ]);
            $gemini_result_raw = curl_exec($ch);
            curl_close($ch);
            
            $gemini_result_decoded = json_decode($gemini_result_raw, true);
            $gemini_result = isset($gemini_result_decoded['candidates'][0]['content']['parts'][0]['text']) ? $gemini_result_decoded['candidates'][0]['content']['parts'][0]['text'] : null;
            if (isset($gemini_result_decoded['error'])) {
                $gemini_result = "API error: " . ($gemini_result_decoded['error']['message'] ?? 'Unknown error');
            }

            $verdict = extract_verdict($gemini_result);
            if ($verdict === 'ПРАВИЛЬНО') {
                // Записываем в БД
                $stmt = $pdo->prepare('INSERT INTO homework_answers (user_id, hw_id, answer, created_at) VALUES (?, ?, ?, NOW())');
                $stmt->execute([$user_id, $hw_id, $user_answer]);
            }

            // Удаляем состояние после отправки результата
            $pdo->prepare('DELETE FROM homework_answers_waiting WHERE user_id = ? AND chat_id = ?')->execute([$user_id, $chat_id]);

            // Временно показываем, что отправляем на проверку и результат
            $debug_message = "<b>Текст задания:</b>\n<pre>" . htmlspecialchars($task_text) . "</pre>";
            if ($example_solution) {
                $debug_message .= "\n<b>Пример решения:</b>\n<pre>" . htmlspecialchars($example_solution) . "</pre>";
            }
            $debug_message .= "\n<b>Текст, отправленный на проверку:</b>\n<pre>" . htmlspecialchars($user_answer) . "</pre>";

            $debug_message .= "\n<b>Промт, переданный АИ:</b>\n<pre>" . htmlspecialchars($system_prompt) . "</pre>";
            $debug_message .= "\n<b>Проверка решения нейросетью:</b>\n" . ($gemini_result ?: 'No response from AI.php'); // Display raw response from ai.php for debugging

            if ($verdict === 'ПРАВИЛЬНО') {
                $debug_message .= "\n\n<b>✅ Ваше решение засчитано!</b>";
            } elseif ($verdict === 'НЕПРАВИЛЬНО') {
                $debug_message .= "\n\n<b>❌ Ваше решение не засчитано.</b>";
            }

            return Request::sendMessage([
                'chat_id' => $chat_id,
                'text' => $debug_message,
                'parse_mode' => 'HTML',
            ]);
        }


        if ($message->getText() === '📚 Домашние задания') {
            return $this->getTelegram()->executeCommand('homework');
        }

        if ($message->getText() === '🤖 АИ репетитор') {
            return $this->getTelegram()->executeCommand('musicaitutor');
        }

        return Request::emptyResponse();
    }
} 