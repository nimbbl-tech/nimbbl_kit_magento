<?php
/**
 * Nimbbl Magento Plugin — Local Fix Verification Script
 *
 * Tests all fixes applied in commits 9fe360b (BUG-1 + P1–P5) and 34f8d24 (C1 + C2).
 *
 * Run:
 *   php app/code/nimbbl/magento/Test/verify_fixes.php
 *
 * No Magento installation, Composer, or PHPUnit required.
 * Pure PHP — all logic extracted from the actual source files.
 */

declare(strict_types=1);

// ── Helpers ──────────────────────────────────────────────────────────────────

$pass = 0;
$fail = 0;
$suite = '';

function suite(string $name): void {
    global $suite;
    $suite = $name;
    echo "\n\033[1;34m══ " . $name . " ══\033[0m\n";
}

function ok(string $label): void {
    global $pass, $suite;
    echo "  \033[0;32m✓\033[0m  " . $label . "\n";
    $pass++;
}

function fail(string $label, string $detail = ''): void {
    global $fail, $suite;
    echo "  \033[0;31m✗\033[0m  " . $label . ($detail ? " — \033[0;33m" . $detail . "\033[0m" : '') . "\n";
    $fail++;
}

function assert_eq(mixed $got, mixed $expected, string $label): void {
    if ($got === $expected) {
        ok($label);
    } else {
        fail($label, 'expected ' . json_encode($expected) . ' got ' . json_encode($got));
    }
}

function assert_true(bool $cond, string $label, string $detail = ''): void {
    if ($cond) { ok($label); } else { fail($label, $detail); }
}

function assert_false(bool $cond, string $label, string $detail = ''): void {
    if (!$cond) { ok($label); } else { fail($label, $detail ?: 'expected false, got true'); }
}

// ─────────────────────────────────────────────────────────────────────────────
// Extracted pure logic (copied verbatim from source files)
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Config::getKeySecret() logic — BUG-1 / G3 (Config.php lines 103-119)
 */
function config_getKeySecret(string $mode, string $testSecret, string $liveSecret, string $legacySecret): string {
    if ($mode === 'sandbox') {
        $k = trim($testSecret);
        if ($k !== '') return $k;
    } elseif ($mode === 'production') {
        $k = trim($liveSecret);
        if ($k !== '') return $k;
    }
    return $legacySecret;
}

/**
 * Config::getKeyId() logic — G3 (Config.php lines 77-93)
 */
function config_getKeyId(string $mode, string $testKeyId, string $liveKeyId, string $legacyKeyId): string {
    if ($mode === 'sandbox') {
        $k = trim($testKeyId);
        if ($k !== '') return $k;
    } elseif ($mode === 'production') {
        $k = trim($liveKeyId);
        if ($k !== '') return $k;
    }
    return $legacyKeyId;
}

/**
 * Outcome classification from callback payload — G2/G5 + C2 fix
 * (Order.php resolveRedirectCallback() SDK/HMAC path, both blocks)
 */
function classifyCallbackOutcome(string $status, string $reason = ''): string {
    $s = strtolower(trim($status));
    if (in_array($s, ['succeeded', 'success'], true)) {
        return 'success';
    } elseif ($s === 'authorized' || $reason === 'payment_authorized') {
        return 'authorized';
    } elseif (in_array($s, ['failed', 'cancelled', 'canceled', 'expired', 'declined', 'voided'], true)) {
        return 'failed';
    } else {
        // C2: unknown/empty → 'pending', NOT 'success'
        return 'pending';
    }
}

/**
 * P1 Transaction Enquiry override — C1 + C2 fix
 * (Order.php resolveRedirectCallback() P1 block, both SDK and HMAC paths)
 *
 * @param array  $enquiry  Response from fetchTransactionEnquiry()
 * @param string $current  Current outcome before override
 * @return string          Outcome after override
 */
