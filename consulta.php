<?php
header('Content-Type: application/json');

$cpf = preg_replace('/\D/', '', $_GET['cpf'] ?? '');

if (empty($cpf)) {
    echo json_encode(['dados' => []]);
    exit;
}

$token = 'c5eebbc9-0469-4324-85f6-0c994b42d18a';
$url = "https://api.amnesiatecnologia.lat/?token={$token}&cpf={$cpf}";

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
$response = curl_exec($ch);

$data = json_decode($response, true);
$d = $data['DADOS'] ?? null;

if (!$d) {
    echo json_encode(['dados' => []]);
    exit;
}

echo json_encode([
    'dados' => [
        [
            'CPF'      => $d['cpf'] ?? $cpf,
            'NOME'     => $d['nome'] ?? '',
            'NASC'     => $d['data_nascimento'] ?? '',
            'NOME_MAE' => $d['nome_mae'] ?? '',
            'SEXO'     => $d['sexo'] ?? '',
        ]
    ]
]);
