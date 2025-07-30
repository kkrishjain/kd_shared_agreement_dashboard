<?php
$current_path = $_SERVER['REQUEST_URI'];
?>
<div class="persistent-header">
    <i class="fas fa-bars fa-2x nav-toggle" onclick="toggleNav()"></i>
</div>

<nav class="navbar">
    <div class="nav-vertical-group">
        <!-- Dashboard -->
        <a href="/agreement_management/dashboard.php" class="nav-item <?= strpos($current_path, 'dashboard.php') !== false ? 'active' : '' ?>">
            <i class="fas fa-home"></i> Dashboard
        </a>

        <!-- Master Menu -->
        <?php $masterActive = (
            strpos($current_path, '/agreement_management/public/') !== false || 
            strpos($current_path, '/agreement_management/partner_management/') !== false ||
            strpos($current_path, '/agreement_management/cycles/') !== false
        ); ?>
        
        <div class="nav-item parent <?= $masterActive ? 'active' : '' ?>" onclick="toggleSubmenu(event, this)">
            <span><i class="fas fa-file-contract"></i> Master</span>
            <i class="bi bi-chevron-right dropdown-icon"></i>
        </div>
        
        <!-- First Submenu -->
        <div class="sub-menu <?= $masterActive ? 'active' : '' ?>">
            <!-- Principle Agreement -->
            <a href="/agreement_management/public/index.php" class="nav-item <?= strpos($current_path, '/agreement_management/public/') !== false ? 'active' : '' ?>">
                <i class="fas fa-chevron-right"></i> Principal Agreement
            </a>
            
            <!-- Partner Agreements -->
            <a href="/agreement_management/partner_management/index.php" class="nav-item <?= strpos($current_path, '/agreement_management/partner_management/') !== false ? 'active' : '' ?>">
                <i class="fas fa-chevron-right"></i> Partner Agreements
            </a>
            
            <!-- Cycles Management -->
            <a href="/agreement_management/cycles/cycle-index.php" class="nav-item <?= strpos($current_path, '/agreement_management/cycles/') !== false ? 'active' : '' ?>">
                <i class="fas fa-chevron-right"></i> Cycles Management
            </a>
            
            <!-- Cycles Management -->
            <a href="/agreement_management/bank_master/bank_master/index.php" class="nav-item <?= strpos($current_path, 'agreement_management/bank_master/bank_master/') !== false ? 'active' : '' ?>">
                <i class="fas fa-chevron-right"></i> Bank Master
            </a>
        </div>

        <!-- Payin Menu -->
        <?php $payinActive = (
            strpos($current_path, '/agreement_management/payin_grid_upload/') !== false || 
            strpos($current_path, '/agreement_management/payin_lci/') !== false || 
            strpos($current_path, '/agreement_management/payin_health/') !== false || 
            strpos($current_path, '/agreement_management/payin_calculations/') !== false|| 
            strpos($current_path, '/agreement_management/payin_health_calculations/') !== false
        ); ?>
        
        <div class="nav-item parent <?= $payinActive ? 'active' : '' ?>" onclick="toggleSubmenu(event, this)">
            <span><i class="fas fa-file-contract"></i> Payin</span>
            <i class="bi bi-chevron-right dropdown-icon"></i>
        </div>
        
        <!-- Payin Submenu -->
        <div class="sub-menu <?= $payinActive ? 'active' : '' ?>">
            <a href="/agreement_management/payin_grid_upload/public/add_payingrid.php" class="nav-item <?= strpos($current_path, '/agreement_management/payin_grid_upload/') !== false ? 'active' : '' ?>">
                <i class="fas fa-chevron-right"></i> Payin Grid Upload
            </a>
            <a href="/agreement_management/payin_lci/public/index.php" class="nav-item <?= strpos($current_path, '/agreement_management/payin_lci/') !== false ? 'active' : '' ?>">
                <i class="fas fa-chevron-right"></i> Payin LCI
            </a>
            <a href="/agreement_management/payin_health/public/index.php" class="nav-item <?= strpos($current_path, '/agreement_management/payin_health/') !== false ? 'active' : '' ?>">
                <i class="fas fa-chevron-right"></i> Payin Health
            </a>
            <a href="/agreement_management/payin_calculations/index.php" class="nav-item <?= strpos($current_path, '/agreement_management/payin_calculations/') !== false ? 'active' : '' ?>">
                <i class="fas fa-chevron-right"></i> Payin calculation
            </a>
            <a href="/agreement_management/payin_health_calculations/index.php" class="nav-item <?= strpos($current_path, '/agreement_management/payin_health_calculations/') !== false ? 'active' : '' ?>">
                <i class="fas fa-chevron-right"></i> Payin Health calculation
            </a>
        </div>

        <!-- Invoicing Menu -->
        <?php $invoicingActive = (strpos($current_path, '/agreement_management/billing_repo/') !== false); ?>
        
        <div class="nav-item parent <?= $invoicingActive ? 'active' : '' ?>" onclick="toggleSubmenu(event, this)">
            <span><i class="fas fa-file-contract"></i> Invoicing & Reconciliation</span>
            <i class="bi bi-chevron-right dropdown-icon"></i>
        </div>
        
        <!-- Invoicing Submenu -->
        <div class="sub-menu <?= $invoicingActive ? 'active' : '' ?>">
            <a href="/agreement_management/billing_repo/index.php" class="nav-item <?= strpos($current_path, '/agreement_management/billing_repo/') !== false ? 'active' : '' ?>">
                <i class="fas fa-chevron-right"></i> Billing Repository
            </a>
        </div>

        <!-- Payout Menu -->
        <?php $payoutActive = (
            strpos($current_path, '/agreement_management/payout_grid_upload/') !== false || 
            strpos($current_path, '/agreement_management/payout_calculations/') !== false
        ); ?>
        
        <div class="nav-item parent <?= $payoutActive ? 'active' : '' ?>" onclick="toggleSubmenu(event, this)">
            <span><i class="fas fa-file-contract"></i> Payout</span>
            <i class="bi bi-chevron-right dropdown-icon"></i>
        </div>
        
        <!-- Payout Submenu -->
        <div class="sub-menu <?= $payoutActive ? 'active' : '' ?>">
            <a href="/agreement_management/payout_grid_upload/public/index.php" class="nav-item <?= strpos($current_path, '/agreement_management/payout_grid_upload/') !== false ? 'active' : '' ?>">
                <i class="fas fa-chevron-right"></i> Payout Grid Upload
            </a>
            <a href="/agreement_management/payout_calculations/payout_cal.php" class="nav-item <?= strpos($current_path, '/agreement_management/payout_calculations/') !== false ? 'active' : '' ?>">
                <i class="fas fa-chevron-right"></i> Payout Calculations
            </a>
        </div>
    </div>
</nav>

<link rel="stylesheet" href="/agreement_management/navbar.css">
<script src="/agreement_management/navbar.js"></script>