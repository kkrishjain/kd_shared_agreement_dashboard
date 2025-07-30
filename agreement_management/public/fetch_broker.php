<?php
require '../config/database.php';

if (isset($_POST['value']) && isset($_POST['type'])) {
    $value = $_POST['value'];
    $type = $_POST['type'];

    $column = ($type == 'id') ? 'br_id' : 'br_name';
    
    $stmt = $pdo->prepare("SELECT br_id, br_name FROM brokers WHERE $column = ?");
    $stmt->execute([$value]);

    $broker = $stmt->fetch(PDO::FETCH_ASSOC);
    
    echo json_encode($broker);
}
?>
