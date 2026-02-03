<?php
/**
 * 🧾 レシート解析システム - 最終安定版
 */

@set_time_limit(600);
@ini_set('memory_limit', '512M');

// --- 1. 設定 (Azure & SQL) ---
$endpoint = "https://cv-receipt.cognitiveservices.azure.com/"; 
$apiKey   = "acFa9r1gRfWfvNsBjsLFsyec437ihmUsWXpA1WKVYD4z5yrPBrrMJQQJ99CBACNns7RXJ3w3AAAFACOGcllL"; 
$serverName = "tcp:receipt-server.database.windows.net,1433"; 
$connectionOptions = ["Database" => "db_receipt", "Uid" => "jn240329", "PWD" => "15828415312dY", "CharacterSet" => "UTF-8"];

$conn = sqlsrv_connect($serverName, $connectionOptions);
if ($conn === false) die(print_r(sqlsrv_errors(), true));

// --- 2. アクション処理 (CSV/Clear) ---
if (isset($_GET['action'])) {
    if ($_GET['action'] == 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=export.csv');
        echo "\xEF\xBB\xBF"; 
        $output = fopen('php://output', 'w');
        fputcsv($output, ['ID', 'Batch', 'File', 'Item', 'Price', 'IsTotal', 'Date']);
        $stmt = sqlsrv_query($conn, "SELECT * FROM Receipts ORDER BY id DESC");
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            fputcsv($output, [$row['id'], $row['upload_batch_id'], $row['file_name'], $row['item_name'], $row['price'], $row['is_total'], $row['created_at']->format('Y-m-d H:i:s')]);
        }
        fclose($output); exit;
    }
    if ($_GET['action'] == 'clear_view') { header("Location: " . strtok($_SERVER["PHP_SELF"], '?')); exit; }
}

// --- 3. 解析ロジック ---
$results = [];
$totalAllAmount = 0;

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['receipts'])) {
    $batchId = uniqid('BT_');

    foreach ($_FILES['receipts']['tmp_name'] as $key => $tmpName) {
        if (empty($tmpName)) continue;
        $fileName = $_FILES['receipts']['name'][$key];
        $imgData = file_get_contents($tmpName);
        
        $ch = curl_init(rtrim($endpoint, '/') . "/computervision/imageanalysis:analyze?api-version=2023-10-01&features=read");
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/octet-stream', 'Ocp-Apim-Subscription-Key: ' . $apiKey],
            CURLOPT_POSTFIELDS => $imgData,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false
        ]);
        $response = curl_exec($ch);
        curl_close($ch);

        $data = json_decode($response, true);
        $lines = $data['readResult']['blocks'][0]['lines'] ?? [];
        $currentItems = [];
        $logTotal = 0;

        foreach ($lines as $i => $line) {
            $text = trim($line['text']);
            // 判定用のクリーンテキスト
            $pureText = str_replace([' ', '　', '＊', '*', '√', '軽', '轻', '(', ')', '8%', '10%', '税'], '', $text);

            // ① 合計行の数値だけ抽出して記録（商品は追加しない）
            if (preg_match('/合計|合計額|小計/u', $pureText)) {
                if (preg_match('/[¥￥]([\d,]+)/u', $text, $m)) $logTotal = (int)str_replace(',', '', $m[1]);
                continue; // 合計行自体は商品として登録しない
            }

            // ② 明らかな「非商品行」を徹底排除
            if (preg_match('/対象|支払|残高|再発行|クレジット|預り|釣銭|番号|電話|店舗/u', $pureText)) continue;

            // ③ 商品と金額の抽出
            if (preg_match('/[¥￥]([\d,]+)/u', $text, $matches)) {
                $price = (int)str_replace(',', '', $matches[1]);
                // 金額部分を消して名前を抽出
                $name = trim(preg_replace('/[\.．…]+|[¥￥].*$/u', '', $text));
                $name = str_replace(['＊', '*', '轻', '軽', '(', ')', '.', '．', ' '], '', $name);

                // 名前が空、または「¥168」のように数字だけになっている場合は上の行を見る
                if (mb_strlen($name) < 2 || preg_match('/^[¥￥\d,]+$/u', $name)) {
                    for ($j = array_search($line, $lines) - 1; $j >= 0; $j--) {
                        $prev = str_replace([' ', '　'], '', $lines[$j]['text']);
                        if (mb_strlen($prev) >= 2 && !preg_match('/領収|合計|店|電話|¥|￥|番号/u', $prev)) {
                            $name = $prev; break;
                        }
                    }
                }

                // 最終チェック：名前が禁止ワードを含まず、かつ数字だけではない場合のみ登録
                if (!empty($name) && !preg_match('/合計|支払|対象|消費税|Family|新宿/u', $name) && !preg_match('/^[¥￥\d,]+$/u', $name)) {
                    $currentItems[] = ['name' => $name, 'price' => $price];
                }
            }
        }

        // DB保存
        foreach ($currentItems as $it) {
            sqlsrv_query($conn, "INSERT INTO Receipts (upload_batch_id, file_name, item_name, price, is_total) VALUES (?, ?, ?, ?, 0)", [$batchId, $fileName, $it['name'], $it['price']]);
            $totalAllAmount += $it['price'];
        }
        if ($logTotal > 0) {
            sqlsrv_query($conn, "INSERT INTO Receipts (upload_batch_id, file_name, item_name, price, is_total) VALUES (?, ?, ?, ?, 1)", [$batchId, $fileName, '合計(OCR)', $logTotal]);
        }
        $results[] = ['file' => $fileName, 'items' => $currentItems, 'total' => $logTotal];
        sleep(1); // API制限対策
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
        .box { max-width: 600px; margin: auto; background: white; padding: 25px; border-radius: 12px; box-shadow: 0 5px 20px rgba(0,0,0,0.1); }
        .card { border-left: 4px solid #2ecc71; background: #fafafa; padding: 12px; margin-bottom: 10px; }
        .row { display: flex; justify-content: space-between; font-size: 14px; padding: 4px 0; border-bottom: 1px dashed #ddd; }
        .btn { width: 100%; padding: 15px; background: #1890ff; color: white; border: none; border-radius: 8px; font-weight: bold; cursor: pointer; }
    </style>
</head>
<body>
    <div class="box">
        <h2 style="text-align:center;">📜 レシート解析システム</h2>
        <form method="post" enctype="multipart/form-data">
            <input type="file" name="receipts[]" multiple required style="margin-bottom:10px;">
            <button type="submit" class="btn">解析を開始してDBに保存</button>
        </form>

        <?php if (!empty($results)): ?>
            <div style="margin-top:20px;">
                <?php foreach ($results as $res): ?>
                    <div class="card">
                        <small style="color:#999;"><?= htmlspecialchars($res['file']) ?></small>
                        <?php foreach ($res['items'] as $it): ?>
                            <div class="row"><span><?= htmlspecialchars($it['name']) ?></span><span>¥<?= number_format($it['price']) ?></span></div>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
                <h1 style="text-align:center; color:#ff4d4f;">¥<?= number_format($totalAllAmount) ?></h1>
            </div>
        <?php endif; ?>
        <div style="margin-top:20px; text-align:center;">
            <a href="?action=csv">CSV保存</a> | <a href="?action=clear_view">クリア</a>
        </div>
    </div>
</body>
</html>
