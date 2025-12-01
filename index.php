<?php
    session_start();
    
    require 'koneksi.php';

    // ---- FILTER BULAN, TAHUN & PENGAMBILAN DATA
    $DATE_COL = 'tanggal_pengambilan_data';
    $bulanFilter = isset($_GET['bulan']) ? max(1, min(12, (int)$_GET['bulan'])) : (int)date('n');
    $tahunFilter = isset($_GET['tahun']) ? (int)$_GET['tahun'] : (int)date('Y');
    // -- [BARU] Filter Pengambilan Data --
    $pengambilanFilter = isset($_GET['pengambilan']) ? (int)$_GET['pengambilan'] : null;


    // ==== DAFTAR TAHUN, BULAN, & PENGAMBILAN DATA YANG ADA DI DB ====
    $years = [];
    $monthsByYear = [];
    $pengambilanOptions = []; 

    // [DIUBAH] Query untuk mengambil tahun dari kolom teks 'tanggal_pengambilan_data'
    // Format: "DD nama_bulan YYYY" (contoh: "05 Agustus 2024")
    $YEAR_EXPR = "CAST(SUBSTRING_INDEX($DATE_COL, ' ', -1) AS UNSIGNED)";
    $yearSql = "SELECT DISTINCT $YEAR_EXPR AS y
                FROM riwayat_prediksi
                WHERE $DATE_COL IS NOT NULL AND verifikasi = 1 AND $DATE_COL RLIKE '^[0-9]{1,2} [a-zA-Z]+ [0-9]{4}$'
                ORDER BY y DESC";
    if ($resY = mysqli_query($conn, $yearSql)) {
        while ($r = mysqli_fetch_assoc($resY)) {
            if ((int)$r['y'] > 2000) { 
                $years[] = (int)$r['y'];
            }
        }
    }

    // Query untuk mengambil tahun dan bulan dari kolom teks 'tanggal_pengambilan_data'
    $MONTH_EXPR = "(CASE SUBSTRING_INDEX(SUBSTRING_INDEX($DATE_COL, ' ', 2), ' ', -1)
                        WHEN 'Januari' THEN 1 WHEN 'Februari' THEN 2 WHEN 'Maret' THEN 3
                        WHEN 'April' THEN 4 WHEN 'Mei' THEN 5 WHEN 'Juni' THEN 6
                        WHEN 'Juli' THEN 7 WHEN 'Agustus' THEN 8 WHEN 'September' THEN 9
                        WHEN 'Oktober' THEN 10 WHEN 'November' THEN 11 WHEN 'Desember' THEN 12
                        ELSE NULL END)";
    $ymSql = "SELECT DISTINCT $YEAR_EXPR AS y, $MONTH_EXPR AS m
            FROM riwayat_prediksi
            WHERE $DATE_COL IS NOT NULL AND verifikasi = 1 AND $DATE_COL RLIKE '^[0-9]{1,2} [a-zA-Z]+ [0-9]{4}$'
            ORDER BY y DESC, m ASC";
    if ($resYM = mysqli_query($conn, $ymSql)) {
        while ($r = mysqli_fetch_assoc($resYM)) {
            $y = (int)$r['y']; $m = (int)$r['m'];
            if ($y > 2000 && $m >= 1 && $m <= 12) {
                if (!isset($monthsByYear[$y])) $monthsByYear[$y] = [];
                if (!in_array($m, $monthsByYear[$y], true)) $monthsByYear[$y][] = $m;
            }
        }
    }
    
    // -- Query untuk mendapatkan opsi filter Pengambilan Data --
    $pengambilanSql = "SELECT DISTINCT pengambilan_data 
                       FROM riwayat_prediksi 
                       WHERE pengambilan_data IS NOT NULL 
                       AND verifikasi = 1
                       ORDER BY pengambilan_data ASC";
    if ($resP = mysqli_query($conn, $pengambilanSql)) {
        while ($r = mysqli_fetch_assoc($resP)) {
            $pengambilanOptions[] = (int)$r['pengambilan_data'];
        }
    }


    // ==== Tetapkan default: tahun/bulan terbaru dari DB bila filter GET tidak valid ====
    if (!empty($years)) {
        if (!in_array($tahunFilter, $years, true)) {
            $tahunFilter = $years[0]; // tahun terbaru (karena DESC)
        }
        $availableMonths = $monthsByYear[$tahunFilter] ?? [];
        if (empty($availableMonths)) {
            // tidak ada bulan untuk tahun tsb → fallback ke tahun terbaru yg punya bulan
            foreach ($years as $y) {
                if (!empty($monthsByYear[$y])) { $tahunFilter = $y; $availableMonths = $monthsByYear[$y]; break; }
            }
        }
        if (empty($availableMonths)) {
            // benar-benar tidak ada data tanggal di DB
            $bulanFilter = null;
        } else {
            if (!in_array($bulanFilter, $availableMonths, true)) {
                $bulanFilter = max($availableMonths); // ambil bulan terbaru di tahun tsb
            }
        }
    } else {
        // Tidak ada data tanggal sama sekali → biarkan default hari ini (atau null)
        $tahunFilter = (int)date('Y');
        $bulanFilter = (int)date('n');
    }

    // --- CATATAN: Bagian di bawah ini untuk KUOTA HARIAN, tetap menggunakan 'tanggal_input' ---
    // --- karena kolom ini kemungkinan besar adalah timestamp kapan data dimasukkan (created_at) ---
    // --- dan tidak berhubungan dengan tanggal pengambilan data di lapangan. ---
    $TIMESTAMP_COL = 'tanggal_input';
    date_default_timezone_set('Asia/Jakarta');
    $today = date('Y-m-d');

    $USE_DB_UTC = false;
    $todayCount = 0;
    if ($USE_DB_UTC) {
        $sqlCount = "SELECT COUNT(*) FROM riwayat_prediksi WHERE DATE(CONVERT_TZ($TIMESTAMP_COL, '+00:00', '+07:00')) = ?";
    } else {
        $sqlCount = "SELECT COUNT(*) FROM riwayat_prediksi WHERE DATE($TIMESTAMP_COL) = ?";
    }

    if ($stmt = mysqli_prepare($conn, $sqlCount)) {
        mysqli_stmt_bind_param($stmt, "s", $today);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_bind_result($stmt, $c);
        if (mysqli_stmt_fetch($stmt)) {
            $todayCount = (int)$c;
        }
        mysqli_stmt_close($stmt);
    } else {
        error_log('[quota] prepare failed: ' . mysqli_error($conn));
    }

    // Progress: 32 Kuota per hari
    $MAX_STEPS       = 32;
    $progressPercent   = min(100, round((min($todayCount, $MAX_STEPS) / $MAX_STEPS) * 100, 1));
    $progressLabel     = $progressPercent . '%';
    $progressStepLabel = $todayCount . '/' . $MAX_STEPS;


    $sql = "SELECT
            TRIM(SUBSTRING_INDEX(kecamatan, ',', 1))                                AS kecamatan,
            TRIM(SUBSTRING_INDEX(SUBSTRING_INDEX(kecamatan, ',', 2), ',', -1))      AS kabupaten,
            TRIM(SUBSTRING_INDEX(kecamatan, ',', -1))                                AS provinsi,

            AVG(populasi_wereng)        AS avg_populasi_wereng,
            AVG(suhu)                   AS avg_suhu,
            AVG(presipitasi)            AS avg_presipitasi,
            AVG(persentase_insidensi)   AS avg_persentase_insidensi,

            SUM(CASE WHEN LOWER(varietas_padi) = 'rentan' THEN 1 ELSE 0 END)        AS rentan,
            SUM(CASE WHEN LOWER(varietas_padi) = 'tahan'  THEN 1 ELSE 0 END)        AS tahan,
            virulensi
        FROM riwayat_prediksi 
        GROUP BY kecamatan;"; 
    $res = mysqli_query($conn, $sql);

    $agg = [];
    $areas = []; 
    while ($row = mysqli_fetch_assoc($res)) {
        $kec = trim($row['kecamatan'] ?? '');
        $kab = trim($row['kabupaten'] ?? '');
        $prov = trim($row['provinsi'] ?? '');

        if ($kec !== '' && $kab !== '' && $prov !== '') {
            $key = $prov.'|'.$kab.'|'.$kec;
            if (!isset($areas[$key])) {
                $areas[$key] = ['provinsi'=>$prov, 'kabupaten'=>$kab, 'kecamatan'=>$kec];
            }
        }

        if ($kec === '') continue;
        if (!isset($agg[$kec])) {
            $agg[$kec] = [
                'sum_pop'=>0,'cnt_pop'=>0,
                'sum_suhu'=>0,'cnt_suhu'=>0,
                'sum_pres'=>0,'cnt_pres'=>0,
                'sum_inc'=>0,'cnt_inc'=>0,
                'vir'=>['Tidak Virulen'=>0,'Virulen'=>0,'Sangat Virulen'=>0],
                'vir_last'=>null,
                'var'=>['Rentan'=>0,'Tahan'=>0]
            ];
        }

        $val_pop = $row['populasi_wereng'] ?? $row['avg_populasi_wereng'] ?? null;
        $val_suhu = $row['suhu'] ?? $row['avg_suhu'] ?? null;
        $val_pres = $row['presipitasi'] ?? $row['avg_presipitasi'] ?? null;
        $val_inc  = $row['persentase_insidensi'] ?? $row['avg_persentase_insidensi'] ?? null;

        if (is_numeric($val_pop)) { $agg[$kec]['sum_pop'] += (float)$val_pop; $agg[$kec]['cnt_pop']++; }
        if (is_numeric($val_suhu)) { $agg[$kec]['sum_suhu'] += (float)$val_suhu; $agg[$kec]['cnt_suhu']++; }
        if (is_numeric($val_pres)) { $agg[$kec]['sum_pres'] += (float)$val_pres; $agg[$kec]['cnt_pres']++; }
        if (is_numeric($val_inc))  { $agg[$kec]['sum_inc']  += (float)$val_inc;  $agg[$kec]['cnt_inc']++; }

        $v = strtolower(trim($row['virulensi'] ?? ''));
        if ($v !== '') {
            if (strpos($v,'sangat') !== false)     { $agg[$kec]['vir']['Sangat Virulen']++; $agg[$kec]['vir_last'] = 'Sangat Virulen'; }
            elseif (strpos($v,'tidak') !== false)  { $agg[$kec]['vir']['Tidak Virulen']++;  $agg[$kec]['vir_last'] = 'Tidak Virulen'; }
            else                                   { $agg[$kec]['vir']['Virulen']++;        $agg[$kec]['vir_last'] = 'Virulen'; }
        }

        if (isset($row['rentan'])) { $agg[$kec]['var']['Rentan'] += (int)$row['rentan']; }
        if (isset($row['tahan']))  { $agg[$kec]['var']['Tahan']  += (int)$row['tahan']; }

        $var = trim($row['varietas_padi'] ?? '');
        if ($var !== '') {
            if (strcasecmp($var,'rentan')===0) $agg[$kec]['var']['Rentan']++;
            elseif (strcasecmp($var,'tahan')===0) $agg[$kec]['var']['Tahan']++;
        }

    }

    $stats = [];
    foreach ($agg as $kec => $a) {
        $dominant = $a['vir_last'];
        if ($dominant === null) {
            $maxCnt = -1;
            foreach ($a['vir'] as $label => $cnt) {
                if ($cnt > $maxCnt) { $maxCnt = $cnt; $dominant = $label; }
            }
            if ($maxCnt <= 0) $dominant = null;
        }

        $totalVar = max(0, (int)$a['var']['Rentan'] + (int)$a['var']['Tahan']);
        $varPct = [];
        if ($totalVar > 0) {
            foreach ($a['var'] as $k => $v) {
                $varPct[$k] = round(($v / $totalVar) * 100, 1);
            }
        }

        $stats[$kec] = [
            "incidence_pct"        => $a['cnt_inc']  ? round($a['sum_inc']  / $a['cnt_inc'], 1)  : 0,
            "mean_populasi_wereng" => $a['cnt_pop']  ? round($a['sum_pop']  / $a['cnt_pop'], 1)  : null,
            "mean_suhu"            => $a['cnt_suhu'] ? round($a['sum_suhu'] / $a['cnt_suhu'], 1) : null,
            "mean_presipitasi"     => $a['cnt_pres'] ? round($a['sum_pres'] / $a['cnt_pres'], 1) : null,
            "virulensi"            => $dominant,
            "varietas"             => $varPct
        ];
    }

    function safeName($s) {
        $s = trim($s);
        $s = preg_replace('/^(Kabupaten|Kota)\s+/i', '', $s);
        $s = preg_replace('/\s+/', ' ', $s);
        if (function_exists('mb_convert_case')) {
            $s = mb_convert_case($s, MB_CASE_TITLE, 'UTF-8');
        } else {
            $s = ucwords(strtolower($s));
        }
        $s = str_replace(['/', '\\', ':', '*', '?', '"', '<', '>', '|'], ' ', $s);
        return $s;
    }

    $geoFeatures = [];
    $missing = [];

    foreach ($areas as $area) {
        $prov = safeName($area['provinsi']);
        $kab  = safeName($area['kabupaten']);
        $kec  = safeName($area['kecamatan']);

        $path = __DIR__ . "/assets/geo/Provinsi {$prov}/Kabupaten {$kab}/Kecamatan {$kec}.geojson";

        if (!file_exists($path)) {
            $missing[] = substr($path, strlen(__DIR__));
            continue;
        }

        $gj = json_decode(@file_get_contents($path), true);
        if (!is_array($gj) || !isset($gj['type'])) {
            $missing[] = "(format salah) " . substr($path, strlen(__DIR__));
            continue;
        }

        if ($gj['type'] === 'FeatureCollection' && isset($gj['features']) && is_array($gj['features'])) {
            foreach ($gj['features'] as $f) {
                if (!isset($f['properties']) || !is_array($f['properties'])) $f['properties'] = [];
                $f['properties'] = array_merge($f['properties'], [
                    'provinsi'  => $area['provinsi'],
                    'kabupaten' => $area['kabupaten'],
                    'kecamatan' => $area['kecamatan'],
                    'stats'     => $stats[$area['kecamatan']] ?? null
                ]);
                $geoFeatures[] = $f;
            }
        } elseif ($gj['type'] === 'Feature') {
            if (!isset($gj['properties']) || !is_array($gj['properties'])) $gj['properties'] = [];
            $gj['properties'] = array_merge($gj['properties'], [
                'provinsi'  => $area['provinsi'],
                'kabupaten' => $area['kabupaten'],
                'kecamatan' => $area['kecamatan'],
                'stats'     => $stats[$area['kecamatan']] ?? null
            ]);
            $geoFeatures[] = $gj;
        } else {
            $missing[] = "(jenis tidak didukung) " . substr($path, strlen(__DIR__));
        }
    }

    $geojson = ["type" => "FeatureCollection", "features" => $geoFeatures];

    if (!empty($missing)) {
        error_log("GeoJSON tidak ditemukan/invalid: " . implode(' | ', $missing));
    }

    function koleksiWilayah($sumbu, $bulan = null, $tahun = null) {
        global $conn; 
        $DATE_COL_KOLEKSI = 'tanggal_input'; // Tetap gunakan kolom lama untuk tabel ini

        $warna = ["#ff6666", "#ff9966", "#ffff99", "#99ff99", "#66ffcc", "#99ffff", "#9999ff", "#ff99ff", "#ce99ff"];

        $WHERE = [];
        if (!empty($bulan)) $WHERE[] = "MONTH($DATE_COL_KOLEKSI) = " . intval($bulan);
        if (!empty($tahun)) $WHERE[] = "YEAR($DATE_COL_KOLEKSI) = " . intval($tahun);
        $whereSql = $WHERE ? ("WHERE " . implode(" AND ", $WHERE)) : "";

        $sql = "
            SELECT provinsi AS distinct_provinsi, COUNT(*) AS kabupaten_count
            FROM koleksi_wilayah
            $whereSql
            GROUP BY provinsi
            ORDER BY provinsi
        ";

        $result = mysqli_query($conn, $sql);
        if (!$result) {
            error_log('[pie] SQL error: ' . mysqli_error($conn) . ' | ' . $sql);
            if ($sumbu == 1) return [];
            if ($sumbu == 2) return [];
            if ($sumbu == 3) return [];
            return '';
        }

        $labels = [];
        $data = [];
        $colors = [];
        $count = 0;

        while ($row = mysqli_fetch_assoc($result)) {
            $labels[] = $row['distinct_provinsi'];
            $data[]   = (int)$row['kabupaten_count'];
            $colors[] = $warna[$count % count($warna)];
            $count++;
        }

        if ($sumbu == 1)      return $labels;
        elseif ($sumbu == 2)  return $data;
        elseif ($sumbu == 3)  return $colors;
        else                  return ''; 
    }
