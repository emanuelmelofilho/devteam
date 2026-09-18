<?php
// Placeholder DevOps worker that consumes RabbitMQ 'tasks_devops' queue
require __DIR__ . '/../../backend/vendor/autoload.php';
use PhpAmqpLib\Connection\AMQPStreamConnection;
$host = getenv('RABBITMQ_HOST') ?: 'rabbitmq';
$port = getenv('RABBITMQ_PORT') ?: 5672;
$user = getenv('RABBITMQ_USER') ?: 'guest';
$pass = getenv('RABBITMQ_PASS') ?: 'guest';
$connection = new AMQPStreamConnection($host, $port, $user, $pass);
$channel = $connection->channel();
$channel->queue_declare('tasks_devops', false, true, false, false);

echo "[devops_worker] waiting for messages...\n";
$callback = function ($msg) {
    echo "[devops_worker] Received: " . $msg->body . "\n";
    $payload = json_decode($msg->body, true);
    if (!$payload) {
        $msg->ack();
        return;
    }

    $apiBase = getenv('KANBAN_API') ?: 'http://localhost:8000/api/kanban';

    if (isset($payload['task_id']) && isset($payload['phase'])) {
        $taskId = $payload['task_id'];
        $phase = $payload['phase']; // 'start' or 'end'
        $comment = ($phase === 'start') ? 'Deploy started.' : 'Deploy finished.';

        $data = ['comment' => $comment, 'user_id' => $payload['user_id'] ?? null];
        $ch = curl_init("{$apiBase}/tasks/{$taskId}/comments");
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json'
        ]);
        $res = curl_exec($ch);
        curl_close($ch);

        echo "[devops_worker] Noted {$phase} for task {$taskId}\n";
    }

    $msg->ack();
};
$channel->basic_consume('tasks_devops', '', false, false, false, false, $callback);
while ($channel->is_consuming()) {
    $channel->wait();
}
$channel->close();
$connection->close();
