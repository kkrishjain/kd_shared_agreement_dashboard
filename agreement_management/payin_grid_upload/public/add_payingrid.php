<?php
session_start();
require '../../config/database.php';
require '../../navbar.php'; // Add this line to include the navbar

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
    $product_id = filter_input(INPUT_POST, 'product_id', FILTER_SANITIZE_NUMBER_INT);
    $subproduct_id = filter_input(INPUT_POST, 'subproduct_id', FILTER_SANITIZE_NUMBER_INT);
    $payin_category = filter_input(INPUT_POST, 'payin_category', FILTER_SANITIZE_STRING);

    if (!$product_id || !$subproduct_id || !$payin_category) {
        die("Product, subproduct, and category are required.");
    }

    // Get product and subproduct names for insertion
    $stmt = $pdo->prepare("SELECT p_name FROM products WHERE p_id = ?");
    $stmt->execute([$product_id]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);
    $product_name = $product['p_name'] ?? 'Insurance'; // Default to 'Insurance' if not found

    $stmt = $pdo->prepare("SELECT sp_name FROM sub_products WHERE sp_id = ?");
    $stmt->execute([$subproduct_id]);
    $subproduct = $stmt->fetch(PDO::FETCH_ASSOC);
    $subproduct_name = $subproduct['sp_name'] ?? '';

    if (isset($_FILES['commission_statement']) && $_FILES['commission_statement']['error'] === UPLOAD_ERR_OK) {
        // Validate file type
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $file_mime = $finfo->file($_FILES['commission_statement']['tmp_name']);
        $allowed_mimes = ['application/pdf', 'image/png', 'image/jpeg', 'image/gif'];

        if (!in_array($file_mime, $allowed_mimes)) {
            die("Invalid file type. Only PDF, PNG, JPG, and GIF files are allowed.");
        }

        // Read file content safely
        $file_content = file_get_contents($_FILES['commission_statement']['tmp_name']);

        try {
            // Determine which table to use based on subproduct
            if ($subproduct_name === 'Life Cum Investment') {
                $table_name = 'payin_grid';
                $success_msg = 'Upload successful to payin_grid (Life Cum Investment)';
            } elseif ($subproduct_name === 'Health Insurance') {
                $table_name = 'ins_payin_grid_health';
                $success_msg = 'Upload successful to ins_payin_grid_health';
            } else {
                $table_name = 'payin_grid';
                $success_msg = 'Payin grid data uploaded successfully';
            }

            // Insert into the appropriate table
            $stmt = $pdo->prepare("
                INSERT INTO $table_name (commission_statement, product_name, subproduct_name)
                VALUES (?, ?, ?)
            ");
            $stmt->execute([$file_content, $product_name, $subproduct_name]);

            $_SESSION['success_message'] = $success_msg;

            // Redirect back to this page to show the success message
            header('Location: add_payingrid.php');
            exit;
        } catch (PDOException $e) {
            die("Database error: " . htmlspecialchars($e->getMessage()));
        }
    } else {
        die("File upload failed.");
    }
}

// Initialize messages
$error = $_SESSION['error'] ?? '';
unset($_SESSION['error']); // Clear error after displaying

$success_message = $_SESSION['success_message'] ?? '';
unset($_SESSION['success_message']); // Clear success message after displaying
?>

