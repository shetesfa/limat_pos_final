<?php
require_once 'config.php';
requireAdmin();
header('Content-Type: application/json');

$product_id = (int)($_GET['product_id'] ?? 0);
if (!$product_id) { echo json_encode(['batches' => []]); exit(); }

$branch_id = (int)($_SESSION['branch_id'] ?? 1);

$stmt = $conn->prepare(
    "SELECT sb.*, u.full_name as received_by_name
     FROM stock_batches sb
     LEFT JOIN users u ON u.id=sb.received_by
     WHERE sb.product_id=? AND sb.branch_id=?
     ORDER BY sb.created_at ASC"
);
$stmt->bind_param('ii', $product_id, $branch_id);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

foreach ($rows as &$r) {
    $r['eth_date']    = ethDateDisplay($r['date_received']);
    $r['eth_created'] = ethDateDisplay($r['created_at']);
}

echo json_encode(['batches' => $rows]);
