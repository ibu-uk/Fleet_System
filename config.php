<?php
// ============================================================
//  Fleet Management System — Configuration
//  Edit DB credentials below before running setup.sql
// ============================================================

define('DB_HOST',    'localhost');
define('DB_PORT',    '3306');
define('DB_USER',    'root');
define('DB_PASS',    '');
define('DB_NAME',    'Fleet_management');
define('DB_CHARSET', 'utf8mb4');

define('APP_NAME_EN',     'Fleet Management System');
define('APP_NAME_AR',     'نظام إدارة الأسطول');
define('COMPANY_NAME_EN', 'Kuwait Delivery Fleet');
define('COMPANY_NAME_AR', 'أسطول التوصيل — الكويت');
define('APP_VERSION',     '1.0.0');

date_default_timezone_set('Asia/Kuwait');

// ---- PDO singleton ----
function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = "mysql:host=".DB_HOST.";port=".DB_PORT
              .";dbname=".DB_NAME.";charset=".DB_CHARSET;
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            die('<div style="padding:30px;font-family:sans-serif;max-width:680px;
                margin:60px auto;background:#fff3f3;border:2px solid #dc3545;border-radius:12px;">
                <h2 style="color:#dc3545;margin-top:0">⚠️ Database Connection Error</h2>
                <p><strong>Message:</strong> '.htmlspecialchars($e->getMessage()).'</p><hr>
                <p><strong>Steps to fix:</strong></p>
                <ol>
                  <li>Open <code>config.php</code> and update DB_HOST, DB_USER, DB_PASS, DB_NAME</li>
                  <li>Import <code>setup.sql</code> into your MySQL server</li>
                  <li>Reload this page</li>
                </ol>
            </div>');
        }
    }
    return $pdo;
}
