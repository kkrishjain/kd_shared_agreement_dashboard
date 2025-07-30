<?php
session_start();
require '../../config/database.php';

// Generate CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF validation
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die("Invalid CSRF token.");
    }

    // Entry type validation
    $entryType = $_POST['entry_type'] ?? '';
    if (!in_array($entryType, ['Credit', 'Debit'])) {
        $_SESSION['error'] = "Invalid entry type selected.";
        header("Location: add_bank_entry.php");
        exit;
    }

    // File validation
    if (!isset($_FILES['excel_file']) || $_FILES['excel_file']['error'] !== UPLOAD_ERR_OK) {
        $uploadErrors = [
            UPLOAD_ERR_INI_SIZE => 'File size exceeds server limit (upload_max_filesize).',
            UPLOAD_ERR_FORM_SIZE => 'File size exceeds form limit.',
            UPLOAD_ERR_PARTIAL => 'File was only partially uploaded.',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded.',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder.',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
            UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the file upload.'
        ];
        $_SESSION['error'] = "Excel file upload failed: " . ($uploadErrors[$_FILES['excel_file']['error']] ?? 'Unknown error.');
        header("Location: add_bank_entry.php");
        exit;
    }

    // Validate file type
    $allowedTypes = [
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
    ];
    if (!in_array($_FILES['excel_file']['type'], $allowedTypes)) {
        $_SESSION['error'] = "Invalid file type. Please upload a valid Excel file (.xls or .xlsx).";
        header("Location: add_bank_entry.php");
        exit;
    }
    
    // Move the uploaded file to a temp location
    $tempFile = tempnam(sys_get_temp_dir(), 'bank_entry_');
    if (move_uploaded_file($_FILES['excel_file']['tmp_name'], $tempFile)) {
        // Store entry type and file path in session for upload handler
        $_SESSION['bank_entry_data'] = [
            'entry_type' => $entryType,
            'file_path' => $tempFile
        ];
        
        // Process Excel file in upload handler
        header("Location: bank_entry_upload_handler.php");
        exit;
    } else {
        $_SESSION['error'] = "Failed to process uploaded file.";
        header("Location: add_bank_entry.php");
        exit;
    }
}

// Initialize error message
$error = $_SESSION['error'] ?? '';
unset($_SESSION['error']);
?>
<!DOCTYPE html>
<html>
<head>
    <link rel="stylesheet" href="../css/add_bank_entry.css">
    <title>Bank Entry Upload</title>
    
    <script>
        function updateSampleLink() {
            const entryType = document.getElementById('entry_type').value;
            const link = document.getElementById('sample_link');
            if (entryType) {
                link.href = `bank_entry_sample.php?type=${encodeURIComponent(entryType)}`;
                link.textContent = `Download ${entryType} Template`;
                link.style.display = 'inline-block';
            } else {
                link.style.display = 'none';
            }
        }

        function validateForm() {
            const entryType = document.getElementById('entry_type').value;
            const fileInput = document.getElementById('excel_file');
            
            if (!entryType) {
                alert('Please select an Entry Type');
                return false;
            }
            
            if (!fileInput.files.length) {
                alert('Please upload an Excel file');
                return false;
            }
            
            const file = fileInput.files[0];
            const allowedTypes = [
                'application/vnd.ms-excel',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
            ];
            
            if (!allowedTypes.includes(file.type)) {
                alert('Please upload a valid Excel file (.xls or .xlsx)');
                return false;
            }
            
            return true;
        }

        // Initialize on page load
        document.addEventListener('DOMContentLoaded', function() {
            updateSampleLink();
        });
    </script>
</head>
<body>
    <a href="index.php" class="back-btn">← Back</a>
    <h2>Bank Entry Upload</h2>

    <?php if (!empty($error)) : ?>
        <div class="error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <div class="form-section">
        <form action="add_bank_entry.php" method="post" enctype="multipart/form-data" onsubmit="return validateForm()">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
            
            <h3>Upload Details</h3>
            
            <label>
                Entry Type:
                <select name="entry_type" id="entry_type" required onchange="updateSampleLink()">
                    <option value="">-- Select Entry Type --</option>
                    <option value="Credit">Credit</option>
                    <option value="Debit">Debit</option>
                </select>
            </label>
            
            <label>
                Sample Template:
                <a id="sample_link" href="#" target="_blank" class="sample-link" style="display:none;">Download Template</a>
            </label>
            
            <label>
                Upload Excel File (.xls, .xlsx):
                <input type="file" name="excel_file" id="excel_file" accept=".xls,.xlsx" required>
            </label>
            
            <input type="submit" value="Upload">
        </form>
    </div>
</body>
</html>