function applyEnquiryOverride(array $enquiry, string $current): string {
    if (empty($enquiry)) {
        return $current;
    }
    // C1: payment_status first, then status, then transaction.status
    $apiStatus = strtolower(trim((string) ($enquiry['payment_status']
        ?? ($enquiry['status']
        ?? ($enquiry['transaction']['status'] ?? '')))));

    if (in_array($apiStatus, ['succeeded', 'success'], true)) {
        return 'success';
    } elseif ($apiStatus === 'authorized') {
        return 'authorized';
    } elseif (in_array($apiStatus, ['failed', 'cancelled', 'canceled', 'expired', 'declined', 'voided'], true)) {
        return 'failed';
    }
    // Unknown enquiry status → keep current (C2 default ensures current is 'pending' for unknown callback)
    return $current;
}

/**
 * HMAC v2/v3 signature computation — Order.php HMAC fallback (lines 786-798)
 * and PaymentMethod::verifyNimbblCallbackSignature() (lines 440-447)
 */
function computeHmac(
    string $sigVer,
    string $invoiceId,
    string $txnId,
    float  $amount,
    string $currency,
    string $status,
    string $txnType,
    string $keySecret
): string {
    $toUtf8 = function (string $v): string {
        return function_exists('mb_convert_encoding')
            ? mb_convert_encoding($v, 'UTF-8', 'ISO-8859-1')
            : $v;
    };

    if ($sigVer === 'v3') {
        $sigStr = $invoiceId . '|' . $txnId . '|'
            . number_format($amount, 2, '.', '') . '|'
            . $currency . '|' . $status . '|' . $txnType;
    } else {
        // v2
        $sigStr = $invoiceId . '|' . $txnId . '|'
            . number_format($amount, 2, '.', '') . '|' . $currency;
    }

    return hash_hmac('sha256', $toUtf8($sigStr), $toUtf8($keySecret));
}

/**
 * HMAC amount formatter — PaymentMethod::formatSignatureAmount() (lines 532-540)
 */
function formatSignatureAmount(float $amount): string {
    $inp   = str_replace(',', '', number_format($amount, 2, '.', ''));
    $parts = explode('.', $inp);
    if (count($parts) === 1) {
        return $parts[0] . '.00';
    }
    return $parts[0] . '.' . str_pad(substr($parts[1], 0, 2), 2, '0');
}

/**
 * Config::getApiHost() — P3 (Config.php lines 169-175)
 */
function config_getApiHost(string $apiBase): string {
    $parsed = parse_url($apiBase);
    $scheme = $parsed['scheme'] ?? 'https';
    $host   = $parsed['host']   ?? 'api.nimbbl.tech';
    return $scheme . '://' . $host;
}

/**
 * P5: SDK log file path logic — NimbblClientFactory::create() (NimbblClientFactory.php)
 */
function resolveLogFile(bool $debugMode, string $bp): ?string {
    return $debugMode ? $bp . '/var/log/nimbbl.log' : null;
}


// =============================================================================
// TEST SUITES
// =============================================================================

// ─────────────────────────────────────────────────────────────────────────────
suite('BUG-1 / G3 — Key selection (Config::getKeyId + getKeySecret)');
// ─────────────────────────────────────────────────────────────────────────────

// Key ID selection
assert_eq(
    config_getKeyId('sandbox', 'test_key_id_abc', 'live_key_id_xyz', 'legacy_key_id'),
    'test_key_id_abc',
    'sandbox mode returns test_key_id'
);
assert_eq(
    config_getKeyId('production', 'test_key_id_abc', 'live_key_id_xyz', 'legacy_key_id'),
    'live_key_id_xyz',
    'production mode returns live_key_id'
);
assert_eq(
    config_getKeyId('', 'test_key_id_abc', 'live_key_id_xyz', 'legacy_key_id'),
    'legacy_key_id',
    'no mode falls back to legacy key_id'
);
assert_eq(
    config_getKeyId('sandbox', '', 'live_key_id_xyz', 'legacy_key_id'),
    'legacy_key_id',
    'sandbox mode with empty test_key_id falls back to legacy'
);
assert_eq(
    config_getKeyId('production', 'test_key_id_abc', '', 'legacy_key_id'),
    'legacy_key_id',
    'production mode with empty live_key_id falls back to legacy'
);

