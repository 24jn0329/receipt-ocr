<?php
/**
 * 🧾 修正版：レシート解析システム (Azure SQL 統合)
 * 重複登録防止ロジック強化済み
 */

// --- 1. 設定と環境構成 ---
@set_time_limit(600);
@ini_set('memory_limit', '512M');

$endpoint = "https://cv-receipt.cognitiveservices.azure.com/"; 
$apiKey   = "acFa9r1gRfWfvNsBjsLFsyec437ihmUsWXpA1WKVYD4z5yrPBrrMJQQJ99CBACNns7RXJ3w3AAAFACOGcllL"; 
$logFile  = 'ocr.log';

// --- 2. Azure SQL 接続設定 ---
$serverName = "tcp:receipt-server.database.windows.net,1433"; 
$connectionOptions = array(
    "Database" => "db_receipt",
    "Uid" => "jn240329",
    "PWD" => "15828415312dY",
    "CharacterSet" => "UTF-8"
);

$conn = sqlsrv_connect($serverName, $connectionOptions);
if ($conn === false) {
    die("<pre>" . print_r(sqlsrv_errors(), true) . "</pre>");
}

// --- 3. アクション処理 (CSV/Log/Clear) ---
if (isset($_GET['action'])) {
    $action = $_GET['action'];
    if ($action == 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=receipt_export_'.date('Ymd').'.csv');
        echo "\xEF\xBB\xBF"; 
        $output = fopen('php://output', 'w');
        fputcsv($output, ['ID', 'バッチID', 'ファイル名', '項目名', '金額', '合計フラグ', '登録日時']);
        $sql = "SELECT id, upload_batch_id, file_name, item_name, price, is_total, created_at FROM Receipts ORDER BY id DESC";
        $stmt = sqlsrv_query($conn, $sql);
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            fputcsv($output, [$row['id'], $row['upload_batch_id'], $row['file_name'], $row['item_name'], $row['price'], $row['is_total'], $row['created_at']->format('Y-m-d H:i:s')]);
        }
        fclose($output); exit;
    }
    if ($action == 'clear_view') {
        header("Location: " . strtok($_SERVER["PHP_SELF"], '?')); exit;
    }
}

