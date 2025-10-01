<?php
// map_api.php
include 'koneksi.php';
header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Asia/Jakarta');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['error'=>'Method not allowed']); exit;
}

$DATE_COL = 'tanggal_pengambilan_data';
$bulan = isset($_POST['bulan']) ? (int)$_POST['bulan'] : null;
$tahun = isset($_POST['tahun']) ? (int)$_POST['tahun'] : null;
$pengambilan = isset($_POST['pengambilan']) && $_POST['pengambilan'] !== '' ? (int)$_POST['pengambilan'] : null;
$level = isset($_POST['level']) ? trim($_POST['level']) : 'provinsi'; 
$filterProv = isset($_POST['filter_provinsi']) ? trim($_POST['filter_provinsi']) : '';

function safeName($s){
  $s = trim($s);
  if (function_exists('mb_convert_case')) $s = mb_convert_case($s, MB_CASE_TITLE, 'UTF-8');
  else $s = ucwords(strtolower($s));
  $s = str_replace(['/', '\\', ':', '*', '?', '"', '<', '>', '|'], ' ', $s);
  return preg_replace('/\s+/', ' ', $s);
}

function pathProv($prov){
  $p = safeName($prov);
  return __DIR__ . "/assets/geo/Provinsi {$p}/Provinsi {$p}.geojson";
}
function pathKab($prov,$kab){
  $P = safeName($prov);
  $K = safeName($kab);
  $kabPath = __DIR__ . "/assets/geo/Provinsi {$P}/Kabupaten {$K}/Kabupaten {$K}.geojson";
  if (file_exists($kabPath)) return $kabPath;
  $kotaPath = __DIR__ . "/assets/geo/Provinsi {$P}/Kota {$K}/Kota {$K}.geojson";
  return $kotaPath;
}
function pathKec($prov,$kab,$kec){
  $P = safeName($prov);
  $K = safeName($kab);
  $C = safeName($kec);
  $kecPath = __DIR__ . "/assets/geo/Provinsi {$P}/Kabupaten {$K}/Kecamatan {$C}.geojson";
  if (file_exists($kecPath)) return $kecPath;
  $altPath = __DIR__ . "/assets/geo/Provinsi {$P}/Kota {$K}/Kecamatan {$C}.geojson";
  return $altPath;
}

$YEAR_EXPR = "CAST(SUBSTRING_INDEX(rp.$DATE_COL, ' ', -1) AS UNSIGNED)";
$MONTH_EXPR = "(CASE SUBSTRING_INDEX(SUBSTRING_INDEX(rp.$DATE_COL, ' ', 2), ' ', -1)
                    WHEN 'Januari' THEN 1 WHEN 'Februari' THEN 2 WHEN 'Maret' THEN 3
                    WHEN 'April' THEN 4 WHEN 'Mei' THEN 5 WHEN 'Juni' THEN 6
                    WHEN 'Juli' THEN 7 WHEN 'Agustus' THEN 8 WHEN 'September' THEN 9
                    WHEN 'Oktober' THEN 10 WHEN 'November' THEN 11 WHEN 'Desember' THEN 12
                    ELSE NULL END)";

$dateWhere = [];
if ($bulan >= 1 && $bulan <= 12) $dateWhere[] = "$MONTH_EXPR = " . intval($bulan);
if (!empty($tahun))              $dateWhere[] = "$YEAR_EXPR = "  . intval($tahun);
if ($pengambilan !== null)       $dateWhere[] = "rp.pengambilan_data = " . intval($pengambilan);
$dateSql = $dateWhere ? ("WHERE " . implode(" AND ", $dateWhere)) : "";

$baseSql = "
  SELECT
    TRIM(SUBSTRING_INDEX(rp.kecamatan, ',', 1))                           AS kecamatan,
    TRIM(SUBSTRING_INDEX(SUBSTRING_INDEX(rp.kecamatan, ',', 2), ',', -1)) AS kabupaten,
    TRIM(SUBSTRING_INDEX(rp.kecamatan, ',', -1))                          AS provinsi,
    rp.populasi_wereng, rp.suhu, rp.presipitasi, rp.persentase_insidensi,
    rp.virulensi, rp.varietas_padi
  FROM riwayat_prediksi rp
  $dateSql
";

$groupSel = ""; $groupOrder = "";
switch ($level) {
  case 'kecamatan': $groupSel = "provinsi, kabupaten, kecamatan"; $groupOrder="provinsi, kabupaten, kecamatan"; break;
  case 'kabupaten': $groupSel = "provinsi, kabupaten";            $groupOrder="provinsi, kabupaten";            break;
  default:          $groupSel = "provinsi";                       $groupOrder="provinsi";                       $level='provinsi';
}

$where = [];
if ($filterProv !== '') {
  $where[] = "t.provinsi = '" . mysqli_real_escape_string($conn, $filterProv) . "'";
}
$whereSql = $where ? ("WHERE " . implode(" AND ", $where)) : "";