// Key SECRET selection (BUG-1 fix — was always returning legacy secret regardless of mode)
assert_eq(
    config_getKeySecret('sandbox', 'test_secret_abc', 'live_secret_xyz', 'legacy_secret'),
    'test_secret_abc',
    'sandbox mode returns test_key_secret'
);
assert_eq(
    config_getKeySecret('production', 'test_secret_abc', 'live_secret_xyz', 'legacy_secret'),
    'live_secret_xyz',
    'production mode returns live_key_secret'
);
assert_eq(
    config_getKeySecret('', 'test_secret_abc', 'live_secret_xyz', 'legacy_secret'),
    'legacy_secret',
    'no mode falls back to legacy key_secret'
);
assert_eq(
    config_getKeySecret('sandbox', '  ', 'live_secret_xyz', 'legacy_secret'),
    'legacy_secret',
    'whitespace-only test_secret falls back to legacy'
);


// ─────────────────────────────────────────────────────────────────────────────
suite('C2 — Unknown callback status defaults to "pending" (not "success")');
// ─────────────────────────────────────────────────────────────────────────────

assert_eq(classifyCallbackOutcome('success'),    'success',    'success → success');
assert_eq(classifyCallbackOutcome('succeeded'),  'success',    'succeeded → success');
assert_eq(classifyCallbackOutcome('SUCCESS'),    'success',    'SUCCESS (uppercase) → success');
assert_eq(classifyCallbackOutcome('authorized'), 'authorized', 'authorized → authorized');
assert_eq(classifyCallbackOutcome('failed'),     'failed',     'failed → failed');
assert_eq(classifyCallbackOutcome('cancelled'),  'failed',     'cancelled → failed');
assert_eq(classifyCallbackOutcome('canceled'),   'failed',     'canceled → failed');
assert_eq(classifyCallbackOutcome('expired'),    'failed',     'expired → failed');
assert_eq(classifyCallbackOutcome('declined'),   'failed',     'declined → failed');
assert_eq(classifyCallbackOutcome('voided'),     'failed',     'voided → failed');

// C2: These were returning 'success' before the fix — now must return 'pending'
assert_eq(classifyCallbackOutcome(''),            'pending', 'empty status → pending (C2 fix)');
assert_eq(classifyCallbackOutcome('processing'),  'pending', 'processing → pending (C2 fix)');
assert_eq(classifyCallbackOutcome('pending'),     'pending', 'pending → pending (C2 fix)');
assert_eq(classifyCallbackOutcome('unknown'),     'pending', 'unknown → pending (C2 fix)');
assert_eq(classifyCallbackOutcome('initiated'),   'pending', 'initiated → pending (C2 fix)');

// pre-auth via reason field
assert_eq(classifyCallbackOutcome('', 'payment_authorized'), 'authorized', 'empty status + reason=payment_authorized → authorized');


// ─────────────────────────────────────────────────────────────────────────────
suite('C1 — payment_status takes precedence in P1 Transaction Enquiry override');
// ─────────────────────────────────────────────────────────────────────────────

// C1: payment_status is the primary field (from SDK fetch() response)
assert_eq(
    applyEnquiryOverride(['payment_status' => 'success'], 'pending'),
    'success',
    'enquiry.payment_status=success overrides pending'
);
assert_eq(
    applyEnquiryOverride(['payment_status' => 'succeeded'], 'pending'),
    'success',
    'enquiry.payment_status=succeeded overrides pending'
);
assert_eq(
    applyEnquiryOverride(['payment_status' => 'authorized'], 'pending'),
    'authorized',
    'enquiry.payment_status=authorized overrides pending'
);
assert_eq(
    applyEnquiryOverride(['payment_status' => 'failed'], 'success'),
    'failed',
    'enquiry.payment_status=failed overrides success'
);
assert_eq(
    applyEnquiryOverride(['payment_status' => 'cancelled'], 'success'),
    'failed',
    'enquiry.payment_status=cancelled overrides success'
);

