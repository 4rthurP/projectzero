<?php

namespace pz;

use Exception;
use Datetime;
use DateTimeZone;
use pz\database\Database;

class Nonce {
    private $user_id;
    private $nonce;
    private $nonce_id;
    private $nonce_expiration;
    private bool $is_valid;
    private bool $is_expired;
    private bool $is_used;

    public function __construct($user_id) {
        if($user_id == null) {
            throw new Exception('Invalid user id');
        }

        $this->user_id = $user_id;
        $result = Database::runQuery('SELECT * FROM nonces WHERE user_id = ? LIMIT 1', 'i', $user_id);

        
        if($result->num_rows == 0) {
            $this->createNonce();
            $id = Database::execute('INSERT INTO nonces (user_id, nonce, expiration, is_expired, is_used) VALUES (?, ?, ?, ?, ?)', 'issii', $this->user_id, $this->nonce, $this->nonce_expiration->format('Y-m-d H:i:s'), false, false);
            $this->nonce_id = $id;
        } else {
            $row = $result->fetch_assoc();
            $this->nonce = $row['nonce'];
            $this->nonce_id = $row['id'];
            $this->nonce_expiration = new DateTime($row['expiration'], new DateTimeZone($_ENV['TZ']));
            $this->is_used = $row['is_used'];
            $this->is_expired = $row['is_expired'];
        }
    }

    public function getNonce() {
        ///TODO: add a check that the nonce was successfully retrieved
        $this->checkExpiration();
        return ['success' => true, 'nonce' => $this->nonce, 'expiration' => $this->nonce_expiration->format('Y-m-d H:i:s'), 'is_expired' => $this->is_expired, 'is_used' => $this->is_used];
    }

    // public function get() {
    //     $this->checkExpiration();
    //     if($this->is_expired) {
    //         return ['success' => false, 'error' => 'expired-nonce', 'message' => 'The nonce is expired'];
    //     }
    //     return ['success' => true, 'nonce' => $this->nonce, 'expiration' => $this->nonce_expiration->format('Y-m-d H:i:s')];
    // }

    public function getNewNonce() {
        $this->createNonce();
        Database::execute('UPDATE nonces SET nonce = ?, expiration = ?, is_expired = ?, is_used = ? WHERE id = ?', 'ssiii', $this->nonce, $this->nonce_expiration->format('Y-m-d H:i:s'), false, false, $this->nonce_id);
        return ['success' => true, 'nonce' => $this->nonce, 'expiration' => $this->nonce_expiration->format('Y-m-d H:i:s'), 'is_expired' => false, 'is_used' => false];
    }    

    public static function getOrNew($user_id) {
        $nonce = new Nonce($user_id);
        $current_nonce = $nonce->getNonce();
        if(!$current_nonce['is_expired']) {
            return $current_nonce;
        }
        
        return $nonce->getNewNonce();
    }

    public function checkNonce($nonce) {
        if($this->nonce != $nonce) {
            return ['success' => false, 'error' => 'invalid-nonce', 'message' => 'The nonce is invalid'];
        }

        $this->checkExpiration();
        if($this->is_expired) {
            return['success' => false, 'error' => 'expired-nonce', 'message' => 'The nonce is expired'];
        }

        if($this->is_used) {
            return ['success' => false, 'error' => 'used-nonce', 'message' => 'The nonce has already been used'];
        }

        return ['success' => true];
    }

    // public function use() {
    //     $this->checkExpiration();
    //     Database::execute('UPDATE nonces SET is_used = ? WHERE id = ?', 'ii', true, $this->nonce_id);
    // }

    private function createNonce() {
        $this->nonce = hash('sha256', $this->user_id . time() . random_bytes(16));
        $this->nonce_expiration = new DateTime("+1 hour", new DateTimeZone($_ENV['TZ']));
        $this->is_valid = true;
        $this->is_expired = false;
        $this->is_used = false;
        return;
    }

    private function checkExpiration() {
        // If we already now that the nonce is expired, we don't need to check again to avoid unnecessary queries
        if($this->is_expired) {
            return;
        }
        
        $now = new DateTime("now", new DateTimeZone($_ENV['TZ']));

        if($now > $this->nonce_expiration) {
            $this->is_expired = true;
            $this->is_valid = false;
            Database::execute('UPDATE nonces SET is_expired = ? WHERE id = ?', 'ii', true, $this->nonce_id);
        } else {
            $this->is_expired = false;
            $this->is_valid = true;
        }
    }
}