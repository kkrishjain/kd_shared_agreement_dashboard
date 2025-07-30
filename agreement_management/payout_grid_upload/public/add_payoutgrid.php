<?php
session_start();
require '../../config/database.php';

// Generate CSRF token if it doesn't exist
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Fetch products and subproducts
try {
    $products = $pdo->query("SELECT p_id, p_name FROM products WHERE p_status = '1'")->fetchAll(PDO::FETCH_ASSOC);
    $subproducts = $pdo->query("SELECT sp_id, sp_name FROM sub_products WHERE sp_status = '1' AND sp_p_id = '4'")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Database error: " . htmlspecialchars($e->getMessage()));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Optional: Check CSRF token
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die("Invalid CSRF token.");
    }

    // Sanitize and validate inputs
    $product_name = filter_input(INPUT_POST, 'product_name', FILTER_SANITIZE_STRING);
    $subproduct_name = filter_input(INPUT_POST, 'subproduct_name', FILTER_SANITIZE_STRING);

    if (!$product_name || !$subproduct_name) {
        die("Product and subproduct are required.");
    }

    $file_content = null;
    if (isset($_FILES['commission_statement']) && $_FILES['commission_statement']['error'] === UPLOAD_ERR_OK) {
        // Validate file type if uploaded
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $file_mime = $finfo->file($_FILES['commission_statement']['tmp_name']);
        $allowed_mimes = ['application/pdf', 'image/png', 'image/jpeg', 'image/gif'];

        if (!in_array($file_mime, $allowed_mimes)) {
            die("Invalid file type. Only PDF, PNG, JPG, and GIF files are allowed.");
        }

        // Read file content safely
        $file_content = file_get_contents($_FILES['commission_statement']['tmp_name']);
    }

    try {
        // Insert into database
        $stmt = $pdo->prepare("
            INSERT INTO payout_grid (commission_statement, product_name, subproduct_name)
            VALUES (?, ?, ?)
        ");
        $stmt->execute([$file_content, $product_name, $subproduct_name]);

        // Redirect to the main page
        header('Location:index.php');
        exit;
    } catch (PDOException $e) {
        die("Database error: " . htmlspecialchars($e->getMessage()));
    }
}

// Initialize error message
$error = $_SESSION['error'] ?? '';
unset($_SESSION['error']); // Clear error after displaying
?>

<?php if (isset($_SESSION['upload_success'])) : ?>
    <script>
        if (confirm('Payout grid data uploaded successfully.\nClick OK to go back to the dashboard.')) {
            window.location.href = 'index.php';
        }
        <?php unset($_SESSION['upload_success']); ?>
    </script>
<?php endif; ?> 
<!DOCTYPE html>
<html>
<head>
        <link rel="stylesheet" href="../css/payout_add_style.css">

    <title>Payout Grid Upload</title>
    
  <script>
        // Update the sample template link
        function updateSampleLink() {
            const link = document.getElementById('sample_link');
            link.href = `sample_generator.php`;
            link.textContent = `Download Payout Grid Sample Template`;
            const commissionInput = document.getElementById('commission_statement');
            commissionInput.disabled = productSelect.value === '';
        }

        // Enable form fields after product selection
        function enableFormFields() {
            const productSelect = document.getElementById('product_id');
            const fields = document.querySelectorAll('select:not(#product_id), input:not([type="hidden"])');
            fields.forEach(field => {
                field.disabled = productSelect.value === '';
                if (field.disabled) {
                    field.addEventListener('click', function(e) {
                        if (this.disabled) {
                            alert('Please select a Product first');
                            productSelect.focus();
                            e.preventDefault();
                        }
                    });
                }
            });
        }

        // Validate form before submission
        function validateForm() {
            const product = document.getElementById('product_id');
            const subproduct = document.getElementById('subproduct_id');
            const fileInput = document.getElementById('excel_file');
            const commissionInput = document.getElementById('commission_statement');

            if (!product.value) {
                alert('Please select a Product');
                product.focus();
                return false;
            }
            if (!subproduct.value) {
                alert('Please select a Subproduct');
                subproduct.focus();
                return false;
            }
            if (!fileInput.files.length) {
                alert('Please upload an Excel file');
                fileInput.focus();
                return false;
            }
            const file = fileInput.files[0];
            const allowedTypes = ['application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'];
            if (!allowedTypes.includes(file.type)) {
                alert('Please upload a valid Excel file (.xls or .xlsx)');
                fileInput.focus();
                return false;
            }
            return true;
        }

        // Initialize on page load
        document.addEventListener('DOMContentLoaded', function() {
            enableFormFields();
            document.getElementById('product_id').addEventListener('change', enableFormFields);
            updateSampleLink();
        });
    </script>
</head>
<body>
    <a href="index.php" class="back-btn">← Back</a>

    <h2>Payout Grid Upload</h2>

    <?php if (!empty($error)) : ?>
        <div class="error">
            Error: <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <div class="form-section">
        <form action="upload_handler.php" method="post" enctype="multipart/form-data" onsubmit="return validateForm()">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">

            <h3>Upload Details</h3>
            <div class="col-2">
                <label>
                    Product:
                    <select name="product_id" id="product_id" required>
                        <option value="">-- Select Product --</option>
                        <?php foreach ($products as $prod): ?>
                            <option value="<?= $prod['p_id'] ?>"><?= htmlspecialchars($prod['p_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>
                    Sub Product:
                    <select name="subproduct_id" id="subproduct_id" required disabled>
                        <option value="">-- Select Sub Product --</option>
                        <?php foreach ($subproducts as $subprod): ?>
                            <option value="<?= $subprod['sp_id'] ?>"><?= htmlspecialchars($subprod['sp_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </div>
            <label>
                Sample Template:
                <a id="sample_link" href="sample_generator.php" target="_blank" class="sample-link">Download Payout Grid Sample Template</a>
            </label>
            <label>
                Upload Excel File (.xls, .xlsx):
                <input type="file" name="excel_file" id="excel_file" accept=".xls,.xlsx" required disabled>
            </label>
            <label>
                Commission Statement (PDF/Image):
                <input type="file" name="commission_statement" id="commission_statement" accept=".pdf,.jpg,.jpeg,.png" disabled>
            </label>
            <input type="submit" value="Upload">
        </form>
    </div>
</body>
</html>