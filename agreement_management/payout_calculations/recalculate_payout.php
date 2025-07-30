<?php
header('Content-Type: application/json');
set_time_limit(300);
require '../config/database.php';

try {
    // Start transaction
    $pdo->beginTransaction();

    // Step 1: Fetch eligible policies
    $lciQuery = "SELECT 
        l.id AS lead_id, l.customer_name, l.pivc_status, l.sv_status, l.policy_no, l.policy_issued, l.ref AS partner_id,
        c.c_name AS insurance_company_name, l.company AS company_code,
        p.pl_name AS plan_name, l.plan_name AS plan_code,
        l.policy_termm AS pt, l.premiun_paying_term AS ppt,
        l.net_amt AS net_premium,
        fr.rname AS partner_name, cup.name AS rm_name, cup.team AS team_name
    FROM life_cum_investment_form_temp l
    LEFT JOIN companies c ON l.company = c.c_id
    LEFT JOIN plans p ON l.plan_name = p.pl_id
    LEFT JOIN first_register fr ON l.ref = fr.refercode
    LEFT JOIN corporate_user_permission cup ON fr.addedBy = cup.id
    WHERE l.case_type = 'New Fresh'
    AND l.status = 'Policy Issued'
    AND l.policy_issued IS NOT NULL
    AND l.net_amt IS NOT NULL
    AND l.login_pt IS NOT NULL
    AND l.login_ppt IS NOT NULL
    AND l.company IS NOT NULL
    AND l.plan_name IS NOT NULL";

    $lciStmt = $pdo->query($lciQuery);
    $eligiblePolicies = $lciStmt->fetchAll(PDO::FETCH_ASSOC);

    // Step 2: Clear old entries
    $leadIds = array_column($eligiblePolicies, 'lead_id');
    if (!empty($leadIds)) {
        $placeholders = implode(',', array_fill(0, count($leadIds), '?'));
        $deleteStmt = $pdo->prepare("DELETE FROM payout_calculation WHERE lead_id IN ($placeholders)");
        $deleteStmt->execute($leadIds);
    }

    // Step 3: Prepare statements once
    $gridQuery = "SELECT 
            pg.applicable_percentage AS payout_percent,
            pg.premium_from, pg.premium_to,
            pg.brokerage_from AS applicable_from,
            pg.brokerage_to AS applicable_to,
            COALESCE(pa.gst, 0) AS gst,
            COALESCE(pa.tds, 0) AS tds
        FROM payout_grid pg
        LEFT JOIN partner_agreement pa 
            ON pg.partner_finqy_id = pa.partner_id
            AND pg.subproduct_code = pa.sub_product_id
        WHERE pg.partner_finqy_id = :partner_id
        AND pg.company_code = :company_code
        AND pg.plan_code = :plan_code
        AND pg.pt = :pt
        AND pg.ppt = :ppt
        AND :net_premium BETWEEN pg.premium_from AND pg.premium_to
        AND :policy_issued BETWEEN pg.brokerage_from AND pg.brokerage_to
        LIMIT 1";
    $gridStmt = $pdo->prepare($gridQuery);
    
    $insertQuery = "INSERT INTO payout_calculation (
        lead_id, product, sub_product, policy_no, customer_name, partner_id, 
        partner_name, company_name, plan_name, pt_ppt, net_premium, policy_issued, 
        payout_percent, payout_amount, tds_percent, tds_amount, gst_percent, 
        gst_amount, gross_receipt, net_receipt, rm_name, team, pivc_status, sv_status
    ) VALUES (
        :lead_id, :product, :sub_product, :policy_no, :customer_name, :partner_id, 
        :partner_name, :company_name, :plan_name, :pt_ppt, :net_premium, :policy_issued, 
        :payout_percent, :payout_amount, :tds_percent, :tds_amount, :gst_percent, 
        :gst_amount, :gross_receipt, :net_receipt, :rm_name, :team, :pivc_status, :sv_status)";
    $insertStmt = $pdo->prepare($insertQuery);
    $results = [];

    foreach ($eligiblePolicies as $policy) {
        $policyDate = date('Y-m-d', strtotime($policy['policy_issued']));
        $gridStmt->execute([
            ':partner_id' => $policy['partner_id'],
            ':company_code' => $policy['company_code'],
            ':plan_code' => $policy['plan_code'],
            ':pt' => $policy['pt'],
            ':ppt' => $policy['ppt'],
            ':net_premium' => $policy['net_premium'],
            ':policy_issued' => $policyDate
        ]);
        $gridData = $gridStmt->fetch(PDO::FETCH_ASSOC);

        if ($gridData) {
            $payoutAmount = $policy['net_premium'] * $gridData['payout_percent'] / 100;
            $tds = $gridData['tds'];
            $gst = $gridData['gst'];

            $tdsAmount = $payoutAmount * $tds / 100;
            $gstAmount = $payoutAmount * $gst / 100;
            $gross = $payoutAmount - $tdsAmount + $gstAmount;
            $net = $payoutAmount - $tdsAmount;

            $row = [
                'lead_id' => $policy['lead_id'],
                'product' => 'Insurance',
                'sub_product' => 'Life Cum Investment',
                'policy_no' => $policy['policy_no'],
                'customer_name' => $policy['customer_name'],
                'partner_id' => $policy['partner_id'],
                'partner_name' => $policy['partner_name'] ?? 'N/A',
                'company_name' => $policy['insurance_company_name'],
                'plan_name' => $policy['plan_name'],
                'pt_ppt' => $policy['pt'] . '/' . $policy['ppt'],
                'net_premium' => $policy['net_premium'],
                'policy_issued' => $policy['policy_issued'],
                'payout_percent' => $gridData['payout_percent'],
                'payout_amount' => $payoutAmount,
                'tds_percent' => $tds,
                'tds_amount' => $tdsAmount,
                'gst_percent' => $gst,
                'gst_amount' => $gstAmount,
                'gross_receipt' => $gross,
                'net_receipt' => $net,
                'rm_name' => $policy['rm_name'] ?? 'N/A',
                'team' => $policy['team_name'] ?? 'N/A',
                'pivc_status' => $policy['pivc_status'],
                'sv_status' => $policy['sv_status']

            ];

            $results[] = $row;
            $insertStmt->execute($row);
        }
    }

    // Commit transaction
    $pdo->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Recalculation completed',
        'eligible_policies' => count($eligiblePolicies),
        'matched_grids' => count($results)
    ]);

} catch (Exception $e) {
    // Rollback transaction on error
    $pdo->rollBack();
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
?>