CREATE DATABASE IF NOT EXISTS housing_offers_testing
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

GRANT ALL PRIVILEGES ON housing_offers_testing.* TO 'housing_offers'@'%';

FLUSH PRIVILEGES;
