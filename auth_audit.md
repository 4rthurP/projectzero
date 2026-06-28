# Authentication System Audit

> Scope: `pz/users/`, `pz/application/application.php`, `pz/routing/`
> Date: 2026-06-28

---

## 1. Critical — Security Risks

### C1 · IP Spoofing bypasses rate limiting
DONE - TEST NEEDED

### C2 · Open Redirect after login
DONE - TEST NEEDED

---

### C3 · All auth cookies missing security flags
DONE

---

### C4 · Logout does not invalidate the server-side session
DONE- TEST NEEDED

---

### C5 · Timing attack on nonce comparison
DONE

---

### C6 · Migration endpoint key comparison is not timing-safe
DONE

---

## 2. Warning — Outdated Approaches, Better Solutions, and Bugs

### W1 · [BUG] Session renewal silently never works
DONE

---

### W2 · [BUG] `loginFromSessionToken` stores the hashed token in `$_SESSION`, corrupting future auth
DONE

---

### W3 · [BUG] `loginFromSessionToken` ignores the configured user model
DONE

### W4 · [BUG] `authenticate()` has swapped arguments to `failedLoginAttempt()`
DONE

---

### W5 · [BUG] `ADMIN` privacy level has no enforcement
**Files:** [pz/enums/routing/privacy.php:8](pz/enums/routing/privacy.php#L8), [pz/routing/route.php:109](pz/routing/route.php#L109)

The `Privacy::ADMIN` case exists and `requiresAuth()` returns `true` for it, but `check()` in `Route` only verifies `requiresLogin()`. There is no subsequent role check anywhere in the routing layer. A regular logged-in user passes all checks on a route declared `Privacy::ADMIN`.

**Fix:** Add a role check in `Route::check()` (or `Action::serve()`) when `$this->privacy === Privacy::ADMIN`.

---

### W6 · Ban checks record new failed attempts on every blocked request
DONE

---

### W7 · `loginFromSession` skips all security checks
**File:** [pz/users/auth.php:116-127](pz/users/auth.php#L116-L127)

Once a PHP session exists, the user is re-authenticated with a simple DB lookup — no IP consistency check, no rate limiting, no session expiration validation at the application level (only the token TTL). A captured `PHPSESSID` cookie allows unrestricted access for the full session lifetime from any IP.

**Fix:** At minimum, store the IP at session creation and verify it on each request from session. Consider also re-validating `cookie_end`.

---

### W8 · `user_id` and `user_name` cookies have no integrity protection
DONE - TEST NEEDED ON FRONT
---

### W9 · Unvalidated cookie values passed to templates
DONE

---

### W10 · Nonce is per-user, not per-session — concurrent sessions conflict
**File:** [pz/users/nonce.php:36-51](pz/users/nonce.php#L36-L51)

The `nonces` table has one row per `user_id`. If a user is logged in from two devices simultaneously, the second login overwrites the nonce for the first. Any in-flight POST from the first session immediately fails nonce validation. This is a correctness bug that degrades to a usability problem for any user with multiple active sessions.

**Fix:** Tie nonces to session IDs (or session tokens), not user IDs.

---

## 3. Features — Enhancement Suggestions

### F1 · No password reset flow
There is no mechanism for users who forget their password. A standard email-based reset (tokenized one-time link) is expected at this level of the auth system.

---

### F2 · No active session management for users
Users cannot see or revoke their active sessions (`user_sessions` table). A "connected devices" page is the minimum expected for a token-based session system.

---

### F3 · Rate limiting is IP-only
Pairing IP-based limiting with per-username limiting (e.g., lock after N failures for a given email regardless of source IP) would defend against distributed brute-force and credential-stuffing attacks where the attacker rotates IPs.

---

### F4 · No MFA support
The nonce mechanism is close in concept to a TOTP second factor but is not user-facing. There is no hook point for TOTP, WebAuthn, or email OTP.

---

### F5 · No login history / session audit log
Recording successful logins (timestamp, IP, device) would help users identify unauthorized access and is a common expectation for user-facing security dashboards.

---

### F6 · `login_attempts` table has no built-in cleanup
Old records accumulate indefinitely. Without a TTL-based prune, this table becomes a slow query and storage problem over time.

---

## 4. Code Changes — No Functional Impact

### CC1 · Typos in method/property names
DONE

### CC2 · `$_SESSION['user']['role']` is hardcoded and never sourced from the model
**File:** [pz/users/auth.php:220](pz/users/auth.php#L220)

```php
$_SESSION['user']['role'] = 'user';
```

The role is hardcoded to `'user'` and never read from the User model. If roles are intended to differentiate users (required for `Privacy::ADMIN` anyway), this needs to be read from the database.

---

### CC3 · Commented-out nonce code in `loginUser()`
DONE
---

### CC4 · Redundant double null-check in `render()`
DONE

---

### CC5 · `isLoggedIn()` in `Request` logs an error for normal public-route calls
NOT NEEDED,  isLoggedIn() should not be called if not needed (eg.public routes)
---

### CC6 · Identical strings on both branches of ternary in `get_user_infos`
DONE

### CC7 · `#TODO` dead code comment in `Application`
DONE

### CC8 · `$_SESSION['user']['cookie_end']` is set but never read
DONE

### CC9 · `previousNonce()` in `Auth` and `Nonce` appears unused
DONE

### CC10 · "WTF" log message in `handleRequest`
THIS STATEMENT IS HERE BECAUSE I DO NOT UNDERSTAND WHEN THIS FIRES, THIS PATH SHOULD NOT BE REACHED BUT I'M MISSING SOMETHING AND LOOSING MY MIND

**File:** [pz/application/application.php:232](pz/application/application.php#L232)

```php
Log::warning("WTF is this statement ???");
```

Replace with a descriptive message explaining the condition (page with both GET and POST action fallback).
