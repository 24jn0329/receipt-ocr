<?php
/**
 * 🧾 Smart Receipt AI - Computer Vision 改善版
 * 修正：座標計算を廃止し、自然な読み取り順序と状態管理でペアリング
 */

$endpoint = "https://cv-receipt.cognitiveservices.azure.com/";
$apiKey   = "acFa9r1gRfWfvNsBjsLFsyec437ihmUsWXpA1WKVYD4z5yrPBrrMJQQJ99CBACNns7RXJ3w3AAAFACOGcllL";

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['receipts'])) {
    foreach ($_FILES['receipts']['tmp_name'] as $key => $tmpName) {
        if (empty($tmpName)) continue;
        
        // 改善点1: readingOrder=natural を追加してAzureに並び替えを任せる
        $apiUrl = rtrim($endpoint, '/') . "/computervision/imageanalysis:analyze?api-version=2023-10-01&features=read&readingOrder=natural";
        
        $ch = curl_init($apiUrl);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/octet-stream', 'Ocp-Apim-Subscription-Key: ' . $apiKey]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, file_get_contents($tmpName));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $response = curl_exec($ch);
        curl_close($ch);

        $data = json_decode($response, true);
        $lines = $data['readResult']['blocks'][0]['lines'] ?? [];

        $currentItems = [];
        $pendingItemName = ""; // 商品名を一時キープする変数

        foreach ($lines as $line) {
            $text = trim($line['text']);

            // 除外ワード
            if (preg_match('/消費税|対象|お釣|預り|現計|点数|合計/u', $text)) {
                $pendingItemName = ""; // 合計系が来たらリセット
                continue;
            }

            // 改善点2: 「テキストの中に数字が含まれるか」で判定を分ける
            if (preg_match('/(.*?)[¥￥\s　](\d{1,3}(?:,\d{3})*|\d{2,})$/u', $text, $matches)) {
                // パターンA: 同一行に品名と金額がある場合 (例: "おにぎり 150")
                $currentItems[] = [
                    'name' => preg_replace('/[¥￥\s　＊\*√軽轻\.\-\/]/u', '', $matches[1]),
                    'price' => (int)str_replace(',', '', $matches[2])
                ];
                $pendingItemName = ""; 
            } 
            elseif (preg_match('/^(\d{1,3}(?:,\d{3})*|\d{2,})$/', preg_replace('/[¥￥\s　＊\*]/u', '', $text), $priceMatch)) {
                // パターンB: この行が純粋な「金額」のみで、前に「品名」をキープしていた場合
                if (!empty($pendingItemName)) {
                    $currentItems[] = [
                        'name' => $pendingItemName,
                        'price' => (int)str_replace(',', '', $priceMatch[1])
                    ];
                    $pendingItemName = "";
                }
            } 
            else {
                // パターンC: 金額が含まれない純粋な「品名」と思われる行
                $cleanName = preg_replace('/[¥￥\s　＊\*√軽轻\.\-\/]/u', '', $text);
                if (mb_strlen($cleanName) >= 2) {
                    $pendingItemName = $cleanName;
                }
            }
        }

        // 応答を返す（DB保存処理は省略）
        header('Content-Type: application/json');
        echo json_encode(['file' => $_FILES['receipts']['name'][$key], 'items' => $currentItems]);
        exit;
    }
}
?>
