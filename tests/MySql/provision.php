<?php

// Only the freshly initialized instance is accepted; never uses application credentials.
require dirname(__DIR__, 2).'/vendor/autoload.php';
$directory = str_replace('\\', '/', dirname(__DIR__, 2)).'/storage/framework/testing/mysql-verification-20261022';
$marker = 'taskbridge-disposable-mysql-verification-20261022';
if (trim(file_get_contents($directory.'/owner.marker')) !== $marker || file_exists($directory.'/connection.json')) {
    throw new RuntimeException('Refusing unmarked or already provisioned instance.');
}
$pdo = new PDO('mysql:host=127.0.0.1;port=33079', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$identity = $pdo->query('SELECT @@port port, @@datadir directory')->fetch(PDO::FETCH_ASSOC);
if ((int) $identity['port'] !== 33079 || str_replace('\\', '/', rtrim($identity['directory'], '/\\')) !== $directory.'/data') {
    throw new RuntimeException('Wrong isolated server.');
}
$password = bin2hex(random_bytes(24));
$rootPassword = bin2hex(random_bytes(24));
$pdo->exec('CREATE DATABASE taskbridge_test_mysql_20261022 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$pdo->exec("CREATE USER 'verification'@'127.0.0.1' IDENTIFIED BY ".$pdo->quote($password));
$pdo->exec("GRANT ALL PRIVILEGES ON taskbridge_test_mysql_20261022.* TO 'verification'@'127.0.0.1'");
$pdo->exec('USE taskbridge_test_mysql_20261022');
$pdo->exec('CREATE TABLE verification_safety (marker VARCHAR(100) NOT NULL PRIMARY KEY)');
$pdo->prepare('INSERT INTO verification_safety VALUES (?)')->execute([$marker]);
$pdo->exec("ALTER USER 'root'@'localhost' IDENTIFIED BY ".$pdo->quote($rootPassword));
file_put_contents($directory.'/connection.json', json_encode(['database' => 'taskbridge_test_mysql_20261022', 'port' => 33079, 'marker' => $marker, 'password' => $password, 'root_password' => $rootPassword], JSON_THROW_ON_ERROR));
echo 'Isolated MySQL provisioned with a database-restricted test user. Secrets stay in ignored storage.'.PHP_EOL;
