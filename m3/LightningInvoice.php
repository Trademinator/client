<?php

declare(strict_types=1);

namespace Trademinator\Client\M3;

use RuntimeException;

/** Read historical BOLT11 evidence; never submit or initiate a Lightning payment. */
final class LightningInvoice
{
    private const CHARSET = 'qpzry9x8gf2tvdw0s3jn54khce6mua7l';
    private const GENERATORS = [0x3b6a57b2, 0x26508e6d, 0x1ea119fa, 0x3d4233dd, 0x2a1462b3];
    private const FIELDS = ['invoice', 'payment_request', 'paymentRequest', 'bolt11', 'bolt_11', 'lightning_invoice', 'withdrawal_address'];

    /** Null requests more evidence; invalid or conflicting supplied evidence is rejected. */
    public static function zeroFeeEvidence(array $raw, array $row, string $gross, ?array $lookup = null): ?array
    {
        if (($row['currency'] ?? null) !== 'BTC' || ($row['status'] ?? null) !== 'ok') {
            return null;
        }
        $records = ['list' => $raw];
        if ($lookup !== null) {
            // The caller must first verify that this is the same completed withdrawal.
            $records['lookup'] = $lookup;
        }
        $invoices = [];
        $invoiceFields = [];
        $hashes = [];
        foreach ($records as $source => $record) {
            foreach (['' => $record, 'details.' => is_array($record['details'] ?? null) ? $record['details'] : []] as $prefix => $fields) {
                foreach (self::FIELDS as $key) {
                    $value = $fields[$key] ?? null;
                    if (!is_string($value) || !preg_match('/^(?:lightning:)?ln(?:bc|tb)/i', $value)) {
                        continue;
                    }
                    $invoice = self::decode($value);
                    $invoices[$invoice['invoice_sha256']] = $invoice;
                    $invoiceFields[] = $source.'.'.$prefix.$key;
                }
                // Cross-check explicit hashes when present. A withdrawal ID,
                // generic hash field or preimage is not a payment hash.
                foreach (['tx_hash', 'payment_hash'] as $key) {
                    if (array_key_exists($key, $fields) || ($prefix === 'details.' && $key === 'tx_hash')) {
                        $hashes[$source.'.'.$prefix.$key] = $fields[$key] ?? null;
                    }
                }
            }
        }
        if ($invoices === []) {
            return null;
        }
        if (count($invoices) !== 1) {
            throw new RuntimeException('Bitso withdrawal contains conflicting Lightning invoices.');
        }
        $invoice = array_values($invoices)[0];
        $hashFields = [];
        foreach ($hashes as $field => $hash) {
            $state = self::hashState($hash, $invoice['payment_hash']);
            $hashFields[$field] = ['state' => $state];
            if (in_array($state, ['match', 'mismatch'], true)) {
                $hashFields[$field]['hash_ref'] = substr(hash('sha256', strtolower($hash)), 0, 16);
            }
        }
        $states = array_column($hashFields, 'state');
        $problem = in_array('mismatch', $states, true) ? 'mismatch'
            : (in_array('invalid_format', $states, true) ? 'invalid_format'
                : (in_array('match', $states, true) ? null : 'missing'));
        if ($problem === 'missing' && $lookup === null) {
            // Confirm this completed withdrawal by ID before relying solely on
            // its attached invoice. No account-specific association is required.
            return null;
        }
        if ($problem !== null && $problem !== 'missing') {
            throw new LightningHashError($row, $gross, $problem, [
                'invoice_fields' => $invoiceFields,
                'invoice_payment_hash_ref' => substr(hash('sha256', $invoice['payment_hash']), 0, 16),
                'ccxt_txid_state' => self::hashState($row['txid'] ?? null, $invoice['payment_hash']),
                'hash_fields' => $hashFields,
            ]);
        }
        // A BOLT11 amount is a minimum payment. Only exact equality proves that
        // this debit leaves no additional fee; a difference is not assumed to be a fee.
        if (bccomp($invoice['amount'], $gross, 24) !== 0) {
            throw new RuntimeException('Lightning invoice amount differs from the Bitso total debit; fee evidence is still required.');
        }
        return ['source' => 'bitso_lightning_invoice',
            'invoice_sha256' => $invoice['invoice_sha256'], 'payment_hash' => $invoice['payment_hash'],
            'amount' => $invoice['amount'], 'fee' => '0',
            'payment_hash_source' => $problem === 'missing' ? 'invoice' : 'exchange'];
    }

    private static function hashState(mixed $hash, string $expected): string
    {
        if ($hash === null || $hash === '') {
            return 'missing';
        }
        if (!is_string($hash) || !preg_match('/^[a-fA-F0-9]{64}$/D', $hash)) {
            return 'invalid_format';
        }
        return hash_equals($expected, strtolower($hash)) ? 'match' : 'mismatch';
    }

