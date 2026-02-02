<?php
/**
 * 🧾 小票解析系统 - 修复版 (兼容 Azure Free Tier F0)
 */

// --- 1. 配置 ---
@set_time_limit(600);
@ini_set('memory_limit', '512M');

// ★★★ 请在这里填入新的 Key，不要用刚才那个泄露的 ★★★
$endpoint = "https://cv-receipt.cognitiveservices.azure.com/"; 
$apiKey   = "acFa9r1gRfWfvNsBjsLFsyec437ihmUsWXpA1WKVYD4z5yrPBrrMJQQJ99CBACNns7RXJ3w3AAAFACOGcllL"; // 请务必更换新的 Key

$logFile = 'ocr.log';

// --- 2. Azure SQL 连接 ---
$serverName = "tcp:receipt-server.database.windows.net,1433"; 
$connectionOptions = array(
    "Database" => "db_receipt",
    "Uid" => "jn240329",
    "PWD" => "15828415312dY", // 请务必更换新的数据库密码
    "CharacterSet" => "UTF-8"
);
// 尝试连接，如果失败友好提示
$conn = sqlsrv_connect($serverName, $connectionOptions);
if ($conn === false) {
    // 为了防止页面报错难看，只输出简单错误，不输出详细连接信息
    die("Database Connection Error. 请检查数据库防火墙和密码设置。");
}

// --- 3. 动作处理 (CSV/下载/清空) ---
if (isset($_GET['action'])) {
    $action = $_GET['action'];
    
    if ($action == 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=receipt_export_'.date('Ymd').'.csv');
        echo "\xEF\xBB\xBF"; 
        $output = fopen('php://output', 'w');
        fputcsv($output, ['文件名', '项目', '金额', '日期']);
        
        $sql = "SELECT r.file_name, r.created_at, i.item_name, i.price FROM receipts r JOIN receipt_items i ON r.id = i.receipt_id";
        $stmt = sqlsrv_query($conn, $sql);
        if ($stmt) {
            while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                $dateStr = $row['created_at'] ? $row['created_at']->format('Y-m-d H:i:s') : '';
                fputcsv($output, [$row['file_name'], $row['item_name'], $row['price'], $dateStr]);
            }
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
        header("Location: " . strtok($_SERVER["PHP_SELF"], '?')); 
        exit;
    }
}

// --- 4. OCR 核心解析逻辑 (使用 Read API v3.2 - 异步模式) ---
$processedIds = []; 

