<?php

namespace pz;

use Exception;
use Datetime;
use DateTimeZone;

use pz\Config;
use pz\database\Database;

/* 
* Nonce class
*
* There are two ways to get a new nonce:
* 1. The user use a correct nonce, he receives one back
* 2. The user makes a new request with its valid credential through the user class
*/
class Nonce {
    protected static $expiration_delay = 120;
    protected $user_id;
    protected string $nonce;
    protected ?string $previous_nonce = null; # Nonce given to authenticate the user, it is overridden by the new nonce so we keep it there 
    protected int $nonce_id;
    protected $nonce_expiration;
    protected bool $is_valid;
    protected bool $is_expired;
    protected bool $was_expired;

    public function __construct($user_id) {
        if($user_id == null) {
            throw new Exception('Invalid user id');
        }

        $this->user_id = $user_id;
        $result = Database::execute('SELECT * FROM nonces WHERE user_id = ? LIMIT 1', 'i', $user_id);
        
        if($result->num_rows == 0) {
            $this->makeNonce();
            $id = Database::execute('INSERT INTO nonces (user_id, nonce, expiration) VALUES (?, ?, ?)', 'iss', $this->user_id, $this->nonce, $this->nonce_expiration->format('Y-m-d H:i:s'));
            $this->nonce_id = $id;
            $this->is_valid = true;
            $this->is_expired = false;
        } else {
            $row = $result->fetch_all(MYSQLI_ASSOC)[0];
            $this->nonce = $row['nonce'];
            $this->nonce_id = $row['id'];
            $this->nonce_expiration = new DateTime($row['expiration'], Config::tz());
            $this->is_valid = true;
            $this->checkExpiration();
        }
    }

    public function nonce() {
        return $this->nonce;
    }
    public function previousNonce() {
        return $this->previous_nonce;
    }
    public function expiration() {
        return $this->nonce_expiration;
    }
    public function isValid() {
        return $this->is_valid;
    }

    public function wasExpired() {
        return $this->was_expired;
    }

    public function checkNonce($nonce) {
        $old_nonce = $this->nonce;
        
        $this->checkExpiration();
        $this->was_expired = $this->is_expired;
        $this->useNonce();
        
        if( !$this->was_expired && $old_nonce == $nonce ) {
            $this->is_valid = true;
        } else {
            $this->is_valid = false;
        }

        return $this;
    }

    public function getOrNew($minutes = 0) {
        $this->checkExpiration($minutes);
        if($this->is_expired) {
            $this->useNonce();
        }
        return $this;
    }

    private function useNonce() {
        $this->makeNonce();
        Database::execute('UPDATE nonces SET nonce = ?, expiration = ? WHERE id = ?', 'ssi', $this->nonce, $this->nonce_expiration->format('Y-m-d H:i:s'), $this->nonce_id);
        return $this;
    }    

    private function makeNonce() {
        $this->previous_nonce = $this->nonce ?? null;
        $this->nonce = hash('sha256', $this->user_id . time() . random_bytes(16));
        $this->nonce_expiration = new DateTime("+".$this::$expiration_delay." minutes", Config::tz());
        
        $_SESSION['user']['nonce'] = $this->nonce;
        $_SESSION['user']['nonce_expiration'] =  $this->nonce_expiration;
        $this->is_valid = true;
        $this->is_expired = false;
        return;
    }

    private function checkExpiration($minutes = 0) {
        $now = new DateTime("now", Config::tz());
        if ($minutes > 0) {
            $now->modify("+$minutes minutes");
        }

        if($now > $this->nonce_expiration) {
            $this->is_expired = true;
            $this->is_valid = false;
            return;
        }

        $this->is_expired = false;
    }
}