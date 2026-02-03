<?php
/**
 * 🧾 レシート解析システム - 大容量対応・3枚同時安定版
 */

// --- 1. 設定と環境構成 ---
@set_time_limit(600);
@ini_set('memory_limit', '512M');
@ini_set('upload_max_filesize', '20M');
@ini_set('post_max_size', '20M');

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

// --- 3. アクション処理 ---
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

// --- 4. OCR 解析 & 処理 ---
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
            $cleanText = str_replace([' ', '　', '＊', '*', '√'], '', $text);

            // 合計行の処理
            if (preg_match('/合計|合計額/u', $cleanText)) {
                if (preg_match('/[¥￥]?([\d,]{2,})/', $text, $m)) $receiptTotal = (int)str_replace(',', '', $m[1]);
                break; 
            }

            // 商品・金額抽出（全家1,2,3共通対応）
            if (preg_match('/[¥￥]([\d,]+)/', $text, $matches) || preg_match('/([\d,]+)(?:軽|轻|8%|10%)/u', $text, $matches)) {
                $price = (int)str_replace(',', '', $matches[1]);
                if (preg_match('/釣銭|預り|残高|対象/u', $cleanText)) continue;

                $namePart = trim(preg_replace('/[¥￥].*|[\d,]+(?:軽|轻|8%|10%).*/u', '', $text));
                $finalName = str_replace(['◎', '○', ' ', '　'], '', $namePart);

                if (mb_strlen($finalName) < 1 && !empty($potentialNames)) {
                    $finalName = end($potentialNames);
                }

                if (mb_strlen($finalName) > 1 && !preg_match('/領収|番号|電話|Family/u', $finalName)) {
                    $items[] = ['name' => $finalName, 'price' => $price];
                }
            } else {
                if (mb_strlen($cleanText) > 2 && !preg_match('/領収|証|http|：|レジ/u', $cleanText)) {
                    $potentialNames[] = $cleanText;
                }
            }
        }

        foreach ($items as $it) {
            sqlsrv_query($conn, "INSERT INTO Receipts (upload_batch_id, file_name, item_name, price, is_total) VALUES (?, ?, ?, ?, 0)", [$batchId, $fileName, $it['name'], $it['price']]);
            $grandTotal += $it['price'];
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
        .box { max-width: 600px; margin: auto; background: white; padding: 25px; border-radius: 12px; box-shadow: 0 5px 15px rgba(0,0,0,0.1); }
        .card { border-left: 5px solid #2ecc71; background: #fafafa; padding: 15px; margin-bottom: 20px; border-radius: 8px; }
        .row { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px dashed #ddd; font-size: 14px; }
        .total-box { font-size: 32px; font-weight: bold; color: #ff4d4f; text-align: center; margin-top: 20px; }
        .btn-main { width: 100%; padding: 15px; background: #4a90e2; color: white; border: none; border-radius: 8px; font-weight: bold; cursor: pointer; }
        #status { display:none; text-align:center; color:#4a90e2; font-weight:bold; margin-top:10px; }
    </style>
</head>
<body>
    <div class="box">
        <h2 style="text-align:center;">📜 レシート解析システム</h2>
        <form id="uploadForm" method="post" enctype="multipart/form-data">
            <input type="file" id="fileInput" name="receipts[]" multiple required style="margin-bottom:20px; width:100%;">
            <button type="submit" id="submitBtn" class="btn-main">解析を開始してDBに保存</button>
            <div id="status">画像を圧縮・送信中...</div>
        </form>

        <?php if (!empty($results)): ?>
            <div style="margin-top:30px;">
                <?php foreach ($results as $res): ?>
                    <div class="card">
                        <small>📄 <?= htmlspecialchars($res['file']) ?></small>
                        <?php foreach ($res['items'] as $it): ?>
                            <div class="row"><span><?= htmlspecialchars($it['name']) ?></span><span>¥<?= number_format($it['price']) ?></span></div>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
                <div class="total-box">合計 ¥<?= number_format($grandTotal) ?></div>
            </div>
        <?php endif; ?>

        <div style="text-align:center; margin-top:30px;">
            <a href="?action=csv">📥 CSV</a> | <a href="?action=clear_view">🔄 クリア</a>
        </div>
    </div>

    <script>
    document.getElementById('uploadForm').onsubmit = async function(e) {
        e.preventDefault();
        const btn = document.getElementById('submitBtn');
        const status = document.getElementById('status');
        const files = document.getElementById('fileInput').files;
        if (!files.length) return;

        btn.disabled = true;
        status.style.display = "block";

        const formData = new FormData();
        for (let i = 0; i < files.length; i++) {
            // JSによる自動圧縮（413エラー対策）
            const compressed = await compressImg(files[i]);
            formData.append('receipts[]', compressed, files[i].name);
        }

        const response = await fetch('', { method: 'POST', body: formData });
        const html = await response.text();
        document.body.innerHTML = new DOMParser().parseFromString(html, 'text/html').body.innerHTML;
    };

    function compressImg(file) {
        return new Promise(resolve => {
            const reader = new FileReader();
            reader.readAsDataURL(file);
            reader.onload = (e) => {
                const img = new Image();
                img.src = e.target.result;
                img.onload = () => {
                    const canvas = document.createElement('canvas');
                    let w = img.width, h = img.height;
                    if (w > 1200) { h = h * (1200/w); w = 1200; }
                    canvas.width = w; canvas.height = h;
                    canvas.getContext('2d').drawImage(img, 0, 0, w, h);
                    canvas.toBlob(blob => resolve(new File([blob], file.name, {type:'image/jpeg'})), 'image/jpeg', 0.7);
                };
            };
        });
    }
    </script>
</body>
</html>
