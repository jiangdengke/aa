<?php
declare(strict_types=1);

require __DIR__ . '/app.php';

if (!app_uses_database()) {
    echo "Database is not enabled. Set DB_DRIVER=mysql first.\n";
    exit(0);
}

app_database();
echo "Database initialized.\n";
