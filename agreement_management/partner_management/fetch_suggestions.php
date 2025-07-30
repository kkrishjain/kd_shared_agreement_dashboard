<?php
session_start();
require '../config/database.php';

$searchTerm = $_GET['query'] ?? '';
$searchType = $_GET['type'] ?? 'name';
$section = $_GET['section'] ?? 'partner'; // 'partner' or 'main'
$results = [];

try {
    $tables = [];
    
    // Determine which tables to search based on section
    if ($section === 'partner') {
        $tables = [
            ['table' => 'first_register', 'name_field' => 'rname', 'source' => 'partner'],
            ['table' => 'corporate_connector', 'name_field' => 'master_refercode', 'source' => 'connector'],
            ['table' => 'corp_leader', 'name_field' => 'leader_of', 'source' => 'team']
        ];
    } else { // main section
        $tables = [
            ['table' => 'first_register', 'name_field' => 'rname', 'source' => 'partner']
        ];
    }
    
    $allResults = [];
    foreach ($tables as $tableConfig) {
        $table = $tableConfig['table'];
        $nameField = $tableConfig['name_field'];
        $source = $tableConfig['source'];
        
        if ($table === 'first_register') {
            // For first_register table - standard search
            $column = ($searchType === 'id') ? 'refercode' : $nameField;
            $query = "SELECT refercode, $nameField AS rname, '$source' AS source 
                      FROM $table 
                      WHERE $column LIKE :search";
            
            $stmt = $pdo->prepare($query);
            $stmt->execute([':search' => '%' . $searchTerm . '%']);
            $allResults = array_merge($allResults, $stmt->fetchAll(PDO::FETCH_ASSOC));
            
        } elseif ($table === 'corporate_connector') {
            // For connector table - handle both direct search and first_register lookup
            if ($searchType === 'id') {
                // Search by refercode in connector table
                $query = "SELECT refercode, $nameField AS rname, '$source' AS source 
                          FROM $table 
                          WHERE refercode LIKE :search";
                $stmt = $pdo->prepare($query);
                $stmt->execute([':search' => '%' . $searchTerm . '%']);
                $allResults = array_merge($allResults, $stmt->fetchAll(PDO::FETCH_ASSOC));
            } else {
                // For name search: search in first_register and match in connector
                $query = "SELECT cc.refercode, cc.$nameField AS rname, '$source' AS source 
                          FROM corporate_connector cc
                          JOIN first_register fr ON cc.$nameField = fr.refercode
                          WHERE fr.rname LIKE :search OR cc.$nameField LIKE :search";
                $stmt = $pdo->prepare($query);
                $stmt->execute([':search' => '%' . $searchTerm . '%']);
                $allResults = array_merge($allResults, $stmt->fetchAll(PDO::FETCH_ASSOC));
            }
            
        } elseif ($table === 'corp_leader') {
            // For team table - handle both direct search and first_register lookup
            if ($searchType === 'id') {
                // Search by refercode in team table
                $query = "SELECT refercode, $nameField AS rname, '$source' AS source 
                          FROM $table 
                          WHERE refercode LIKE :search";
                $stmt = $pdo->prepare($query);
                $stmt->execute([':search' => '%' . $searchTerm . '%']);
                $allResults = array_merge($allResults, $stmt->fetchAll(PDO::FETCH_ASSOC));
            } else {
                // For name search: search in first_register and match in team
                $query = "SELECT cl.refercode, cl.$nameField AS rname, '$source' AS source 
                          FROM corp_leader cl
                          JOIN first_register fr ON cl.$nameField = fr.refercode
                          WHERE fr.rname LIKE :search OR cl.$nameField LIKE :search";
                $stmt = $pdo->prepare($query);
                $stmt->execute([':search' => '%' . $searchTerm . '%']);
                $allResults = array_merge($allResults, $stmt->fetchAll(PDO::FETCH_ASSOC));
            }
        }
    }

    // Sort results by relevance
    usort($allResults, function($a, $b) use ($searchTerm) {
        $aVal = $a['refercode'] . $a['rname'];
        $bVal = $b['refercode'] . $b['rname'];
        $aPos = stripos($aVal, $searchTerm);
        $bPos = stripos($bVal, $searchTerm);
        
        if ($aPos === $bPos) return 0;
        return ($aPos < $bPos) ? -1 : 1;
    });
    
    $results = array_slice($allResults, 0, 10);
    
} catch (PDOException $e) {
    error_log("Search Error: " . $e->getMessage());
}

header('Content-Type: application/json');
echo json_encode($results);
?>