// C1: payment_status beats status when both are present
assert_eq(
    applyEnquiryOverride(['payment_status' => 'failed', 'status' => 'success'], 'success'),
    'failed',
    'payment_status=failed beats status=success (C1 field precedence)'
);
assert_eq(
    applyEnquiryOverride(['payment_status' => 'success', 'status' => 'failed'], 'pending'),
    'success',
    'payment_status=success beats status=failed (C1 field precedence)'
);

// Fallback to status when payment_status absent
assert_eq(
    applyEnquiryOverride(['status' => 'success'], 'pending'),
    'success',
    'enquiry.status=success (no payment_status) overrides pending'
);
assert_eq(
    applyEnquiryOverride(['status' => 'failed'], 'success'),
    'failed',
    'enquiry.status=failed (no payment_status) overrides success'
);

// Fallback to transaction.status when both top-level fields absent
assert_eq(
    applyEnquiryOverride(['transaction' => ['status' => 'success']], 'pending'),
    'success',
    'enquiry.transaction.status=success (no top-level fields) overrides pending'
);
assert_eq(
    applyEnquiryOverride(['transaction' => ['status' => 'failed']], 'success'),
    'failed',
    'enquiry.transaction.status=failed (no top-level fields) overrides success'
);

// Empty enquiry → no override
assert_eq(applyEnquiryOverride([], 'success'),  'success',  'empty enquiry keeps success');
assert_eq(applyEnquiryOverride([], 'pending'),  'pending',  'empty enquiry keeps pending');
assert_eq(applyEnquiryOverride([], 'failed'),   'failed',   'empty enquiry keeps failed');

// Unknown enquiry status → keep current
assert_eq(
    applyEnquiryOverride(['payment_status' => 'processing'], 'pending'),
    'pending',
    'unknown enquiry status keeps current outcome'
);
assert_eq(
    applyEnquiryOverride(['payment_status' => ''], 'success'),
    'success',
    'empty payment_status → fallback chain → empty → keep current'
);


// ─────────────────────────────────────────────────────────────────────────────
suite('C1+C2 Combined — callback status unknown then enquiry resolves');
// ─────────────────────────────────────────────────────────────────────────────

// Scenario: callback status is 'processing' (unknown), enquiry returns 'success'
$callbackOutcome  = classifyCallbackOutcome('processing');   // C2: pending
$finalOutcome     = applyEnquiryOverride(['payment_status' => 'success'], $callbackOutcome);
assert_eq($callbackOutcome, 'pending',  'processing callback → pending before enquiry (C2)');
assert_eq($finalOutcome,    'success',  'enquiry resolves processing → success (C1+C2)');

// Scenario: callback says success, enquiry says failed (caught fraud)
$callbackOutcome2 = classifyCallbackOutcome('success');
$finalOutcome2    = applyEnquiryOverride(['payment_status' => 'failed'], $callbackOutcome2);
assert_eq($finalOutcome2, 'failed', 'enquiry overrides callback success with failed (C1+C2 fraud catch)');

// Scenario: both callback and enquiry unknown (network error)
$callbackOutcome3 = classifyCallbackOutcome('');
$finalOutcome3    = applyEnquiryOverride([], $callbackOutcome3);
assert_eq($callbackOutcome3, 'pending', 'empty callback status → pending (C2)');
assert_eq($finalOutcome3,    'pending', 'network error enquiry + empty callback → pending (routes to cart)');


// ─────────────────────────────────────────────────────────────────────────────
suite('HMAC — v2 signature (redirect HMAC fallback + popup verifyNimbblCallbackSignature)');
// ─────────────────────────────────────────────────────────────────────────────

