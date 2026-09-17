<?php
/**
 * transacties.php — geeft de boekingen van één rekening in één maand terug als JSON.
 * Wordt aangeroepen vanuit pnl.php wanneer je op een cel klikt.
 */

declare(strict_types=1);
require __DIR__ . '/lib.php';
require __DIR__ . '/yuki.php';

start_sessie();
header('Content-Type: application/json; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');

// Geen inlogscherm teruggeven aan een fetch-verzoek; gewoon een nette weigering.
if (empty($_SESSION['ingelogd'])) {
    http_response_code(401);
    echo json_encode(['fout' => 'Niet aangemeld. Herlaad de pagina.']);
    exit;
}

$code  = preg_replace('/\D/', '', (string) ($_GET['rekening'] ?? ''));
$jaar  = (int) ($_GET['jaar'] ?? 0);
$maand = (int) ($_GET['maand'] ?? 0);

if ($code === '' || $jaar < 2000 || $jaar > 2100 || $maand < 1 || $maand > 12) {
    http_response_code(400);
    echo json_encode(['fout' => 'Ongeldige aanvraag.']);
    exit;
}

try {
    $y = new Yuki($CFG);
    echo json_encode($y->transacties($code, $jaar, $maand, isset($_GET['ververs'])));
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['fout' => $e->getMessage()]);
}
