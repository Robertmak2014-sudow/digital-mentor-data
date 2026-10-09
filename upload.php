<?php
/**
 * Простой API для добавления/удаления задач в public/tasks.json
 *
 * URL‑пример:
 *   POST /upload.php?action=add    – добавить задачу
 *   POST /upload.php?action=delete – удалить задачу (передать id)
 *
 * Тело запроса – JSON.
 * Ответ – тоже JSON.
 *
 * Поскольку файл находится в открытом каталоге, никаких скрытых путей не требуется.
 */

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

// ----------------------- CONFIG -----------------------
$DATA_FILE = __DIR__ . '/tasks.json';      // файл лежит рядом с этим скриптом
// ----------------------------------------------------

// ---------- Утилиты ----------
/**
 * Читает массив задач из $DATA_FILE.
 * Делает shared‑lock, чтобы несколько запросов могли читать одновременно.
 */
function readTasks(string $file): array
{
    if (!file_exists($file)) {
        // создаём пустой файл, если его ещё нет
        file_put_contents($file, json_encode([], JSON_UNESCAPED_UNICODE));
    }

    $fp = fopen($file, 'r');
    if (!$fp) {
        http_response_code(500);
        echo json_encode(['error' => 'Cannot open data file']);
        exit;
    }

    flock($fp, LOCK_SH);                     // shared lock
    $content = stream_get_contents($fp);
    flock($fp, LOCK_UN);
    fclose($fp);

    $data = json_decode($content, true);
    return is_array($data) ? $data : [];
}

/**
 * Записывает массив задач в $DATA_FILE.
 * Делает exclusive‑lock, чтобы исключить «гонки» между запросами.
 */
function writeTasks(string $file, array $tasks): void
{
    $fp = fopen($file, 'c+');
    if (!$fp) {
        http_response_code(500);
        echo json_encode(['error' => 'Cannot open data file for writing']);
        exit;
    }

    if (!flock($fp, LOCK_EX)) {              // exclusive lock
        http_response_code(500);
        echo json_encode(['error' => 'Cannot lock file']);
        exit;
    }

    // Стираем старое содержимое и пишем заново
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($tasks, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
}

/**
 * Генерирует новый id – max(id)+1 (если массив пустой → 1).
 */
function generateId(array $tasks): int
{
    $max = 0;
    foreach ($tasks as $t) {
        if (isset($t['id']) && $t['id'] > $max) {
            $max = $t['id'];
        }
    }
    return $max + 1;
}

/**
 * Возврат JSON‑ответа и завершение скрипта.
 */
function answer(array $payload, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}
// --------------------------------

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? null;

// Принимаем только POST
if ($method !== 'POST' || $action === null) {
    answer(['error' => 'Bad request – use POST with ?action=add|delete'], 400);
}

// Текущий список
$tasks = readTasks($DATA_FILE);

// ------------------- ADD -------------------
if ($action === 'add') {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        answer(['error' => 'Invalid JSON body'], 400);
    }

    // Минимальная проверка обязательных полей
    $required = ['title','description','price','category','duration','match'];
    foreach ($required as $fld) {
        if (!array_key_exists($fld, $data)) {
            answer(['error' => "Missing field: $fld"], 400);
        }
    }

    $newTask = [
        'id'          => generateId($tasks),
        'title'       => (string)$data['title'],
        'description' => (string)$data['description'],
        'price'       => (int)$data['price'],
        'category'    => (string)$data['category'],
        'duration'    => (string)$data['duration'],
        'match'       => (int)$data['match'],
    ];

    $tasks[] = $newTask;
    writeTasks($DATA_FILE, $tasks);

    answer([
        'status' => 'added',
        'task'   => $newTask,
        'all'    => $tasks
    ]);
}

// ------------------- DELETE -------------------
if ($action === 'delete') {
    // Удаляем по id – id может приходить в JSON‑теле или как GET‑параметр
    $json = file_get_contents('php://input');
    $body = json_decode($json, true);
    $id = null;

    if (isset($_GET['id'])) {
        $id = (int)$_GET['id'];
    } elseif (is_array($body) && isset($body['id'])) {
        $id = (int)$body['id'];
    }

    if (!$id) {
        answer(['error' => 'Missing task id (GET ?id=… or JSON { "id": … })'], 400);
    }

    $found = false;
    foreach ($tasks as $i => $t) {
        if (($t['id'] ?? 0) === $id) {
            $found = true;
            unset($tasks[$i]);
            break;
        }
    }

    if (!$found) {
        answer(['error' => "Task with id=$id not found"], 404);
    }

    // Сбрасываем индексы, чтобы JSON‑массив оставался «чистым»
    $tasks = array_values($tasks);
    writeTasks($DATA_FILE, $tasks);

    answer([
        'status' => 'deleted',
        'deletedId' => $id,
        'all' => $tasks
    ]);
}

// ------------------- UNKNOWN ACTION -------------------
answer(['error' => 'Unknown action'], 400);
