-- Optional SQL helper: a small notifications table for in-app messages
CREATE TABLE IF NOT EXISTS notifications (
  id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  type VARCHAR(255) NOT NULL,
  notifiable_id BIGINT NOT NULL,
  notifiable_type VARCHAR(255) NOT NULL,
  data JSONB NOT NULL,
  read_at TIMESTAMP NULL,
  created_at TIMESTAMP DEFAULT now()
);
