<?php
session_start();
require 'koneksi.php';
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['success' => false, 'message' => 'Method not allowed']); exit;
}

if (!isset($_SESSION['user_id']) || !isset($_SESSION['status']) || $_SESSION['status'] !== 'admin') {
  http_response_code(403);
  echo json_encode(['success' => false, 'message' => 'Akses ditolak.']); exit;
}

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
$ids  = isset($data['ids']) && is_array($data['ids']) ? $data['ids'] : [];

if (empty($ids)) {
  echo json_encode(['success' => false, 'message' => 'Tidak ada ID yang dikirim.']); exit;
}

$cleanIds = [];
foreach ($ids as $id) {
  if (is_numeric($id)) $cleanIds[] = (int)$id;
}
$cleanIds = array_values(array_unique($cleanIds));

if (empty($cleanIds)) {
  echo json_encode(['success' => false, 'message' => 'ID tidak valid.']); exit;
}

$placeholders = implode(',', array_fill(0, count($cleanIds), '?'));
$types = str_repeat('i', count($cleanIds));

$sql = "DELETE FROM riwayat_prediksi WHERE id IN ($placeholders)";
$stmt = mysqli_prepare($conn, $sql);
if (!$stmt) {
  error_log('[delete_data] prepare failed: ' . mysqli_error($conn));
  http_response_code(500);
  echo json_encode(['success' => false, 'message' => 'Gagal mempersiapkan query.']); exit;
}

mysqli_stmt_bind_param($stmt, $types, ...$cleanIds);
$ok = mysqli_stmt_execute($stmt);

if (!$ok) {
  error_log('[delete_data] execute failed: ' . mysqli_stmt_error($stmt));
  http_response_code(500);
  echo json_encode(['success' => false, 'message' => 'Gagal menghapus data.']); 
  mysqli_stmt_close($stmt);
  mysqli_close($conn);
  exit;
}

$affected = mysqli_stmt_affected_rows($stmt);
mysqli_stmt_close($stmt);
mysqli_close($conn);

echo json_encode([
  'success' => true,
  'message' => "Berhasil menghapus $affected data."
], JSON_UNESCAPED_UNICODE);
