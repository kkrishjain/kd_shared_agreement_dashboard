<?php
include '../config/database.php';

try {
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    if (isset($_GET['id']) && isset($_GET['type'])) {
        $agreement_id = $_GET['id'];
        $type = $_GET['type'];

        if (!is_numeric($agreement_id)) die("Invalid request");
        
        if ($type == "agreement") {
            $column = "agreement_pdf";
            $force_pdf = true;
        } elseif ($type == "cheque") {
            $column = "cheque_file";
            $force_pdf = false;
        } else {
            die("Invalid file type");
        }

        $stmt = $pdo->prepare("SELECT agreement_pdf, cheque_file, partner_name 
                              FROM partner_agreement 
                              WHERE agreement_id = ?");
        $stmt->execute([$agreement_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$row || !$row[$column]) die("File not found in database");
        
        $fileData = $row[$column];
        $partnerName = preg_replace('/[^a-zA-Z0-9_]/', '_', $row['partner_name']);

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->buffer($fileData);

        if ($force_pdf) {
            header("Content-Type: application/pdf");
            $filename = "{$partnerName}_agreement.pdf";
        } else {
            header("Content-Type: $mimeType");
            
            $extensions = [
                'image/png'  => 'png',
                'image/jpeg' => 'jpg',
                'image/gif'  => 'gif',
                'application/pdf' => 'pdf'
            ];
            
            $extension = $extensions[$mimeType] ?? 'bin';
            $filename = "{$partnerName}_cheque.{$extension}";
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