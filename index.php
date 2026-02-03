<?php
/**
 * 🧾 レシート解析システム - 最終精度向上版
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
    if ($action == 'download_log') {
        if (file_exists($logFile)) {
            header('Content-Type: text/plain');
            header('Content-Disposition: attachment; filename="ocr.log"');
            readfile($logFile); exit;
        }
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
        if ($key > 0) sleep(1);

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
        $potentialNames = []; // 金額が見つかる前のテキスト行を保持

        foreach ($lines as $line) {
            $text = trim($line['text']);
            // 判定用クリーニング
            $pureText = str_replace([' ', '　', '＊', '*', '√', '軽', '轻', '(', ')', '8%', '10%', '税', '.', '．', '…'], '', $text);
            
            // ① 合計金額を見つけたら解析終了モードへ
            if (preg_match('/合計|合計額|小計/u', $pureText)) {
                if (preg_match('/[¥￥]([\d,]+)/u', $text, $totalMatch)) {
                    $logTotal = (int)str_replace(',', '', $totalMatch[1]);
                }
                break; // 合計以降は読まない
            }

            // ② 金額(¥)が含まれているかチェック
            if (preg_match('/[¥￥]([\d,]+)/u', $text, $matches)) {
                $price = (int)str_replace(',', '', $matches[1]);
                
                // 同じ行に名前があるか確認
                $namePart = trim(preg_replace('/[¥￥][\d,]+.*/u', '', $text));
                $cleanName = str_replace(['＊', '*', '轻', '軽', '(', ')', '.', '．', ' ', '　'], '', $namePart);

                // 行に名前がない場合、直前の有効な行を名前として採用
                if (mb_strlen($cleanName) < 1 && !empty($potentialNames)) {
                    $cleanName = end($potentialNames);
                }

                // 除外ワードチェック（店舗情報やヘッダーを排除）
                if (!empty($cleanName) && !preg_match('/Family|新宿|電話|番号|登録|領収|http|：/u', $cleanName)) {
                    // 同じ名前が連続して登録されるのを防止（OCRの重複読み対策）
                    $isDuplicate = false;
                    foreach ($currentItems as $ci) { if ($ci['name'] == $cleanName) $isDuplicate = true; }
                    
                    if (!$isDuplicate) {
                        $currentItems[] = ['name' => $cleanName, 'price' => $price];
                    }
                }
            } else {
                // 金额のない行は、商品名の候補として保存
                if (mb_strlen($pureText) >= 2 && !preg_match('/Family|新宿|電話|番号|登録|領収|http|No/u', $pureText)) {
                    $potentialNames[] = $pureText;
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
        body { font-family: sans-serif; background: #f4f7f9; padding: 20px; }
        .box { max-width: 600px; margin: auto; background: white; padding: 25px; border-radius: 12px; box-shadow: 0 8px 30px rgba(0,0,0,0.05); }
        .card { border-left: 4px solid #2ecc71; background: #fafafa; padding: 15px; margin-bottom: 15px; border-radius: 6px; }
        .row { display: flex; justify-content: space-between; padding: 6px 0; border-bottom: 1px dashed #eee; font-size: 14px; }
        .amount-big { font-size: 32px; font-weight: bold; color: #ff4d4f; text-align: center; }
        .btn-main { width: 100%; padding: 15px; background: #1890ff; color: white; border: none; border-radius: 8px; font-weight: bold; cursor: pointer; }
        .nav-bar { margin-top: 25px; display: flex; justify-content: space-around; }
    </style>
</head>
<body>
    <div class="box">
        <h2 style="text-align:center;">📜 レシート解析システム</h2>
        <form id="uploadForm" method="post" enctype="multipart/form-data">
            <input type="file" id="fileInput" name="receipts[]" multiple required style="margin-bottom:20px; width: 100%;">
            <button type="submit" id="submitBtn" class="btn-main">解析を開始してDBに保存</button>
            <div id="status" style="display:none; text-align:center; margin-top:10px; color:#1890ff;">解析中...</div>
        </form>

        <?php if (!empty($results)): ?>
            <div style="margin-top:30px;">
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
                                <span>(OCR読取合計)</span>
                                <span>¥<?= number_format($res['total']) ?></span>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
                <div class="amount-big">¥<?= number_format($totalAllAmount) ?></div>
            </div>
        <?php endif; ?>

        <div class="nav-bar">
            <a href="?action=csv">📥 CSV</a>
            <a href="?action=download_log">📝 ログ</a>
            <a href="?action=clear_view">🔄 クリア</a>
        </div>
    </div>
    <script>
    document.getElementById('uploadForm').onsubmit = async function(e) {
        document.getElementById('submitBtn').disabled = true;
        document.getElementById('status').style.display = "block";
    };
    </script>
</body>
</html>
