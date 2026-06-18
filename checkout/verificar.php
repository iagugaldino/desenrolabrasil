<?php
/**
 * Verificação de status de pagamento PIX — WinnerPay
 * --------------------------------------------------------------
 * Endpoint chamado pelo front-end:  GET /checkout/verificar.php?id={transaction_id}
 * Também recebe POSTs de webhook da WinnerPay (postbackUrl).
 *
 * Fluxo (polling):
 *   1. Recebe o transaction_id que o pagamento.php devolveu.
 *   2. Consulta o status atual na API da WinnerPay.
 *   3. Traduz o status para o que o seu JS entende.
 *   4. Devolve { success: true, status: "..." }.
 *
 * Nada precisa ser alterado no front-end.
 */

declare(strict_types=1);

/* ============================================================
 *  CONFIGURAÇÃO  (edite apenas aqui se precisar)
 * ============================================================ */
const WINNER_QUERY_URL = 'https://api.winnerpayy.com.br/api/dashboard/transactions/';
const WINNER_API_KEY   = '8797590b-7852-42a3-8520-1b7671ce6ec3';

/* ============================================================
 *  CABEÇALHOS
 * ============================================================ */
header('Content-Type: application/json; charset=utf-8');

/* ============================================================
 *  WEBHOOK DA WINNERPAY (POST)
 *  A WinnerPay chama este endpoint via postbackUrl.
 *  Apenas confirmamos recebimento com 200.
 * ============================================================ */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    // Leitura do payload (pode ser usado para log ou lógica futura)
    // $webhook = json_decode(file_get_contents('php://input'), true);
    http_response_code(200);
    echo json_encode(['received' => true]);
    exit;
}

/* ============================================================
 *  ENTRADA (polling via GET)
 * ============================================================ */
$id = trim((string) ($_GET['id'] ?? ''));

if ($id === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Parâmetro "id" ausente.']);
    exit;
}

/* ============================================================
 *  CONSULTA À WINNERPAY
 *  GET /dashboard/transactions/{transactionId}
 * ============================================================ */
$ch = curl_init(WINNER_QUERY_URL . urlencode($id));
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPGET        => true,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'X-Client-Id: '     . WINNER_API_KEY,
        'X-Client-Secret: ' . WINNER_API_KEY,
    ],
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_SSL_VERIFYPEER => true,
]);

$resp      = curl_exec($ch);
$curlError = curl_error($ch);
curl_close($ch);

if ($resp === false) {
    echo json_encode(['success' => false, 'message' => 'Erro de conexão: ' . $curlError]);
    exit;
}

$data = json_decode($resp, true);

if (!is_array($data)) {
    echo json_encode(['success' => false, 'message' => 'Resposta inválida do provedor.']);
    exit;
}

/* ============================================================
 *  EXTRAÇÃO DO STATUS
 * ============================================================ */
$status = $data['transaction']['status']
       ?? $data['status']
       ?? null;

if ($status === null) {
    echo json_encode(['success' => false, 'message' => 'Transação não encontrada.']);
    exit;
}

/* ============================================================
 *  NORMALIZAÇÃO DO STATUS
 *  O seu JS reconhece:
 *    - aprovado:  paid | approved | completed
 *    - cancelado: expired | cancelled
 *  A WinnerPay usa: pending, paid, processing,
 *                   failed, refunded, chargeback, cancelled
 * ============================================================ */
$bruto = strtolower((string) $status);

$aprovados  = ['approved', 'paid', 'completed'];
$cancelados = ['failed', 'refunded', 'chargeback', 'expired', 'cancelled', 'canceled'];

if (in_array($bruto, $aprovados, true)) {
    $statusFinal = 'approved';   // JS trata como pago
} elseif (in_array($bruto, $cancelados, true)) {
    $statusFinal = 'cancelled';  // JS trata como cancelado
} else {
    // pending, processing... → JS mantém o polling
    $statusFinal = $bruto;
}

/* ============================================================
 *  RESPOSTA
 * ============================================================ */
echo json_encode([
    'success' => true,
    'status'  => $statusFinal,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
