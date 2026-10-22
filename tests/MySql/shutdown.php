<?php

require dirname(__DIR__, 2).'/vendor/autoload.php';
require __DIR__.'/environment.php';
$config = verificationEnvironment();
$pdo = verificationPdo($config, true);
// Only this exact, marked disposable server is shut down. No DROP on application databases.
$pdo->exec('SHUTDOWN');
echo "Marked disposable MySQL instance shut down.\n";
