<?php
/**
 * 🧾 レシート解析システム - 複数枚・一括処理安定版
 */

// --- 1. 設定と環境構成 ---
@set_time_limit(600);
@ini_set('memory_limit', '512M');

$endpoint = "https://cv-receipt.cognitiveservices.azure.com/"; 
$apiKey   = "acFa9r1gRfWfvNsBjsLFsyec437ihmUsWXpA1WKVYD4z5yrPBrrMJQQJ99CBACNns7RXJ3w3AAAFACOGcllL"; 

// --- 2. Azure SQL 接続設定 ---
$serverName = "tcp:receipt-server.database.windows.net,1433"; 
$connectionOptions = array(
    "Database" => "db_receipt",
    "Uid" => "jn240329",
    "PWD" => "15828415312dY",
    "CharacterSet" => "UTF-8"
);

$conn = sqlsrv_connect($serverName, $connectionOptions);
if ($conn === false) { die("<pre>" . print_r(sqlsrv_errors(), true) . "</pre>"); }

// --- 3. アクション処理 (CSV/Clear) ---
if (isset($_GET['action'])) {
    if ($_GET['action'] == 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=receipt_export_'.date('Ymd').'.csv');
        echo "\xEF\xBB\xBF"; 
        $output = fopen('php://output', 'w');
        fputcsv($output, ['ID', 'バッチID', 'ファイル名', '項目名', '金額', '合計フラグ', '登録日時']);
        $stmt = sqlsrv_query($conn, "SELECT id, upload_batch_id, file_name, item_name, price, is_total, created_at FROM Receipts ORDER BY id DESC");
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            fputcsv($output, [$row['id'], $row['upload_batch_id'], $row['file_name'], $row['item_name'], $row['price'], $row['is_total'], $row['created_at']->format('Y-m-d H:i:s')]);
        }
        fclose($output); exit;
    }
    if ($_GET['action'] == 'clear_view') {
        header("Location: " . strtok($_SERVER["PHP_SELF"], '?')); exit;
    }
}