?>

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, shrink-to-fit=no">
    <title>Tanamaman</title>
    <link rel="icon" type="image/x-icon" href="assets/img/logo.png">
    <link rel="stylesheet" href="assets/bootstrap/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Nunito:200,200i,300,300i,400,400i,600,600i,700,700i,800,800i,900,900i&amp;display=swap">
    <link rel="stylesheet" href="https://use.fontawesome.com/releases/v5.12.0/css/all.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="assets/css/styles.css">
</head>

<body id="page-top">
    <div id="wrapper">
        <div class="d-flex flex-column" id="content-wrapper">
            <div id="content">
                <div class="container-fluid">
                    <div class="d-sm-flex justify-content-between align-items-center mb-2" style="margin-top: 20px;">
                        <h3 class="mb-2 mt-2 title-header">Dashboard Tanamaman</h3>
                        
                        <?php if (isset($_SESSION['user_id'])): ?>
                            <div class="dropdown">
                                <button class="btn btn-success btn-sm text-white dropdown-toggle" style="background:#6CD756" type="button" id="userMenu" data-bs-toggle="dropdown" aria-expanded="false">
                                    <i class="fas fa-user me-1"></i> 
                                    Halo, <?= htmlspecialchars(explode(' ', $_SESSION['nama_lengkap'])[0]) ?>
                                    <?= (isset($_SESSION['status']) && $_SESSION['status'] === 'admin') ? '<span class="badge bg-warning ms-1">Admin</span>' : '' ?> 
                                </button>
                                <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="userMenu">
                                    <li><a class="dropdown-item" href="logout.php">
                                        <i class="fas fa-sign-out-alt me-2"></i>Logout
                                    </a></li>
                                </ul>
                            </div>
                        <?php else: ?>
                            <button id="openLoginBtn" type="button" class="btn btn-success btn-sm text-white" style="background:#6CD756">
                                <i class="fas fa-sign-in-alt me-1"></i> Masuk
                            </button>
                        <?php endif; ?>
                    </div>
                    <div class="row">
                        
                    </div>
                    <div class="row g-2 align-items-end mb-3 global-filter">
                        <div class="col-auto">
                            <label class="small text-muted mb-1">Tahun</label>
                            <select id="filterTahun" class="form-select form-select-sm" style="min-width:100px;">
                            <?php foreach ($years as $y): 
                                $sel = ($y == $tahunFilter) ? 'selected' : ''; ?>
                                <option value="<?= htmlspecialchars($y) ?>" <?= $sel ?>><?= htmlspecialchars($y) ?></option>
                            <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-auto">
                            <label class="small text-muted mb-1">Bulan</label>
                            <select id="filterBulan" class="form-select form-select-sm" style="min-width:120px;" <?= empty($monthsByYear[$tahunFilter]) ? 'disabled' : '' ?>>
                            <?php
                                $namaBulan = [1=>'Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];
                                $mList = $monthsByYear[$tahunFilter] ?? [];
                                foreach ($mList as $m) {
                                $sel = ($m == $bulanFilter) ? 'selected' : '';
                                echo "<option value=\"$m\" $sel>{$namaBulan[$m]}</option>";
                                }
                            ?>
                            </select>
                        </div>
                        <div class="col-auto">
                            <label class="small text-muted mb-1">Pengambilan Data</label>
                            <select id="filterPengambilan" class="form-select form-select-sm" style="min-width:120px;">
                                <option value="">Semua</option>
                            <?php foreach ($pengambilanOptions as $p): 
                                $sel = ($p == $pengambilanFilter) ? 'selected' : ''; ?>
                                <option value="<?= htmlspecialchars($p) ?>" <?= $sel ?>><?= htmlspecialchars($p) ?></option>
                            <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-auto ms-auto">
                            <label class="small text-muted mb-1 d-block">&nbsp;</label>
                            <?php if (isset($_SESSION['user_id']) && isset($_SESSION['status']) && $_SESSION['status'] === 'admin'): ?>
                                <button id="openCurationPanel" style="background:#6CD756" type="button" class="btn btn-success btn-sm text-white me-2">
                                <i class="fas fa-check-double me-1"></i> Verifikasi Data
                                </button>
                            <?php endif; ?>
                            <button id="openInfoPanel" style="background:#6CD756" type="button" class="btn btn-success btn-sm text-white">
                                <i class="fas fa-info-circle me-1"></i> Informasi Situs
                            </button>
                        </div>
                        <div class="modal fade info-modal" id="infoPanel" tabindex="-1" aria-hidden="true">
                        <div class="modal-dialog modal-dialog-centered">
                            <div class="modal-content p-3" style="border-radius:16px;">
                            <div class="text-center">
                                <img src="assets/img/farmer.png" alt="Petani" style="width:56px;height:56px;">
                                <h5 class="fw-bold mt-2 mb-2">Informasi Situs</h5>
                            </div>

                            <div class="px-1">
                                <span id="welcomeTyped"></span><span class="welcome-cursor"></span>
                            </div>

                            <div class="pt-3">
                                <button type="button" class="btn w-100" data-bs-dismiss="modal" style="background:#6CD756; color:#FFFFFF">Tutup</button>
                            </div>
                            </div>
                        </div>
                        </div>

                    </div>
                    

                    <div class="row">
                        <div class="col-lg-7 col-xl-8">
                            <div class="card shadow mb-4">
                                <div class="card-header d-flex justify-content-between align-items-center">
                                    <h6 class="fw-bold m-0 card-header-text">Informasi Prediksi</h6>
                                </div>
                                <div class="card-body">
                                    <div class="row g-2 align-items-end mb-3">
                                    <div class="col-md-6">
                                        <label class="small text-muted mb-1">Filter Provinsi</label>
                                        <select id="filterProvinsi" class="form-select form-select-sm">
                                        <option value="">Semua Provinsi</option>
                                        </select>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="small text-muted mb-1">Tingkat Keparahan</label>
                                        <select id="filterSeverity" class="form-select form-select-sm">
                                        <option value="">Semua Keparahan</option>
                                        <option value="1-10">1–10% (Ringan)</option>
                                        <option value="10-30">&gt;10–30% (Sedang)</option>
                                        <option value="30-50">&gt;30–50% (Berat)</option>
                                        <option value="50+">≥50% (Sangat Berat)</option>
                                        </select>
                                    </div>
                                    </div>

                                    <div id="mapTungro"></div>
                                    <div id="statPanel" class="mt-3"></div>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-5 col-xl-4">
                            <div class="card shadow mb-4">
                                <div class="card-header d-flex justify-content-between align-items-center">
                                    <h6 class="fw-bold m-0 card-header-text">Data Jumlah Prediksi per Wilayah</h6>
                                </div>
                                <div class="card-body">
                                    <div class="chart-area mb-4">
                                        <canvas id="pieChart" style="margin-top: 30px;"></canvas>
                                        <button id="backButton" class="btn btn-secondary btn-sm chart-back-btn">Kembali</button>
                                    </div>
                                </div>
                            </div>
                            <div class="card shadow mb-4">
                                <div class="card-header d-flex justify-content-between align-items-center">
                                    <h6 class="fw-bold m-0 card-header-text">Kuota Prediksi Hari Ini</h6>
                                </div>
                                <div class="chart-area gauge-wrap px-3">       <div class="gauge-inner mx-auto">           <canvas id="apiGauge"
                                                data-percent="<?= htmlspecialchars($progressPercent) ?>"
                                                data-steps="<?= htmlspecialchars($progressStepLabel) ?>"></canvas>
                                    </div>

                                    <div class="gauge-title">Kuota Prediksi Hari Ini</div>
                                    <div class="gauge-label">
                                        <div class="gauge-percent"><?= htmlspecialchars($progressLabel) ?></div>
                                        <div class="gauge-steps text-muted small"><?= htmlspecialchars($progressStepLabel) ?></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <footer class="bg-white sticky-footer"></footer>
        </div><a class="border rounded d-inline scroll-to-top" href="#page-top"><i class="fas fa-angle-up"></i></a>
    </div>

    <div class="modal fade auth-modal" id="loginModal" tabindex="-1" aria-labelledby="loginModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header justify-content-center">
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    <h5 class="modal-title" id="loginModalLabel">Masuk</h5>
                    </div>
                <div class="modal-body">
                    <form id="loginForm">
                        <div class="form-group">
                            <label for="loginEmail" class="form-label">Email</label>
                            <input type="email" class="form-control" id="loginEmail" required>
                        </div>
                        <div class="form-group">
                            <label for="loginPassword" class="form-label">Password</label>
                            <input type="password" class="form-control" id="loginPassword" required>
                            <i class="fas fa-eye-slash form-control-icon-right" id="togglePassword"></i>
                        </div>
                        <button type="submit" class="btn btn-primary btn-login">Login</button>
                    </form>
                    <div class="social-login">
                        <p>Belum punya akun? <a href="#" id="openRegisterFromLogin">Daftar</a></p> 
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade auth-modal" id="registerModal" tabindex="-1" aria-labelledby="registerModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header justify-content-center">
                     <h5 class="modal-title" id="registerModalLabel">Daftar</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="registerForm">
                        <div class="form-group">
                            <label for="regNama" class="form-label">Nama Lengkap</label>
                            <input type="text" class="form-control" id="regNama" required>
                        </div>
                        <div class="form-group">
                            <label for="regEmail" class="form-label">Email</label>
                            <input type="email" class="form-control" id="regEmail" required>
                        </div>
                        <div class="form-group">
                            <label for="regDomisili" class="form-label">Domisili</label>
                            <input type="text" class="form-control" id="regDomisili" required>
                        </div>
                        <div class="form-group">
                            <label for="regTelepon" class="form-label">Nomor Telepon</label>
                            <input type="tel" class="form-control" id="regTelepon" required>
                        </div>
                        <div class="form-group">
                            <label for="regPassword" class="form-label">Password</label>
                            <input type="password" class="form-control" id="regPassword" required>
                        </div>
                        <button type="submit" class="btn btn-primary btn-login mt-3">Daftar</button>
                    </form>
                     <div class="signup-link">
                         <p>Sudah punya akun? <a href="#" id="openLoginFromRegister">Masuk</a></p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="curationModal" tabindex="-1" aria-labelledby="curationModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="curationModalLabel">Verifikasi Data</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="d-flex justify-content-end mb-2">
                        <label for="curationRows" class="form-label me-2 col-auto col-form-label">Tampilkan:</label>
                        <div class="col-auto">
                            <select class="form-select form-select-sm" id="curationRows">
                                <option value="10" selected>10 baris</option>
                                <option value="20">20 baris</option>
                                <option value="50">50 baris</option>
                            </select>
                        </div>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-striped table-hover" id="curationTable">
                        <thead>
                            <tr>
                                <th scope="col">
                                <input class="form-check-input" type="checkbox" id="checkAllCuration">
                                </th>
                                <th scope="col" class="sortable" data-sort="tanggal_input">Tanggal Prediksi <span class="sort-indicator"></span></th>
                                <th scope="col" class="sortable" data-sort="kecamatan">Kecamatan <span class="sort-indicator"></span></th>
                                <th scope="col" class="sortable" data-sort="nama_wilayah">Nama Wilayah <span class="sort-indicator"></span></th>
                                <th scope="col" class="sortable" data-sort="waktu_semai">Waktu Semai <span class="sort-indicator"></span></th>
                                <th scope="col" class="sortable" data-sort="pengambilan_data">Pengambilan Data <span class="sort-indicator"></span></th>
                                <th scope="col" class="sortable" data-sort="suhu">Suhu <span class="sort-indicator"></span></th>
                                <th scope="col" class="sortable" data-sort="presipitasi">Presipitasi <span class="sort-indicator"></span></th>
                                <th scope="col" class="sortable" data-sort="populasi_wereng">Populasi Wereng <span class="sort-indicator"></span></th>
                                <th scope="col" class="sortable" data-sort="varietas_padi">Varietas <span class="sort-indicator"></span></th>
                                <th scope="col" class="sortable" data-sort="virulensi">Virulensi <span class="sort-indicator"></span></th>
                                <th scope="col" class="sortable" data-sort="persentase_insidensi">Persentase Insidensi <span class="sort-indicator"></span></th>
                            </tr>
                        </thead>
                            <tbody id="curationTableBody"></tbody>
                        </table>
                        
                    </div>
                    <div class="d-flex justify-content-between align-items-center mt-2">
                            <div id="curationInfo" class="small text-muted"></div>
                            <nav>
                                <ul class="pagination pagination-sm mb-0" id="curationPagination"></ul>
                            </nav>
                        </div>
                     </div>
                     <div class="modal-footer d-flex justify-content-between">
                        <?php if (isset($_SESSION['status']) && $_SESSION['status'] === 'admin'): ?>
                            <div>
                            <button type="button" class="btn btn-danger" id="btnDeleteCuration" disabled>
                                Hapus Terpilih
                            </button>
                            </div>
                            <div>
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                            <button type="button" class="btn btn-success text-white" style="background:#4AB83F;" id="btnVerifyCuration" disabled>
                                Verifikasi Data Terpilih
                            </button>
                            </div>
                        <?php else: ?>
                            <div class="ms-auto">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Tutup</button>
                            </div>
                        <?php endif; ?>
                    </div>

            </div>
        </div>
    </div>
    
    <div class="modal fade" id="customAlertModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
        <div class="modal-dialog modal-dialog-centered" style="max-width: 400px;">
            <div class="modal-content" style="border-radius: 12px; box-shadow: 0 5px 15px rgba(0,0,0,0.1);">
                <div class="modal-body p-4 text-center">
                    <p id="customAlertMessage" class="mb-4" style="font-size: 1.1rem; font-weight: 500; color: #333; line-height: 1.5;"></p>
                    <button type="button" class="btn w-100 custom-alert-close-btn" data-bs-dismiss="modal" style="background:#6CD756; color:white; font-weight: 600; padding: 10px 0; border-radius: 8px;">Tutup</button>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="customConfirmModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
        <div class="modal-dialog modal-dialog-centered" style="max-width: 400px;">
            <div class="modal-content" style="border-radius: 12px; box-shadow: 0 5px 15px rgba(0,0,0,0.1);">
                <div class="modal-body p-4 text-center">
                    <p id="customConfirmMessage" class="mb-4" style="font-size: 1.1rem; font-weight: 500; color: #333; line-height: 1.5;"></p>
                    <div class="d-flex justify-content-center gap-2">
                        <button type="button" class="btn btn-secondary flex-grow-1" id="customConfirmNoBtn" style="font-weight: 600; padding: 10px 0; border-radius: 8px;">Tidak</button>
                        <button type="button" class="btn flex-grow-1" id="customConfirmYesBtn" style="background:#6CD756; color:white; font-weight: 600; padding: 10px 0; border-radius: 8px;">Ya</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/2.8.0/Chart.bundle.min.js"></script>
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script>
        // ... (Kode Typewriter tidak berubah) ...
        document.addEventListener('DOMContentLoaded', function () {
            const panelEl = document.getElementById('infoPanel');
            const openBtn = document.getElementById('openInfoPanel');

            if (openBtn && panelEl) {
                openBtn.addEventListener('click', () => {
                new bootstrap.Modal(panelEl).show();
                });
            }

            let hasTyped = false;
            function runTypewriter(){
                if (hasTyped) return; hasTyped = true;
                const el = document.getElementById('welcomeTyped');
                const cursor = document.querySelector('#infoPanel .welcome-cursor');
                if (!el) return;
                const parts = [ 'Website ini menampilkan ringkasan hasil prediksi insidensi penyakit tungro yang dikumpulkan dari aplikasi Tanamaman.', { type: 'break' }, { type: 'break' }, 'Aplikasi Tanamaman adalah aplikasi untuk membantu petani di Indonesia dalam memprediksi penyakit tungro pada padi.', { type: 'break' }, { type: 'break' }, 'Klik link berikut untuk mendownload aplikasi tanamaman ', { href: 'https://drive.google.com/drive/folders/1Xw7g16SIevxKxDVOrl5R_DXwYfKp1s-n?usp=sharing', text: 'Aplikasi Tanamaman' } ];
                let partIdx = 0, charIdx = 0; let textNode = document.createTextNode(''); el.appendChild(textNode);
                function type() { if (partIdx >= parts.length) { setTimeout(() => cursor && (cursor.style.display = 'none'), 900); return; } const part = parts[partIdx]; if (part.type === 'break') { el.appendChild(document.createElement('br')); partIdx++; textNode = document.createTextNode(''); el.appendChild(textNode); return void setTimeout(type, 25); } if (typeof part === 'string') { if (charIdx < part.length) { textNode.textContent += part.charAt(charIdx++); return void setTimeout(type, 25); } else { partIdx++; charIdx = 0; return void setTimeout(type, 25); } } const a = document.createElement('a'); a.href = part.href; a.target = '_blank'; a.rel = 'noopener'; a.textContent = ''; el.appendChild(a); let linkIdx = 0; (function typeLink() { if (linkIdx < part.text.length) { a.textContent += part.text.charAt(linkIdx++); setTimeout(typeLink, 18); } else { partIdx++; charIdx = 0; textNode = document.createTextNode(''); el.appendChild(textNode); setTimeout(type, 18); } })(); } setTimeout(type, 200);
            }
            if (panelEl) { panelEl.addEventListener('shown.bs.modal', runTypewriter); }
        });
    </script>
    <script>
        // ... (Kode Pie Chart tidak berubah) ...
        document.addEventListener('DOMContentLoaded', () => {
            const chartArea = document.querySelector('.chart-area');
            const backButton = document.getElementById('backButton');
            if (chartArea && backButton && backButton.parentElement !== chartArea) { chartArea.appendChild(backButton); }
        });
        const ctx = document.getElementById('pieChart').getContext('2d');
        const pieArea = document.getElementById('pieChart').parentElement;
        const levelLabelEl = document.createElement('div'); levelLabelEl.id = 'pieLevelLabel'; levelLabelEl.className = 'pie-level-badge'; pieArea.appendChild(levelLabelEl);
        const LEVEL_NAME = { provinsi: 'Provinsi', kabupaten: 'Kabupaten', kecamatan: 'Kecamatan', nama_wilayah: 'Wilayah' };
        function updatePieLevelLabel(extraText = '') { const name = LEVEL_NAME[currentLevel] || currentLevel; levelLabelEl.textContent = `${name}${extraText ? ' • ' + extraText : ''}`; }
        let currentLevel = 'provinsi'; let history = []; const backButton = document.getElementById('backButton');
        const bulanSelect = document.getElementById('filterBulan'); const tahunSelect = document.getElementById('filterTahun'); const pengambilanSelect = document.getElementById('filterPengambilan');
        const monthsByYear = <?php echo json_encode($monthsByYear, JSON_UNESCAPED_UNICODE); ?>; const monthNames = ['', 'Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];
        function rebuildMonthOptions() { const year = parseInt(tahunSelect.value, 10); const list = (monthsByYear[String(year)] || monthsByYear[year]) || []; bulanSelect.innerHTML = ''; if (!list.length) { bulanSelect.disabled = true; return; } bulanSelect.disabled = false; const sorted = list.slice().sort((a,b)=>a-b); const latest = sorted[sorted.length - 1]; for (const m of sorted) { const opt = document.createElement('option'); opt.value = String(m); opt.textContent = monthNames[m] || m; bulanSelect.appendChild(opt); } if (!sorted.includes(parseInt(bulanSelect.value, 10))) { bulanSelect.value = String(latest); } }
        tahunSelect.addEventListener('change', () => { rebuildMonthOptions(); reloadTopLevel(); });
        bulanSelect.addEventListener('change', reloadTopLevel); pengambilanSelect.addEventListener('change', reloadTopLevel);
        rebuildMonthOptions();
        function reloadTopLevel() { history = []; currentLevel = 'provinsi'; backButton.classList.remove('is-visible'); updatePieLevelLabel(); const postData = `level=provinsi&sumbu=1&bulan=${encodeURIComponent(bulanSelect.value)}&tahun=${encodeURIComponent(tahunSelect.value)}&pengambilan=${encodeURIComponent(pengambilanSelect.value)}`; fetch('api.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: postData }) .then(r => r.json()) .then(data => { pieChart.data.labels = data.labels || []; pieChart.data.datasets[0].data = data.data || []; pieChart.data.datasets[0].backgroundColor = data.colors || []; pieChart.update(); updatePieLevelLabel(); }) .catch(err => console.error(err)); }
        let pieChart = new Chart(ctx, { type: 'doughnut', data: { labels: [], datasets: [{ data: [], backgroundColor: [], borderColor: ['#ffffff'], borderWidth: 1 }] }, options: { maintainAspectRatio: false, legend: { display: true }, onClick: function (evt, elements) { if (elements.length === 0) return; if (currentLevel === 'nama_wilayah') return; const index = elements[0]._index; const selectedLabel = pieChart.data.labels[index]; history.push({ level: currentLevel, labels: pieChart.data.labels.slice(), data: pieChart.data.datasets[0].data.slice(), colors: pieChart.data.datasets[0].backgroundColor.slice() }); backButton.classList.add('is-visible'); let postData = `sumbu=1&bulan=${encodeURIComponent(bulanSelect.value)}&tahun=${encodeURIComponent(tahunSelect.value)}&pengambilan=${encodeURIComponent(pengambilanSelect.value)}`; if (currentLevel === 'provinsi') { postData += `&provinsi=${encodeURIComponent(selectedLabel)}`; currentLevel = 'kabupaten'; } else if (currentLevel === 'kabupaten') { postData += `&kabupaten=${encodeURIComponent(selectedLabel)}`; currentLevel = 'kecamatan'; } else if (currentLevel === 'kecamatan') { postData += `&kecamatan=${encodeURIComponent(selectedLabel)}`; currentLevel = 'nama_wilayah'; } updatePieLevelLabel(); fetch('api.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: postData }) .then(response => response.json()) .then(data => { pieChart.data.labels = data.labels || []; pieChart.data.datasets[0].data = data.data || []; pieChart.data.datasets[0].backgroundColor = data.colors || []; pieChart.update(); }) .catch(error => console.error('Error:', error)); } } });
        reloadTopLevel();
        backButton.addEventListener('click', function () { if (history.length > 0) { const previousState = history.pop(); currentLevel = previousState.level; pieChart.data.labels = previousState.labels; pieChart.data.datasets[0].data = previousState.data; pieChart.data.datasets[0].backgroundColor = previousState.colors; pieChart.update(); updatePieLevelLabel(); if (history.length === 0) { backButton.classList.remove('is-visible'); } } else { console.log("Tidak ada data sebelumnya untuk kembali."); } });
    </script>
    <script>
        // ... (Kode Map/Leaflet tidak berubah) ...
        (function(){
            const provSelect = document.getElementById('filterProvinsi'); const sevSelect = document.getElementById('filterSeverity'); const bulanSelect = document.getElementById('filterBulan'); const tahunSelect = document.getElementById('filterTahun'); const pengambilanSelect = document.getElementById('filterPengambilan'); const statPanel = document.getElementById('statPanel');
            const map = L.map('mapTungro', { worldCopyJump: true, inertia: true, inertiaDeceleration: 3000, }); const osm = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',{maxZoom:19}); osm.addTo(map); const indoBounds = L.latLngBounds([[-11.2, 95.0], [6.2, 141.0]]); map.fitBounds(indoBounds, { animate: false }); map.setMaxBounds(indoBounds.pad(0.05)); map.setMinZoom(3); map.setMaxZoom(19); map.on('drag', () => map.panInsideBounds(indoBounds, { animate: true }));
            let currentMapLevel = null; let geojsonData = null; let gjLayer = null; let zoomLock = false; let zoomChangeTimer = null;
            function getColor(d) { return d > 50 ? '#9B1D3A' : d > 30 ? '#C12F38' : d > 10 ? '#E8765D' : d > 0 ? '#E4C98B' : '#58E26F'; } function style(feature){ const inc = feature?.properties?.stats?.incidence_pct ?? 0; return { weight:1, opacity:1, color:'#ffffff', dashArray:'3', fillOpacity:0.75, fillColor:getColor(inc) }; }
            const legend = L.control({ position: 'bottomright' }); legend.onAdd = function () { const div = L.DomUtil.create('div', 'legend'); const items = [ { label: '0%', sample: 0 }, { label: '1–10%', sample: 1 }, { label: '>10–30%', sample: 11 }, { label: '>30–50%', sample: 31 }, { label: '≥50%', sample: 51 } ]; let html = '<div class="mb-1"><b>Insidensi (%)</b></div>'; items.forEach(it => { html += `<div><i style="background:${getColor(it.sample)}"></i> ${it.label}</div>`; }); div.innerHTML = html; return div; }; legend.addTo(map);
            function severityMatch(inc, sevVal){ if(!sevVal) return true; if(sevVal==='0') return inc == 0; if(sevVal==='1-10') return inc > 0 && inc <= 10; if(sevVal==='10-30') return inc > 10 && inc <= 30; if(sevVal==='30-50') return inc > 30 && inc <= 50; if(sevVal==='50+') return inc > 50; return true; }
            function showStatsForFeature(f){ const p = f.properties || {}; const s = p.stats || {}; const name = p.kecamatan || p.kabupaten || p.provinsi || '(tanpa nama)'; const virLabel = s.virulensi || '-'; const vars = s.varietas || {}; const rentan = Number(vars.Rentan ?? vars.rentan ?? 0); const tahan = Number(vars.Tahan ?? vars.tahan ?? 0); const total = rentan + tahan; const rDisp = rentan.toFixed(1); const tDisp = tahan.toFixed(1); const rWidth = total ? (rentan/total)*100 : 0; const tWidth = total ? (tahan /total)*100 : 0; const varietasHTML = total === 0 ? `<div class="text-muted">-</div>` : ` <div class="d-flex justify-content-between small mb-1"> <span>Varietas</span> <span>Rentan ${rDisp}% • Tahan ${tDisp}%</span> </div> <div class="progress" style="height:20px;"> <div class="progress-bar bg-warning" role="progressbar" style="width:${rWidth}%;">Rentan ${rDisp}%</div> <div class="progress-bar bg-success" role="progressbar" style="width:${tWidth}%;">Tahan ${tDisp}%</div> </div>`; statPanel.innerHTML = ` <h6 class="mb-2"><b>${name}</b> — Rata-rata Insidensi: <b>${s.incidence_pct ?? 0}%</b></h6> <table class="table table-sm mb-2"> <tbody> <tr><td>Rata-rata Populasi Wereng</td><td class="text-end">${s.mean_populasi_wereng ?? '-'}</td></tr> <tr><td>Rata-rata Suhu</td><td class="text-end">${s.mean_suhu ?? '-'} ℃</td></tr> <tr><td>Rata-rata Presipitasi</td><td class="text-end">${s.mean_presipitasi ?? '-'} mm</td></tr> <tr><td>Virulensi</td><td class="text-end">${virLabel}</td></tr> </tbody> </table> ${varietasHTML} `; }
            function onEachFeature(feature, layer){ const p = feature.properties || {}; const name = p.kecamatan || p.kabupaten || p.provinsi || '(tanpa nama)'; const inc = p.stats?.incidence_pct ?? 0; const vlab = p.stats?.virulensi ? `<br>Virulensi: <b>${p.stats.virulensi}</b>` : ''; layer.bindTooltip(`${name}<br>Insidensi: <b>${inc}%</b>${vlab}`, {sticky:true}); layer.on({ mouseover: e => { const l = e.target; l.setStyle({weight:2, color:'#666', dashArray:'', fillOpacity:0.85}); l.bringToFront(); }, mouseout : e => { gjLayer && gjLayer.resetStyle(e.target); }, click: e => { const bounds = e.target.getBounds(); zoomLock = true; map.flyToBounds(bounds, { padding:[20,20], duration:0.35 }); showStatsForFeature(e.target.feature); setTimeout(() => { zoomLock = false; handleZoomChange(); }, 380); } }); }
            function applyLayer(opts = {}) { const preserveView = !!opts.preserveView; if (gjLayer) { map.removeLayer(gjLayer); gjLayer = null; } if (!geojsonData) return; const selectedProv = provSelect.value; const selectedSev = sevSelect.value; const filtered = { type: 'FeatureCollection', features: (geojsonData.features || []).filter(f => { const p = f.properties || {}; const inc = p.stats?.incidence_pct ?? 0; const provOk = !selectedProv || (p.provinsi === selectedProv); const sevOk = severityMatch(inc, selectedSev); return provOk && sevOk; }) }; if (!filtered.features.length) { statPanel.innerHTML = `<div class="alert alert-warning mb-0">Tidak ada area yang cocok dengan filter.</div>`; if (!opts.preserveView) { try { map.fitBounds(indoBounds, { animate: true, padding:[20,20] }); } catch(e){} } return; } gjLayer = L.geoJSON(filtered, { style, onEachFeature }); gjLayer.addTo(map); if (!preserveView) { try { map.fitBounds(gjLayer.getBounds(), { padding:[20,20], animate:false }); } catch(e){} } showStatsForFeature(filtered.features[0]); }
            function decideLevelByZoom(){ const z = map.getZoom(); const minZ = map.getMinZoom() ?? 0; const maxZ = map.getMaxZoom() ?? (osm.options.maxZoom ?? 19); const pct = Math.max(0, Math.min(100, ((z - minZ) / (maxZ - minZ)) * 100)); if (pct < 30) return 'provinsi'; if (pct < 40) return 'kabupaten'; return 'kecamatan'; }
            let loading = false; async function loadGeoByLevel(level, opts = {}) { if (loading) return; loading = true; currentMapLevel = level; const body = new URLSearchParams({ level: level, bulan: bulanSelect ? (bulanSelect.value || '') : '', tahun: tahunSelect ? (tahunSelect.value || '') : '', pengambilan: pengambilanSelect ? (pengambilanSelect.value || '') : '', filter_provinsi: provSelect ? (provSelect.value || '') : '' }); try { const resp = await fetch('map_api.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body.toString() }); const json = await resp.json(); geojsonData = json.geojson || {type:'FeatureCollection',features:[]}; applyLayer({ preserveView: !!opts.preserveView }); } catch (e) { console.error(e); } finally { loading = false; } }
            async function refreshFilters(opts = { reload: true }) { const prevProv = provSelect.value || ''; const prevSev = sevSelect.value || ''; const body = new URLSearchParams({ act: 'filters', bulan: bulanSelect ? (bulanSelect.value || '') : '', tahun: tahunSelect ? (tahunSelect.value || '') : '', pengambilan: pengambilanSelect ? (pengambilanSelect.value || '') : '' }); try { const resp = await fetch('api.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body.toString() }); const json = await resp.json(); provSelect.innerHTML = ''; const optAllProv = document.createElement('option'); optAllProv.value = ''; optAllProv.textContent = 'Semua Provinsi'; provSelect.appendChild(optAllProv); (json.provinsi || []).forEach(p => { const o = document.createElement('option'); o.value = p; o.textContent = p; provSelect.appendChild(o); }); if (prevProv && (json.provinsi || []).includes(prevProv)) { provSelect.value = prevProv; } else { provSelect.value = ''; } const labelMap = { '0' : '0% (Tidak Ada Insidensi)', '1-10' : '1–10% (Ringan)', '10-30' : '>10–30% (Sedang)', '30-50' : '>30–50% (Berat)', '50+' : '≥50% (Sangat Berat)' }; sevSelect.innerHTML = ''; const optAllSev = document.createElement('option'); optAllSev.value = ''; optAllSev.textContent = 'Semua Keparahan'; sevSelect.appendChild(optAllSev); (json.severity || []).forEach(k => { const o = document.createElement('option'); o.value = k; o.textContent = labelMap[k] || k; sevSelect.appendChild(o); }); if (prevSev && (json.severity || []).includes(prevSev)) { sevSelect.value = prevSev; } else { sevSelect.value = ''; } if (opts.reload) { loadGeoByLevel(currentMapLevel || 'provinsi', { preserveView: true }); } } catch (e) { console.error('refreshFilters failed', e); } }
            sevSelect.addEventListener('change', () => applyLayer({ preserveView: true })); provSelect.addEventListener('change', () => { loadGeoByLevel(currentMapLevel || 'provinsi', { preserveView: false }); });
            if (bulanSelect) bulanSelect.addEventListener('change', () => { refreshFilters({ reload: true }); }); if (tahunSelect) tahunSelect.addEventListener('change', () => { refreshFilters({ reload: true }); }); if (pengambilanSelect) pengambilanSelect.addEventListener('change', () => { refreshFilters({ reload: true }); });
            refreshFilters({ reload: false }).then(() => { loadGeoByLevel(decideLevelByZoom(), { preserveView: false }); });
            function handleZoomChange(){ if (zoomLock) return; if (zoomChangeTimer) clearTimeout(zoomChangeTimer); zoomChangeTimer = setTimeout(() => { const desired = decideLevelByZoom(); if (desired !== currentMapLevel) { loadGeoByLevel(desired, { preserveView: true }); } }, 120); } map.on('zoomend', handleZoomChange);
        })();
    </script>
    <script>
        // ... (Kode Gauge Chart tidak berubah) ...
        (function(){
            const gaugeEl = document.getElementById('apiGauge'); if(!gaugeEl) return;
            const pct = Number(gaugeEl.dataset.percent || 0); const stepsText = gaugeEl.dataset.steps || ''; const value = Math.max(0, Math.min(100, pct)); const data = [value, 100 - value];
            const filledColor = 'rgba(54, 162, 235, 1)'; const emptyColor = 'rgba(230, 236, 245, .9)';
            const ctxGauge = gaugeEl.getContext('2d'); new Chart(ctxGauge, { type: 'doughnut', data: { labels: ['Terpakai','Sisa'], datasets: [{ data: data, backgroundColor: [filledColor, emptyColor], borderWidth: 0 }] }, options: { maintainAspectRatio: false, cutoutPercentage: 75, rotation: Math.PI, circumference: Math.PI, legend: { display: false }, tooltips: { enabled: false }, animation: { animateRotate: true, duration: 700 } } });
            const pctLabel = document.querySelector('.gauge-percent'); const stepLabel = document.querySelector('.gauge-steps'); if (pctLabel) pctLabel.textContent = value.toFixed(1) + '%'; if (stepLabel) stepLabel.textContent = stepsText;
        })();
    </script>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            // ======================================================
            // == HELPER MODAL ALERT & KONFIRMASI KUSTOM ==
            // ======================================================
            
            // --- Alert ---
            const customAlertModalEl = document.getElementById('customAlertModal');
            const customAlertModal = customAlertModalEl ? new bootstrap.Modal(customAlertModalEl) : null;
            const customAlertMessageEl = document.getElementById('customAlertMessage');
            let alertQueue = []; 
            let isAlertShowing = false;
            let currentAlertOnHiddenCallback = null; 

            function showCustomAlert(message, type = 'error', onHiddenCallback = null) {
                if (!customAlertModal || !customAlertMessageEl) {
                    console.warn('Custom alert modal not found. Falling back to native alert.');
                    alert(message);
                    if (onHiddenCallback) onHiddenCallback();
                    return;
                }
                let fullMessage = (type === 'error' ? '' : (type === 'success' ? 'Sukses: ' : 'Info: ')) + message;
                alertQueue.push({ message: fullMessage, onHidden: onHiddenCallback });
                if (!isAlertShowing) { showNextAlert(); }
            }
            
            function showNextAlert() {
                if (alertQueue.length === 0) { isAlertShowing = false; return; }
                isAlertShowing = true;
                const item = alertQueue.shift(); 
                customAlertMessageEl.textContent = item.message;
                currentAlertOnHiddenCallback = item.onHidden; 
                customAlertModal.show();
            }

            if (customAlertModalEl) {
                // Gunakan class unik untuk tombol close alert
                const alertCloseButton = customAlertModalEl.querySelector('.custom-alert-close-btn');
                if (alertCloseButton) {
                    alertCloseButton.addEventListener('click', () => {
                        // Tidak perlu lakukan apa-apa di sini, biarkan event hidden.bs.modal yang handle
                    });
                }

                customAlertModalEl.addEventListener('hidden.bs.modal', () => {
                    isAlertShowing = false;
                    if (currentAlertOnHiddenCallback) {
                        currentAlertOnHiddenCallback();
                        currentAlertOnHiddenCallback = null; 
                    }
                    if (alertQueue.length > 0) {
                        setTimeout(showNextAlert, 50); // Jeda sedikit agar tidak langsung tumpang tindih
                    }
                });
            }

            // --- Confirm ---
            const customConfirmModalEl = document.getElementById('customConfirmModal');
            const customConfirmModal = customConfirmModalEl ? new bootstrap.Modal(customConfirmModalEl) : null;
            const customConfirmMessageEl = document.getElementById('customConfirmMessage');
            const customConfirmYesBtn = document.getElementById('customConfirmYesBtn');
            const customConfirmNoBtn = document.getElementById('customConfirmNoBtn');
            let currentConfirmResolve = null; // Menyimpan fungsi resolve dari Promise

            /**
             * Menampilkan konfirmasi kustom menggunakan Promise.
             * @param {string} message Pesan konfirmasi.
             * @returns {Promise<boolean>} Promise yang resolve ke true jika "Ya" diklik, false jika "Tidak" diklik.
             */
            function showCustomConfirm(message) {
                return new Promise((resolve) => {
                    if (!customConfirmModal || !customConfirmMessageEl || !customConfirmYesBtn || !customConfirmNoBtn) {
                        console.warn('Custom confirm modal elements not found. Falling back to native confirm.');
                        resolve(confirm(message)); // Fallback ke confirm bawaan
                        return;
                    }

                    // Simpan fungsi resolve untuk digunakan nanti
                    currentConfirmResolve = resolve; 

                    customConfirmMessageEl.textContent = message;
                    customConfirmModal.show();
                });
            }

            // Tambahkan listener HANYA SEKALI saat setup
            if (customConfirmYesBtn) {
                customConfirmYesBtn.addEventListener('click', () => {
                    if (currentConfirmResolve) {
                        currentConfirmResolve(true); // Resolve promise dengan true
                        currentConfirmResolve = null; // Reset
                    }
                    customConfirmModal.hide();
                });
            }
            if (customConfirmNoBtn) {
                customConfirmNoBtn.addEventListener('click', () => {
                    if (currentConfirmResolve) {
                        currentConfirmResolve(false); // Resolve promise dengan false
                        currentConfirmResolve = null; // Reset
                    }
                    customConfirmModal.hide();
                });
            }
            // Event listener saat modal ditutup (misal klik backdrop atau escape) dianggap 'Tidak'
            if (customConfirmModalEl) {
                 customConfirmModalEl.addEventListener('hidden.bs.modal', () => {
                    if (currentConfirmResolve) { // Jika masih ada resolve (belum klik Ya/Tidak)
                        currentConfirmResolve(false); // Anggap "Tidak"
                        currentConfirmResolve = null; // Reset
                    }
                });
            }

            // ======================================================
            // == AKHIR HELPER MODAL ==
            // ======================================================


            const userIsLoggedIn = <?php echo json_encode(isset($_SESSION['user_id'])); ?>;
            const userIsAdmin    = <?php echo json_encode(isset($_SESSION['status']) && $_SESSION['status'] === 'admin'); ?>;

            const loginModalEl    = document.getElementById('loginModal');
            const loginModal      = loginModalEl ? new bootstrap.Modal(loginModalEl) : null;
            const curationModalEl = document.getElementById('curationModal');
            const curationModal   = curationModalEl ? new bootstrap.Modal(curationModalEl) : null;
            const registerModalEl = document.getElementById('registerModal');
            const registerModal = registerModalEl ? new bootstrap.Modal(registerModalEl) : null;

            let curationPage = 1; let curationSort = 'tanggal_input'; let curationDir  = 'DESC'; 
            const curationInfoEl = document.getElementById('curationInfo'); const curationPaginationEl = document.getElementById('curationPagination');
            function setSortIndicator() { document.querySelectorAll('#curationTable thead th.sortable .sort-indicator').forEach(el => el.textContent = ''); const activeTh = document.querySelector(`#curationTable thead th.sortable[data-sort="${curationSort}"] .sort-indicator`); if (activeTh) activeTh.textContent = (curationDir === 'ASC' ? '▲' : '▼'); }
            function buildPagination(totalPages, current) { /* ... (fungsi buildPagination tidak berubah) ... */ curationPaginationEl.innerHTML = ''; if (totalPages <= 1) return; function addItem(label, page, disabled=false, active=false) { const li = document.createElement('li'); li.className = `page-item ${disabled ? 'disabled' : ''} ${active ? 'active' : ''}`; const a = document.createElement('a'); a.className = 'page-link'; a.href = '#'; a.textContent = label; a.addEventListener('click', (e) => { e.preventDefault(); if (disabled || active) return; curationPage = page; loadCurationData(); }); li.appendChild(a); curationPaginationEl.appendChild(li); } addItem('«', 1, current === 1); addItem('‹', Math.max(1, current - 1), current === 1); const windowSize = 7; let start = Math.max(1, current - Math.floor(windowSize/2)); let end = Math.min(totalPages, start + windowSize - 1); if (end - start + 1 < windowSize) start = Math.max(1, end - windowSize + 1); for (let p = start; p <= end; p++) { addItem(String(p), p, false, p === current); } addItem('›', Math.min(totalPages, current + 1), current === totalPages); addItem('»', totalPages, current === totalPages); }

            const openLoginBtn = document.getElementById('openLoginBtn'); if (openLoginBtn) { openLoginBtn.addEventListener('click', () => { loginModal?.show(); }); }
            const openCurationBtn = document.getElementById('openCurationPanel'); if (openCurationBtn) { openCurationBtn.addEventListener('click', () => { if (!userIsLoggedIn) { showCustomAlert('Anda harus login.', 'error'); loginModal?.show(); return; } if (!userIsAdmin) { showCustomAlert('Hanya admin yang dapat mengakses menu verifikasi data.', 'error'); return; } loadCurationData(); curationModal?.show(); }); }
            
            const openRegFromLogin = document.getElementById('openRegisterFromLogin');
            if (openRegFromLogin && loginModal && registerModal) {
                 openRegFromLogin.addEventListener('click', (e) => { e.preventDefault(); loginModal.hide(); registerModal.show(); });
            }
            const openLoginFromReg = document.getElementById('openLoginFromRegister');
             if (openLoginFromReg && loginModal && registerModal) {
                openLoginFromReg.addEventListener('click', (e) => { e.preventDefault(); registerModal.hide(); loginModal.show(); });
             }

            const loginForm = document.getElementById('loginForm');
             if (loginForm && loginModal) {
                loginForm.addEventListener('submit', (e) => {
                    e.preventDefault();
                    const email = document.getElementById('loginEmail').value;
                    const password = document.getElementById('loginPassword').value;
                    const submitButton = e.target.querySelector('button[type="submit"]');
                    submitButton.disabled = true; submitButton.textContent = 'Memproses...';
                    fetch('login_process.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ email: email, password: password }) })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) { 
                            showCustomAlert(data.message, 'success', () => { window.location.reload(); }); 
                        } else { 
                            showCustomAlert(data.message, 'error'); 
                        }
                    })
                    .catch(error => { console.error('Error:', error); showCustomAlert('Terjadi kesalahan koneksi.', 'error'); })
                    .finally(() => { submitButton.disabled = false; submitButton.textContent = 'Login'; });
                });
             }

            const registerForm = document.getElementById('registerForm');
             if (registerForm && registerModal && loginModal) {
                registerForm.addEventListener('submit', (e) => {
                    e.preventDefault();
                    const submitButton = e.target.querySelector('button[type="submit"]');
                    const formData = { nama: document.getElementById('regNama').value, email: document.getElementById('regEmail').value, password: document.getElementById('regPassword').value, domisili: document.getElementById('regDomisili').value, telepon: document.getElementById('regTelepon').value };
                    submitButton.disabled = true; submitButton.textContent = 'Mendaftarkan...';
                    fetch('register_process.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(formData) })
                    .then(response => response.json())
                    .then(data => {
                        showCustomAlert(data.message, data.success ? 'success' : 'error');
                        if (data.success) { registerModal.hide(); loginModal.show(); e.target.reset(); }
                    })
                    .catch(error => { console.error('Error:', error); showCustomAlert('Terjadi kesalahan koneksi.', 'error'); })
                    .finally(() => { submitButton.disabled = false; submitButton.textContent = 'Register'; });
                });
             }

            const togglePassword = document.getElementById('togglePassword'); if (togglePassword) { togglePassword.addEventListener('click', function () { const passwordInput = document.getElementById('loginPassword'); const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password'; passwordInput.setAttribute('type', type); this.classList.toggle('fa-eye'); this.classList.toggle('fa-eye-slash'); }); }

            const checkAllCuration = document.getElementById('checkAllCuration');
            const curationTableBody = document.getElementById('curationTableBody');
            const btnVerifyCuration = document.getElementById('btnVerifyCuration');
            const btnDeleteCuration = document.getElementById('btnDeleteCuration');
            const curationRowsSelect = document.getElementById('curationRows');

            function checkCurationButtonState() { if (!curationTableBody) return; const checkedRows = curationTableBody.querySelectorAll('.check-row-curation:checked'); const hasSelection = checkedRows.length > 0; if (btnVerifyCuration) btnVerifyCuration.disabled = !hasSelection; if (btnDeleteCuration) btnDeleteCuration.disabled = !hasSelection; }

            if (checkAllCuration && curationTableBody) {
                checkAllCuration.addEventListener('change', function () { const rowCheckboxes = curationTableBody.querySelectorAll('.check-row-curation'); rowCheckboxes.forEach(cb => { cb.checked = this.checked; }); checkCurationButtonState(); });
                curationTableBody.addEventListener('change', (e) => { if (e.target.classList.contains('check-row-curation')) { checkCurationButtonState(); if (checkAllCuration) { const total = curationTableBody.querySelectorAll('.check-row-curation').length; const checked = curationTableBody.querySelectorAll('.check-row-curation:checked').length; checkAllCuration.checked = total > 0 && total === checked; } } });
            }
            
            // --- [DIUBAH] Menggunakan showCustomConfirm ---
            if (btnVerifyCuration) {
                btnVerifyCuration.addEventListener('click', async () => { 
                    const checkedRows = curationTableBody.querySelectorAll('.check-row-curation:checked');
                    const idsToVerify = Array.from(checkedRows).map(cb => cb.value);
                    if (idsToVerify.length === 0) return;

                    // Tampilkan konfirmasi kustom
                    const userConfirmed = await showCustomConfirm(`Anda yakin ingin memverifikasi ${idsToVerify.length} data terpilih?`);
                    
                    if (!userConfirmed) return; // Jika pengguna klik "Tidak"

                    btnVerifyCuration.disabled = true; btnVerifyCuration.textContent = 'Memverifikasi...';
                    try {
                        const response = await fetch('verify_data.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ ids: idsToVerify }) });
                        const result = await response.json();
                        showCustomAlert(result.message, result.success ? 'success' : 'error', () => { if (result.success) { loadCurationData(); } });
                    } catch (error) { console.error('Error verifying data:', error); showCustomAlert('Terjadi kesalahan koneksi saat verifikasi.', 'error');
                    } finally { btnVerifyCuration.disabled = true; /* Akan di-enable lagi oleh checkCurationButtonState() */ btnVerifyCuration.textContent = 'Verifikasi Data Terpilih'; }
                });
            }
            
            // --- [DIUBAH] Menggunakan showCustomConfirm ---
            if (btnDeleteCuration){
                btnDeleteCuration.addEventListener('click', async () => {
                    const checkedRows = curationTableBody.querySelectorAll('.check-row-curation:checked');
                    const idsToDelete = Array.from(checkedRows).map(cb => cb.value);
                    if (idsToDelete.length === 0) return;

                    // Tampilkan konfirmasi kustom
                    const userConfirmed = await showCustomConfirm(`Anda yakin ingin menghapus ${idsToDelete.length} data terpilih? Tindakan ini tidak dapat dibatalkan.`);

                    if (!userConfirmed) return; // Jika pengguna klik "Tidak"

                    btnDeleteCuration.disabled = true; const oldText = btnDeleteCuration.textContent; btnDeleteCuration.textContent = 'Menghapus...';
                    try {
                        const response = await fetch('delete_data.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ ids: idsToDelete }) });
                        const result = await response.json();
                        const alertMessage = result.message || (result.success ? 'Berhasil menghapus data.' : 'Gagal menghapus data.');
                        showCustomAlert(alertMessage, result.success ? 'success' : 'error', () => { if (result.success) { loadCurationData(); } });
                    } catch (err) { console.error('Error deleting data:', err); showCustomAlert('Terjadi kesalahan koneksi saat menghapus.', 'error');
                    } finally { btnDeleteCuration.disabled = true; /* Akan di-enable lagi oleh checkCurationButtonState() */ btnDeleteCuration.textContent = oldText; }
                });
            }

            if (curationRowsSelect) {
                 curationRowsSelect.addEventListener('change', () => { loadCurationData(); });
            }

            document.querySelectorAll('#curationTable thead th.sortable').forEach(th => { th.style.cursor = 'pointer'; th.addEventListener('click', () => { const s = th.getAttribute('data-sort'); if (!s) return; if (curationSort === s) { curationDir = (curationDir === 'ASC') ? 'DESC' : 'ASC'; } else { curationSort = s; curationDir = 'ASC'; } curationPage = 1; loadCurationData(); }); });
            
            if (curationRowsSelect) {
                 curationRowsSelect.addEventListener('change', () => { curationPage = 1; loadCurationData(); });
            }


            async function loadCurationData() { /* ... (fungsi loadCurationData tidak berubah) ... */ const limit = parseInt(curationRowsSelect?.value || '10', 10); const loadingRow = '<tr><td colspan="12" class="text-center">Memuat data...</td></tr>'; if(curationTableBody) curationTableBody.innerHTML = loadingRow; try { const url = `get_curation_data.php?limit=${encodeURIComponent(limit)}&page=${encodeURIComponent(curationPage)}&sort=${encodeURIComponent(curationSort)}&dir=${encodeURIComponent(curationDir)}`; const response = await fetch(url); const result = await response.json(); if(curationTableBody) curationTableBody.innerHTML = ''; if (result.success) { const rows = result.data || []; if (rows.length > 0) { rows.forEach(row => { const tr = ` <tr> <td><input class="form-check-input check-row-curation" type="checkbox" value="${row.id}"></td> <td>${row.tanggal_input ?? '-'}</td> <td>${row.kecamatan_simple || '-'}</td> <td>${row.nama_wilayah || '-'}</td> <td>${row.waktu_semai ?? '-'}</td> <td>${row.pengambilan_data ?? '-'}</td> <td>${row.suhu ?? '-'}</td> <td>${row.presipitasi ?? '-'}</td> <td>${row.populasi_wereng ?? '-'}</td> <td>${row.varietas_padi || '-'}</td> <td>${row.virulensi || '-'}</td> <td>${row.persentase_insidensi ?? '-'}</td> </tr> `; if(curationTableBody) curationTableBody.insertAdjacentHTML('beforeend', tr); }); if(curationInfoEl) curationInfoEl.textContent = `Halaman ${result.page} dari ${result.total_pages} • Total ${result.total} data`; buildPagination(result.total_pages, result.page); } else { if(curationInfoEl) curationInfoEl.textContent = 'Halaman 1 dari 1 • Total 0 data'; if(curationPaginationEl) curationPaginationEl.innerHTML = ''; if(curationTableBody) curationTableBody.innerHTML = '<tr><td colspan="12" class="text-center">Tidak ada data untuk diverifikasi.</td></tr>'; } } else { if(curationInfoEl) curationInfoEl.textContent = ''; if(curationPaginationEl) curationPaginationEl.innerHTML = ''; if(curationTableBody) curationTableBody.innerHTML = `<tr><td colspan="12" class="text-center text-danger">Gagal memuat data: ${result.message || 'Kesalahan tidak diketahui.'}</td></tr>`; console.error("Gagal load kurasi:", result.message); } } catch (error) { console.error('Error fetching curation data:', error); if(curationInfoEl) curationInfoEl.textContent = ''; if(curationPaginationEl) curationPaginationEl.innerHTML = ''; if(curationTableBody) curationTableBody.innerHTML = '<tr><td colspan="12" class="text-center text-danger">Gagal memuat data. Periksa koneksi atau log server.</td></tr>'; } finally { if(checkAllCuration) checkAllCuration.checked = false; checkCurationButtonState(); setSortIndicator(); } }

            // Panggil loadCurationData jika modal kurasi ada saat load halaman (jika diperlukan)
            // if (curationModalEl) {
            //    loadCurationData(); // Uncomment jika data perlu dimuat saat halaman pertama kali dibuka
            // }

        });
    </script>
</body>

<?php
    mysqli_close($conn);
?>