    /** Mainnet, amount-bearing invoices with an explicit payee key only. */
    public static function decode(string $input): array
    {
        if (strlen($input) > 4096) {
            throw new RuntimeException('Invalid Lightning invoice evidence.');
        }
        if (strncasecmp($input, 'lightning:', 10) === 0) {
            $input = substr($input, 10);
        }
        if ($input !== strtolower($input) && $input !== strtoupper($input)) {
            throw new RuntimeException('Invalid Lightning invoice evidence.');
        }
        $input = strtolower($input);
        $separator = strrpos($input, '1');
        if ($separator === false) {
            throw new RuntimeException('Invalid Lightning invoice evidence.');
        }
        $hrp = substr($input, 0, $separator);
        if (!preg_match('/^lnbc([1-9][0-9]{0,23})([munp]?)$/D', $hrp, $match)
            || ($match[2] === 'p' && !str_ends_with($match[1], '0'))) {
            throw new RuntimeException('Lightning evidence requires a Bitcoin mainnet invoice with an explicit valid amount.');
        }
        $scale = ['' => 0, 'm' => 3, 'u' => 6, 'n' => 9, 'p' => 12][$match[2]];
        $amount = Numbers::decimal(bcdiv($match[1], '1'.str_repeat('0', $scale), 24));
        $words = [];
        foreach (str_split(substr($input, $separator + 1)) as $character) {
            $word = strpos(self::CHARSET, $character);
            if ($word === false) {
                throw new RuntimeException('Invalid Lightning invoice evidence.');
            }
            $words[] = $word;
        }
        if (count($words) < 7 + 104 + 6) {
            throw new RuntimeException('Invalid Lightning invoice evidence.');
        }
        $expanded = [];
        foreach (str_split($hrp) as $character) { $expanded[] = ord($character) >> 5; }
        $expanded[] = 0;
        foreach (str_split($hrp) as $character) { $expanded[] = ord($character) & 31; }
        $checksum = 1;
        foreach (array_merge($expanded, $words) as $word) {
            $top = $checksum >> 25;
            $checksum = (($checksum & 0x1ffffff) << 5) ^ $word;
            foreach (self::GENERATORS as $bit => $generator) {
                if (($top >> $bit) & 1) { $checksum ^= $generator; }
            }
        }
        if ($checksum !== 1) {
            throw new RuntimeException('Lightning invoice checksum is invalid.');
        }
        $unsigned = array_slice($words, 0, -110);
        $signature = self::bytes(array_slice($words, -110, 104));
        if (ord($signature[64]) > 3) {
            throw new RuntimeException('Invalid Lightning invoice evidence.');
        }
        $tags = [];
        for ($i = 7, $end = count($unsigned); $i < $end;) {
            if ($i + 3 > $end) {
                throw new RuntimeException('Invalid Lightning invoice evidence.');
            }
            $type = self::CHARSET[$unsigned[$i]];
            $length = ($unsigned[$i + 1] << 5) | $unsigned[$i + 2];
            $i += 3;
            if ($i + $length > $end) {
                throw new RuntimeException('Invalid Lightning invoice evidence.');
            }
            if (in_array($type, ['p', 'n', 's', 'h', 'd'], true)) {
                if (isset($tags[$type]) || (isset(['p' => 52, 'n' => 53, 's' => 52, 'h' => 52][$type])
                    && $length !== ['p' => 52, 'n' => 53, 's' => 52, 'h' => 52][$type])) {
                    throw new RuntimeException('Invalid Lightning invoice evidence.');
                }
                $tags[$type] = self::bytes(array_slice($unsigned, $i, $length));
            }
            $i += $length;
        }
        if (!isset($tags['p']) || !isset($tags['s']) || isset($tags['d']) === isset($tags['h'])) {
            throw new RuntimeException('Invalid Lightning invoice evidence.');
        }
        if (!isset($tags['n'])) {
            throw new RuntimeException('Lightning invoice evidence needs an explicit payee public key for signature verification.');
        }
        if (!in_array(ord($tags['n'][0]), [2, 3], true)) {
            throw new RuntimeException('Invalid Lightning invoice evidence.');
        }
        // SEC1 compressed point in a SubjectPublicKeyInfo for id-ecPublicKey / secp256k1.
        $der = hex2bin('3036301006072a8648ce3d020106052b8104000a032200').$tags['n'];
        $pem = "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END PUBLIC KEY-----\n";
        $key = @openssl_pkey_get_public($pem);
        $r = self::derInteger(substr($signature, 0, 32));
        $s = self::derInteger(substr($signature, 32, 32));
        $derSignature = "\x30".chr(strlen($r.$s)).$r.$s;
        if ($key === false || @openssl_verify($hrp.self::bytes($unsigned, true), $derSignature, $key, OPENSSL_ALGO_SHA256) !== 1) {
            throw new RuntimeException('Lightning invoice signature could not be verified.');
        }
        // No invoice, payment secret, description, routes or payee key leave this parser.
        return ['amount' => $amount, 'payment_hash' => bin2hex($tags['p']), 'invoice_sha256' => hash('sha256', $input)];
    }

    private static function bytes(array $words, bool $pad = false): string
    {
        $accumulator = 0;
        $bits = 0;
        $out = '';
        foreach ($words as $word) {
            $accumulator = (($accumulator << 5) | $word) & 0xffff;
            $bits += 5;
            while ($bits >= 8) {
                $bits -= 8;
                $out .= chr(($accumulator >> $bits) & 255);
            }
        }
        if ($pad && $bits > 0) {
            $out .= chr(($accumulator << (8 - $bits)) & 255);
        } elseif (!$pad && ($bits >= 5 || (($accumulator << (8 - $bits)) & 255) !== 0)) {
            throw new RuntimeException('Invalid Lightning invoice evidence.');
        }
        return $out;
    }

    private static function derInteger(string $bytes): string
    {
        $bytes = ltrim($bytes, "\0");
        if ($bytes === '' || (ord($bytes[0]) & 128)) { $bytes = "\0".$bytes; }
        return "\x02".chr(strlen($bytes)).$bytes;
    }
}
