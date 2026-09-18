<?php
// Placeholder Tester worker that consumes RabbitMQ 'tasks_tester' queue
require __DIR__ . '/../../backend/vendor/autoload.php';
use PhpAmqpLib\Connection\AMQPStreamConnection;
$host = getenv('RABBITMQ_HOST') ?: 'rabbitmq';
$port = getenv('RABBITMQ_PORT') ?: 5672;
$user = getenv('RABBITMQ_USER') ?: 'guest';
$pass = getenv('RABBITMQ_PASS') ?: 'guest';
$connection = new AMQPStreamConnection($host, $port, $user, $pass);
$channel = $connection->channel();
$channel->queue_declare('tasks_tester', false, true, false, false);

echo "[tester_worker] waiting for messages...\n";
$callback = function ($msg) {
    echo "[tester_worker] Received: " . $msg->body . "\n";
    $payload = json_decode($msg->body, true);
    if (!$payload || !isset($payload['task_id'])) {
        $msg->ack();
        return;
    }

    $apiBase = getenv('KANBAN_API') ?: 'http://localhost:8000/api/kanban';
    $taskId = $payload['task_id'];

    if (isset($payload['result']) && $payload['result'] === 'bug') {
        $comment = $payload['comment'] ?? 'Bug found during tests.';
        $userId = $payload['user_id'] ?? null;
        $data = ['comment' => $comment];
        if ($userId) $data['user_id'] = $userId;

        $ch = curl_init("{$apiBase}/tasks/{$taskId}/comments");
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json'
        ]);
        $res = curl_exec($ch);
        curl_close($ch);

        echo "[tester_worker] Created comment on task {$taskId}\n";

        // Optionally notify developer by sending a message to tasks_developer queue
        $channel = $msg->getChannel();
        $channel->basic_publish(
            new \PhpAmqpLib\Message\AMQPMessage(json_encode(['task_id'=>$taskId,'action'=>'bug_reported','comment'=>$comment])),
            '',
            'tasks_developer'
        );
    }

    $msg->ack();
};
$channel->basic_consume('tasks_tester', '', false, false, false, false, $callback);
while ($channel->is_consuming()) {
    $channel->wait();
}
$channel->close();
$connection->close();