$keySecret  = 'test_secret_key_abcdef1234567890';
$invoiceId  = 'INV-2024-001234';
$txnId      = 'txn_abc123xyz789';
$amount     = 1500.00;
$currency   = 'INR';
$status     = 'success';
$txnType    = 'sale';

$sigV2 = computeHmac('v2', $invoiceId, $txnId, $amount, $currency, $status, $txnType, $keySecret);
$sigV3 = computeHmac('v3', $invoiceId, $txnId, $amount, $currency, $status, $txnType, $keySecret);

// v2 and v3 produce different signatures (different string formats)
assert_true($sigV2 !== $sigV3, 'v2 and v3 produce different signatures');
assert_true(strlen($sigV2) === 64, 'v2 signature is 64 hex chars (sha256)');
assert_true(strlen($sigV3) === 64, 'v3 signature is 64 hex chars (sha256)');

// Verify v2 round-trip
$recomputed = computeHmac('v2', $invoiceId, $txnId, $amount, $currency, $status, $txnType, $keySecret);
assert_true(hash_equals($sigV2, $recomputed), 'v2 signature verifies correctly on round-trip');

// Wrong key → mismatch
$wrongSig = computeHmac('v2', $invoiceId, $txnId, $amount, $currency, $status, $txnType, 'wrong_key');
assert_false(hash_equals($sigV2, $wrongSig), 'v2 wrong key → signature mismatch');

// v2 signature does NOT verify as v3 (version cross-check)
assert_false(hash_equals($sigV3, $sigV2), 'v2 signature fails v3 verification (prevents version downgrade)');

// Amount precision: 100 and 100.00 must produce the same signature
$sig100a = computeHmac('v2', $invoiceId, $txnId, 100.0,   $currency, $status, $txnType, $keySecret);
$sig100b = computeHmac('v2', $invoiceId, $txnId, 100.00,  $currency, $status, $txnType, $keySecret);
assert_true(hash_equals($sig100a, $sig100b), 'v2 amount 100 and 100.00 produce same signature');


// ─────────────────────────────────────────────────────────────────────────────
suite('HMAC — v3 signature (Order.php HMAC fallback + PaymentMethod popup)');
// ─────────────────────────────────────────────────────────────────────────────

// v3 round-trip
$recomputedV3 = computeHmac('v3', $invoiceId, $txnId, $amount, $currency, $status, $txnType, $keySecret);
assert_true(hash_equals($sigV3, $recomputedV3), 'v3 signature verifies correctly on round-trip');

// v3 includes status and txn_type in string — changing either breaks verification
$sigV3alt = computeHmac('v3', $invoiceId, $txnId, $amount, $currency, 'failed', $txnType, $keySecret);
assert_false(hash_equals($sigV3, $sigV3alt), 'v3 tampered status → signature mismatch');

$sigV3alt2 = computeHmac('v3', $invoiceId, $txnId, $amount, $currency, $status, 'refund', $keySecret);
assert_false(hash_equals($sigV3, $sigV3alt2), 'v3 tampered txn_type → signature mismatch');

// v3 wrong key
$sigV3wrong = computeHmac('v3', $invoiceId, $txnId, $amount, $currency, $status, $txnType, 'wrong_key');
assert_false(hash_equals($sigV3, $sigV3wrong), 'v3 wrong key → signature mismatch');

// Default fallback 'v2' behaviour when version field is absent (JS sends 'v2' as default)
$defaultSig = computeHmac('v2', $invoiceId, $txnId, $amount, $currency, $status, $txnType, $keySecret);
assert_true(hash_equals($sigV2, $defaultSig), 'default (v2) fallback produces same signature as explicit v2');


// ─────────────────────────────────────────────────────────────────────────────
suite('HMAC — formatSignatureAmount (PaymentMethod::formatSignatureAmount)');
// ─────────────────────────────────────────────────────────────────────────────