// --- 4. OCR 解析核心逻辑 ---
$results = [];
$grandTotal = 0;

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['receipts'])) {
    $batchId = uniqid('BT_');

    foreach ($_FILES['receipts']['tmp_name'] as $key => $tmpName) {
        if (empty($tmpName)) continue;
        
        $fileName = $_FILES['receipts']['name'][$key];
        $imgData = file_get_contents($tmpName);
        $apiUrl = rtrim($endpoint, '/') . "/computervision/imageanalysis:analyze?api-version=2023-10-01&features=read";
        
        $ch = curl_init($apiUrl);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/octet-stream', 'Ocp-Apim-Subscription-Key: '.$apiKey]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $imgData);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $response = curl_exec($ch);
        $data = json_decode($response, true);
        curl_close($ch);

        $lines = $data['readResult']['blocks'][0]['lines'] ?? [];
        $items = [];
        $receiptTotal = 0;
        $potentialNames = []; 

        foreach ($lines as $line) {
            $text = trim($line['text']);
            // 判定用クリーニング (判定文字: チョコバターメロンパ, ザバス, アポロ等)
            $cleanText = str_replace([' ', '　', '＊', '*', '√'], '', $text);

            // ① 合计行检测
            if (preg_match('/合計|合計額/u', $cleanText)) {
                if (preg_match('/[¥￥]?([\d,]{2,})/', $text, $m)) $receiptTotal = (int)str_replace(',', '', $m[1]);
                break; 
            }

            // ② 商品和金额行匹配 (核心逻辑更新)
            // 匹配格式如: "アポロチョコレート ¥198轻" 或 "¥198"
            if (preg_match('/[¥￥]([\d,]+)/', $text, $matches) || preg_match('/([\d,]+)(?:軽|轻|8%|10%)/u', $text, $matches)) {
                $price = (int)str_replace(',', '', $matches[1]);
                
                // 排除一些明显的非商品金额（如找零、余额）
                if (preg_match('/釣銭|預り|残高|対象/u', $cleanText)) continue;

                // 提取商品名
                $namePart = trim(preg_replace('/[¥￥].*|[\d,]+(?:軽|轻|8%|10%).*/u', '', $text));
                $finalName = str_replace(['◎', '○', ' ', '　'], '', $namePart);

                // 如果本行没有名字，从上一行缓冲取
                if (mb_strlen($finalName) < 1 && !empty($potentialNames)) {
                    $finalName = end($potentialNames);
                }

                if (mb_strlen($finalName) > 1 && !preg_match('/領収|番号|電話|Family/u', $finalName)) {
                    $items[] = ['name' => $finalName, 'price' => $price];
                }
            } else {
                // 将不含金额的行存入候选商品名缓冲
                if (mb_strlen($cleanText) > 2 && !preg_match('/領収|証|http|：|レジ/u', $cleanText)) {
                    $potentialNames[] = $cleanText;
                }
            }
        }

        // --- 保存至 DB ---
        foreach ($items as $it) {
            sqlsrv_query($conn, "INSERT INTO Receipts (upload_batch_id, file_name, item_name, price, is_total) VALUES (?, ?, ?, ?, 0)", [$batchId, $fileName, $it['name'], $it['price']]);
            $grandTotal += $it['price'];
        }
        if ($receiptTotal > 0) {
            sqlsrv_query($conn, "INSERT INTO Receipts (upload_batch_id, file_name, item_name, price, is_total) VALUES (?, ?, ?, ?, 1)", [$batchId, $fileName, '合計(OCR)', $receiptTotal]);
        }
        $results[] = ['file' => $fileName, 'items' => $items, 'total' => $receiptTotal];
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
        .box { max-width: 700px; margin: auto; background: white; padding: 25px; border-radius: 12px; box-shadow: 0 5px 15px rgba(0,0,0,0.1); }
        .card { border-left: 5px solid #2ecc71; background: #fafafa; padding: 15px; margin-bottom: 20px; border-radius: 8px; }
        .row { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px dashed #ddd; }
        .total-box { font-size: 40px; font-weight: bold; color: #ff4d4f; text-align: center; margin-top: 20px; }
        .btn-main { width: 100%; padding: 15px; background: #4a90e2; color: white; border: none; border-radius: 8px; font-size: 18px; cursor: pointer; }
    </style>
</head>
<body>
    <div class="box">
        <h2 style="text-align:center;">📜 レシート解析システム</h2>
        <form method="post" enctype="multipart/form-data">
            <input type="file" name="receipts[]" multiple required style="margin-bottom:20px;">
            <button type="submit" class="btn-main">解析を開始してDBに保存</button>
        </form>

        <?php if (!empty($results)): ?>
            <div style="margin-top:30px;">
                <?php foreach ($results as $res): ?>
                    <div class="card">
                        <strong>📄 <?= htmlspecialchars($res['file']) ?></strong>
                        <?php foreach ($res['items'] as $it): ?>
                            <div class="row">
                                <span><?= htmlspecialchars($it['name']) ?></span>
                                <span>¥<?= number_format($it['price']) ?></span>
                            </div>
                        <?php endforeach; ?>
                        <div class="row" style="color:#e74c3c; font-weight:bold; border-top:2px solid #ddd; margin-top:10px;">
                            <span>ファイル合計</span>
                            <span>¥<?= number_format($res['total']) ?></span>
                        </div>
                    </div>
                <?php endforeach; ?>
                <div class="total-box">¥<?= number_format($grandTotal) ?></div>
            </div>
        <?php endif; ?>

        <div style="text-align:center; margin-top:30px;">
            <a href="?action=csv" style="margin-right:20px;">📥 CSVダウンロード</a>
            <a href="?action=clear_view">🔄 表示をクリア</a>
        </div>
    </div>
</body>
</html>
