CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    email VARCHAR(255) UNIQUE NULL,
    password_hash VARCHAR(255) NULL,
    confirmed TINYINT(1) NOT NULL DEFAULT 1,
    confirmation_token VARCHAR(64) NULL
    );

INSERT INTO users (name, email, confirmed)
SELECT 'Initial User', 'initial@works-on-my-machine.rip', 1
    WHERE NOT EXISTS (
    SELECT 1 FROM users WHERE email = 'initial@works-on-my-machine.rip'
);

CREATE USER IF NOT EXISTS 'repl'@'%' IDENTIFIED BY 'replpass';
GRANT REPLICATION SLAVE ON *.* TO 'repl'@'%';
FLUSH PRIVILEGES;