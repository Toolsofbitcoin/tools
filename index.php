<?php
/**
 * CryptographyTube Telegram Bot - PHP Implementation
 * Hosted on Render.com with Docker
 */

require_once __DIR__ . '/vendor/autoload.php';

use Telegram\Bot\Api;
use Telegram\Bot\Objects\Update;
use Telegram\Bot\Keyboard\Keyboard;
use BitWasp\Bitcoin\Bitcoin;
use BitWasp\Bitcoin\Key\Factory\PrivateKeyFactory;
use BitWasp\Bitcoin\Key\Factory\PublicKeyFactory;
use BitWasp\Bitcoin\Address\PayToPubKeyHashAddress;
use BitWasp\Bitcoin\Network\NetworkFactory;

// Error reporting
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/error.log');

// Configuration
define('TOKEN', '8377296010:AAFx0gxdvK3Q4ZpeAQE4clZt1IhcFNYzCmA');
define('BS_API', 'https://blockstream.info/api');
define('MAX_ADDR_TX_LOOKUP', 25);
define('HTTP_TIMEOUT', 10);

class CryptographyTubeBot {
    private $telegram;
    private $usersFile;
    private $errorLog;
    
    public function __construct() {
        $this->telegram = new Api(TOKEN);
        $this->usersFile = __DIR__ . '/users.json';
        $this->errorLog = __DIR__ . '/error.log';
        $this->ensureFiles();
        Bitcoin::setNetwork(NetworkFactory::bitcoin());
    }
    
    private function ensureFiles() {
        if (!file_exists($this->usersFile)) {
            file_put_contents($this->usersFile, json_encode([]));
            chmod($this->usersFile, 0666);
        }
        if (!file_exists($this->errorLog)) {
            file_put_contents($this->errorLog, '');
            chmod($this->errorLog, 0666);
        }
    }
    
    private function logError($error) {
        $timestamp = date('Y-m-d H:i:s');
        $message = "[$timestamp] ERROR: $error\n";
        file_put_contents($this->errorLog, $message, FILE_APPEND | LOCK_EX);
    }
    
    private function getUserData($userId) {
        $data = json_decode(file_get_contents($this->usersFile), true) ?? [];
        return $data[$userId] ?? ['action' => null, 'last_pubkeys' => [], 'addr_info' => [], 'last_raw_tx' => []];
    }
    
    private function saveUserData($userId, $data) {
        $allData = json_decode(file_get_contents($this->usersFile), true) ?? [];
        $allData[$userId] = $data;
        file_put_contents($this->usersFile, json_encode($allData, JSON_PRETTY_PRINT));
    }
    
    public function handleWebhook() {
        try {
            $update = $this->telegram->getWebhookUpdate();
            
            if ($update->getMessage()) {
                $this->handleMessage($update);
            } elseif ($update->getCallbackQuery()) {
                $this->handleCallback($update);
            }
            
            return 'OK';
        } catch (Exception $e) {
            $this->logError($e->getMessage());
            return 'ERROR';
        }
    }
    
