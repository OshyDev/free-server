# free-server

# That's is a code if you want to give "free-servers" at your company (pterodactyl panel required)


# Database

```
CREATE TABLE free_server_challenges (
  id CHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
  email VARCHAR(254) NOT NULL,
  display_name VARCHAR(100) NOT NULL,
  code_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  ip_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  verified_at DATETIME NULL,
  used_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  expires_at DATETIME NOT NULL,
  INDEX idx_free_challenge_email_created (email, created_at),
  INDEX idx_free_challenge_ip_created (ip_hash, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE free_server_orders (
  panel_user_id INT UNSIGNED PRIMARY KEY,
  panel_server_id INT UNSIGNED NULL UNIQUE,
  email VARCHAR(254) NOT NULL,
  status ENUM('provisioning', 'active', 'failed') NOT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  INDEX idx_free_order_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```