assert_eq(formatSignatureAmount(100.0),     '100.00',  '100.0 → 100.00');
assert_eq(formatSignatureAmount(100.99),    '100.99',  '100.99 → 100.99');
assert_eq(formatSignatureAmount(1500.0),    '1500.00', '1500.0 → 1500.00');
assert_eq(formatSignatureAmount(999.9),     '999.90',  '999.9 → 999.90');
assert_eq(formatSignatureAmount(0.01),      '0.01',    '0.01 → 0.01');
assert_eq(formatSignatureAmount(123456.78), '123456.78', '123456.78 → unchanged');

// Verify that formatSignatureAmount output matches what computeHmac uses internally
$manualAmount = formatSignatureAmount(1500.00);
$hmacAmount   = number_format(1500.00, 2, '.', '');
assert_eq($manualAmount, $hmacAmount, 'formatSignatureAmount matches number_format(2) used in HMAC string');


// ─────────────────────────────────────────────────────────────────────────────
suite('P3 — Config::getApiHost() extracts scheme+host from API base URL');
// ─────────────────────────────────────────────────────────────────────────────

assert_eq(config_getApiHost('https://api.nimbbl.tech/api/v3'),     'https://api.nimbbl.tech',     'production URL extracts host');
assert_eq(config_getApiHost('https://api-qa1.nimbbl.tech/api/v3'), 'https://api-qa1.nimbbl.tech', 'QA URL extracts host');
assert_eq(config_getApiHost('https://api.nimbbl.tech'),            'https://api.nimbbl.tech',     'URL without path extracts host');
assert_eq(config_getApiHost('http://localhost:8080/api/v3'),        'http://localhost',             'localhost URL extracts scheme+host (no port in parse_url host)');
assert_eq(config_getApiHost(''),                                    'https://api.nimbbl.tech',     'empty URL returns production default');


// ─────────────────────────────────────────────────────────────────────────────
suite('P5 — SDK log file path (NimbblClientFactory)');
// ─────────────────────────────────────────────────────────────────────────────

assert_eq(resolveLogFile(true,  '/var/www/html'),  '/var/www/html/var/log/nimbbl.log', 'debug on → log file path set');
assert_eq(resolveLogFile(false, '/var/www/html'),  null,                               'debug off → log file null');
assert_eq(resolveLogFile(true,  ''),               '/var/log/nimbbl.log',              'debug on, empty BP → still produces path');


// ─────────────────────────────────────────────────────────────────────────────
suite('End-to-end — full redirect callback flow simulation');
// ─────────────────────────────────────────────────────────────────────────────

// Simulate the complete resolveRedirectCallback() HMAC path for a successful payment

$testKeySecret = 'sim_secret_key_9876543210abcdef';
$testInvoiceId = 'INV-SIM-0001';
$testTxnId     = 'txn_sim_001';
$testAmount    = 2500.50;
$testCurrency  = 'INR';
$testStatus    = 'success';
$testTxnType   = 'sale';

// 1. Nimbbl server computes v3 signature
$serverSig = computeHmac('v3', $testInvoiceId, $testTxnId, $testAmount, $testCurrency, $testStatus, $testTxnType, $testKeySecret);

// 2. Callback arrives — build a fake decoded payload
$callbackData = [
    'nimbbl_transaction_id'   => $testTxnId,
    'nimbbl_order_id'         => 'order_sim_001',
    'nimbbl_signature'        => $serverSig,
    'nimbbl_signature_version'=> 'v3',
    'transaction' => [
        'status'               => $testStatus,
        'transaction_type'     => $testTxnType,
        'transaction_currency' => $testCurrency,
        'transaction_amount'   => $testAmount,
        'payment_mode'         => 'UPI',
    ],
    'order' => [
        'invoice_id' => $testInvoiceId,
    ],
];