// --- 4. OCR 解析 & DB保存ロジック ---
$results = [];
$totalAllAmount = 0;

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['receipts'])) {
    $batchId = uniqid('BT_');

    foreach ($_FILES['receipts']['tmp_name'] as $key => $tmpName) {
        if (empty($tmpName)) continue;
        $fileName = $_FILES['receipts']['name'][$key];
        $imgData = file_get_contents($tmpName);
        $apiUrl = rtrim($endpoint, '/') . "/computervision/imageanalysis:analyze?api-version=2023-10-01&features=read";
        
        $ch = curl_init($apiUrl);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/octet-stream', 'Ocp-Apim-Subscription-Key: ' . $apiKey]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $imgData);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) continue;

        $data = json_decode($response, true);
        $lines = $data['readResult']['blocks'][0]['lines'] ?? [];
        $currentItems = [];
        $logTotal = 0;
        $stopFlag = false; // ★商品解析を止めるフラグ

        for ($i = 0; $i < count($lines); $i++) {
            $text = trim($lines[$i]['text']);
            // クリーニング
            $pureText = str_replace([' ', '　', '＊', '*', '√', '軽', '轻', '(', ')', '8%', '10%'], '', $text);

            // ① 合計金額の判定（ここでstopFlagを立てる）
            if (preg_match('/合計|合計額|総計/u', $pureText)) {
                if (preg_match('/[¥￥]([\d,]+)/u', $text, $totalMatch)) {
                    $logTotal = (int)str_replace(',', '', $totalMatch[1]);
                }
                $stopFlag = true; 
                continue;
            }

            // ② 解析除外ワード（支払い情報や消費税など）
            if (preg_match('/内消費税|対象|支払|残高|再発行|クレジット|メダル/u', $pureText)) {
                $stopFlag = true;
                continue;
            }

            // ③ 商品抽出（stopFlagが立っていない場合のみ実行）
            if (!$stopFlag && preg_match('/[¥￥]([\d,]+)/u', $text, $matches)) {
                $price = (int)str_replace(',', '', $matches[1]);
                $nameInLine = trim(preg_replace('/[\.．…]+|[¥￥].*$/u', '', $text));
                $cleanName = str_replace(['＊', '*', '轻', '軽', '(', ')', '.', '．', ' '], '', $nameInLine);

                // 行に名前がない場合は上の行を遡る
                if (mb_strlen($cleanName) < 2) {
                    for ($j = $i - 1; $j >= 0; $j--) {
                        $prev = trim($lines[$j]['text']);
                        if (preg_match('/合計|店舗|電話|領収/u', $prev)) break;
                        $cleanPrev = str_replace(['＊', '*', ' ', '√', '軽', '轻'], '', $prev);
                        if (mb_strlen($cleanPrev) >= 2) {
                            $cleanName = $cleanPrev;
                            break;
                        }
                    }
                }

                // 特定の禁止ワードが含まれていないかチェックして追加
                if (!empty($cleanName) && !preg_match('/Family|新宿|電話|登録|領収/u', $cleanName)) {
                    $currentItems[] = ['name' => $cleanName, 'price' => $price];
                }
            }
        }

        // --- DB保存 ---
        foreach ($currentItems as $it) {
            $sql = "INSERT INTO Receipts (upload_batch_id, file_name, item_name, price, is_total) VALUES (?, ?, ?, ?, 0)";
            sqlsrv_query($conn, $sql, [$batchId, $fileName, $it['name'], $it['price']]);
            $totalAllAmount += $it['price'];
        }
        if ($logTotal > 0) {
            $sql = "INSERT INTO Receipts (upload_batch_id, file_name, item_name, price, is_total) VALUES (?, ?, ?, ?, 1)";
            sqlsrv_query($conn, $sql, [$batchId, $fileName, '合計(OCR読み取り)', $logTotal]);
        }
        $results[] = ['file' => $fileName, 'items' => $currentItems, 'total' => $logTotal];
    }
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>レシート解析システム</title>
    <style>
        body { font-family: sans-serif; background: #f4f7f9; padding: 20px; color: #333; }
        .box { max-width: 600px; margin: auto; background: white; padding: 25px; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.1); }
        .card { border-left: 4px solid #2ecc71; background: #fafafa; padding: 15px; margin-bottom: 10px; }
        .row { display: flex; justify-content: space-between; padding: 5px 0; border-bottom: 1px dashed #eee; }
        .btn-main { width: 100%; padding: 15px; background: #1890ff; color: white; border: none; border-radius: 8px; font-weight: bold; cursor: pointer; }
        .nav-bar { margin-top: 20px; display: flex; justify-content: space-between; }
        .nav-link { font-size: 13px; color: #666; text-decoration: none; padding: 5px 10px; border: 1px solid #ddd; border-radius: 4px; }
    </style>
</head>
<body>
    <div class="box">
        <h2 style="text-align:center;">📜 レシート解析システム</h2>
        
        <form id="uploadForm" method="post" enctype="multipart/form-data">
            <input type="file" id="fileInput" name="receipts[]" multiple required style="margin-bottom:20px;">
            <button type="submit" id="submitBtn" class="btn-main">解析を開始してDBに保存</button>
            <div id="status" style="display:none; text-align:center; margin-top:10px; color:#1890ff;">解析中...</div>
        </form>

        <?php if (!empty($results)): ?>
            <div style="margin-top:30px;">
                <h3 style="color: #1890ff;">✅ 解析結果</h3>
                <?php foreach ($results as $res): ?>
                    <div class="card">
                        <small>📄 <?= htmlspecialchars($res['file']) ?></small>
                        <?php foreach ($res['items'] as $it): ?>
                            <div class="row">
                                <span><?= htmlspecialchars($it['name']) ?></span>
                                <span>¥<?= number_format($it['price']) ?></span>
                            </div>
                        <?php endforeach; ?>
                        <?php if($res['total'] > 0): ?>
                            <div class="row" style="color:red; font-weight:bold;">
                                <span>(合計値)</span>
                                <span>¥<?= number_format($res['total']) ?></span>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
                <div style="text-align:center; background:#fff5f5; padding:15px; border-radius:8px;">
                    商品合計：<span style="font-size:24px; color:red; font-weight:bold;">¥<?= number_format($totalAllAmount) ?></span>
                </div>
            </div>
        <?php endif; ?>

        <div class="nav-bar">
            <a href="?action=csv" class="nav-link">📥 CSV保存</a>
            <a href="?action=clear_view" class="nav-link">🔄 表示クリア</a>
        </div>
    </div>

    <script>
    document.getElementById('uploadForm').onsubmit = async function(e) {
        const btn = document.getElementById('submitBtn');
        const status = document.getElementById('status');
        btn.disabled = true;
        status.style.display = "block";
    };
    </script>
</body>
</html>
