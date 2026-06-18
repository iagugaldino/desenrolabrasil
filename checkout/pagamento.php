<?php
/**
 * Ponte de pagamento PIX — WinnerPay
 * --------------------------------------------------------------
 * Endpoint chamado pelo front-end:  POST /checkout/pagamento.php
 *
 * Fluxo:
 *   1. Recebe os dados do cliente que o JS envia.
 *   2. Traduz para o formato da API da WinnerPay.
 *   3. Cria a cobrança PIX em /financial/receber-pix.
 *   4. Devolve a resposta JÁ no formato que o seu JS espera.
 *
 * Nada precisa ser alterado no front-end.
 */

declare(strict_types=1);

/* ============================================================
 *  CONFIGURAÇÃO  (edite apenas aqui se precisar)
 * ============================================================ */
const WINNER_API_URL    = 'https://api.winnerpayy.com.br/api/financial/receber-pix';
const WINNER_API_KEY    = '8797590b-7852-42a3-8520-1b7671ce6ec3';
const PRODUCT_NAME      = 'Produto195'; // nome que aparece na transação

/* ============================================================
 *  CABEÇALHOS
 * ============================================================ */
set_time_limit(60);
header('Content-Type: application/json; charset=utf-8');

// Aceita apenas POST
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Método não permitido.']);
    exit;
}

/* ============================================================
 *  LEITURA DO CORPO DA REQUISIÇÃO
 * ============================================================ */
$raw   = file_get_contents('php://input');
$input = json_decode($raw, true);

if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'JSON inválido.']);
    exit;
}

/* Helpers */
$str = static function ($v): string {
    return is_scalar($v) ? trim((string) $v) : '';
};
$digits = static function ($v): string {
    return preg_replace('/\D+/', '', is_scalar($v) ? (string) $v : '');
};

/* ============================================================
 *  DADOS RECEBIDOS DO FRONT-END
 * ============================================================ */
$valor    = (int) ($input['valor'] ?? 0);     // já vem em centavos
$nome     = $str($input['nome']     ?? '');
$email    = $str($input['email']    ?? '');
$telefone = $digits($input['telefone'] ?? '');
$cpf      = $digits($input['cpf']   ?? '');

if ($valor <= 0 || $nome === '' || $cpf === '') {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Dados obrigatórios ausentes (valor, nome ou cpf).',
    ]);
    exit;
}

// Fallbacks (mesma lógica de defesa que o JS já usa)
if ($email === '')    { $email    = 'cliente@email.com'; }
if ($telefone === '') { $telefone = '11999999999'; }

/* ============================================================
 *  MONTAGEM DO PAYLOAD PARA A WINNERPAY
 *  A WinnerPay recebe amount em reais (float), não em centavos
 * ============================================================ */
$reference = 'REF-' . date('YmdHis') . '-' . bin2hex(random_bytes(4));

// Tracking (UTMs) — só envia o que veio preenchido
$tracking = array_filter([
    'utm_source'   => $str($input['utm_source']   ?? ''),
    'utm_medium'   => $str($input['utm_medium']   ?? ''),
    'utm_campaign' => $str($input['utm_campaign'] ?? ''),
    'utm_content'  => $str($input['utm_content']  ?? ''),
    'utm_term'     => $str($input['utm_term']     ?? ''),
    'src'          => $str($input['src']          ?? ''),
    'sck'          => $str($input['sck']          ?? ''),
], static fn ($v) => $v !== '');

$payload = [
    'amount'      => round($valor / 100, 2),  // converte centavos → reais
    'description' => PRODUCT_NAME,
    'postbackUrl' => (isset($_SERVER['HTTPS']) ? 'https' : 'http')
                     . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')
                     . '/checkout/verificar.php',
    'customer'    => [
        'name'     => $nome,
        'email'    => $email,
        'document' => $cpf,
    ],
    'metadata'    => array_merge(
        [
            'order_id'     => $reference,
            'product'      => ['name' => PRODUCT_NAME],
        ],
        !empty($tracking) ? ['tracking' => $tracking] : []
    ),
];

/* ============================================================
 *  CHAMADA À API DA WINNERPAY
 * ============================================================ */
$ch = curl_init(WINNER_API_URL);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'X-Client-Id: '     . WINNER_API_KEY,
        'X-Client-Secret: ' . WINNER_API_KEY,
    ],
    CURLOPT_TIMEOUT        => 25,
    CURLOPT_SSL_VERIFYPEER => true,
]);

$response  = curl_exec($ch);
$httpCode  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($response === false) {
    http_response_code(502);
    echo json_encode([
        'success' => false,
        'message' => 'Falha na comunicação com o provedor: ' . $curlError,
        'error'   => 'Falha na comunicação com o provedor.',
    ]);
    exit;
}

$data = json_decode($response, true);

if (!is_array($data)) {
    http_response_code(502);
    echo json_encode([
        'success' => false,
        'message' => 'Resposta inválida do provedor.',
        'error'   => 'Resposta inválida do provedor.',
    ]);
    exit;
}

/* ============================================================
 *  TRATAMENTO DE ERRO DA WINNERPAY
 * ============================================================ */
$sucesso = !empty($data['success'])
    && !empty($data['pix_copia_e_cola']);

if (!$sucesso) {
    $msg = $data['message'] ?? ($data['error'] ?? 'Não foi possível gerar o PIX.');
    http_response_code($httpCode >= 400 ? $httpCode : 422);
    echo json_encode([
        'success' => false,
        'message' => $msg,
        'error'   => $msg,
    ]);
    exit;
}

/* ============================================================
 *  RESPOSTA NO FORMATO QUE O FRONT-END ESPERA
 * ============================================================ */
$txId     = (string) ($data['transaction']['transaction_id'] ?? ($data['transaction']['id'] ?? ''));
$pixCode  = $data['pix_copia_e_cola'] ?? ($data['qr_code_data'] ?? '');
$qrBase64 = $data['qr_code_base64']   ?? '';   // WinnerPay pode não retornar base64

echo json_encode([
    'success'        => true,
    'token'          => $txId,
    'transaction_id' => $txId,
    'reference'      => $reference,
    'pixCopiaECola'  => $pixCode,
    'pixCode'        => $pixCode,
    'pix_code'       => $pixCode,
    'qrCodeUrl'      => $qrBase64,
    'qr_code_image'  => $qrBase64,
    'amount'         => $data['transaction']['amount'] ?? $valor,
    'expires_at'     => $data['expires_at'] ?? null,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