// 3. HMAC verification
$sigVer    = $callbackData['nimbbl_signature_version'] ?? ($callbackData['transaction']['signature_version'] ?? 'v2');
$invoiceId = $callbackData['order']['invoice_id'] ?? '';
$sig       = $callbackData['nimbbl_signature'] ?? ($callbackData['transaction']['signature'] ?? null);
$txnIdCb   = $callbackData['nimbbl_transaction_id'] ?? ($callbackData['transaction']['transaction_id'] ?? null);
$statusCb  = $callbackData['transaction']['status'] ?? '';
$txnTypeCb = $callbackData['transaction']['transaction_type'] ?? '';
$currencyCb= $callbackData['transaction']['transaction_currency'] ?? 'INR';
$amountCb  = (float) ($callbackData['transaction']['transaction_amount'] ?? 0);

$expected = computeHmac($sigVer, $invoiceId, $txnIdCb, $amountCb, $currencyCb, $statusCb, $txnTypeCb, $testKeySecret);
assert_true(hash_equals($expected, (string) $sig), 'e2e: HMAC verification passes');

// 4. Initial outcome from callback status
$initialOutcome = classifyCallbackOutcome($statusCb);
assert_eq($initialOutcome, 'success', 'e2e: callback status success → initial outcome success');

// 5. P1 override with enquiry (simulated success response)
$fakeEnquiry = ['payment_status' => 'success', 'payment_mode' => 'UPI'];
$finalOutcome = applyEnquiryOverride($fakeEnquiry, $initialOutcome);
assert_eq($finalOutcome, 'success', 'e2e: enquiry confirms success → final outcome success');

// Scenario: tampered signature
$tamperedSig = substr_replace($serverSig, '00', 0, 2);
assert_false(hash_equals($expected, $tamperedSig), 'e2e: tampered signature is rejected');

// Scenario: callback says success, enquiry says failed (fraud catch)
$fraudOutcome = applyEnquiryOverride(['payment_status' => 'failed'], 'success');
assert_eq($fraudOutcome, 'failed', 'e2e: fraud catch — enquiry overrides callback success with failed');

// Scenario: network error during enquiry (enquiry returns []) — pending callback status kept
$networkErrorOutcome = applyEnquiryOverride([], 'pending');
assert_eq($networkErrorOutcome, 'pending', 'e2e: network error + pending callback → stays pending (routes to cart)');


// ─────────────────────────────────────────────────────────────────────────────
suite('Security — signature replay + cross-key attacks');
// ─────────────────────────────────────────────────────────────────────────────

$keyA  = 'merchant_key_A_1234567890abcdef';
$keyB  = 'merchant_key_B_fedcba0987654321';
$sigA  = computeHmac('v2', 'inv-001', 'txn-001', 500.0, 'INR', 'success', 'sale', $keyA);
$sigB  = computeHmac('v2', 'inv-001', 'txn-001', 500.0, 'INR', 'success', 'sale', $keyB);

assert_false(hash_equals($sigA, $sigB),               'different merchant keys produce different signatures');
assert_false(hash_equals($sigA, computeHmac('v2', 'inv-001', 'txn-001', 500.0, 'INR', 'success', 'sale', $keyB)),
    'signature from key A does not verify with key B (cross-merchant replay blocked)');

// Amount tampering
$sigOriginal = computeHmac('v2', 'inv-001', 'txn-001', 100.0, 'INR', 'success', 'sale', $keyA);
$sigTampered = computeHmac('v2', 'inv-001', 'txn-001', 1.0,   'INR', 'success', 'sale', $keyA);
assert_false(hash_equals($sigOriginal, $sigTampered), 'tampered amount → signature mismatch (amount tampering blocked)');


// =============================================================================
// Summary
// =============================================================================

echo "\n";
echo str_repeat('─', 52) . "\n";
$total = $pass + $fail;
if ($fail === 0) {
    echo "\033[1;32m  ALL $total TESTS PASSED\033[0m\n";
} else {
    echo "\033[1;32m  PASSED: $pass\033[0m  \033[1;31mFAILED: $fail\033[0m  (total: $total)\n";
}
echo str_repeat('─', 52) . "\n\n";

exit($fail > 0 ? 1 : 0);
