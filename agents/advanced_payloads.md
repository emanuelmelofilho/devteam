# Advanced payload examples for agents

These examples show realistic message shapes to publish to the RabbitMQ queues so the workers can act accordingly.

Developer queue (tasks_developer)
- Update lane:

```json
{ "task_id": 42, "lane": "in_progress", "user_id": 5 }
```

- Mark done and include notes:

```json
{ "task_id": 42, "lane": "done", "user_id": 5, "metadata": { "work_hours": 2.5 } }
```

Tester queue (tasks_tester)
- Report bug found during functional tests:

```json
{
  "task_id": 42,
  "result": "bug",
  "comment": "Steps to reproduce: ...",
  "user_id": 6,
  "severity": "minor"
}
```

DevOps queue (tasks_devops)
- Deploy start/finish notifications:

```json
{ "task_id": 42, "phase": "start", "user_id": 3 }
{ "task_id": 42, "phase": "end", "user_id": 3 }
```

Publishing these with `php agents/publish_example.php <role> '<json>'` will deliver the messages to the matching queue.
