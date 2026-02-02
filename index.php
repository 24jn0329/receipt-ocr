<?php
/**
 * 🧾 レシート解析システム - 精度向上版
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
        header('Content-Disposition: attachment; filename=receipt_'.date('Ymd').'.csv');
        echo "\xEF\xBB\xBF";
        $output = fopen('php://output', 'w');
        fputcsv($output, ['ID', 'バッチID', 'ファイル名', '項目名', '金額', '合計フラグ', '日時']);
        $sql = "SELECT * FROM Receipts ORDER BY id DESC";
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

// --- 4. OCR 解析ロジック ---
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

        for ($i = 0; $i < count($lines); $i++) {
            $text = trim($lines[$i]['text']);
            
            // 1. 金額パターン (¥100, \100, 100) を探す
            // 日本のレシート特有の「軽」「*」などの記号付き金額にも対応
            if (preg_match('/[¥￥\\]\s?([\d,]+)/u', $text, $matches)) {
                $price = (int)str_replace(',', '', $matches[1]);

                // 合計金額行の判定
                if (preg_match('/合計|合\s+計|領収金額/u', $text)) {
                    $logTotal = $price;
                    continue; 
                }

                // 2. 商品名の特定ロジック
                // まず同じ行から金額部分を除去して名前候補を作る
                $nameCandidate = trim(preg_replace('/[¥￥\\].*$/u', '', $text));
                // 不要な記号をクリーニング
                $nameCandidate = str_replace(['◎', '＊', '*', '軽', '轻', '(', ')', '8%', '10%', '単', '価'], '', $nameCandidate);

                // もし同じ行に名前がない（金額だけだった）場合、1〜2行上を遡って探す
                if (mb_strlen($nameCandidate) < 2) {
                    for ($j = 1; $j <= 2; $j++) {
                        if (isset($lines[$i - $j])) {
                            $prevText = trim($lines[$i - $j]['text']);
                            // 明らかに商品名でない行（電話番号、日付、住所等）は無視
                            if (!preg_match('/\d{4}年|[\d-]{10,}|新宿|店|レジ|No/u', $prevText)) {
                                $nameCandidate = str_replace(['◎', '＊', '*', '軽', '轻'], '', $prevText);
                                break;
                            }
                        }
                    }
                }

                $finalName = trim($nameCandidate);

                // 3. 最終バリデーション（除外ワードリスト）
                if (mb_strlen($finalName) >= 2 && !preg_match('/合計|消費税|対象|支払|領収|再発行|残高|お釣/u', $finalName)) {
                    // 重複登録防止（同じ名前・金額が連続して解析された場合を弾く）
                    $isDuplicate = false;
                    foreach ($currentItems as $existing) {
                        if ($existing['name'] === $finalName && $existing['price'] === $price) {
                            $isDuplicate = true; break;
                        }
                    }
                    if (!$isDuplicate) {
                        $currentItems[] = ['name' => $finalName, 'price' => $price];
                    }
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
            sqlsrv_query($conn, $sql, [$batchId, $fileName, '合計(OCR読取)', $logTotal]);
        }
        $results[] = ['file' => $fileName, 'items' => $currentItems, 'total' => $logTotal];
    }
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>レシート解析システム Pro</title>
    <style>
        body { font-family: sans-serif; background: #f0f2f5; padding: 20px; }
        .container { max-width: 600px; margin: auto; background: white; padding: 30px; border-radius: 15px; box-shadow: 0 4px 20px rgba(0,0,0,0.1); }
        .result-card { border-left: 5px solid #007bff; background: #f8f9fa; padding: 15px; margin: 15px 0; border-radius: 5px; }
        .item-row { display: flex; justify-content: space-between; padding: 5px 0; border-bottom: 1px solid #eee; }
        .total-box { text-align: center; background: #fff1f0; padding: 20px; border-radius: 10px; margin-top: 20px; }
        .price { font-weight: bold; color: #d93025; }
        .btn { display: inline-block; padding: 10px 20px; background: #007bff; color: white; text-decoration: none; border-radius: 5px; border: none; cursor: pointer; }
    </style>
</head>
<body>
    <div class="container">
        <h2>📜 レシート解析システム</h2>
        <form method="post" enctype="multipart/form-data">
            <input type="file" name="receipts[]" multiple required style="display:block; margin-bottom:20px;">
            <button type="submit" class="btn">解析を開始して保存</button>
        </form>

        <?php if (!empty($results)): ?>
            <?php foreach ($results as $res): ?>
                <div class="result-card">
                    <small>📄 <?= htmlspecialchars($res['file']) ?></small>
                    <?php foreach ($res['items'] as $it): ?>
                        <div class="item-row">
                            <span><?= htmlspecialchars($it['name']) ?></span>
                            <span class="price">¥<?= number_format($it['price']) ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
            <div class="total-box">
                <h3>今回の全レシート商品合計</h3>
                <h2 class="price">¥<?= number_format($totalAllAmount) ?></h2>
            </div>
        <?php endif; ?>

        <div style="margin-top:20px; border-top: 1px solid #ddd; padding-top: 20px;">
            <a href="?action=csv" class="btn" style="background:#28a745;">CSVダウンロード</a>
            <a href="?action=clear_view" style="margin-left:15px; color:#666;">表示をリセット</a>
        </div>
    </div>
</body>
</html>
