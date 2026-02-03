<?php
/**
 * 🧾 レシート解析システム - 重複防止修正版（Azure SQL 1テーブル）
 */

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
    die(print_r(sqlsrv_errors(), true));
}

/* ===== 重複判定関数（★追加） ===== */
function isDuplicateItem(array $items, string $name, int $price): bool {
    foreach ($items as $it) {
        if ($it['name'] === $name && $it['price'] === $price) {
            return true;
        }
    }
    return false;
}

$results = [];
$totalAllAmount = 0;

/* ===== OCR処理 ===== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['receipts'])) {

    $batchId = uniqid('BT_');

    foreach ($_FILES['receipts']['tmp_name'] as $key => $tmpName) {

        if (!$tmpName) continue;
        if ($key > 0) sleep(1);

        $fileName = $_FILES['receipts']['name'][$key];
        $imgData  = file_get_contents($tmpName);

        $apiUrl = rtrim($endpoint, '/') .
            "/computervision/imageanalysis:analyze?api-version=2023-10-01&features=read";

        $ch = curl_init($apiUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/octet-stream',
                'Ocp-Apim-Subscription-Key: ' . $apiKey
            ],
            CURLOPT_POSTFIELDS => $imgData,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_TIMEOUT => 60
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) continue;

        $data  = json_decode($response, true);
        $lines = $data['readResult']['blocks'][0]['lines'] ?? [];

        $currentItems = [];
        $logTotal = 0;
        $stopFlag = false;

        for ($i = 0; $i < count($lines); $i++) {

            $text = trim($lines[$i]['text']);
            $pure = str_replace([' ', '　', '＊', '*', '轻', '軽', '(', ')', '8%', '10%'], '', $text);

            /* 合計 */
            if (preg_match('/合計/u', $pure) && preg_match('/[¥￥]([\d,]+)/u', $text, $m)) {
                $logTotal = (int)str_replace(',', '', $m[1]);
            }

            /* 停止ワード */
            if (preg_match('/内消費税|消費税|対象|支払|残高/u', $pure)) {
                $stopFlag = true;
                continue;
            }
            if ($stopFlag) continue;

            /* 金額検出 */
            if (preg_match('/[¥￥]([\d,]+)/u', $text, $m)) {

                $price = (int)str_replace(',', '', $m[1]);
                $name  = trim(preg_replace('/[¥￥].*/u', '', $text));
                $name  = str_replace(['＊','*',' ','軽','轻','(',')','.','．'], '', $name);

                if (mb_strlen($name) < 2) {
                    for ($j = $i - 1; $j >= 0; $j--) {
                        $prev = trim($lines[$j]['text']);
                        $prev = str_replace(['＊','*',' ','軽','轻'], '', $prev);
                        if (mb_strlen($prev) >= 2 && !preg_match('/合計|%|電話|領収/u', $prev)) {
                            $name = $prev;
                            break;
                        }
                    }
                }

                if ($name && !isDuplicateItem($currentItems, $name, $price)) {
                    $currentItems[] = [
                        'name'  => $name,
                        'price' => $price
                    ];
                }
            }
        }

        /* DB保存 */
        foreach ($currentItems as $it) {
            sqlsrv_query(
                $conn,
                "INSERT INTO Receipts (upload_batch_id, file_name, item_name, price, is_total)
                 VALUES (?, ?, ?, ?, 0)",
                [$batchId, $fileName, $it['name'], $it['price']]
            );
            $totalAllAmount += $it['price'];
        }

        if ($logTotal > 0) {
            sqlsrv_query(
                $conn,
                "INSERT INTO Receipts (upload_batch_id, file_name, item_name, price, is_total)
                 VALUES (?, ?, '合計(OCR)', ?, 1)",
                [$batchId, $fileName, $logTotal]
            );
        }

        $results[] = [
            'file'  => $fileName,
            'items' => $currentItems,
            'total' => $logTotal
        ];
    }
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<title>レシート解析</title>
<style>
body{font-family:sans-serif;background:#f4f7f9;padding:20px}
.box{max-width:600px;margin:auto;background:#fff;padding:25px;border-radius:10px}
.row{display:flex;justify-content:space-between;border-bottom:1px dashed #eee;padding:5px 0}
.total{font-size:28px;color:#e74c3c;text-align:center;margin-top:20px}
</style>
</head>
<body>
<div class="box">
<h2>📜 レシート解析</h2>

<form method="post" enctype="multipart/form-data">
<input type="file" name="receipts[]" multiple required>
<button>解析開始</button>
</form>

<?php if ($results): ?>
<hr>
<?php foreach ($results as $r): ?>
<b><?= htmlspecialchars($r['file']) ?></b>
<?php foreach ($r['items'] as $it): ?>
<div class="row">
<span><?= htmlspecialchars($it['name']) ?></span>
<span>¥<?= number_format($it['price']) ?></span>
</div>
<?php endforeach; ?>
<?php endforeach; ?>
<div class="total">¥<?= number_format($totalAllAmount) ?></div>
<?php endif; ?>

</div>
</body>
</html>

