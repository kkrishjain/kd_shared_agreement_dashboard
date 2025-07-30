<?php
include '../config/database.php';

try {
//$pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    if (isset($_GET['id']) && isset($_GET['type'])) {
        $agreement_id = $_GET['id'];
        $type = $_GET['type'];

        // Validate input
        if (!is_numeric($agreement_id)) die("Invalid request");
        
        // Determine file type
        if ($type == "agreement") {
            $column = "agreement_file";
            $default_filename = "agreement.pdf";
            $force_pdf = true;
        } elseif ($type == "commission") {
            $column = "commission_file";
            $default_filename = "commission";
            $force_pdf = false;
        } else {
            die("Invalid file type");
        }

        // Fetch BLOB data
        $stmt = $pdo->prepare("SELECT $column, broker_name FROM agreements WHERE agreement_id = ?");
        $stmt->execute([$agreement_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$row || !$row[$column]) die("File not found in database");
$fileData = $row[$column];
$brokerName = preg_replace('/[^a-zA-Z0-9_]/', '_', $row['broker_name']); // sanitize filename


        if (!$fileData) die("File not found in database");

        // Detect MIME type
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->buffer($fileData);

        // Set headers based on file type
        if ($force_pdf) {
            // Force PDF for agreements
            header("Content-Type: application/pdf");
            $filename = $brokerName . '_agreement.pdf';
        } else {
            // Handle commission files dynamically
            header("Content-Type: $mimeType");
            
            // Map MIME types to extensions
            $extensions = [
                'image/png'  => 'png',
                'image/jpeg' => 'jpg',
                'image/gif'  => 'gif',
                'application/pdf' => 'pdf'
            ];
            
            $extension = $extensions[$mimeType] ?? 'bin';
            $filename = "$default_filename.$extension";
        }

        header("Content-Disposition: attachment; filename=\"$filename\"");
        header("Content-Length: " . strlen($fileData));
        echo $fileData;
        exit();
        
    } else {
        die("Invalid request");
    }
} catch (PDOException $e) {
    die("Database error: " . $e->getMessage());
}
?>