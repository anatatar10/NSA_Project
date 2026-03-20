CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    email VARCHAR(255) NULL,
    password_hash VARCHAR(255) NULL,
    confirmed TINYINT(1) NOT NULL DEFAULT 1
);
INSERT INTO users (name, email, confirmed)
SELECT 'Initial User', 'initial@works-on-my-machine.rip', 1
WHERE NOT EXISTS (SELECT 1 FROM users WHERE name = 'Initial User');
