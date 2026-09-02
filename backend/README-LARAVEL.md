Laravel integration notes

1. After running `composer create-project --prefer-dist laravel/laravel .` copy the files from `../migrations` to `database/migrations`.
2. Create `database/seeders/AdminSeeder.php` with provided template and run `php artisan db:seed --class=AdminSeeder` to create the admin user.
3. Add `WhatsappSettings` model and a small settings controller + API route to allow the frontend settings page to read/write the WhatsApp API config.
4. Configure queue driver (e.g., `redis` or `database`) and set up `php artisan queue:work` as needed. Agents will publish to RabbitMQ; use a bridge or a Laravel RabbitMQ package like `vyuldashev/laravel-queue-rabbitmq`.

Suggested packages:
- laravel/ui or breeze/jetstream for frontend auth
- php-amqplib/php-amqplib for agents (if using PHP workers)
- pusher/laravel-websockets or laravel-echo-server for in-app push notifications
