<?php
/**
 * Чат отображения сообщений из игры в SphereWeb
 *
 * Работает так: Мы парсим файл chat.log (из игрового сервера) и отправляем данные в базу данных сайта SphereWeb
 * Соответственно необходимо скрипт запустить на VPS где работает сервер.
 */

/**
 * Основные настройки
 */

//Укажите месторасположение файла chat.log
const file_chat = 'chat.log';

//Укажите ID сервера
const server_id = 1;

//Укажите паттерн для парсинга строки
const pattern_chat_message = '/^\[(.*)\] (.*) \[(.*)] (.+)$/';

//Соединение с базой данных сайта Sphere
const db_host = 'localhost';
const db_user = 'root';
const db_pass = '';
const db_name = 'l2j';

new sphereChat();

class sphereChat
{
    private int $lastLine = 0;
    private int $lastModified = 0;
    private ChatMessage $chatMessages;

    public function __construct()
    {
        SphereWeb::connect();
        if (!file_exists(file_chat)) {
            echo sprintf("Chat file «%s» not found\n", file_chat);
            exit;
        }
        echo "Start Parser Chat\n";
        $this->lastLine = $this->getLastLine();
        $this->setLastModified();
        $this->getNewLines();
    }

    // Функция для получения номера последней строки
    private function getLastLine(): int
    {
        $lines = file(file_chat, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (is_array($lines) && count($lines) > 0) {
            return count($lines);
        } else {
            return 0;
        }
    }

    private function setLastModified(): void
    {
        $this->lastModified = filemtime(file_chat);
    }

    public function getNewLines(): void
    {
        while (true) {
            clearstatcache();
            $oldLastModified = $this->getLastModified();
            if (filemtime(file_chat) != $oldLastModified) {
                $this->setLastModified();
                $messages = [];
                $lines = file(file_chat, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                if (is_array($lines) && count($lines) > 0) {
                    $lineCount = count($lines);
                    for ($i = $this->lastLine; $i < $lineCount; $i++) {
                        $chatMessage = $this->parseLine($lines[$i]);
                        if ($chatMessage instanceof ChatMessage) {
                            $this->chatMessages = $chatMessage;
                        } else {
                            $this->chatMessages->setMessage($chatMessage);
                        }
                        $messages[] = $this->chatMessages;
                    }
                    $this->lastLine = $lineCount;
                    echo sprintf("New messages: +%d\n", count($messages));
                }
                SphereWeb::send($messages);
            }
            sleep(1);
        }
    }

    private function getLastModified(): int
    {
        return $this->lastModified;
    }

    public function parseLine($message): string|ChatMessage
    {
        if (preg_match(pattern_chat_message, $message, $matches)) {
            $date = $matches[1];
            $type = $matches[2];
            $player = $matches[3];
            $message = $matches[4];
            $date = SphereWeb::convertMySQLTime($date);
            return new ChatMessage($date, $type, $player, $message);
        }
        return $message;
    }

}

class ChatMessage
{
    private string $date;
    private string $type;
    private string $player;
    private string $message;

    public function __construct($date, $type, $player, $message)
    {
        $this->date = $date;
        $this->type = $type;
        $this->player = $player;
        $this->message = $message;
    }

    /**
     * @return string
     */
    public function getDate(): string
    {
        return $this->date;
    }

    /**
     * @return string
     */
    public function getType(): string
    {
        return $this->type;
    }

    /**
     * @return string
     */
    public function getPlayer(): string
    {
        return $this->player;
    }

    /**
     * @return string
     */
    public function getMessage(): string
    {
        return $this->message;
    }

    /**
     * @param string $message
     */
    public function setMessage(string $message): void
    {
        $this->message = $message;
    }



}

class SphereWeb
{
    private static PDO $pdo;
    static public function connect(): void
    {
        $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8', db_host, db_name);
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ];
        try {
            self::$pdo = new PDO($dsn, db_user, db_pass, $options);
        } catch (PDOException $e) {
            echo sprintf("Error connect to DB SphereWeb: %s\n", $e->getMessage());
            exit;
        }
    }

    /**
     * @param ChatMessage[] $messages Массив объектов ChatMessage
     */
    static public function send(array $messages): void
    {
        if (empty($messages)) {
            return;
        }
        try {
            self::$pdo->beginTransaction();
            $sql = 'INSERT INTO `chat` (`date`, `type`, `player`, `message`, `server`) VALUES (:date, :type, :player, :message, :server)';
            $stmt = self::$pdo->prepare($sql);
            foreach ($messages as $chatMessage) {
                $stmt->execute([
                    'date' => $chatMessage->getDate(),
                    'type' => $chatMessage->getType(),
                    'player' => $chatMessage->getPlayer(),
                    'message' => $chatMessage->getMessage(),
                    'server' => server_id,
                ]);
            }
            self::$pdo->commit();
        } catch (PDOException $e) {
            echo sprintf("Ошибка при вставке данных: %s\n", $e->getMessage());
            self::$pdo->rollBack();
        }
    }

    static public function convertMySQLTime($inputTime): string|bool {
        $date = DateTime::createFromFormat('d.m.y H:i:s', $inputTime);
        if ($date !== false) {
            return $date->format('Y-m-d H:i:s');
        }
        return date('Y-m-d H:i:s');
    }

}