// 辅助函数：调用 Azure Read API (异步)
function callAzureReadAPI($endpoint, $apiKey, $imgData) {
    // 1. 发送请求 (POST)
    $url = rtrim($endpoint, '/') . "/vision/v3.2/read/analyze";
    $headers = [
        "Content-Type: application/octet-stream",
        "Ocp-Apim-Subscription-Key: $apiKey"
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $imgData);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true); // 获取 Header
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $respHeader = substr($response, 0, $headerSize);
    curl_close($ch);

    if ($httpCode != 202) {
        return null; // 请求失败
    }

    // 2. 获取结果 URL
    if (!preg_match('/Operation-Location: (.*)/i', $respHeader, $matches)) {
        return null;
    }
    $resultUrl = trim($matches[1]);

    // 3. 轮询结果 (GET)
    $maxRetries = 10;
    for ($i = 0; $i < $maxRetries; $i++) {
        sleep(1); // 等待处理
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $resultUrl);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ["Ocp-Apim-Subscription-Key: $apiKey"]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $jsonResponse = curl_exec($ch);
        curl_close($ch);

        $data = json_decode($jsonResponse, true);
        if (($data['status'] ?? '') === 'succeeded') {
            return $data;
        }
        if (($data['status'] ?? '') === 'failed') {
            return null;
        }
    }
    return null; // 超时
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['receipts'])) {
    foreach ($_FILES['receipts']['tmp_name'] as $key => $tmpName) {
        if (empty($tmpName)) continue;
        
        $fileName = $_FILES['receipts']['name'][$key];
        $imgData = file_get_contents($tmpName);
        
        // 调用 v3.2 API
        $data = callAzureReadAPI($endpoint, $apiKey, $imgData);

        if (!$data) continue;

        // v3.2 的结构是 analyzeResult -> readResults -> lines
        $lines = $data['analyzeResult']['readResults'][0]['lines'] ?? [];
        $currentItems = [];
        $stopFlag = false;
        
        $logStore = "Unknown";
        $logTotal = 0;

        // --- 解析逻辑 (保持不变) ---
        for ($i = 0; $i < count($lines); $i++) {
            $text = trim($lines[$i]['text']);
            
            if ($i < 5 && preg_match('/FamilyMart|セブン|ローソン|LAWSON/i', $text, $storeMatch)) {
                $logStore = $storeMatch[0];
            }

            $pureText = str_replace([' ', '　', '＊', '*', '√', '軽', '轻', '(', ')', '8%', '10%'], '', $text);

            if (preg_match('/合計|合计/u', $pureText) && preg_match('/[¥￥]([\d,]+)/u', $text, $totalMatch)) {
                $logTotal = (float)str_replace(',', '', $totalMatch[1]);
            }

            if (preg_match('/内消費税|消費税|対象|支払|残高|再発行/u', $pureText)) {
                if (!empty($currentItems)) $stopFlag = true; 
                continue; 
            }
            if ($stopFlag) continue;

            if (preg_match('/[¥￥]([\d,]+)/u', $text, $matches)) {
                $price = (int)str_replace(',', '', $matches[1]);
                $nameInLine = trim(preg_replace('/[\.．…]+|[¥￥].*$/u', '', $text));
                $cleanNameInLine = str_replace(['＊', '*', '轻', '軽', '(', ')', '.', '．', ' '], '', $nameInLine);

                if (mb_strlen($cleanNameInLine) < 2 || preg_match('/^[¥￥\d,\s]+$/u', $cleanNameInLine)) {
                    $foundName = "";
                    for ($j = $i - 1; $j >= 0; $j--) {
                        $prev = trim($lines[$j]['text']);
                        $cleanPrev = str_replace(['＊', '*', ' ', '√', '軽', '轻'], '', $prev);
                        if (mb_strlen($cleanPrev) >= 2 && !preg_match('/領|収|証|合|计|計|%|店|电话|電話|¥|￥/u', $cleanPrev)) {
                            $foundName = $cleanPrev; break;
                        }
                    }
                    $finalName = $foundName;
                } else {
                    $finalName = $cleanNameInLine;
                }

                if (!empty($finalName) && !preg_match('/Family|新宿|電話|登録|領収|対象|消費税|合計|内訳/u', $finalName)) {
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

        // 写入 Log
        $logContent = "\n===== OCR RESULT =====\n";
        $logContent .= "TIME: " . date('Y-m-d\TH:i:s.v') . "\n";
        $logContent .= "STORE: $logStore\n";
        $logContent .= "TOTAL: " . number_format($logTotal, 1, '.', '') . "\n";
        foreach ($currentItems as $it) {
            $logContent .= "{$it['name']}," . number_format($it['price'], 1, '.', '') . "\n";
        }
        file_put_contents($logFile, $logContent, FILE_APPEND);

        // 写入 DB
        if (!empty($currentItems)) {
            $sqlR = "INSERT INTO Receipts (file_name) OUTPUT INSERTED.id VALUES (?)";
            $stmtR = sqlsrv_query($conn, $sqlR, array($fileName));
            if ($stmtR && sqlsrv_fetch($stmtR)) {
                $newId = sqlsrv_get_field($stmtR, 0);
                $processedIds[] = $newId; 
                foreach ($currentItems as $it) {
                    $sqlI = "INSERT INTO receipt_items (receipt_id, item_name, price) VALUES (?, ?, ?)";
                    sqlsrv_query($conn, $sqlI, array($newId, $it['name'], $it['price']));
                }
            }
        }
    }
}

// --- 5. 显示逻辑 (从 DB 读取刚插入的数据) ---
$results = [];
$totalAllAmount = 0;

if ($_SERVER['REQUEST_METHOD'] == 'POST' && !empty($processedIds)) {
    $idList = implode(',', $processedIds);
    $sqlMain = "SELECT id, file_name FROM Receipts WHERE id IN ($idList)";
    $resMain = sqlsrv_query($conn, $sqlMain);
    if ($resMain) {
        while ($row = sqlsrv_fetch_array($resMain, SQLSRV_FETCH_ASSOC)) {
            $items = [];
            $sqlSub = "SELECT item_name as name, price FROM receipt_items WHERE receipt_id = ? ORDER BY id ASC";
            $resSub = sqlsrv_query($conn, $sqlSub, array($row['id']));
            if ($resSub) {
                while ($it = sqlsrv_fetch_array($resSub, SQLSRV_FETCH_ASSOC)) {
                    $items[] = $it;
                    $totalAllAmount += $it['price'];
                }
            }
            $results[] = ['file' => $row['file_name'], 'items' => $items];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Azure SQL 小票解析</title>
    <style>
        body { font-family: 'PingFang SC', 'Microsoft YaHei', sans-serif; background: #f4f7f9; padding: 20px; color: #333; }
        .box { max-width: 600px; margin: auto; background: white; padding: 25px; border-radius: 12px; box-shadow: 0 8px 30px rgba(0,0,0,0.05); }
        .card { border-left: 4px solid #2ecc71; background: #fafafa; padding: 15px; margin-bottom: 15px; border-radius: 6px; }
        .row { display: flex; justify-content: space-between; padding: 6px 0; border-bottom: 1px dashed #eee; font-size: 14px; }
        .grand-total { margin-top: 25px; padding: 20px; background: #fff5f5; border: 1px solid #ffccc7; border-radius: 10px; text-align: center; }
        .amount-big { font-size: 32px; font-weight: bold; color: #ff4d4f; }
        .btn-main { width: 100%; padding: 15px; background: #1890ff; color: white; border: none; border-radius: 8px; font-size: 16px; font-weight: bold; cursor: pointer; }
        .btn-main:disabled { background: #ccc; }
        .nav-bar { margin-top: 25px; display: flex; justify-content: space-around; border-top: 1px solid #eee; padding-top: 15px; flex-wrap: wrap; gap: 10px; }
        .nav-link { font-size: 12px; color: #666; text-decoration: none; padding: 6px 10px; border: 1px solid #ddd; border-radius: 4px; background: #fff; }
        .nav-link:hover { background: #f9f9f9; }
    </style>
</head>
<body>
    <div class
