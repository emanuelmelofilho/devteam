<?php
// Simple publisher examples for RabbitMQ queues used by agents.
// Usage: php publish_example.php developer '{"task_id":123,"lane":"in_progress"}'

require __DIR__ . '/../backend/vendor/autoload.php';
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

$host = getenv('RABBITMQ_HOST') ?: 'rabbitmq';
$port = getenv('RABBITMQ_PORT') ?: 5672;
$user = getenv('RABBITMQ_USER') ?: 'guest';
$pass = getenv('RABBITMQ_PASS') ?: 'guest';

$connection = new AMQPStreamConnection($host, $port, $user, $pass);
$channel = $connection->channel();

$queues = ['developer' => 'tasks_developer', 'tester' => 'tasks_tester', 'devops' => 'tasks_devops'];

$role = $argv[1] ?? 'developer';
$body = $argv[2] ?? json_encode(['task_id' => 1, 'lane' => 'in_progress']);

$queue = $queues[$role] ?? 'tasks_developer';
$channel->queue_declare($queue, false, true, false, false);

$msg = new AMQPMessage($body, ['delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT]);
$channel->basic_publish($msg, '', $queue);

echo "Published to {$queue}: {$body}\n";

$channel->close();
$connection->close();
