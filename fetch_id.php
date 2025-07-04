<?php
require '../config/database.php';

if (isset($_GET['value'])) {
    $value = trim($_GET['value']);
    $type = $_GET['type'] ?? 'id';
    $section = $_GET['section'] ?? 'partner'; // 'partner' or 'main'

    $partner = null;

    if ($section === 'partner') {
        // Try first_register
        $column = ($type == 'id') ? 'refercode' : 'rname';
        $stmt = $pdo->prepare("
            SELECT fr.refercode, fr.rname, fr.addedby, fr.product, 'first_register' AS source
            FROM first_register fr 
            WHERE LOWER(fr.$column) = LOWER(?)
        ");
        $stmt->execute([$value]);
        $partner = $stmt->fetch(PDO::FETCH_ASSOC);

        // If not found, try corporate_connector
        if (!$partner) {
            $column = ($type == 'id') ? 'refercode' : 'master_refercode';
            $stmt = $pdo->prepare("
                SELECT fr.refercode, fr.rname, fr.addedby, fr.product, 'corporate_connector' AS source
                FROM corporate_connector cc
                JOIN first_register fr ON cc.master_refercode = fr.refercode
                WHERE LOWER(cc.$column) = LOWER(?)
            ");
            $stmt->execute([$value]);
            $partner = $stmt->fetch(PDO::FETCH_ASSOC);
        }

        // If still not found, try corp_leader
        if (!$partner) {
            $column = ($type == 'id') ? 'refercode' : 'leader_of';
            $stmt = $pdo->prepare("
                SELECT fr.refercode, fr.rname, fr.addedby, fr.product, 'corp_leader' AS source
                FROM corp_leader cl
                JOIN first_register fr ON cl.leader_of = fr.refercode
                WHERE LOWER(cl.$column) = LOWER(?)
            ");
            $stmt->execute([$value]);
            $partner = $stmt->fetch(PDO::FETCH_ASSOC);
        }
    } else {
        // For main section: only first_register
        $column = ($type == 'id') ? 'refercode' : 'rname';
        $stmt = $pdo->prepare("
            SELECT fr.refercode, fr.rname, fr.addedby, fr.product, 'first_register' AS source
            FROM first_register fr 
            WHERE LOWER(fr.$column) = LOWER(?)
        ");
        $stmt->execute([$value]);
        $partner = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if ($partner) {
        // Fetch RM Name and Team from corporate_user_permission
        $stmt = $pdo->prepare("
            SELECT name AS rm_name, team 
            FROM corporate_user_permission 
            WHERE id = ?
        ");
        $stmt->execute([$partner['addedby']]);
        $rmTeam = $stmt->fetch(PDO::FETCH_ASSOC);

        // Merge partner and RM/team data, default to empty strings if no RM/team found
        echo json_encode(array_merge(
            $partner,
            $rmTeam ?: ['rm_name' => '', 'team' => '']
        ));
    } else {
        echo json_encode([]);
    }
}
?>