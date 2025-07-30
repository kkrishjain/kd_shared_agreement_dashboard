<?php
require '../../config/database.php';


if (!isset($_GET['id'])) {
    die("Invalid request");
}

$id = $_GET['id'];
$stmt = $pdo->prepare("SELECT commission_statement FROM payin_grid WHERE id = ?");
$stmt->execute([$id]);
$data = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$data || empty($data['commission_statement'])) {
    die("File not found");
}

// Detect MIME type using finfo
$finfo = new finfo(FILEINFO_MIME_TYPE);
$content_type = $finfo->buffer($data['commission_statement']);

// Map MIME type to file extension
$mime_to_extension = [
    'application/pdf' => 'pdf',
    'image/png' => 'png',
    'image/jpeg' => 'jpg',
    'image/gif' => 'gif',
];

// Set default content type and extension for unrecognized files
$file_extension = isset($mime_to_extension[$content_type]) ? $mime_to_extension[$content_type] : '';
$content_type = $file_extension ? $content_type : 'application/octet-stream';
$filename = "commission_" . $id . ($file_extension ? '.' . $file_extension : '');

// Force download headers
header('Content-Description: File Transfer');
header('Content-Type: ' . $content_type);
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . strlen($data['commission_statement']));
header('Cache-Control: no-cache, must-revalidate');
header('Expires: 0');

// Output the file content
echo $data['commission_statement'];
exit;
?>