$sql = "
  SELECT $groupSel,
         AVG(t.populasi_wereng)      AS avg_populasi_wereng,
         AVG(t.suhu)                 AS avg_suhu,
         AVG(t.presipitasi)          AS avg_presipitasi,
         AVG(t.persentase_insidensi) AS avg_persentase_insidensi,
         SUM(CASE WHEN LOWER(t.varietas_padi)='rentan' THEN 1 ELSE 0 END) AS rentan_cnt,
         SUM(CASE WHEN LOWER(t.varietas_padi)='tahan'  THEN 1 ELSE 0 END) AS tahan_cnt,
         SUM(CASE WHEN LOWER(t.virulensi) LIKE '%sangat%' THEN 1 ELSE 0 END) AS v_sangat,
         SUM(CASE WHEN LOWER(t.virulensi) LIKE '%tidak%'  THEN 1 ELSE 0 END) AS v_tidak,
         SUM(CASE WHEN t.virulensi IS NOT NULL AND t.virulensi<>'' 
                  AND LOWER(t.virulensi) NOT LIKE '%sangat%' 
                  AND LOWER(t.virulensi) NOT LIKE '%tidak%' THEN 1 ELSE 0 END) AS v_sedang
  FROM ( $baseSql ) t
  $whereSql
  GROUP BY $groupSel
  ORDER BY $groupOrder
";

$res = mysqli_query($conn, $sql);
if (!$res) {
  http_response_code(500);
  echo json_encode(['error'=>'SQL error','sql'=>$sql,'msg'=>mysqli_error($conn)]); exit;
}

$features = [];
$missing  = [];

while ($row = mysqli_fetch_assoc($res)) {
  $prov = $row['provinsi'] ?? '';
  $kab  = $row['kabupaten'] ?? '';
  $kec  = $row['kecamatan'] ?? '';

  $counts = [
    'Sangat Virulen' => (int)$row['v_sangat'],
    'Tidak Virulen'  => (int)$row['v_tidak'],
    'Virulen'        => (int)$row['v_sedang'],
  ];
  arsort($counts);
  $vir = null;
  foreach ($counts as $lbl=>$cnt) { if ($cnt>0) { $vir=$lbl; break; } }

  $rentan = (int)$row['rentan_cnt'];
  $tahan  = (int)$row['tahan_cnt'];
  $totVar = $rentan + $tahan;
  $varietas = $totVar>0 ? [
      'Rentan' => round(($rentan/$totVar)*100,1),
      'Tahan'  => round(($tahan/$totVar)*100,1),
  ] : [];

  $stats = [
    'incidence_pct'        => $row['avg_persentase_insidensi'] !== null ? round((float)$row['avg_persentase_insidensi'],1) : 0,
    'mean_populasi_wereng' => $row['avg_populasi_wereng']      !== null ? round((float)$row['avg_populasi_wereng'],1)      : null,
    'mean_suhu'            => $row['avg_suhu']                 !== null ? round((float)$row['avg_suhu'],1)                 : null,
    'mean_presipitasi'     => $row['avg_presipitasi']          !== null ? round((float)$row['avg_presipitasi'],1)          : null,
    'virulensi'            => $vir,
    'varietas'             => $varietas
  ];

  if ($level === 'provinsi') {
    $path = pathProv($prov);
  } elseif ($level === 'kabupaten') {
    $path = pathKab($prov,$kab);
  } else {
    $path = pathKec($prov,$kab,$kec);
  }

  if (!file_exists($path)) {
    $missing[] = str_replace(__DIR__,'',$path);
    continue;
  }

  $gj = json_decode(@file_get_contents($path), true);
  if (!is_array($gj) || !isset($gj['type'])) {
    $missing[] = "(format salah) ".str_replace(__DIR__,'',$path);
    continue;
  }

  $baseProps = [
    'level'     => $level,
    'provinsi'  => $prov,
    'kabupaten' => $kab,
    'kecamatan' => $kec,
    'stats'     => $stats
  ];

  if ($gj['type'] === 'FeatureCollection' && isset($gj['features']) && is_array($gj['features'])) {
    foreach ($gj['features'] as $f) {
      if (!isset($f['properties']) || !is_array($f['properties'])) $f['properties'] = [];
      $f['properties'] = array_merge($f['properties'], $baseProps);
      $features[] = $f;
    }
  } elseif ($gj['type'] === 'Feature') {
    if (!isset($gj['properties']) || !is_array($gj['properties'])) $gj['properties'] = [];
    $gj['properties'] = array_merge($gj['properties'], $baseProps);
    $features[] = $gj;
  }
}

echo json_encode([
  'level'   => $level,
  'geojson' => ['type'=>'FeatureCollection','features'=>$features],
  'missing' => $missing
], JSON_UNESCAPED_UNICODE);

?>