    private function handleMessage($update) {
        $message = $update->getMessage();
        $chatId = $message->getChat()->getId();
        $text = $message->getText();
        $userId = $message->getFrom()->getId();
        
        $userData = $this->getUserData($userId);
        
        if ($text === '/start') {
            $this->showMenu($chatId);
            $userData['action'] = null;
            $this->saveUserData($userId, $userData);
            return;
        }
        
        $action = $userData['action'] ?? null;
        
        if (!$action) {
            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => 'Press /start and choose an option.',
                'reply_markup' => $this->menuKeyboard()
            ]);
            return;
        }
        
        switch ($action) {
            case 'addr2pub':
                $this->handleAddr2Pub($chatId, $text, $userId, $userData);
                break;
            case 'addr2h160':
                $this->handleAddr2H160($chatId, $text);
                break;
            case 'pub2addr':
                $this->handlePub2Addr($chatId, $text);
                break;
            case 'priv2pub':
                $this->handlePriv2Pub($chatId, $text);
                break;
            case 'priv2addr':
                $this->handlePriv2Addr($chatId, $text);
                break;
            case 'compuncomp':
                $this->handleCompUncomp($chatId, $text);
                break;
            case 'privfmt':
                $this->handlePrivFmt($chatId, $text);
                break;
            case 'addrinfo':
                $this->handleAddrInfo($chatId, $text, $userId, $userData);
                break;
            case 'rsz':
                $this->handleTxRaw($chatId, $text, $userId, $userData);
                break;
            default:
                $this->showMenu($chatId);
        }
    }
    
    private function handleCallback($update) {
        $callback = $update->getCallbackQuery();
        $chatId = $callback->getMessage()->getChat()->getId();
        $data = $callback->getData();
        $userId = $callback->getFrom()->getId();
        
        $userData = $this->getUserData($userId);
        
        if ($data === 'menu') {
            $this->showMenu($chatId);
            $userData['action'] = null;
            $this->saveUserData($userId, $userData);
            return;
        }
        
        $prompts = [
            'addr2pub' => "Send BTC address — I will try to extract public key(s) from tx inputs/witness (recent txs):",
            'addr2h160' => "Send BTC address — I'll return its Hash160 (base58check P2PKH only):",
            'pub2addr' => "Send public key hex (compressed/uncompressed):",
            'priv2pub' => "Send private key (WIF or hex):",
            'priv2addr' => "Send private key (WIF or hex):",
            'compuncomp' => "Send public key (compressed or uncompressed hex):",
            'privfmt' => "Send private key (WIF or hex):",
            'addrinfo' => "Send BTC address to get a formatted summary:",
            'rsz' => "Send TXID (raw transaction lookup):"
        ];
        
        if (isset($prompts[$data])) {
            $userData['action'] = $data;
            $this->saveUserData($userId, $userData);
            
            $this->telegram->editMessageText([
                'chat_id' => $chatId,
                'message_id' => $callback->getMessage()->getMessageId(),
                'text' => $prompts[$data],
                'reply_markup' => $this->backMenuMarkup()
            ]);
        } elseif (strpos($data, 'save_') === 0) {
            $this->handleSaveCallback($chatId, $data, $userId, $userData);
        }
    }
    
    private function handleSaveCallback($chatId, $data, $userId, $userData) {
        // Implementation for save callbacks
        $this->telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => 'Save functionality would be implemented here.',
            'reply_markup' => $this->backMenuMarkup()
        ]);
    }
    
    // Crypto helper methods
    private function pubkeyToAddr($pubHex) {
        try {
            $pubKeyFactory = new PublicKeyFactory();
            $publicKey = $pubKeyFactory->fromHex($pubHex);
            $address = new PayToPubKeyHashAddress($publicKey->getPubKeyHash());
            return $address->getAddress();
        } catch (Exception $e) {
            return null;
        }
    }
    
    private function addrToHash160($addr) {
        try {
            $network = NetworkFactory::bitcoin();
            $payload = \BitWasp\Bitcoin\Address\AddressCreator::fromString($addr, $network)->getHash()->getHex();
            return ['version' => 0, 'hash160' => $payload];
        } catch (Exception $e) {
            return null;
        }
    }
    
    private function privToPubkey($privHex, $compressed = true) {
        try {
            $factory = new PrivateKeyFactory();
            $privateKey = $factory->fromHexCompressed($privHex);
            return $compressed ? $privateKey->getPublicKey()->getHex() : $privateKey->getPublicKey()->getUncompressedPubKey()->getHex();
        } catch (Exception $e) {
            return null;
        }
    }
    
    private function compUncomp($pubHex) {
        try {
            $pubKeyFactory = new PublicKeyFactory();
            $publicKey = $pubKeyFactory->fromHex($pubHex);
            
            if ($publicKey->isCompressed()) {
                return [
                    'comp' => $pubHex,
                    'uncomp' => $publicKey->getUncompressedPubKey()->getHex()
                ];
            } else {
                return [
                    'comp' => $publicKey->getCompressedPubKey()->getHex(),
                    'uncomp' => $pubHex
                ];
            }
        } catch (Exception $e) {
            return null;
        }
    }
    
    private function privFormats($wifOrHex) {
        try {
            if (strlen($wifOrHex) === 64 && ctype_xdigit($wifOrHex)) {
                $factory = new PrivateKeyFactory();
                $privateKey = $factory->fromHexUncompressed($wifOrHex);
                return [
                    'hex' => $wifOrHex,
                    'wif_c' => $privateKey->toWif(),
                    'wif_u' => $privateKey->toWif(false)
                ];
            } else {
                $factory = new PrivateKeyFactory();
                $privateKey = $factory->fromWif($wifOrHex);
                return [
                    'hex' => $privateKey->getHex(),
                    'wif_c' => $privateKey->toWif(),
                    'wif_u' => $privateKey->toWif(false)
                ];
            }
        } catch (Exception $e) {
            return null;
        }
    }
    
    // HTTP helper methods
    private function httpGet($url) {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => HTTP_TIMEOUT,
            CURLOPT_USERAGENT => 'CryptographyTubeBot/1.0'
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        return $httpCode === 200 ? $response : null;
    }
    
    private function fetchJson($url) {
        $response = $this->httpGet($url);
        return $response ? json_decode($response, true) : null;
    }
    
    private function fetchText($url) {
        return $this->httpGet($url);
    }
    
    // Blockstream API methods
    private function blockstreamAddressTxs($addr, $limit = MAX_ADDR_TX_LOOKUP) {
        $url = BS_API . "/address/$addr/txs";
        $txs = $this->fetchJson($url);
        
        if (!is_array($txs)) {
            return null;
        }
        
        $results = [];
        foreach (array_slice($txs, 0, $limit) as $tx) {
            if (isset($tx['txid'])) {
                $txDetail = $this->fetchJson(BS_API . "/tx/{$tx['txid']}");
                if ($txDetail) {
                    $results[] = $txDetail;
                }
            }
        }
        
        return $results;
    }
    
    private function blockstreamTxRawHex($txid) {
        return $this->fetchText(BS_API . "/tx/$txid/hex");
    }
    
    // Message handler methods
    private function handleAddr2Pub($chatId, $addr, $userId, &$userData) {
        $txs = $this->blockstreamAddressTxs($addr);
        
        if (!$txs) {
            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => 'No txs found or API error.',
                'reply_markup' => $this->backMenuMarkup()
            ]);
            return;
        }
        
        $found = [];
        foreach ($txs as $tx) {
            $pubkeys = $this->extractPubkeysFromTx($tx);
            $found = array_merge($found, $pubkeys);
        }
        
        $found = array_unique($found);
        
        if (empty($found)) {
            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => 'No public key found in recent txs/witness.',
                'reply_markup' => $this->backMenuMarkup()
            ]);
            return;
        }
        
        $userData['last_pubkeys'] = ['addr' => $addr, 'pubkeys' => $found];
        $this->saveUserData($userId, $userData);
        
        $text = "Found public key(s):\n\n";
        foreach ($found as $i => $pk) {
            $cu = $this->compUncomp($pk);
            if ($cu) {
                $text .= ($i+1) . ". Compressed: {$cu['comp']}\n   Uncompressed: {$cu['uncomp']}\n\n";
            } else {
                $text .= ($i+1) . ". $pk\n\n";
            }
        }
        
        $keyboard = Keyboard::make()
            ->inline()
            ->row([
                Keyboard::inlineButton(['text' => 'Save PUBKEYS TXT', 'callback_data' => "save_pubkeys:$addr"]),
                Keyboard::inlineButton(['text' => 'Main Menu', 'callback_data' => 'menu'])
            ]);
            
        $this->telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => $text,
            'reply_markup' => $keyboard
        ]);
    }
    
    private function extractPubkeysFromTx($tx) {
        $found = [];
        $hexPattern = '/\b([0-9a-fA-F]{66}|[0-9a-fA-F]{130})\b/';
        
        // Check vin
        foreach ($tx['vin'] ?? [] as $vin) {
            if (isset($vin['scriptsig'])) {
                preg_match_all($hexPattern, $vin['scriptsig'], $matches);
                $found = array_merge($found, $matches[1] ?? []);
            }
            
            if (isset($vin['witness']) && is_array($vin['witness'])) {
                foreach ($vin['witness'] as $witness) {
                    if (is_string($witness)) {
                        preg_match_all($hexPattern, $witness, $matches);
                        $found = array_merge($found, $matches[1] ?? []);
                    }
                }
            }
        }
        
        return array_unique($found);
    }
    
    private function handleAddr2H160($chatId, $addr) {
        $result = $this->addrToHash160($addr);
        
        if (!$result) {
            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => 'Invalid or unsupported address.',
                'reply_markup' => $this->backMenuMarkup()
            ]);
            return;
        }
        
        $this->telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => "Address: $addr\nHash160: {$result['hash160']}",
            'reply_markup' => $this->backMenuMarkup()
        ]);
    }
    
    private function handlePub2Addr($chatId, $pubKey) {
        $addr = $this->pubkeyToAddr($pubKey);
        
        if (!$addr) {
            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => 'Invalid public key hex.',
                'reply_markup' => $this->backMenuMarkup()
            ]);
            return;
        }
        
        $this->telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => "P2PKH Address:\n$addr",
            'reply_markup' => $this->backMenuMarkup()
        ]);
    }
    
    private function handlePriv2Pub($chatId, $privKey) {
        $formats = $this->privFormats($privKey);
        
        if (!$formats) {
            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => 'Invalid WIF or hex private key.',
                'reply_markup' => $this->backMenuMarkup()
            ]);
            return;
        }
        
        $pubC = $this->privToPubkey($formats['hex'], true);
        $pubU = $this->privToPubkey($formats['hex'], false);
        
        $this->telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => "Compressed PubKey:\n$pubC\n\nUncompressed PubKey:\n$pubU",
            'reply_markup' => $this->backMenuMarkup()
        ]);
    }
    
    private function handlePriv2Addr($chatId, $privKey) {
        $formats = $this->privFormats($privKey);
        
        if (!$formats) {
            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => 'Invalid WIF or hex private key.',
                'reply_markup' => $this->backMenuMarkup()
            ]);
            return;
        }
        
        $pubC = $this->privToPubkey($formats['hex'], true);
        $pubU = $this->privToPubkey($formats['hex'], false);
        $addrC = $this->pubkeyToAddr($pubC);
        $addrU = $this->pubkeyToAddr($pubU);
        
        $this->telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => "Compressed Addr: $addrC\nUncompressed Addr: $addrU",
            'reply_markup' => $this->backMenuMarkup()
        ]);
    }
    
    private function handleCompUncomp($chatId, $pubKey) {
        $result = $this->compUncomp($pubKey);
        
        if (!$result) {
            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => 'Invalid public key format.',
                'reply_markup' => $this->backMenuMarkup()
            ]);
            return;
        }
        
        $this->telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => "Compressed:\n{$result['comp']}\n\nUncompressed:\n{$result['uncomp']}",
            'reply_markup' => $this->backMenuMarkup()
        ]);
    }
    
    private function handlePrivFmt($chatId, $privKey) {
        if (strlen($privKey) === 64 && ctype_xdigit($privKey)) {
            $formats = $this->privFormats($privKey);
            $text = "Hex: {$formats['hex']}\nWIF-compressed: {$formats['wif_c']}\nWIF-uncompressed: {$formats['wif_u']}";
        } else {
            $formats = $this->privFormats($privKey);
            if (!$formats) {
                $this->telegram->sendMessage([
                    'chat_id' => $chatId,
                    'text' => 'Invalid WIF/hex.',
                    'reply_markup' => $this->backMenuMarkup()
                ]);
                return;
            }
            $text = "Hex: {$formats['hex']}\nWIF-compressed: {$formats['wif_c']}\nWIF-uncompressed: {$formats['wif_u']}";
        }
        
        $this->telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => $text,
            'reply_markup' => $this->backMenuMarkup()
        ]);
    }
    
    private function handleAddrInfo($chatId, $addr, $userId, &$userData) {
        $data = $this->fetchJson(BS_API . "/address/$addr");
        
        if (!$data) {
            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => 'Address not found or API error.',
                'reply_markup' => $this->backMenuMarkup()
            ]);
            return;
        }
        
        $userData['addr_info'] = ['raw' => $data, 'txs' => $data['txs'] ?? []];
        $this->saveUserData($userId, $userData);
        
        $pretty = $this->formatAddressInfo($addr, $data);
        
        $keyboard = Keyboard::make()->inline();
        $txs = $data['txs'] ?? [];
        
        if (!empty($txs)) {
            $row = [];
            for ($i = 0; $i < min(5, count($txs)); $i++) {
                $row[] = Keyboard::inlineButton(['text' => "Tx " . ($i+1), 'callback_data' => "tx_page:$addr:$i"]);
            }
            $keyboard->row($row);
        }
        
        $keyboard->row([Keyboard::inlineButton(['text' => 'Save TXT', 'callback_data' => "save_txt:$addr"])])
                 ->row([Keyboard::inlineButton(['text' => 'Main Menu', 'callback_data' => 'menu'])]);
                 
        $this->telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => $pretty,
            'reply_markup' => $keyboard
        ]);
    }
    
    private function handleTxRaw($chatId, $txid, $userId, &$userData) {
        $raw = $this->blockstreamTxRawHex($txid);
        
        if (!$raw) {
            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => 'TX not found or API error.',
                'reply_markup' => $this->backMenuMarkup()
            ]);
            return;
        }
        
        $userData['last_raw_tx'] = ['txid' => $txid, 'raw' => $raw];
        $this->saveUserData($userId, $userData);
        
        $keyboard = Keyboard::make()
            ->inline()
            ->row([
                Keyboard::inlineButton(['text' => 'Save Raw TXT', 'callback_data' => "save_txt_raw:$txid"]),
                Keyboard::inlineButton(['text' => 'Main Menu', 'callback_data' => 'menu'])
            ]);
            
        $this->telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => "Raw TX length: " . strlen($raw) . " hex chars",
            'reply_markup' => $keyboard
        ]);
    }
    
    private function formatAddressInfo($addr, $data) {
        $lines = [];
        $lines[] = "Address Info";
        $lines[] = str_repeat("-", 36);
        $lines[] = "Address: $addr";
        
        $chain = $data['chain_stats'] ?? [];
        $received = $chain['funded_txo_sum'] ?? 0;
        $spent = $chain['spent_txo_sum'] ?? 0;
        $balance = $received - $spent;
        
        $lines[] = "Tx count: " . ($chain['tx_count'] ?? $data['tx_count'] ?? 'N/A');
        $lines[] = "Total received: " . $this->satoshiToBtc($received);
        $lines[] = "Total sent: " . $this->satoshiToBtc($spent);
        $lines[] = "Final balance: " . $this->satoshiToBtc($balance);
        
        $txs = $data['txs'] ?? [];
        if (!empty($txs)) {
            $lines[] = "";
            $lines[] = "Recent TXids (limited to 10):";
            foreach (array_slice($txs, 0, 10) as $i => $tx) {
                $txid = is_string($tx) ? $tx : ($tx['txid'] ?? 'N/A');
                $lines[] = ($i+1) . ". $txid";
            }
        }
        
        return implode("\n", $lines);
    }
    
    private function satoshiToBtc($sat) {
        return number_format($sat / 1e8, 8) . ' BTC';
    }
    
    // Keyboard methods
    private function menuKeyboard() {
        return Keyboard::make()
            ->inline()
            ->row([Keyboard::inlineButton(['text' => '1 Address → PubKey', 'callback_data' => 'addr2pub'])])
            ->row([Keyboard::inlineButton(['text' => '2 Address → Hash160', 'callback_data' => 'addr2h160'])])
            ->row([Keyboard::inlineButton(['text' => '3 PubKey → Address', 'callback_data' => 'pub2addr'])])
            ->row([Keyboard::inlineButton(['text' => '4 Priv → Pub', 'callback_data' => 'priv2pub'])])
            ->row([Keyboard::inlineButton(['text' => '5 Priv → Addr', 'callback_data' => 'priv2addr'])])
            ->row([Keyboard::inlineButton(['text' => '6 Compress/Uncompress', 'callback_data' => 'compuncomp'])])
            ->row([Keyboard::inlineButton(['text' => '7 Priv formats', 'callback_data' => 'privfmt'])])
            ->row([Keyboard::inlineButton(['text' => '8 Address Info', 'callback_data' => 'addrinfo'])])
            ->row([Keyboard::inlineButton(['text' => '9 TX Raw', 'callback_data' => 'rsz')]);
    }
    
    private function backMenuMarkup() {
        return Keyboard::make()
            ->inline()
            ->row([Keyboard::inlineButton(['text' => '🔙 Back to Menu', 'callback_data' => 'menu'])]);
    }
    
    private function showMenu($chatId) {
        $this->telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => 'Cryptographytube — choose an option:',
            'reply_markup' => $this->menuKeyboard()
        ]);
    }
}

// Webhook handler
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = file_get_contents('php://input');
    $update = json_decode($input, true);
    
    if ($update) {
        $bot = new CryptographyTubeBot();
        
        // Create Update object from webhook data
        $updateObj = new Update($update);
        
        if ($updateObj->getMessage()) {
            $bot->handleMessage($updateObj);
        } elseif ($updateObj->getCallbackQuery()) {
            $bot->handleCallback($updateObj);
        }
    }
    
    echo 'OK';
} else {
    // Simple health check response
    header('Content-Type: application/json');
    echo json_encode(['status' => 'ok', 'service' => 'CryptographyTube Bot']);
}