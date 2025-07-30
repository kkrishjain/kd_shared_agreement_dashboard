<?php require_once "../public/index.php"; ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Agreements</title>
    <link rel="stylesheet" href="../css/style.css">
</head>
<body>
    <h2>Agreements</h2>
    <table>
        <tr>
            <th>Broker Name</th>
            <th>Start Date</th>
            <th>End Date</th>
            <th>Agreement</th>
            <th>Commission</th>
        </tr>
        <?php foreach ($agreements as $agreement): ?>
            <tr>
                <td><?= $agreement['broker_name'] ?></td>
                <td><?= $agreement['start_date'] ?></td>
                <td><?= $agreement['end_date'] ?></td>
                <td><a href="<?= $agreement['agreement_file'] ?>" download>Download</a></td>
                <td><a href="<?= $agreement['commission_file'] ?>" download>Download</a></td>
            </tr>
        <?php endforeach; ?>
    </table>
</body>
</html>
