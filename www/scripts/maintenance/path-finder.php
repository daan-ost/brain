<?php
/**
 * Path Finder Tool
 * Upload this file to your public folder to find the correct paths
 */

// Set basic auth to protect this file
$username = 'staging';
$password = 'pdfqr';

if (! isset($_SERVER['PHP_AUTH_USER']) ||
    $_SERVER['PHP_AUTH_USER'] !== $username ||
    $_SERVER['PHP_AUTH_PW'] !== $password) {
    header('WWW-Authenticate: Basic realm="Path Finder"');
    header('HTTP/1.0 401 Unauthorized');
    echo 'Access denied';
    exit;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Server Path Finder</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            max-width: 800px;
            margin: 50px auto;
            padding: 20px;
            background: #f5f5f5;
        }
        .info-box {
            background: white;
            padding: 20px;
            margin: 10px 0;
            border-radius: 5px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }
        h1 {
            color: #333;
        }
        h2 {
            color: #666;
            border-bottom: 2px solid #4CAF50;
            padding-bottom: 5px;
        }
        code {
            background: #f0f0f0;
            padding: 2px 6px;
            border-radius: 3px;
            font-family: 'Courier New', monospace;
        }
        .path {
            background: #e8f5e9;
            padding: 15px;
            border-left: 4px solid #4CAF50;
            margin: 10px 0;
            font-family: 'Courier New', monospace;
            word-break: break-all;
        }
        .warning {
            background: #fff3cd;
            padding: 15px;
            border-left: 4px solid #ffc107;
            margin: 10px 0;
        }
        .success {
            background: #d4edda;
            padding: 15px;
            border-left: 4px solid #28a745;
            margin: 10px 0;
        }
        .error {
            background: #f8d7da;
            padding: 15px;
            border-left: 4px solid #dc3545;
            margin: 10px 0;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 10px 0;
        }
        th, td {
            padding: 10px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }
        th {
            background: #4CAF50;
            color: white;
        }
        .delete-btn {
            background: #dc3545;
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            margin-top: 20px;
        }
        .delete-btn:hover {
            background: #c82333;
        }
    </style>
</head>
<body>
    <h1>🔍 Server Path Finder</h1>
    
    <div class="info-box">
        <h2>1. Current Directory (Public Folder)</h2>
        <div class="path"><?php echo __DIR__; ?></div>
        
        <h2>2. Correct .htpasswd Path for .htaccess</h2>
        <div class="path">AuthUserFile <?php echo __DIR__.'/.htpasswd'; ?></div>
        
        <h2>3. Alternative .htpasswd Path (Parent Directory)</h2>
        <div class="path">AuthUserFile <?php echo dirname(__DIR__).'/public/.htpasswd'; ?></div>
    </div>

    <div class="info-box">
        <h2>4. File Check</h2>
        <?php
        $htpasswd = __DIR__.'/.htpasswd';
$htaccess = __DIR__.'/.htaccess';
$htaccessProtected = __DIR__.'/.htaccess-protected';

if (file_exists($htpasswd)) {
    echo "<div class='success'>✅ .htpasswd exists at: $htpasswd</div>";
    echo "<div class='path'>".file_get_contents($htpasswd).'</div>';
} else {
    echo "<div class='error'>❌ .htpasswd NOT found at: $htpasswd</div>";
}

if (file_exists($htaccess)) {
    echo "<div class='success'>✅ .htaccess exists</div>";
} else {
    echo "<div class='warning'>⚠️ .htaccess NOT found (normal if using .htaccess-protected)</div>";
}

if (file_exists($htaccessProtected)) {
    echo "<div class='success'>✅ .htaccess-protected exists</div>";
} else {
    echo "<div class='warning'>⚠️ .htaccess-protected NOT found</div>";
}
?>
    </div>

    <div class="info-box">
        <h2>5. Server Information</h2>
        <table>
            <tr>
                <th>Setting</th>
                <th>Value</th>
            </tr>
            <tr>
                <td>Document Root</td>
                <td><code><?php echo $_SERVER['DOCUMENT_ROOT'] ?? 'N/A'; ?></code></td>
            </tr>
            <tr>
                <td>Script Filename</td>
                <td><code><?php echo $_SERVER['SCRIPT_FILENAME'] ?? 'N/A'; ?></code></td>
            </tr>
            <tr>
                <td>Server Software</td>
                <td><code><?php echo $_SERVER['SERVER_SOFTWARE'] ?? 'N/A'; ?></code></td>
            </tr>
            <tr>
                <td>PHP Version</td>
                <td><code><?php echo phpversion(); ?></code></td>
            </tr>
            <tr>
                <td>Current User</td>
                <td><code><?php echo get_current_user(); ?></code></td>
            </tr>
        </table>
    </div>

    <div class="info-box">
        <h2>6. Copy This to .htaccess</h2>
        <div class="warning">
            <strong>Use this exact line in your .htaccess file:</strong>
        </div>
        <div class="path">
            AuthUserFile <?php echo __DIR__.'/.htpasswd'; ?>
        </div>
    </div>

    <div class="info-box">
        <h2>7. Directory Permissions</h2>
        <?php
$publicDir = __DIR__;
$perms = fileperms($publicDir);
$permString = substr(sprintf('%o', $perms), -4);

echo "<div class='path'>Public directory permissions: $permString</div>";

if ($perms & 0x0004) {
    echo "<div class='success'>✅ Directory is readable</div>";
} else {
    echo "<div class='error'>❌ Directory is NOT readable</div>";
}

if ($perms & 0x0002) {
    echo "<div class='success'>✅ Directory is writable</div>";
} else {
    echo "<div class='warning'>⚠️ Directory is NOT writable</div>";
}
?>
    </div>

    <div class="info-box">
        <h2>⚠️ Security Warning</h2>
        <div class="error">
            <strong>DELETE THIS FILE AFTER USE!</strong><br>
            This file exposes sensitive server information.
        </div>
        <form method="post" style="margin-top: 10px;">
            <button type="submit" name="delete" class="delete-btn">🗑️ Delete This File Now</button>
        </form>
        <?php
if (isset($_POST['delete'])) {
    if (unlink(__FILE__)) {
        echo "<div class='success'>✅ File deleted successfully!</div>";
        echo "<script>setTimeout(function(){ window.location.href = '/'; }, 2000);</script>";
    } else {
        echo "<div class='error'>❌ Could not delete file. Please delete manually.</div>";
    }
}
?>
    </div>

</body>
</html>






