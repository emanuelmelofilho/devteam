<?php
// Placeholder Developer worker that consumes RabbitMQ 'tasks_developer' queue

require __DIR__ . '/../../backend/vendor/autoload.php';

use PhpAmqpLib\Connection\AMQPStreamConnection;

$host = getenv('RABBITMQ_HOST') ?: 'rabbitmq';
$port = getenv('RABBITMQ_PORT') ?: 5672;
$user = getenv('RABBITMQ_USER') ?: 'guest';
$pass = getenv('RABBITMQ_PASS') ?: 'guest';

$connection = new AMQPStreamConnection($host, $port, $user, $pass);
$channel = $connection->channel();

$channel->queue_declare('tasks_developer', false, true, false, false);

echo "[developer_worker] waiting for messages...\n";

$callback = function ($msg) {
    echo "[developer_worker] Received task message\n";
    $payload = json_decode($msg->body, true);
    if (!$payload || !isset($payload['task_id'])) {
        $msg->ack();
        return;
    }

    $apiBase = getenv('KANBAN_API') ?: 'http://localhost:8000/api/kanban';

    // if payload contains lane, update the task lane
    if (isset($payload['lane'])) {
        $taskId = $payload['task_id'];
        $data = json_encode(['lane' => $payload['lane']]);
        $ch = curl_init("{$apiBase}/tasks/{$taskId}");
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Accept: application/json',
            ...(!empty($payload['api_token']) ? ['Authorization: Bearer ' . $payload['api_token']] : []),
        ]);
        $res = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($status >= 200 && $status < 300) {
            echo "[developer_worker] Updated task {$taskId} lane to {$payload['lane']}\n";
        } else {
            echo "[developer_worker] API rejected task {$taskId} update (HTTP {$status}): {$res}\n";
        }
    }

    $msg->ack();
};

$channel->basic_consume('tasks_developer', '', false, false, false, false, $callback);

while ($channel->is_consuming()) {
    $channel->wait();
}

$channel->close();
$connection->close();
