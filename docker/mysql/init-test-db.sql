-- Runs once, when the MySQL volume is created: a separate database for the automated tests.
CREATE DATABASE IF NOT EXISTS rihla_test;
GRANT ALL PRIVILEGES ON rihla_test.* TO 'rihla'@'%';
FLUSH PRIVILEGES;
