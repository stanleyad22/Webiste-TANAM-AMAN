<?php
include 'koneksi.php';
header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Asia/Jakarta');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$DATE_COL = 'tanggal_pengambilan_data';
$bulan = isset($_POST['bulan']) ? (int)$_POST['bulan'] : null;
$tahun = isset($_POST['tahun']) ? (int)$_POST['tahun'] : null;
$pengambilan = isset($_POST['pengambilan']) && $_POST['pengambilan'] !== '' ? (int)$_POST['pengambilan'] : null;

$prov  = isset($_POST['provinsi'])  ? trim($_POST['provinsi'])  : '';
$kab   = isset($_POST['kabupaten']) ? trim($_POST['kabupaten']) : '';
$kec   = isset($_POST['kecamatan']) ? trim($_POST['kecamatan']) : '';
$level = isset($_POST['level']) ? trim($_POST['level']) : '';
$act   = isset($_POST['act']) ? trim($_POST['act']) : '';

// ===== filter tanggal & pengambilan data untuk riwayat_prediksi =====
$YEAR_EXPR = "CAST(SUBSTRING_INDEX(rp.$DATE_COL, ' ', -1) AS UNSIGNED)";
$MONTH_EXPR = "(CASE SUBSTRING_INDEX(SUBSTRING_INDEX(rp.$DATE_COL, ' ', 2), ' ', -1)
                    WHEN 'Januari' THEN 1 WHEN 'Februari' THEN 2 WHEN 'Maret' THEN 3
                    WHEN 'April' THEN 4 WHEN 'Mei' THEN 5 WHEN 'Juni' THEN 6
                    WHEN 'Juli' THEN 7 WHEN 'Agustus' THEN 8 WHEN 'September' THEN 9
                    WHEN 'Oktober' THEN 10 WHEN 'November' THEN 11 WHEN 'Desember' THEN 12
                    ELSE NULL END)";

$dateWhere = [];
if ($bulan >= 1 && $bulan <= 12) $dateWhere[] = "$MONTH_EXPR = " . intval($bulan);
if (!empty($tahun))             $dateWhere[] = "$YEAR_EXPR = "  . intval($tahun);
if ($pengambilan !== null)      $dateWhere[] = "rp.pengambilan_data = " . intval($pengambilan);
$dateSql = $dateWhere ? ("WHERE " . implode(" AND ", $dateWhere)) : "";

// ========= ACT=filters → kembalikan daftar provinsi + bucket keparahan tersedia =========
if ($act === 'filters') {
    // 1) Daftar provinsi yg ada pada bulan/tahun/pengambilan
    $sqlProv = "
        SELECT DISTINCT TRIM(SUBSTRING_INDEX(rp.kecamatan, ',', -1)) AS provinsi
        FROM riwayat_prediksi rp
        $dateSql
        ORDER BY provinsi
    ";
    $provinsi = [];
    if ($resP = mysqli_query($conn, $sqlProv)) {
        while ($r = mysqli_fetch_assoc($resP)) {
            $p = trim($r['provinsi'] ?? '');
            if ($p !== '') $provinsi[] = $p;
        }
    }

    // 2) Bucket keparahan yang memang muncul (pakai AVG insidensi per kecamatan)
    $sqlInc = "
        SELECT TRIM(SUBSTRING_INDEX(rp.kecamatan, ',', 1)) AS kecamatan,
               AVG(rp.persentase_insidensi) AS avg_inc
        FROM riwayat_prediksi rp
        $dateSql
        GROUP BY kecamatan
    ";
    $bucket = ['0'=>false,'1-10'=>false,'10-30'=>false,'30-50'=>false,'50+'=>false];
    if ($resI = mysqli_query($conn, $sqlInc)) {
        while ($r = mysqli_fetch_assoc($resI)) {
            $inc = (float)($r['avg_inc'] ?? 0);
            if ($inc == 0)                    $bucket['0']     = true;
            elseif ($inc > 0 && $inc <= 10)   $bucket['1-10']  = true;
            elseif ($inc > 10 && $inc <= 30)  $bucket['10-30'] = true;
            elseif ($inc > 30 && $inc <= 50)  $bucket['30-50'] = true;
            elseif ($inc > 50)                $bucket['50+']   = true;
        }
    }
    $severity = [];
    foreach ($bucket as $k => $v) if ($v) $severity[] = $k;

    echo json_encode([
        'provinsi' => $provinsi,
        'severity' => $severity
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ===== Lanjut ke logic lama (pie/drilldown) =====
$baseSql = "
(
  SELECT
    TRIM(SUBSTRING_INDEX(rp.kecamatan, ',', 1))                           AS kecamatan,
    TRIM(SUBSTRING_INDEX(SUBSTRING_INDEX(rp.kecamatan, ',', 2), ',', -1)) AS kabupaten,
    TRIM(SUBSTRING_INDEX(rp.kecamatan, ',', -1))                          AS provinsi,
    rp.nama_wilayah
  FROM riwayat_prediksi rp
  $dateSql
) t
";

// ===== 3) Filter wilayah (diterapkan di luar subquery) =====
$w = [];
if ($prov !== '') $w[] = "t.provinsi = '"  . mysqli_real_escape_string($conn, $prov) . "'";
if ($kab  !== '') $w[] = "t.kabupaten = '" . mysqli_real_escape_string($conn, $kab)  . "'";
if ($kec  !== '') $w[] = "t.kecamatan = '" . mysqli_real_escape_string($conn, $kec)  . "'";
$whereSql = $w ? ("WHERE " . implode(" AND ", $w)) : "";

// ===== 4) Tentukan kolom group sesuai level drill-down =====
if ($kec !== '') {
    $groupCol = 't.nama_wilayah';
} elseif ($kab !== '') {
    $groupCol = 't.kecamatan';
} elseif ($prov !== '') {
    $groupCol = 't.kabupaten';
} else {
    $groupCol = 't.provinsi';
}

// ===== 5) Query akhir =====
$sql = "
  SELECT $groupCol AS label, COUNT(*) AS val
  FROM $baseSql
  $whereSql
  GROUP BY $groupCol
  ORDER BY $groupCol
";

$res = mysqli_query($conn, $sql);
if (!$res) {
    error_log('[api] SQL error: ' . mysqli_error($conn) . ' | ' . $sql);
    http_response_code(500);
    echo json_encode(['labels'=>[], 'data'=>[], 'colors'=>[], 'error'=>'SQL error']);
    exit;
}

// ===== 6) Susun output untuk Chart.js =====
$labels = [];
$data   = [];
$colors = [];
$palette = ["#ff6666","#ff9966","#ffff99","#99ff99","#66ffcc","#99ffff","#9999ff","#ff99ff","#ce99ff"];

$i = 0;
while ($row = mysqli_fetch_assoc($res)) {
    $labels[] = $row['label'];
    $data[]   = (int)$row['val'];
    $colors[] = $palette[$i % count($palette)];
    $i++;
}

echo json_encode(['labels'=>$labels,'data'=>$data,'colors'=>$colors], JSON_UNESCAPED_UNICODE);

?>