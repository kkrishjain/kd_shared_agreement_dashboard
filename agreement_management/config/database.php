<?php

$host = "e2e-116-195.ssdcloudindia.net";
$dbname = "finqy_dev";
$username = "dev_db_user";
$password = "*fiO*nQY@22)(25#DEV";

try {
    $GLOBALS['pdo'] = new PDO("mysql:host=$host;dbname=$dbname", $username, $password);
    $GLOBALS['pdo']->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}
?>