<!DOCTYPE html>
<html>
<head>
    <link rel="stylesheet" href="/agreement_management/navbar.css">
    <script src="/agreement_management/navbar.js"></script>
    <link rel="stylesheet" href="../css/payin_add_style.css">
    <title>Payin Grid Upload</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.0/font/bootstrap-icons.css">
    <script>
        // Update payin categories based on selected subproduct
        function updatePayinCategories() {
            const subproductSelect = document.getElementById('subproduct_id');
            const categorySelect = document.getElementById('payin_category');
            const sampleLabel = document.getElementById('sample_label');
            const sampleLink = document.getElementById('sample_link');
            const commissionInput = document.getElementById('commission_statement');
            
            // Clear existing options except the first
            while (categorySelect.options.length > 1) {
                categorySelect.remove(1);
            }
            
            // Get selected subproduct name
            const selectedOption = subproductSelect.options[subproductSelect.selectedIndex];
            const subproductName = selectedOption.text;
            
            // Life Cum Investment - show all categories
            if (subproductName === 'Life Cum Investment') {
                addCategoryOption(categorySelect, 'Base');
                addCategoryOption(categorySelect, 'ORC');
                addCategoryOption(categorySelect, 'Incentive');
                addCategoryOption(categorySelect, 'Contest');
                sampleLabel.style.display = 'block';
            } 
            // Health Insurance - show only Base and ORC
            else if (subproductName === 'Health Insurance') {
                addCategoryOption(categorySelect, 'Base');
                addCategoryOption(categorySelect, 'Reward');
                sampleLabel.style.display = 'block';
            } 
            // Other subproducts - hide sample link
            else {
                sampleLabel.style.display = 'none';
            }
            
            // Update sample link immediately
            updateSampleLink();
            
            // Enable/disable commission input based on category availability
            commissionInput.disabled = categorySelect.options.length <= 1;
        }
        
        // Helper function to add category options
        function addCategoryOption(selectElement, value) {
            const option = document.createElement('option');
            option.value = value;
            option.textContent = value;
            selectElement.appendChild(option);
        }
        /*// New function to handle form submission
        function handleFormSubmission() {
            const subproductSelect = document.getElementById('subproduct_id');
            const selectedSubproduct = subproductSelect.options[subproductSelect.selectedIndex].text;
            
            if (selectedSubproduct === 'Health Insurance') {
                if (validateForm()) {
                    window.location.href = '/agreement_management/payin_health/public/index.php';
                    return false; // Prevent default form submission
                }
            } else if (selectedSubproduct === 'Life Cum Investment') {
                if (validateForm()) {
                    window.location.href = '/agreement_management/payin_lci/public/index.php';
                    return false; // Prevent default form submission
                }
            }
            
            // For other subproducts, proceed with normal form submission
            return validateForm();
        }*/
        // Update the sample template link based on category
        function updateSampleLink() {
            const category = document.getElementById('payin_category').value;
            const link = document.getElementById('sample_link');
            const subproductSelect = document.getElementById('subproduct_id');
            
            // Only update if we have a valid category
            if (category) {
                const subproductName = subproductSelect.options[subproductSelect.selectedIndex].text;
                link.href = `sample_generator.php?category=${encodeURIComponent(category)}&product_type=${encodeURIComponent(subproductName)}`;
                link.textContent = `Download ${category} Sample Template`;
            }
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
            // Update categories when product changes
            updatePayinCategories();
        }

        // Validate form before submission
        function validateForm() {
            const product = document.getElementById('product_id');
            const subproduct = document.getElementById('subproduct_id');
            const category = document.getElementById('payin_category');
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
            if (!category.value) {
                alert('Please select a Payin Category');
                category.focus();
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
            // Validate Commission Statement
            if (!commissionInput.files.length) {
                alert('Please upload a Commission Statement (PDF/Image)');
                commissionInput.focus();
                return false;
            }
            const commissionFile = commissionInput.files[0];
            const allowedCommissionTypes = ['application/pdf', 'image/jpeg', 'image/png'];
            if (!allowedCommissionTypes.includes(commissionFile.type)) {
                alert('Commission Statement must be PDF, JPG, or PNG');
                commissionInput.focus();
                return false;
            }
            return true;
        }

        // Initialize on page load
        document.addEventListener('DOMContentLoaded', function() {
            enableFormFields();
            document.getElementById('product_id').addEventListener('change', enableFormFields);
            document.getElementById('subproduct_id').addEventListener('change', updatePayinCategories);
            document.getElementById('payin_category').addEventListener('change', updateSampleLink);
        });
    </script>
</head>
<body>
    <div class="content">

    <h2>Payin Grid Upload</h2>

    <?php if (!empty($error)) : ?>
    <div class="error">
        Error: <?= htmlspecialchars($error) ?>
    </div>
<?php endif; ?>

<?php if (!empty($success_message)) : ?>
    <div class="success">
        <?= htmlspecialchars($success_message) ?>
    </div>
<?php endif; ?>

<?php if (isset($_SESSION['success'])): ?>
    <div class="success">
        <?= $_SESSION['success']; ?>
        <?php unset($_SESSION['success']); ?>
    </div>
<?php endif; ?>

<?php if (isset($_SESSION['error'])): ?>
    <div class="error">
        <?= $_SESSION['error']; ?>
        <?php unset($_SESSION['error']); ?>
    </div>
<?php endif; ?>
    

    <div class="form-section">
<form method="post" enctype="multipart/form-data" action="upload_handler.php" onsubmit="return validateForm()">            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">

            <h3>Upload Details</h3>
            <div class="col-2">
                <label>
                    Product:
                    <select name="product_id" id="product_id" required>
                        <option value="">-- Select Product --</option>
                        <?php foreach ($products as $prod): ?>
                            <option value="<?= $prod['p_id'] ?>" <?= $prod['p_id'] == 4 ? 'selected' : '' ?>><?= htmlspecialchars($prod['p_name']) ?></option>
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
                Payin Category:
                <select id="payin_category" name="payin_category" required disabled>
                    <option value="">-- Select Category --</option>
                    <!-- Options will be populated dynamically -->
                </select>
            </label>
            <label id="sample_label" style="display:none;">
                Sample Template:
                <a id="sample_link" href="#" target="_blank" class="sample-link">Download Sample Template</a>
            </label>
            <label>
                Upload Excel File (.xls, .xlsx):
                <input type="file" name="excel_file" id="excel_file" accept=".xls,.xlsx" required disabled>
            </label>
            <label>
                Commission Statement (PDF/Image):
                <input type="file" name="commission_statement" id="commission_statement" accept=".pdf,.jpg,.jpeg,.png" required disabled>
            </label>
            <input type="submit" value="Upload">
        </form>
    </div>
    </div>
</body>
</html>